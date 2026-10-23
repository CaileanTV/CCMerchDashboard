<?php
/**
 * Read-only access to MerchAlert's data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Import;

use MerchBoard\Core\Database\DatabaseException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what the old plugin MerchAlert ("CCMerch Alert", Beta 2.0.2) left
 * in the database, for the import (step 25.10.1, ADR-065 pkt. 10). Only
 * reads; MerchAlert's tables, options and meta are never changed. Reading
 * the `ccmerch_*` names is the exception ADR-065 pkt. 5 allows.
 *
 * - Tables `{prefix}ccmerch_creator_profiles`, `…_creator_category_assignments`,
 *   `…_creator_overlay_settings`, `…_alert_events`, `…_creator_chatbot`,
 *   `…_chatbot_messages` and `…_google_fonts`.
 * - Options `ccmerch_alert_settings`, `ccmerch_overlay_settings` (the global
 *   overlay) and `ccmerch_layout_settings` (its older layout).
 * - Attachment meta `_ccmerch_audio_creator_id` (a sound's owner).
 *
 * Customers' first names (`customer_first_name`) are never read.
 */
class MerchAlertSource {

	/**
	 * MerchAlert's options.
	 */
	public const SETTINGS_OPTION = 'ccmerch_alert_settings';
	public const OVERLAY_OPTION  = 'ccmerch_overlay_settings';
	public const LAYOUT_OPTION   = 'ccmerch_layout_settings';

	/**
	 * A sound's owner (MerchAlert creator id).
	 */
	public const SOUND_OWNER_META = '_ccmerch_audio_creator_id';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Whether there is MerchAlert data to import.
	 *
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function available(): bool {
		return $this->has_table( 'creator_overlay_settings' ) || is_array( get_option( self::OVERLAY_OPTION, null ) );
	}

	/**
	 * MerchAlert's creators with their category (the newest assignment, as
	 * MerchAlert's router picks it) and overlay, by id.
	 *
	 * @return list<array{id: int, name: string, status: string, term_id: int, overlay_id: string, settings: array<mixed>}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function creators(): array {
		if ( ! $this->has_table( 'creator_profiles' ) ) {
			return array();
		}

		$profiles = $this->rows( 'SELECT id, creator_name, status FROM %i ORDER BY id', $this->table( 'creator_profiles' ) );
		$terms    = array();
		$overlays = array();

		if ( $this->has_table( 'creator_category_assignments' ) ) {
			foreach ( $this->rows( 'SELECT creator_id, wc_category_term_id FROM %i ORDER BY id', $this->table( 'creator_category_assignments' ) ) as $row ) {
				$terms[ (int) $row['creator_id'] ] = (int) $row['wc_category_term_id'];
			}
		}

		if ( $this->has_table( 'creator_overlay_settings' ) ) {
			foreach ( $this->rows( 'SELECT creator_id, overlay_id, settings FROM %i', $this->table( 'creator_overlay_settings' ) ) as $row ) {
				$overlays[ (int) $row['creator_id'] ] = array( (string) $row['overlay_id'], self::json( $row['settings'] ) );
			}
		}

		$creators = array();

		foreach ( $profiles as $row ) {
			$id         = (int) $row['id'];
			$creators[] = array(
				'id'         => $id,
				'name'       => (string) $row['creator_name'],
				'status'     => (string) $row['status'],
				'term_id'    => $terms[ $id ] ?? 0,
				'overlay_id' => $overlays[ $id ][0] ?? '',
				'settings'   => $overlays[ $id ][1] ?? array(),
			);
		}

		return $creators;
	}

	/**
	 * A creator's alerts, oldest first (never the customer's first name).
	 *
	 * @param int $creator_id MerchAlert creator.
	 * @return list<array{order_id: int, order_number: string, order_item_id: int, quantity: int, product_id: int, variation_id: int, product_name: string, image_id: int, category_ids: list<int>, created_at: string}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function events( int $creator_id ): array {
		if ( ! $this->has_table( 'alert_events' ) ) {
			return array();
		}

		$events = array();

		foreach ( $this->rows( 'SELECT order_id, order_number, order_item_id, quantity, product_id, variation_id, product_name, featured_image_attachment_id, category_term_ids, created_at FROM %i WHERE creator_id = %d ORDER BY id', $this->table( 'alert_events' ), $creator_id ) as $row ) {
			$categories = self::json( $row['category_term_ids'] );
			$events[]   = array(
				'order_id'      => (int) $row['order_id'],
				'order_number'  => (string) $row['order_number'],
				'order_item_id' => (int) $row['order_item_id'],
				'quantity'      => (int) $row['quantity'],
				'product_id'    => (int) $row['product_id'],
				'variation_id'  => (int) $row['variation_id'],
				'product_name'  => (string) $row['product_name'],
				'image_id'      => (int) $row['featured_image_attachment_id'],
				'category_ids'  => array_values( array_filter( array_map( 'intval', $categories ), static fn( int $id ): bool => $id > 0 ) ),
				'created_at'    => (string) $row['created_at'],
			);
		}

		return $events;
	}

	/**
	 * A creator's Twitch chatbot settings, or null for none.
	 *
	 * @param int $creator_id MerchAlert creator.
	 * @return array{enabled: bool, template: string}|null
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function chatbot( int $creator_id ): ?array {
		if ( ! $this->has_table( 'creator_chatbot' ) ) {
			return null;
		}

		$row = $this->rows( 'SELECT enabled, template FROM %i WHERE creator_id = %d LIMIT 1', $this->table( 'creator_chatbot' ), $creator_id )[0] ?? null;

		return null === $row ? null : array(
			'enabled'  => 1 === (int) $row['enabled'],
			'template' => (string) $row['template'],
		);
	}

	/**
	 * The order lines the chatbot announced for a creator.
	 *
	 * @param int $creator_id MerchAlert creator.
	 * @return list<array{order_id: int, order_item_id: int, created_at: string}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function messages( int $creator_id ): array {
		if ( ! $this->has_table( 'chatbot_messages' ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'order_id'      => (int) $row['order_id'],
				'order_item_id' => (int) $row['order_item_id'],
				'created_at'    => (string) $row['created_at'],
			),
			$this->rows( 'SELECT order_id, order_item_id, created_at FROM %i WHERE creator_id = %d ORDER BY id', $this->table( 'chatbot_messages' ), $creator_id )
		);
	}

	/**
	 * The global overlay's settings (with `overlay_id`), or null for none.
	 *
	 * @return array<mixed>|null
	 */
	public function global_overlay(): ?array {
		$stored = get_option( self::OVERLAY_OPTION, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * The older layout setting the global overlay falls back to.
	 *
	 * @return array<mixed>|null
	 */
	public function legacy_layout(): ?array {
		$stored = get_option( self::LAYOUT_OPTION, null );
		$stored = is_string( $stored ) ? self::json( $stored ) : $stored;

		return is_array( $stored ) && array() !== $stored ? $stored : null;
	}

	/**
	 * MerchAlert's settings.
	 *
	 * @return array<mixed>
	 */
	public function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The approved Google fonts.
	 *
	 * @return list<array{family: string, category: string, variants: list<string>}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function approved_fonts(): array {
		if ( ! $this->has_table( 'google_fonts' ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'family'   => (string) $row['family'],
				'category' => (string) $row['category'],
				'variants' => array_values( array_filter( self::json( $row['variants'] ), 'is_string' ) ),
			),
			$this->rows( 'SELECT family, category, variants FROM %i WHERE approved = 1 ORDER BY sort_order, family', $this->table( 'google_fonts' ) )
		);
	}

	/**
	 * A sound attachment's MerchAlert owner: a creator id, 0 for an
	 * administrator's upload, or null when it is not a sound.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public function sound_owner( int $attachment_id ): ?int {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) || ! str_starts_with( (string) get_post_mime_type( $attachment_id ), 'audio/' ) ) {
			return null;
		}

		return absint( get_post_meta( $attachment_id, self::SOUND_OWNER_META, true ) );
	}

	/**
	 * A MerchAlert table with the prefix.
	 *
	 * @param string $name Table without `{prefix}ccmerch_`.
	 */
	private function table( string $name ): string {
		return $this->wpdb->prefix . 'ccmerch_' . $name;
	}

	/**
	 * Whether a MerchAlert table exists.
	 *
	 * @param string $name Table without `{prefix}ccmerch_`.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function has_table( string $name ): bool {
		$wpdb  = $this->wpdb;
		$table = $this->table( $name );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks for MerchAlert's table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read MerchAlert\'s tables.' );
		}

		return $found === $table;
	}

	/**
	 * Rows of a prepared query.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string     $query   Query with placeholders.
	 * @param int|string ...$args Values.
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function rows( string $query, int|string ...$args ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared; MerchAlert's tables, read once by the import.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read MerchAlert\'s data.' );
		}

		return $rows;
	}

	/**
	 * A JSON object or list as an array ([] when it is none).
	 *
	 * @param mixed $value Stored value.
	 * @return array<mixed>
	 */
	private static function json( mixed $value ): array {
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}
}
