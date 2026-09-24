<?php
/**
 * Detects whether the plugin's tables support transactions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Transactions only protect tables whose storage engine supports them.
 *
 * The plugin does not force an engine in CREATE TABLE (neither does WordPress
 * core); new tables get the server default, which is InnoDB on every supported
 * MySQL/MariaDB version. A server configured for MyISAM (or a table converted
 * later) would silently ignore ROLLBACK, so Transaction asks this class first
 * and refuses to write when a table cannot roll back (fail closed, ADR-024).
 *
 * The engine comes from information_schema, where MySQL and MariaDB report
 * TRANSACTIONS = 'YES' per engine; this does not hardcode a list of engines.
 * SQLite (WordPress Playground) has no engines and transactions always cover
 * the whole database.
 */
final class TransactionSupport {

	/**
	 * Cached positive result; negative results are re-checked, so a table that
	 * is converted or created later is picked up in the same request.
	 *
	 * @var bool
	 */
	private bool $available = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Whether every plugin table can take part in a transaction.
	 */
	public function available(): bool {
		return array() === $this->unsupported_tables();
	}

	/**
	 * Plugin tables that cannot roll back, as full table name => engine
	 * ('missing' when the table does not exist). Empty when all are fine.
	 *
	 * @return array<string, string>
	 */
	public function unsupported_tables(): array {
		if ( $this->available || self::is_sqlite() ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.TABLE_NAME AS name, t.ENGINE AS engine, e.TRANSACTIONS AS transactions FROM information_schema.TABLES t LEFT JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'merchboard_' ) . '%'
			),
			ARRAY_A
		);
		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Table names can come back lower-cased (lower_case_table_names on Windows/macOS).
			$found[ strtolower( (string) $row['name'] ) ] = $row;
		}

		$unsupported = array();

		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $wpdb, $table );
			$row  = $found[ strtolower( $name ) ] ?? null;

			if ( null === $row ) {
				$unsupported[ $name ] = 'missing';
			} elseif ( 'YES' !== strtoupper( (string) $row['transactions'] ) ) {
				$unsupported[ $name ] = is_string( $row['engine'] ) && '' !== $row['engine'] ? $row['engine'] : 'unknown';
			}
		}

		$this->available = array() === $unsupported;

		return $unsupported;
	}

	/**
	 * Whether WordPress runs on the SQLite integration (e.g. Playground), which
	 * defines DB_ENGINE as 'sqlite'.
	 */
	private static function is_sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
	}
}
