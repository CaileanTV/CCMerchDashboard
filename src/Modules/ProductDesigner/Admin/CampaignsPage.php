<?php
/**
 * Merch Dashboard → Campaigns.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Campaigns\Campaign;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * The company's campaigns (step 30.4, ADR-080 pkt. 9), for
 * `merchboard_manage_designer`: the list with period, coupon codes, sale
 * prices, status and how many sales rows each covers, and the form to
 * create or change one. Times are entered and shown in the site's time zone
 * and stored in UTC. Every change goes through CampaignActions.
 */
final class CampaignsPage {

	/**
	 * The page slug.
	 */
	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_campaign_result';

	/**
	 * `admin_menu` priority: after "News" (28), before "Audit log" (30).
	 */
	public const PRIORITY = 29;

	/**
	 * Format of `<input type="datetime-local">`.
	 */
	public const INPUT_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository $campaigns Campaigns.
	 */
	public function __construct( private CampaignRepository $campaigns ) {
	}

	/**
	 * The list's URL.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result = '' ): string {
		return self::page_url( array(), $result );
	}

	/**
	 * The form's URL: a new campaign, or a campaign to change.
	 *
	 * @param int    $id     Campaign id; 0 for a new one.
	 * @param string $result Outcome code.
	 */
	public static function edit_url( int $id = 0, string $result = '' ): string {
		return self::page_url( $id > 0 ? array( 'campaign' => $id ) : array( 'view' => 'new' ), $result );
	}

	/**
	 * Message and notice type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'created'                                => array( __( 'The campaign was created.', 'merchboard' ), 'success' ),
			'saved'                                  => array( __( 'The campaign was saved. It applies to orders registered from now on; sales already registered keep their amounts.', 'merchboard' ), 'success' ),
			'deleted'                                => array( __( 'The campaign was deleted.', 'merchboard' ), 'success' ),
			'invalid_time'                           => array( __( 'Enter a valid start and end.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_NAME         => array( __( 'Enter a name of at most 191 characters.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_PERIOD       => array( __( 'The end must be after the start.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_SCOPE        => array( __( 'A campaign needs coupon codes, sale prices or both.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_CODES        => array( __( 'Enter at most 100 coupon codes of at most 191 characters each.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_CREATOR_CODE => array( __( 'A creator\'s own discount code cannot be a campaign code: it would let the creator take the company below its base price.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_UNKNOWN      => array( __( 'The campaign no longer exists.', 'merchboard' ), 'error' ),
			DesignerException::CAMPAIGN_IN_USE       => array( __( 'The campaign covers registered sales and cannot be deleted. Move its end to end it.', 'merchboard' ), 'error' ),
			DesignerException::NOT_AUTHORIZED        => array( __( 'You are not allowed to manage campaigns.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage campaigns.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters; nothing is changed by this request.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$id     = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( 'new' !== $view && 0 === $id ) {
			echo '<p><a class="button" href="' . esc_url( self::edit_url() ) . '">' . esc_html__( 'New campaign', 'merchboard' ) . '</a></p>';
		}

		try {
			if ( 'new' === $view ) {
				$this->render_form( null );
			} elseif ( $id > 0 ) {
				$campaign = $this->campaigns->find( $id );

				if ( null === $campaign ) {
					echo '<p>' . esc_html__( 'The campaign no longer exists.', 'merchboard' ) . '</p>';
				} else {
					$this->render_form( $campaign );
				}
			} else {
				$this->render_list();
			}
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The campaigns could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
		}
	}

	/**
	 * The list.
	 *
	 * @throws DatabaseException When the campaigns cannot be read.
	 */
	private function render_list(): void {
		echo '<p>' . esc_html__( 'The company\'s own campaigns for designer products. Outside a campaign the company always keeps the base price: a discount comes off the creator\'s profit, never below 0. In a campaign the discount is shared between the company and the creator in proportion to base price and profit. An order line is in a campaign when the campaign ran when it was bought and the order used one of the campaign\'s coupon codes, or the campaign covers sale prices and the line was sold below its price. Legacy products are never affected.', 'merchboard' ) . '</p>';

		$campaigns = $this->campaigns->all();

		if ( array() === $campaigns ) {
			echo '<p>' . esc_html__( 'No campaigns yet.', 'merchboard' ) . '</p>';

			return;
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'merchboard' ) . '</th><th>' . esc_html__( 'Period', 'merchboard' ) . '</th><th>' . esc_html__( 'Coupon codes', 'merchboard' ) . '</th><th>' . esc_html__( 'Sale prices', 'merchboard' ) . '</th><th>' . esc_html__( 'Status', 'merchboard' ) . '</th><th>' . esc_html__( 'Sales rows', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $campaigns as $campaign ) {
			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( self::edit_url( (int) $campaign->id ) ),
				esc_html( $campaign->name ),
				esc_html( self::local( $campaign->starts_at, 'display' ) . ' – ' . self::local( $campaign->ends_at, 'display' ) ),
				esc_html( array() === $campaign->coupon_codes ? '—' : implode( ', ', $campaign->coupon_codes ) ),
				esc_html( $campaign->sale_prices ? __( 'Yes', 'merchboard' ) : __( 'No', 'merchboard' ) ),
				esc_html( self::status( $campaign, $now ) ),
				esc_html( (string) $this->campaigns->rows( (int) $campaign->id ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The form.
	 *
	 * @param Campaign|null $campaign The campaign; null for a new one.
	 * @throws DatabaseException When the campaign's sales cannot be counted.
	 */
	private function render_form( ?Campaign $campaign ): void {
		$id = (int) ( $campaign->id ?? 0 );

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( '← All campaigns', 'merchboard' ) . '</a></p>';

		if ( null !== $campaign && $this->campaigns->rows( $id ) > 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This campaign covers registered sales. A change applies only to orders registered from now on; registered sales keep their amounts.', 'merchboard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CampaignActions::ACTION ) . '">';
		echo '<input type="hidden" name="campaign_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( CampaignActions::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-campaign-name">' . esc_html__( 'Name', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-campaign-name" name="name" class="regular-text" maxlength="191" required value="' . esc_attr( $campaign->name ?? '' ) . '"><p class="description">' . esc_html__( 'Shown to administrators only.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-campaign-start">' . esc_html__( 'Start', 'merchboard' ) . '</label></th><td><input type="datetime-local" id="merchboard-campaign-start" name="starts_at" required value="' . esc_attr( null === $campaign ? '' : self::local( $campaign->starts_at, self::INPUT_FORMAT ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-campaign-end">' . esc_html__( 'End', 'merchboard' ) . '</label></th><td><input type="datetime-local" id="merchboard-campaign-end" name="ends_at" required value="' . esc_attr( null === $campaign ? '' : self::local( $campaign->ends_at, self::INPUT_FORMAT ) ) . '">';
		/* translators: %s: the site's time zone, e.g. "Europe/Copenhagen". */
		echo '<p class="description">' . esc_html( sprintf( __( 'Time zone: %s. The campaign covers orders bought from the start until just before the end.', 'merchboard' ), wp_timezone_string() ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-campaign-codes">' . esc_html__( 'Coupon codes', 'merchboard' ) . '</label></th><td><textarea id="merchboard-campaign-codes" name="coupon_codes" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $campaign->coupon_codes ?? array() ) ) . '</textarea><p class="description">' . esc_html__( 'One per line (or separated by commas). The company\'s WooCommerce coupons only; a creator\'s own discount code is refused.', 'merchboard' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Sale prices', 'merchboard' ) . '</th><td><label><input type="checkbox" name="sale_prices" value="1"' . checked( $campaign->sale_prices ?? false, true, false ) . '> ' . esc_html__( 'Also cover designer products sold at a sale price', 'merchboard' ) . '</label></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" name="do" value="save" class="button button-primary">' . esc_html( null === $campaign ? __( 'Create campaign', 'merchboard' ) : __( 'Save campaign', 'merchboard' ) ) . '</button></p>';

		if ( null !== $campaign && 0 === $this->campaigns->rows( $id ) ) {
			echo '<p><button type="submit" name="do" value="delete" class="button-link button-link-delete" formnovalidate>' . esc_html__( 'Delete campaign', 'merchboard' ) . '</button></p>';
		}

		echo '</form>';
	}

	/**
	 * A campaign's status now.
	 *
	 * @param Campaign          $campaign Campaign.
	 * @param DateTimeImmutable $now      Now (UTC).
	 */
	private static function status( Campaign $campaign, DateTimeImmutable $now ): string {
		return match ( true ) {
			$campaign->runs_at( $now )   => __( 'Running', 'merchboard' ),
			$now < $campaign->starts_at  => __( 'Planned', 'merchboard' ),
			default                      => __( 'Ended', 'merchboard' ),
		};
	}

	/**
	 * A UTC time in the site's time zone.
	 *
	 * @param DateTimeImmutable $utc    Time.
	 * @param string            $format `display` for the site's date and time format, else a PHP format.
	 */
	private static function local( DateTimeImmutable $utc, string $format ): string {
		$local = $utc->setTimezone( wp_timezone() );

		return 'display' === $format ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $local->getTimestamp() ) : $local->format( $format );
	}

	/**
	 * A URL of the page.
	 *
	 * @param array<string, int|string> $args   Query arguments.
	 * @param string                    $result Outcome code.
	 */
	private static function page_url( array $args, string $result ): string {
		if ( '' !== $result ) {
			$args[ self::RESULT_ARG ] = $result;
		}

		return DesignerMenu::url( DesignerMenu::CAMPAIGNS, $args );
	}
}
