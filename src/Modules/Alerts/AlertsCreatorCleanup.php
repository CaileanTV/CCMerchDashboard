<?php
/**
 * The Alerts module's part of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;
use Throwable;
use WP_Post;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (phase 27, step 27.3, ADR-076): the creator's chatbot
 * channels are disconnected (tokens released and subscriptions removed at
 * the platform, like "Disconnect"), then its overlay, alert history, chat
 * messages, API keys, webhooks and their deliveries are deleted, and its
 * uploaded sounds and images (never MerchAlert's own files, ADR-065). Queued
 * chat messages find no channel and send nothing; queued webhook deliveries
 * find no delivery. Idempotent.
 */
class AlertsCreatorCleanup implements CreatorDataCleaner {

	/**
	 * The creator's rows, deleted (deliveries before their webhooks).
	 */
	private const TABLES = array(
		Tables::ALERT_WEBHOOK_DELIVERIES,
		Tables::ALERT_WEBHOOKS,
		Tables::ALERT_API_KEYS,
		Tables::ALERT_CHAT_MESSAGES,
		Tables::ALERT_CHATBOT,
		Tables::ALERT_EVENTS,
		Tables::ALERT_OVERLAYS,
	);

	/**
	 * Constructor.
	 *
	 * @param Closure $disconnect Disconnects a channel (CreatorChatbot::disconnect()).
	 * @param wpdb    $wpdb       Database.
	 * @param Logger  $logger     Technical log.
	 * @phpstan-param Closure(int, ChatPlatform): bool $disconnect
	 */
	public function __construct( private Closure $disconnect, private wpdb $wpdb, private Logger $logger ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'alerts';
	}

	/**
	 * Removes the creator's alert data.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 * @throws DatabaseException When something cannot be removed.
	 */
	public function remove( Creator $creator ): array {
		$removed = array( 'channels' => $this->disconnect( $creator->id ) );

		foreach ( self::TABLES as $table ) {
			$deleted = $this->wpdb->delete( Tables::name( $this->wpdb, $table ), array( 'creator_id' => $creator->id ), array( '%d' ) );

			if ( false === $deleted ) {
				throw new DatabaseException( 'Could not delete the alert data of the deleted creator.' );
			}

			$removed[ str_replace( 'merchboard_alert_', '', $table ) ] = $deleted;
		}

		$removed['files'] = $this->delete_files( $creator->id );

		return $removed;
	}

	/**
	 * Disconnects every chatbot channel. A platform that cannot be reached
	 * does not stop the deletion: the error is logged, and the channel row is
	 * deleted with the rest.
	 *
	 * @param int $creator_id Creator.
	 * @return int Channels disconnected.
	 * @throws DatabaseException When the database fails.
	 */
	private function disconnect( int $creator_id ): int {
		$disconnected = 0;

		foreach ( ChatPlatform::cases() as $platform ) {
			try {
				$disconnected += ( $this->disconnect )( $creator_id, $platform ) ? 1 : 0;
			} catch ( DatabaseException $e ) {
				throw $e;
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'A chatbot channel of a deleted creator could not be disconnected at the platform.',
					array(
						'creator_id' => $creator_id,
						'platform'   => $platform->value,
						'error'      => $e,
					)
				);
			}
		}

		return $disconnected;
	}

	/**
	 * Deletes the creator's uploaded sounds and images; a file MerchAlert's
	 * import took over stays and only loses the plugin's meta.
	 *
	 * @param int $creator_id Creator.
	 * @return int Files deleted.
	 */
	private function delete_files( int $creator_id ): int {
		$ids     = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only when a creator is deleted.
					'relation' => 'OR',
					array(
						'key'   => AlertSounds::OWNER_META,
						'value' => (string) $creator_id,
					),
					array(
						'key'   => AlertImages::OWNER_META,
						'value' => (string) $creator_id,
					),
				),
			)
		);
		$deleted = 0;

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( '' !== (string) get_post_meta( $id, Uninstaller::ALERT_IMPORTED_META, true ) || '' !== (string) get_post_meta( $id, '_ccmerch_audio_creator_id', true ) ) {
				delete_post_meta( $id, AlertSounds::OWNER_META );
				delete_post_meta( $id, AlertImages::OWNER_META );
				continue;
			}

			$deleted += wp_delete_attachment( $id, true ) instanceof WP_Post ? 1 : 0;
		}

		return $deleted;
	}
}
