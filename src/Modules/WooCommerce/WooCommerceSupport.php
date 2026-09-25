<?php
/**
 * Whether the WooCommerce module can run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Requirements;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, once WooCommerce has loaded, whether the WooCommerce module may
 * boot (ADR-030): only with an active WooCommerce of at least
 * Requirements::MIN_WOOCOMMERCE. An older WooCommerce keeps the module off
 * entirely (no WooCommerce hooks at all) and shows administrators a neutral
 * notice; the rest of the plugin runs normally. Without WooCommerce nothing
 * happens.
 */
final class WooCommerceSupport {

	/**
	 * WooCommerce's version, or null when WooCommerce is not active. An
	 * empty string means WooCommerce is active but reported no version.
	 *
	 * @var string|null
	 */
	private ?string $version;

	/**
	 * Constructor.
	 *
	 * @param string|null $version WooCommerce version; null when WooCommerce is not active.
	 */
	public function __construct( ?string $version ) {
		$this->version = $version;
	}

	/**
	 * The support status of the running site. WooCommerce defines its class and
	 * WC_VERSION while it is loaded, so this is reliable from `plugins_loaded`.
	 */
	public static function from_environment(): self {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			return new self( null );
		}

		return new self( defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '' );
	}

	/**
	 * WooCommerce is active.
	 */
	public function is_active(): bool {
		return null !== $this->version;
	}

	/**
	 * WooCommerce is active and new enough for the WooCommerce module.
	 */
	public function is_supported(): bool {
		return null !== $this->version
			&& '' !== $this->version
			&& version_compare( $this->version, Requirements::MIN_WOOCOMMERCE, '>=' );
	}

	/**
	 * Registers the notice when WooCommerce is active but too old.
	 */
	public function register_notice(): void {
		if ( $this->is_active() && ! $this->is_supported() ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}
	}

	/**
	 * Prints the neutral notice for an unsupported WooCommerce version. Only
	 * shown to users who can manage plugins, like the core requirements notice,
	 * since it discloses version information.
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$current = '' === (string) $this->version ? __( 'unknown', 'merchboard' ) : (string) $this->version;

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
				__( 'The Merch Dashboard WooCommerce integration is not active: WooCommerce %1$s or later is required. Current version: %2$s. The rest of Merch Dashboard works normally.', 'merchboard' ),
				Requirements::MIN_WOOCOMMERCE,
				$current
			)
		);
		echo '</p></div>';
	}
}
