<?php
/**
 * Storage of designer products and their versions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_products` and `merchboard_designer_versions` (ADR-080
 * pkt. 3). Step 30.8 creates products with a draft version and saves the
 * draft; step 30.9 attaches the submission's files to the draft, submits it
 * (draft → pending, `pending_key` = the product, so a product has at most
 * one pending version) and withdraws it (pending → withdrawn, continued by a
 * new draft). Step 30.10: the administrator approves (pending → approved,
 * then applied to WooCommerce and live; the version that was live is
 * superseded) or rejects with a note (continued by a new draft); a creator
 * starts a change of a live product as a new draft. Writes only through
 * DesignerDrafts, DesignerSubmissions, DesignerReview and DesignerApply.
 */
class DesignerProductRepository {

	public const DRAFT = 'draft';

	public const PENDING = 'pending';

	public const WITHDRAWN = 'withdrawn';

	public const APPROVED = 'approved';

	public const REJECTED = 'rejected';

	public const SUPERSEDED = 'superseded';

	public const LIVE = 'live';

	/**
	 * `apply_state` of an approved version: waiting for, being applied, failed, done.
	 */
	public const APPLY_QUEUED = 'queued';

	public const APPLY_RUNNING = 'running';

	public const APPLY_FAILED = 'failed';

	public const APPLY_DONE = 'done';

	private const PRODUCT_COLUMNS = 'id, creator_id, base_id, wc_product_id, live_version_id, status, created_at, updated_at';

	private const VERSION_COLUMNS = 'id, design_product_id, creator_id, number, status, base_version, title, description, colors, sizes, sides, design, profit_kind, profit_value, quoted_price, files, apply_state, apply_step, apply_error, note, updated_at, submitted_at, decided_at, decided_by';

	/**
	 * VERSION_COLUMNS of the alias v (joined with the product).
	 */
	private const VERSION_COLUMNS_V = 'v.id, v.design_product_id, v.creator_id, v.number, v.status, v.base_version, v.title, v.description, v.colors, v.sizes, v.sides, v.design, v.profit_kind, v.profit_value, v.quoted_price, v.files, v.apply_state, v.apply_step, v.apply_error, v.note, v.updated_at, v.submitted_at, v.decided_at, v.decided_by';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Creates a designer product with its first draft.
	 *
	 * @param int                  $creator_id Owner.
	 * @param int                  $base_id    Base product.
	 * @param array<string, mixed> $draft      Draft fields (DesignerDrafts::fields()).
	 * @return array{0: int, 1: int} Product and version id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, int $base_id, array $draft ): array {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table( Tables::DESIGNER_PRODUCTS ),
			array(
				'creator_id'     => $creator_id,
				'base_id'        => $base_id,
				'creation_token' => bin2hex( random_bytes( 16 ) ),
				'status'         => self::DRAFT,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( 1 !== $done ) {
			throw new DatabaseException( 'Could not store the designer product.' );
		}

		$product = (int) $this->wpdb->insert_id;
		$done    = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array_merge(
				self::version_columns( $draft ),
				array(
					'design_product_id' => $product,
					'creator_id'        => $creator_id,
					'number'            => 1,
					'status'            => self::DRAFT,
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			)
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the design.' );
		}

		return array( $product, (int) $this->wpdb->insert_id );
	}

	/**
	 * A creator's designer product, or null (also another creator's).
	 *
	 * @param int $creator_id Owner.
	 * @param int $product_id Designer product.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $creator_id, int $product_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::PRODUCT_COLUMNS . ' FROM %i WHERE id = %d AND creator_id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $product_id, $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer product.' );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A creator's designer products with their newest version, newest first.
	 *
	 * @param int $creator_id Owner.
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.id, p.base_id, p.status, p.wc_product_id, v.id AS version_id, v.number, v.status AS version_status, v.title, v.updated_at
				FROM %i p
				JOIN %i v ON v.design_product_id = p.id AND v.number = ( SELECT MAX( number ) FROM %i WHERE design_product_id = p.id )
				WHERE p.creator_id = %d
				ORDER BY v.updated_at DESC, p.id DESC',
				$this->table( Tables::DESIGNER_PRODUCTS ),
				$this->table( Tables::DESIGNER_VERSIONS ),
				$this->table( Tables::DESIGNER_VERSIONS ),
				$creator_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables; the creator's list.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the designer products.' );
		}

		return $rows;
	}

	/**
	 * The draft version of a designer product, or null.
	 *
	 * @param int  $product_id Designer product.
	 * @param bool $lock       Read FOR UPDATE (inside a transaction).
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function draft( int $product_id, bool $lock = false ): ?array {
		return $this->version_in( $product_id, self::DRAFT, $lock );
	}

	/**
	 * The pending version of a designer product, or null.
	 *
	 * @param int  $product_id Designer product.
	 * @param bool $lock       Read FOR UPDATE (inside a transaction).
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function pending( int $product_id, bool $lock = false ): ?array {
		return $this->version_in( $product_id, self::PENDING, $lock );
	}

	/**
	 * Stores the submission's files of a draft (SubmissionFiles JSON).
	 *
	 * @param int    $version_id Draft version.
	 * @param string $files      JSON.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_files( int $version_id, string $files ): void {
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array( 'files' => $files ),
			array(
				'id'     => $version_id,
				'status' => self::DRAFT,
			)
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the files of the design.' );
		}
	}

	/**
	 * Submits a draft: it waits for approval with the price it gives now.
	 *
	 * @param int    $version_id Draft version.
	 * @param int    $product_id Its designer product.
	 * @param string $price      Customer's price (decimal).
	 * @throws DatabaseException When it cannot be stored (also when the product already has a pending version).
	 */
	public function submit( int $version_id, int $product_id, string $price ): void {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array(
				'status'       => self::PENDING,
				'pending_key'  => (string) $product_id,
				'quoted_price' => $price,
				'submitted_at' => $now,
				'updated_at'   => $now,
			),
			array(
				'id'     => $version_id,
				'status' => self::DRAFT,
			)
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not submit the design.' );
		}
	}

	/**
	 * Withdraws a pending version and continues it as a new draft with the
	 * same content and files.
	 *
	 * @param array<string, mixed> $version The pending version (VERSION_COLUMNS).
	 * @return int The new draft's id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function withdraw( array $version ): int {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array(
				'status'      => self::WITHDRAWN,
				'pending_key' => null,
				'files'       => null,
				'decided_at'  => $now,
				'updated_at'  => $now,
			),
			array(
				'id'     => (int) $version['id'],
				'status' => self::PENDING,
			)
		);
		if ( 1 !== $done ) {
			throw new DatabaseException( 'Could not withdraw the design.' );
		}

		return $this->copy_as_draft( $version, true );
	}

	/**
	 * A new draft with a version's content (and its files, when they move
	 * with it: a live version keeps its own, which orders point at).
	 *
	 * @param array<string, mixed> $version    Version (VERSION_COLUMNS).
	 * @param bool                 $with_files Whether the draft takes the version's files.
	 * @return int The new draft's id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function copy_as_draft( array $version, bool $with_files ): int {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX( number ) + 1 FROM %i WHERE design_product_id = %d', $this->table( Tables::DESIGNER_VERSIONS ), (int) $version['design_product_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( ! is_numeric( $next ) ) {
			throw new DatabaseException( 'Could not continue the design as a draft.' );
		}

		$copied = array();

		foreach ( array( 'design_product_id', 'creator_id', 'base_version', 'title', 'description', 'colors', 'sizes', 'sides', 'design', 'profit_kind', 'profit_value', 'quoted_price', 'files' ) as $column ) {
			$copied[ $column ] = $version[ $column ] ?? null;
		}

		if ( ! $with_files ) {
			$copied['files'] = null;
		}

		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array_merge(
				$copied,
				array(
					'number'     => (int) $next,
					'status'     => self::DRAFT,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);

		if ( 1 !== $done ) {
			throw new DatabaseException( 'Could not continue the design as a draft.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * A version with its product's facts (`wc_product_id`, `live_version_id`,
	 * `base_id`), or null.
	 *
	 * @param int  $version_id Version.
	 * @param bool $lock       Read FOR UPDATE (inside a transaction).
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function version( int $version_id, bool $lock = false ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::VERSION_COLUMNS_V . ', p.base_id, p.wc_product_id, p.live_version_id FROM %i v JOIN %i p ON p.id = v.design_product_id WHERE v.id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $this->table( Tables::DESIGNER_VERSIONS ), $this->table( Tables::DESIGNER_PRODUCTS ), $version_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own tables.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the design.' );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The live version of a designer product, or null.
	 *
	 * @param int $product_id Designer product.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function live( int $product_id ): ?array {
		$wpdb = $this->wpdb;
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT live_version_id FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer product.' );
		}

		return is_numeric( $id ) ? $this->version( (int) $id ) : null;
	}

	/**
	 * The live version of the designer product behind a WooCommerce product, or null.
	 *
	 * @param int $wc_product_id WooCommerce product.
	 * @return int|null Version id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function live_for_shop_product( int $wc_product_id ): ?int {
		$wpdb = $this->wpdb;
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT live_version_id FROM %i WHERE wc_product_id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $wc_product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; once per order line.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer product.' );
		}

		return is_numeric( $id ) ? (int) $id : null;
	}

	/**
	 * The newest rejected version after the live one, or null: its note is
	 * shown to the creator.
	 *
	 * @param int $product_id Designer product.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function last_rejection( int $product_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT number, note, decided_at, status FROM %i WHERE design_product_id = %d AND status IN ( %s, %s ) ORDER BY number DESC LIMIT 1', $this->table( Tables::DESIGNER_VERSIONS ), $product_id, self::APPROVED, self::REJECTED ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the design.' );
		}

		return is_array( $row ) && self::REJECTED === $row['status'] ? $row : null;
	}

	/**
	 * Decides a pending version: approved (to be applied) or rejected with a
	 * note. Its files stay with an approved version and move with a
	 * rejected one to the draft that continues it.
	 *
	 * @param int         $version_id Pending version.
	 * @param string      $status     APPROVED or REJECTED.
	 * @param string|null $note       The note to the creator (a rejection).
	 * @param int         $by         Administrator.
	 * @param string|null $price      The price frozen at approval (decimal).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function decide( int $version_id, string $status, ?string $note, int $by, ?string $price ): void {
		$now     = gmdate( 'Y-m-d H:i:s' );
		$columns = array(
			'status'      => $status,
			'pending_key' => null,
			'note'        => $note,
			'decided_at'  => $now,
			'decided_by'  => $by,
			'updated_at'  => $now,
		);

		if ( self::APPROVED === $status ) {
			$columns['apply_state']  = self::APPLY_QUEUED;
			$columns['quoted_price'] = $price;
		} else {
			$columns['files'] = null;
		}

		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			$columns,
			array(
				'id'     => $version_id,
				'status' => self::PENDING,
			)
		);

		if ( 1 !== $done ) {
			throw new DatabaseException( 'Could not decide the design.' );
		}
	}

	/**
	 * The apply state of an approved version.
	 *
	 * @param int         $version_id Version.
	 * @param string      $state      APPLY_ constant.
	 * @param string|null $step       The step being run (or failed).
	 * @param string|null $error      Short error code.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_apply( int $version_id, string $state, ?string $step, ?string $error ): void {
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array(
				'apply_state' => $state,
				'apply_step'  => $step,
				'apply_error' => null === $error ? null : substr( $error, 0, 191 ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $version_id,
				'status' => self::APPROVED,
			)
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the apply state of the design.' );
		}
	}

	/**
	 * Makes an applied version the live one: the version that was live is
	 * superseded.
	 *
	 * @param int $product_id Designer product.
	 * @param int $version_id Applied version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function go_live( int $product_id, int $version_id ): void {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );

		$sql        = $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE design_product_id = %d AND status = %s AND id <> %d', $this->table( Tables::DESIGNER_VERSIONS ), self::SUPERSEDED, $now, $product_id, self::APPROVED, $version_id );
		$superseded = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the designer's own table.
		$applied    = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array(
				'apply_state' => self::APPLY_DONE,
				'apply_step'  => null,
				'apply_error' => null,
				'updated_at'  => $now,
			),
			array(
				'id'     => $version_id,
				'status' => self::APPROVED,
			)
		);
		$live       = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_PRODUCTS ),
			array(
				'live_version_id' => $version_id,
				'status'          => self::LIVE,
				'updated_at'      => $now,
			),
			array( 'id' => $product_id )
		);

		if ( false === $superseded || 1 !== $applied || 1 !== $live ) {
			throw new DatabaseException( 'Could not make the design live.' );
		}
	}

	/**
	 * Approved versions not applied yet (waiting, running or failed), oldest first.
	 *
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function applying(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT v.id, v.design_product_id, v.creator_id, v.number, v.title, v.quoted_price, v.apply_state, v.apply_step, v.apply_error, v.decided_at, p.base_id, p.live_version_id FROM %i v JOIN %i p ON p.id = v.design_product_id WHERE v.status = %s AND v.apply_state <> %s ORDER BY v.decided_at ASC, v.id ASC', $this->table( Tables::DESIGNER_VERSIONS ), $this->table( Tables::DESIGNER_PRODUCTS ), self::APPROVED, self::APPLY_DONE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the approved designs.' );
		}

		return $rows;
	}

	/**
	 * The versions waiting for approval, oldest first, with their product.
	 *
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function pending_queue(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.id, v.design_product_id, v.creator_id, v.number, v.title, v.description, v.colors, v.sizes, v.files, v.profit_kind, v.profit_value, v.quoted_price, v.submitted_at, p.base_id, p.live_version_id
				FROM %i v
				JOIN %i p ON p.id = v.design_product_id
				WHERE v.status = %s
				ORDER BY v.submitted_at ASC, v.id ASC',
				$this->table( Tables::DESIGNER_VERSIONS ),
				$this->table( Tables::DESIGNER_PRODUCTS ),
				self::PENDING
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables; the approval queue.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the designs waiting for approval.' );
		}

		return $rows;
	}

	/**
	 * The stored submission files of all versions of a designer product.
	 *
	 * @param int $product_id Designer product.
	 * @return list<string> File names.
	 * @throws DatabaseException When they cannot be read.
	 */
	public function files_of( int $product_id ): array {
		$wpdb  = $this->wpdb;
		$files = $wpdb->get_col( $wpdb->prepare( 'SELECT files FROM %i WHERE design_product_id = %d AND files IS NOT NULL', $this->table( Tables::DESIGNER_VERSIONS ), $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the files of the design.' );
		}

		$names = array();

		foreach ( $files as $json ) {
			$names = array_merge( $names, SubmissionFiles::names( is_string( $json ) ? $json : null ) );
		}

		return $names;
	}

	/**
	 * Saves a draft version.
	 *
	 * @param int                  $version_id Version.
	 * @param array<string, mixed> $draft      Draft fields.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_draft( int $version_id, array $draft ): void {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
			$this->table( Tables::DESIGNER_VERSIONS ),
			array_merge( self::version_columns( $draft ), array( 'updated_at' => $now ) ),
			array(
				'id'     => $version_id,
				'status' => self::DRAFT,
			)
		);

		if ( 1 !== $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not save the design.' );
		}
	}

	/**
	 * Deletes a designer product that is neither waiting nor was ever
	 * approved: only drafts and withdrawn versions (its versions too).
	 *
	 * @param int $product_id Designer product.
	 * @return bool Whether it was deleted (false: it waits, was approved or is in the shop).
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete_draft_product( int $product_id ): bool {
		$wpdb   = $this->wpdb;
		$others = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE design_product_id = %d AND status NOT IN ( %s, %s )', $this->table( Tables::DESIGNER_VERSIONS ), $product_id, self::DRAFT, self::WITHDRAWN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
		$shop   = $wpdb->get_var( $wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_PRODUCTS ), $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the designer product.' );
		}

		if ( 0 !== (int) $others || null !== $shop ) {
			return false;
		}

		$versions = $wpdb->delete( $this->table( Tables::DESIGNER_VERSIONS ), array( 'design_product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.
		$product  = $wpdb->delete( $this->table( Tables::DESIGNER_PRODUCTS ), array( 'id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table.

		if ( false === $versions || 1 !== $product ) {
			throw new DatabaseException( 'Could not delete the designer product.' );
		}

		return true;
	}

	/**
	 * The stored columns of draft fields.
	 *
	 * @param array<string, mixed> $draft Draft fields.
	 * @return array<string, mixed>
	 * @throws DatabaseException When the JSON cannot be encoded.
	 */
	private static function version_columns( array $draft ): array {
		$json = array();

		foreach ( array( 'colors', 'sizes', 'sides', 'design' ) as $key ) {
			$encoded = wp_json_encode( $draft[ $key ] ?? array() );

			if ( false === $encoded ) {
				throw new DatabaseException( 'Could not encode the design.' );
			}

			$json[ $key ] = $encoded;
		}

		return array_merge(
			$json,
			array(
				'base_version' => (int) ( $draft['base_version'] ?? 1 ),
				'title'        => (string) ( $draft['title'] ?? '' ),
				'description'  => (string) ( $draft['description'] ?? '' ),
				'profit_kind'  => (string) ( $draft['profit_kind'] ?? 'fixed' ),
				'profit_value' => (string) ( $draft['profit_value'] ?? '0' ),
				'quoted_price' => $draft['quoted_price'] ?? null,
			)
		);
	}

	/**
	 * The newest version of a designer product in a status, or null.
	 *
	 * @param int    $product_id Designer product.
	 * @param string $status     Status.
	 * @param bool   $lock       Read FOR UPDATE (inside a transaction).
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When it cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	private function version_in( int $product_id, string $status, bool $lock ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::VERSION_COLUMNS . ' FROM %i WHERE design_product_id = %d AND status = %s ORDER BY number DESC LIMIT 1' . ( $lock ? ' FOR UPDATE' : '' ), $this->table( Tables::DESIGNER_VERSIONS ), $product_id, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns; the designer's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the design.' );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Table constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
