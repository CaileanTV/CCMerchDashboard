<?php
/**
 * "API" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Api\AlertApiAccess;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiKeyRepository;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhook;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhookRepository;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The alert API for the creators' own bots (steps 25.12.1–25.12.4,
 * ADR-073): the global switch (off by default) and one table of the
 * creators. Each row has the creator's access to API keys and webhooks
 * (step 25.12.4; off until given here), their working keys (how many, last
 * used) with "Revoke all", and their webhooks (name, format, host, status)
 * with "Switch off all". Keys, addresses and secrets are never shown. The
 * page changes nothing; the forms post to AlertApiActions.
 */
final class AlertApiTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'api';

	/**
	 * Constructor.
	 *
	 * @param AlertApiSwitch              $api      The switch.
	 * @param AlertApiKeyRepository       $keys     Keys.
	 * @param CreatorRepository           $creators Creators.
	 * @param AlertWebhookRepository|null $webhooks Webhooks (step 25.12.2); null for none.
	 * @param AlertApiAccess|null         $access   The creators' access (step 25.12.4); null for none.
	 */
	public function __construct( private AlertApiSwitch $api, private AlertApiKeyRepository $keys, private CreatorRepository $creators, private ?AlertWebhookRepository $webhooks = null, private ?AlertApiAccess $access = null ) {
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'api_enabled'                                 => array( __( 'The alert API is on. Give creators access to API keys or webhooks in the table below.', 'merchboard' ), 'success' ),
			'api_disabled'                                => array( __( 'The alert API is off. Every key stops working until it is switched on again.', 'merchboard' ), 'success' ),
			'api_access_saved'                            => array( __( 'The creator\'s access was saved.', 'merchboard' ), 'success' ),
			'api_keys_revoked'                            => array( __( 'The creator\'s keys were revoked.', 'merchboard' ), 'success' ),
			'api_webhooks_disabled'                       => array( __( 'The creator\'s webhooks were switched off. The creator can switch them on again while the API is on.', 'merchboard' ), 'success' ),
			'api_confirm_required'                        => array( __( 'Tick the box to confirm.', 'merchboard' ), 'error' ),
			'api_error'                                   => array( __( 'The change could not be saved. Please try again.', 'merchboard' ), 'error' ),
			'api_' . AlertApiException::NOT_AUTHORIZED    => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'api_' . AlertApiException::UNKNOWN_CREATOR   => array( __( 'The creator no longer exists.', 'merchboard' ), 'error' ),
			default                                       => null,
		};
	}

	/**
	 * Renders the tab.
	 *
	 * @throws DatabaseException When the creators, keys or webhooks cannot be read.
	 */
	public function render(): void {
		$enabled = $this->api->enabled();

		echo '<p>' . esc_html__( 'Creators can fetch their own purchase alerts into their own bots (for example a Discord or Twitch bot) with an API key, or have them sent to a webhook. A bot gets the same facts as the alert template: the customer\'s voluntary username, the products, quantities, categories, variations and amounts; never names, e-mails, addresses or order numbers. Keys are shown to the creator once and stored only as a hash.', 'merchboard' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="switch">';
		wp_nonce_field( AlertApiActions::ACTION );
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Creators can use the alert API', 'merchboard' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Off by default. While it is off, the API answers 503, nothing is sent to webhooks and creators cannot make keys or webhooks; everything is kept. Alerts that are switched off in Settings → Alerts are not sent through the API either.', 'merchboard' ) . '</p>';
		submit_button( __( 'Save', 'merchboard' ) );
		echo '</form>';

		$this->render_creators();
	}

	/**
	 * One row per creator: access, keys and webhooks. Every active creator,
	 * and an inactive one that still has keys or webhooks.
	 *
	 * @throws DatabaseException When the creators, keys or webhooks cannot be read.
	 */
	private function render_creators(): void {
		$keys = array();

		foreach ( $this->keys->overview() as $row ) {
			$keys[ $row['creator_id'] ] = $row;
		}

		$webhooks = array();

		foreach ( null === $this->webhooks ? array() : $this->webhooks->all() as $webhook ) {
			$webhooks[ $webhook->creator_id ][] = $webhook;
		}

		$creators = array_values( array_filter( $this->creators->all(), static fn( Creator $creator ): bool => $creator->is_active() || isset( $keys[ $creator->id ] ) || isset( $webhooks[ $creator->id ] ) ) );

		echo '<h2>' . esc_html__( 'Creators', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A creator can only use API keys or webhooks with access given here. Without access their keys and webhooks are kept but do not work, and the pages are hidden in their dashboard.', 'merchboard' ) . '</p>';

		if ( array() === $creators ) {
			echo '<p>' . esc_html__( 'There are no active creators.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Access', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'API keys', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Webhooks', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $creators as $creator ) {
			echo '<tr><td><a href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) ) . '">' . esc_html( $creator->display_name ) . '</a>' . ( $creator->is_active() ? '' : ' (' . esc_html__( 'inactive', 'merchboard' ) . ')' ) . '</td>';
			echo '<td>';
			$this->render_access( $creator->id );
			echo '</td><td>';
			$this->render_keys( $creator->id, $keys[ $creator->id ] ?? null );
			echo '</td><td>';
			$this->render_webhooks( $creator->id, $webhooks[ $creator->id ] ?? array() );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The access form of a creator.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the access cannot be read.
	 */
	private function render_access( int $creator_id ): void {
		if ( null === $this->access ) {
			return;
		}

		$access = $this->access->for_creator( $creator_id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="access">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
		wp_nonce_field( AlertApiActions::ACTION );
		echo '<label><input type="checkbox" name="keys" value="1"' . checked( $access['keys'], true, false ) . '> ' . esc_html__( 'API keys', 'merchboard' ) . '</label><br>';
		echo '<label><input type="checkbox" name="webhooks" value="1"' . checked( $access['webhooks'], true, false ) . '> ' . esc_html__( 'Webhooks', 'merchboard' ) . '</label><br>';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Save access', 'merchboard' ) . '</button>';
		echo '</form>';
	}

	/**
	 * A creator's working keys, with "Revoke all".
	 *
	 * @param int                                                                          $creator_id Creator.
	 * @param array{creator_id: int, keys: int, last_used_at: DateTimeImmutable|null}|null $row        Keys of the creator; null for none.
	 */
	private function render_keys( int $creator_id, ?array $row ): void {
		if ( null === $row ) {
			echo esc_html__( 'None', 'merchboard' );

			return;
		}

		$used = null === $row['last_used_at'] ? __( 'never', 'merchboard' ) : wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $row['last_used_at']->getTimestamp() );

		/* translators: 1: number of working keys, 2: when one was last used. */
		echo '<p>' . esc_html( sprintf( __( '%1$d working · last used %2$s', 'merchboard' ), $row['keys'], false === $used ? '' : $used ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="revoke_all">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
		wp_nonce_field( AlertApiActions::ACTION );
		echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Stop every key of this creator', 'merchboard' ) . '</label> ';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Revoke all', 'merchboard' ) . '</button>';
		echo '</form>';
	}

	/**
	 * A creator's webhooks, with "Switch off all". Never the address or the secret.
	 *
	 * @param int            $creator_id Creator.
	 * @param AlertWebhook[] $items      The creator's webhooks.
	 */
	private function render_webhooks( int $creator_id, array $items ): void {
		if ( array() === $items ) {
			echo esc_html__( 'None', 'merchboard' );

			return;
		}

		echo '<ul>';

		foreach ( $items as $webhook ) {
			$status = match ( true ) {
				! $webhook->enabled           => __( 'off', 'merchboard' ),
				null !== $webhook->paused_at => __( 'paused', 'merchboard' ),
				default                       => __( 'on', 'merchboard' ),
			};
			/* translators: 1: webhook name, 2: JSON or Discord, 3: host, 4: on, off or paused. */
			echo '<li>' . esc_html( sprintf( __( '%1$s (%2$s, %3$s): %4$s', 'merchboard' ), $webhook->name, AlertWebhook::DISCORD === $webhook->format ? 'Discord' : 'JSON', $webhook->url_host, $status ) ) . '</li>';
		}

		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="disable_webhooks">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
		wp_nonce_field( AlertApiActions::ACTION );
		echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Stop every webhook of this creator', 'merchboard' ) . '</label> ';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Switch off all', 'merchboard' ) . '</button>';
		echo '</form>';
	}
}
