<?php
/**
 * The alerts an overlay shows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The public feed of an overlay (ADR-065 pkt. 4), read by the overlay page
 * with long-polling.
 *
 * - **Cursor:** the first request (no cursor) answers at once with the id of
 *   the creator's newest alert, so the page only shows alerts from now on.
 *   Later requests return the alerts after their cursor, oldest first, and
 *   the new cursor. Ids grow with every insert, so nothing is skipped or
 *   repeated (MerchAlert's one-second timestamps could do both).
 * - **Long-poll:** with `wait`, the request checks once a second for up to
 *   WAIT_SECONDS before it answers without alerts.
 * - **Version:** every answer carries the overlay's version; the page
 *   reloads when it changes (new settings or a new id).
 * - **Not found:** an unknown id, a regenerated id or an inactive creator
 *   give null (404), without saying which.
 *
 * Test alerts (OverlayTestQueue) are delivered with id 0 and do not move
 * the cursor.
 *
 * Each alert is rendered on the server: the texts are plain text, and the
 * image and the sound (AlertSounds) are URLs of this site, or '' (the
 * page's CSP only allows its own images and media).
 */
final class OverlayFeed {

	/**
	 * Longest wait of a long-poll in seconds.
	 */
	public const WAIT_SECONDS = 8;

	/**
	 * At most this many alerts per answer.
	 */
	public const LIMIT = 20;

	/**
	 * Sleeps for a number of seconds.
	 *
	 * @var Closure(int): void
	 */
	private Closure $sleep;

	/**
	 * The URL of an attachment image in a size, or ''.
	 *
	 * @var Closure(int, int): string
	 */
	private Closure $image_url;

	/**
	 * Constructor.
	 *
	 * @param AlertOverlayRepository           $overlays  Overlays.
	 * @param AlertEventRepository             $events    Alerts.
	 * @param CreatorRepository                $creators  Creator status.
	 * @param AlertTemplate                    $template  Text rendering.
	 * @param (Closure(int, int): string)|null $image_url Image URL lookup; defaults to the Media Library, same origin only.
	 * @param (Closure(int): void)|null        $sleep     Sleep; defaults to sleep().
	 * @param OverlayTestQueue|null            $tests     Test alerts; null for none.
	 * @param AlertSounds|null                 $sounds    Alert sounds; null for none.
	 */
	public function __construct(
		private AlertOverlayRepository $overlays,
		private AlertEventRepository $events,
		private CreatorRepository $creators,
		private AlertTemplate $template,
		?Closure $image_url = null,
		?Closure $sleep = null,
		private ?OverlayTestQueue $tests = null,
		private ?AlertSounds $sounds = null
	) {
		$this->image_url = $image_url ?? self::media_library_url( ... );
		$this->sleep     = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * The feed answer, or null when the overlay is unknown or its creator inactive.
	 *
	 * @param string   $overlay_id Secret overlay id.
	 * @param int|null $after      Cursor; null for the first request.
	 * @param bool     $wait       Whether to wait for new alerts.
	 * @return array{cursor: int, version: string, alerts: list<array{id: int, image: string, text: string, secondary: string, sound: string}>}|null
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function read( string $overlay_id, ?int $after, bool $wait ): ?array {
		$overlay = $this->active_overlay( $overlay_id );

		if ( null === $overlay ) {
			return null;
		}

		if ( null === $after ) {
			return self::answer( $this->events->latest_id( $overlay->creator_id ), $overlay, array() );
		}

		$checks = $wait ? self::WAIT_SECONDS : 0;

		for ( $check = 0; ; ++$check ) {
			$events = $this->events->after( $overlay->creator_id, $after, self::LIMIT );
			$tests  = null === $this->tests ? array() : $this->tests->drain( $overlay->id );

			if ( array() !== $events || array() !== $tests || $check >= $checks ) {
				$last = end( $events );

				return self::answer( false === $last ? $after : $last->id, $overlay, array_merge( array_map( fn( AlertEvent $event ): array => $this->render( $overlay, $event ), $events ), $tests ) );
			}

			( $this->sleep )( 1 );

			// The settings or the id may have changed while waiting.
			$overlay = $this->active_overlay( $overlay_id );

			if ( null === $overlay ) {
				return null;
			}
		}
	}

	/**
	 * The overlay, if its creator is active; null otherwise.
	 *
	 * @param string $overlay_id Secret overlay id.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function active_overlay( string $overlay_id ): ?AlertOverlay {
		$overlay = $this->overlays->find_by_overlay_id( $overlay_id );

		return null !== $overlay && true === $this->creators->find( $overlay->creator_id )?->is_active() ? $overlay : null;
	}

	/**
	 * One alert as the page shows it (also used for test alerts).
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @param AlertEvent   $event   Alert.
	 * @return array{id: int, image: string, text: string, secondary: string, sound: string}
	 */
	public function render( AlertOverlay $overlay, AlertEvent $event ): array {
		$settings = $overlay->settings;

		return array(
			'id'        => $event->id,
			'image'     => $event->image_id > 0 ? ( $this->image_url )( $event->image_id, $settings->image_size() ) : '',
			'text'      => $this->template->render( $settings->template(), $event ),
			'secondary' => $settings->shows_secondary() ? $this->template->render( $settings->secondary_template(), $event ) : '',
			'sound'     => null === $this->sounds ? '' : $this->sounds->url_for( $overlay, $event ),
		);
	}

	/**
	 * The answer.
	 *
	 * @param int                                                                                 $cursor  Cursor.
	 * @param AlertOverlay                                                                        $overlay Overlay.
	 * @param list<array{id: int, image: string, text: string, secondary: string, sound: string}> $alerts  Alerts.
	 * @return array{cursor: int, version: string, alerts: list<array{id: int, image: string, text: string, secondary: string, sound: string}>}
	 */
	private static function answer( int $cursor, AlertOverlay $overlay, array $alerts ): array {
		return array(
			'cursor'  => $cursor,
			'version' => $overlay->version(),
			'alerts'  => $alerts,
		);
	}

	/**
	 * The URL of an image in the Media Library, only when it is on this site.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $size          Wanted width and height in pixels.
	 */
	private static function media_library_url( int $attachment_id, int $size ): string {
		$url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && wp_parse_url( home_url(), PHP_URL_HOST ) === $host ? $url : '';
	}
}
