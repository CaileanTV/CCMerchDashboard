<?php
/**
 * The merch ad overlay's page for OBS.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * `/?merchboard_merch_ad=<id>` (ADR-113 pkt. 1): the creator's own browser
 * source in OBS. A complete page without the theme, with its own CSP
 * (scripts only from this site with a nonce, images only from this site,
 * framed only by this site for the dashboard's preview). `&preview=1` shows
 * the ad at once and again every few seconds. An unknown id, an ad that is
 * switched off for the shop or a missing build gives an empty 404 page.
 */
final class MerchAdPage {

	/**
	 * The query argument.
	 */
	public const QUERY = 'merchboard_merch_ad';

	/**
	 * The script and style handle.
	 */
	public const HANDLE = 'merchboard-merch-ad';

	/**
	 * Constructor.
	 *
	 * @param MerchAdRepository $settings   The creators' ads.
	 * @param MerchAdView       $view       What an ad shows.
	 * @param string            $plugin_dir The plugin's directory, with a trailing slash.
	 * @param string            $plugin_url The plugin's URL, with a trailing slash.
	 * @param Logger            $logger     Technical log.
	 */
	public function __construct( private MerchAdRepository $settings, private MerchAdView $view, private string $plugin_dir, private string $plugin_url, private Logger $logger ) {
	}

	/**
	 * Hooks the page.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );
	}

	/**
	 * The OBS link of an overlay id.
	 *
	 * @param string $overlay_id The secret id.
	 * @param bool   $preview    The dashboard's preview.
	 */
	public static function url( string $overlay_id, bool $preview = false ): string {
		$args = array( self::QUERY => $overlay_id );

		if ( $preview ) {
			$args['preview'] = '1';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * `template_redirect`: shows the page and stops.
	 */
	public function handle_request(): void {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public page, the id is the credential.
			return;
		}

		$id      = sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$preview = isset( $_GET['preview'] ) && '1' === $_GET['preview']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$nonce   = base64_encode( random_bytes( 18 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A CSP nonce.
		$html    = $this->render( $id, $preview, $nonce );

		foreach ( self::security_headers( $nonce ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		status_header( null === $html ? 404 : 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html ?? '<!DOCTYPE html><title>404</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value; the 404 page is static.
		exit;
	}

	/**
	 * The page, or null when there is nothing to show.
	 *
	 * @param string $overlay_id The secret id.
	 * @param bool   $preview    The dashboard's preview.
	 * @param string $nonce      CSP nonce of the page's script.
	 */
	public function render( string $overlay_id, bool $preview, string $nonce ): ?string {
		try {
			$creator_id = Settings::merch_ad_enabled() ? $this->settings->creator_for( $overlay_id ) : null;

			if ( null === $creator_id || ! $this->register_assets() ) {
				return null;
			}

			$merchboard_view = array(
				'config' => array(
					'status'  => rest_url( 'merchboard/v1/merch-ad/' . $overlay_id ),
					'version' => $this->view->version( $creator_id ),
					'showAt'  => $this->settings->find( $creator_id )->show_at,
					'preview' => $preview,
					'ad'      => $this->view->overlay( $creator_id ),
				),
			);
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The merch ad overlay could not be read.', $e->context() );

			return null;
		}

		$add_nonce = static function ( mixed $attributes ) use ( $nonce ): mixed {
			if ( is_array( $attributes ) ) {
				$attributes['nonce'] = $nonce;
			}

			return $attributes;
		};

		add_filter( 'wp_script_attributes', $add_nonce );
		ob_start();

		try {
			require dirname( __DIR__, 4 ) . '/templates/community/merch-ad.php';

			return (string) ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();
			$this->logger->error( 'The merch ad overlay could not be rendered.', array( 'reason' => $e->getMessage() ) );

			return null;
		} finally {
			remove_filter( 'wp_script_attributes', $add_nonce );
		}
	}

	/**
	 * Security headers of the page.
	 *
	 * @param string $nonce CSP nonce of the page's script.
	 * @return array<string, string>
	 */
	public static function security_headers( string $nonce ): array {
		return array(
			'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
		);
	}

	/**
	 * Registers the overlay's build (`build/merch-ad.js` and `.css`).
	 */
	private function register_assets(): bool {
		$file  = $this->plugin_dir . 'build/merch-ad.asset.php';
		$asset = is_readable( $file ) && is_readable( $this->plugin_dir . 'build/merch-ad.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) ) {
			$this->logger->error( 'The merch ad overlay\'s build is missing; run npm run build.', array( 'file' => 'build/merch-ad.asset.php' ) );

			return false;
		}

		$dependencies = is_array( $asset['dependencies'] ?? null ) ? array_values( array_filter( $asset['dependencies'], static fn( mixed $handle ): bool => is_string( $handle ) && '' !== $handle ) ) : array();

		wp_register_script( self::HANDLE, $this->plugin_url . 'build/merch-ad.js', $dependencies, $asset['version'], true );
		wp_register_style( self::HANDLE, $this->plugin_url . 'build/merch-ad.css', array(), $asset['version'] );

		return true;
	}
}
