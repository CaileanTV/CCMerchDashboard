<?php
/**
 * The global alert overlay's page for OBS.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Overlay\OverlayPage;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the global overlay's URL (step 25.9.4) with the same page,
 * script, CSP and headers as a creator's overlay (OverlayPage::page() and
 * send()), but with the global overlay's settings, its own feed and its
 * own text fill images. 404 for an unknown or regenerated id, or while it is
 * switched off or alerts are not being sent.
 */
final class GlobalOverlayPage {

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayRoute       $route  The URL.
	 * @param GlobalOverlayFeed        $feed   The feed (finds the active overlay).
	 * @param OverlayPage              $page   The shared page.
	 * @param Logger                   $logger Technical log.
	 * @param GlobalOverlayImages|null $images Text fill images; null shows only presets.
	 */
	public function __construct( private GlobalOverlayRoute $route, private GlobalOverlayFeed $feed, private OverlayPage $page, private Logger $logger, private ?GlobalOverlayImages $images = null ) {
	}

	/**
	 * `template_redirect`: the page, when the URL is the global overlay's.
	 */
	public function handle_request(): void {
		$id = $this->route->requested_id();

		if ( '' === $id ) {
			return;
		}

		$nonce = OverlayPage::nonce();

		OverlayPage::send( $this->render( $id, $nonce ), $nonce );
	}

	/**
	 * The page for an id, or null when it is not the active global overlay.
	 *
	 * @param string $overlay_id Requested id.
	 * @param string $nonce      CSP nonce of the page's script.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( string $overlay_id, string $nonce ): ?string {
		try {
			$overlay = $this->feed->active( $overlay_id );
		} catch ( Throwable $e ) {
			$this->logger->error( 'The global alert overlay could not be loaded.', array( 'error' => $e::class ) );
			$overlay = null;
		}

		if ( null === $overlay ) {
			return null;
		}

		return $this->page->page(
			$overlay->settings,
			rest_url( 'merchboard/v1/alerts/global-overlay/' . $overlay->overlay_id . '/feed' ),
			$overlay->version(),
			fn( array $fill ): ?array => null === $this->images ? AlertImages::look( null, 0, $fill ) : $this->images->look( $fill ),
			$nonce
		);
	}
}
