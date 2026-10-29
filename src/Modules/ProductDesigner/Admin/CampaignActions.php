<?php
/**
 * The admin-post handler of the campaigns.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignService;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, changes and deletes campaigns (ADR-080 pkt. 9). Order of checks:
 * capability `merchboard_manage_designer` → nonce → the service (which
 * checks again). Times come in the site's time zone and are stored in UTC.
 * Post/Redirect/Get to the campaigns page with an outcome code.
 */
final class CampaignActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_campaign';

	/**
	 * Constructor.
	 *
	 * @param CampaignService $campaigns The campaigns.
	 * @param Logger          $logger    Technical log.
	 */
	public function __construct( private CampaignService $campaigns, private Logger $logger ) {
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); the service validates and sanitizes.
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage campaigns.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text  = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$id    = absint( $text( 'campaign_id' ) );
		$do    = sanitize_key( $text( 'do' ) );
		$nonce = $text( '_wpnonce' );

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( 'delete' === $do && $id > 0 ) {
				$this->campaigns->delete( $id );

				return CampaignsPage::url( 'deleted' );
			}

			if ( 'save' !== $do ) {
				return CampaignsPage::url( 'error' );
			}

			$starts = self::utc( $text( 'starts_at' ) );
			$ends   = self::utc( $text( 'ends_at' ) );

			if ( null === $starts || null === $ends ) {
				return CampaignsPage::edit_url( $id, 'invalid_time' );
			}

			if ( $id > 0 ) {
				$this->campaigns->update( $id, $text( 'name' ), $starts, $ends, $text( 'coupon_codes' ), '1' === $text( 'sale_prices' ) );

				return CampaignsPage::edit_url( $id, 'saved' );
			}

			return CampaignsPage::edit_url( $this->campaigns->create( $text( 'name' ), $starts, $ends, $text( 'coupon_codes' ), '1' === $text( 'sale_prices' ) ), 'created' );
		} catch ( DesignerException $e ) {
			return CampaignsPage::edit_url( DesignerException::CAMPAIGN_UNKNOWN === $e->reason() ? 0 : $id, $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing a campaign failed.', array_merge( $e->context(), array( 'campaign_id' => $id ) ) );

			return CampaignsPage::url( 'error' );
		}
	}

	/**
	 * A `datetime-local` value in the site's time zone as UTC, or null.
	 *
	 * @param string $value Input.
	 */
	private static function utc( string $value ): ?DateTimeImmutable {
		$local = DateTimeImmutable::createFromFormat( '!' . CampaignsPage::INPUT_FORMAT, trim( $value ), wp_timezone() );

		if ( false === $local || $local->format( CampaignsPage::INPUT_FORMAT ) !== trim( $value ) ) {
			return null;
		}

		return $local->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
