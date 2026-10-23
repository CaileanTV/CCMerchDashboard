<?php
/**
 * "API" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiKeyRepository;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhook;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhookRepository;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The alert API for the creators' own bots (step 25.12.1, ADR-073): the
 * switch (off by default) and the creators with working keys, how many and
 * when one was last used, with "Revoke all" per creator. The keys
 * themselves are never shown. The page changes nothing; the forms post to
 * AlertApiActions.
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
	 */
	public function __construct( private AlertApiSwitch $api, private AlertApiKeyRepository $keys, private CreatorRepository $creators, private ?AlertWebhookRepository $webhooks = null ) {
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'api_enabled'                                => array( __( 'The alert API is on. Creators can make keys under Alerts → API in their dashboard.', 'merchboard' ), 'success' ),
			'api_disabled'                               => array( __( 'The alert API is off. Every key stops working until it is switched on again.', 'merchboard' ), 'success' ),
			'api_keys_revoked'                           => array( __( 'The creator\'s keys were revoked.', 'merchboard' ), 'success' ),
			'api_webhooks_disabled'                      => array( __( 'The creator\'s webhooks were switched off. The creator can switch them on again while the API is on.', 'merchboard' ), 'success' ),
			'api_confirm_required'                       => array( __( 'Tick the box to confirm.', 'merchboard' ), 'error' ),
			'api_error'                                  => array( __( 'The change could not be saved. Please try again.', 'merchboard' ), 'error' ),
			'api_' . AlertApiException::NOT_AUTHORIZED   => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			default                                      => null,
		};
	}

	/**
	 * Renders the tab.
	 *
	 * @throws DatabaseException When the keys cannot be read.
	 */
	public function render(): void {
		$enabled = $this->api->enabled();

		echo '<p>' . esc_html__( 'Creators can fetch their own purchase alerts into their own bots (for example a Discord or Twitch bot) with an API key. A bot gets the same facts as the alert template: the customer\'s voluntary username, the products, quantities, categories, variations and amounts; never names, e-mails, addresses or order numbers. Keys are shown to the creator once and stored only as a hash.', 'merchboard' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="switch">';
		wp_nonce_field( AlertApiActions::ACTION );
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Creators can use the alert API', 'merchboard' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Off by default. While it is off, the API answers 503 and creators cannot make keys; the keys are kept. Alerts that are switched off in Settings → Alerts are not sent through the API either.', 'merchboard' ) . '</p>';
		submit_button( __( 'Save', 'merchboard' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Creators with API keys', 'merchboard' ) . '</h2>';
		$overview = $this->keys->overview();

		if ( array() === $overview ) {
			echo '<p>' . esc_html__( 'No creator has a working key.', 'merchboard' ) . '</p>';
		} else {
			$this->render_keys( $overview );
		}

		if ( null !== $this->webhooks ) {
			$this->render_webhooks( $this->webhooks->all() );
		}
	}

	/**
	 * The creators with webhooks: name, format, host and status of each,
	 * with "Switch off all" per creator. Never the address or the secret.
	 *
	 * @param AlertWebhook[] $webhooks Every webhook.
	 * @throws DatabaseException When a creator cannot be read.
	 */
	private function render_webhooks( array $webhooks ): void {
		echo '<h2>' . esc_html__( 'Creators with webhooks', 'merchboard' ) . '</h2>';

		if ( array() === $webhooks ) {
			echo '<p>' . esc_html__( 'No creator has a webhook.', 'merchboard' ) . '</p>';

			return;
		}

		$by_creator = array();

		foreach ( $webhooks as $webhook ) {
			$by_creator[ $webhook->creator_id ][] = $webhook;
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Webhooks', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Switch off', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $by_creator as $creator_id => $items ) {
			$creator = $this->creators->find( $creator_id );
			$name    = null === $creator ? '#' . $creator_id : $creator->display_name;

			echo '<tr><td><a href="' . esc_url( CreatorsAdminPage::edit_url( $creator_id ) ) . '">' . esc_html( $name ) . '</a></td><td><ul>';

			foreach ( $items as $webhook ) {
				$status = match ( true ) {
					! $webhook->enabled           => __( 'off', 'merchboard' ),
					null !== $webhook->paused_at => __( 'paused', 'merchboard' ),
					default                       => __( 'on', 'merchboard' ),
				};
				/* translators: 1: webhook name, 2: JSON or Discord, 3: host, 4: on, off or paused. */
				echo '<li>' . esc_html( sprintf( __( '%1$s (%2$s, %3$s): %4$s', 'merchboard' ), $webhook->name, AlertWebhook::DISCORD === $webhook->format ? 'Discord' : 'JSON', $webhook->url_host, $status ) ) . '</li>';
			}

			echo '</ul></td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
			echo '<input type="hidden" name="do" value="disable_webhooks">';
			echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator_id ) . '">';
			wp_nonce_field( AlertApiActions::ACTION );
			echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Stop every webhook of this creator', 'merchboard' ) . '</label> ';
			echo '<button type="submit" class="button">' . esc_html__( 'Switch off all', 'merchboard' ) . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The creators with working keys.
	 *
	 * @param list<array{creator_id: int, keys: int, last_used_at: \DateTimeImmutable|null}> $overview Keys per creator.
	 * @throws DatabaseException When a creator cannot be read.
	 */
	private function render_keys( array $overview ): void {
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Creator', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Working keys', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Last used', 'merchboard' ) . '</th><th scope="col">' . esc_html__( 'Revoke', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $overview as $row ) {
			$creator = $this->creators->find( $row['creator_id'] );
			$name    = null === $creator ? '#' . $row['creator_id'] : $creator->display_name;
			$used    = null === $row['last_used_at'] ? __( 'Never', 'merchboard' ) : wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $row['last_used_at']->getTimestamp() );

			echo '<tr><td><a href="' . esc_url( CreatorsAdminPage::edit_url( $row['creator_id'] ) ) . '">' . esc_html( $name ) . '</a></td>';
			echo '<td>' . esc_html( (string) $row['keys'] ) . '</td>';
			echo '<td>' . esc_html( false === $used ? '' : $used ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( AlertApiActions::ACTION ) . '">';
			echo '<input type="hidden" name="do" value="revoke_all">';
			echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $row['creator_id'] ) . '">';
			wp_nonce_field( AlertApiActions::ACTION );
			echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Stop every key of this creator', 'merchboard' ) . '</label> ';
			echo '<button type="submit" class="button">' . esc_html__( 'Revoke all', 'merchboard' ) . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}
}
