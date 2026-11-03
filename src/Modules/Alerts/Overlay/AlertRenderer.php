<?php
/**
 * One alert as an overlay page shows it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;
use MerchBoard\Modules\Alerts\AlertEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an alert with an overlay's settings: the product image (only
 * from this site's Media Library), the main text and the secondary text
 * (when shown), and the sound the caller resolved. Shared by the creators'
 * overlays (OverlayFeed) and the global overlay (step 25.9.4); it knows no
 * owner, so it never mixes their data.
 */
final class AlertRenderer {

	/**
	 * The URL of an image: attachment id and size → URL ('' for none).
	 *
	 * @var Closure(int, int): string
	 */
	private Closure $image_url;

	/**
	 * Constructor.
	 *
	 * @param AlertTemplate                    $template  Text templates.
	 * @param (Closure(int, int): string)|null $image_url Image URL; defaults to the Media Library on this site.
	 */
	public function __construct( private AlertTemplate $template, ?Closure $image_url = null ) {
		$this->image_url = $image_url ?? self::media_library_url( ... );
	}

	/**
	 * One alert as the page shows it: the texts whole and in pieces (the
	 * inserted values marked, ADR-088), and the variation it is shown with.
	 *
	 * @param OverlaySettings $settings The settings of the chosen look (OverlaySettings::look()).
	 * @param AlertEvent      $event    Alert.
	 * @param string          $sound    The sound's URL ('' for none).
	 * @param string          $look     The chosen variation's id; '' for the default.
	 * @return array{id: int, look: string, image: string, text: string, secondary: string, parts: list<array{text: string, value: bool}>, secondary_parts: list<array{text: string, value: bool}>, sound: string}
	 */
	public function render( OverlaySettings $settings, AlertEvent $event, string $sound, string $look = '' ): array {
		$values    = $settings->to_array();
		$parts     = $this->template->segments( $settings->template(), $event, $values['number_format'], $values['max_name_length'] );
		$secondary = $settings->shows_secondary() ? $this->template->segments( $settings->secondary_template(), $event, $values['number_format'], $values['max_name_length'] ) : array();

		return array(
			'id'              => $event->id,
			'look'            => $look,
			'image'           => $event->image_id > 0 ? ( $this->image_url )( $event->image_id, $settings->image_size() ) : '',
			'text'            => implode( '', array_column( $parts, 'text' ) ),
			'secondary'       => implode( '', array_column( $secondary, 'text' ) ),
			'parts'           => $parts,
			'secondary_parts' => $secondary,
			'sound'           => $sound,
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
