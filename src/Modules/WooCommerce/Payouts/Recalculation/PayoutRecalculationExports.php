<?php
/**
 * Exports of recalculations with manifest and checksums (ADR-062 pkt. 9, ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use Closure;
use DateTimeImmutable;
use JsonException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * An export of one recalculation, optionally filtered by group or creator,
 * as five files:
 *
 * - `items.csv`: the items with amounts, status and adjustment;
 * - `rows.csv`: the settled rows with their stored inputs;
 * - `timeline.csv`: the recalculation's audited lifecycle;
 * - `manifest.json`: what was exported, when, by whom, with which filters,
 *   versions and known limitations, the SHA-256 of the three data files,
 *   and their comparison with the previous export of the same filters;
 * - `SHA256SUMS`: the SHA-256 of the data files and of the manifest, in the
 *   format of `sha256sum -c`. The manifest does not contain its own checksum,
 *   so nothing is circular.
 *
 * An export is created in one transaction with its audit record and stored
 * unchanged; a new export never replaces an earlier one. Before a file is
 * delivered, the whole export is verified against its manifest and checksums;
 * a changed or missing file is never delivered. Every download is audited.
 * Requires `merchboard_manage_payouts`.
 */
final class PayoutRecalculationExports {

	public const FORMAT = 'merchboard-recalculation-export';

	public const FORMAT_VERSION = 1;

	/**
	 * Data files, in this order.
	 */
	public const DATA_FILES = array( 'items.csv', 'rows.csv', 'timeline.csv' );

	public const MANIFEST = 'manifest.json';

	public const SUMS = 'SHA256SUMS';

	/**
	 * How the files are checked with common tools.
	 */
	public const VERIFICATION = 'Put all five files in one folder. Linux and Git Bash: sha256sum -c SHA256SUMS. macOS: shasum -a 256 -c SHA256SUMS. Windows PowerShell: Get-FileHash -Algorithm SHA256 <file> and compare with the line of the file in SHA256SUMS. The checksums of the data files are also listed in manifest.json.';

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRecalculationRepository       $recalculations Recalculations and their items.
	 * @param PayoutRecalculationExportRepository $exports        Exports.
	 * @param CreatorRepository                   $creators       Creator names.
	 * @param AuditTrail                          $audit          Audit.
	 * @param Transaction                         $transaction    Transactions.
	 * @param Closure                             $clock          Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct(
		private PayoutRecalculationRepository $recalculations,
		private PayoutRecalculationExportRepository $exports,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock
	) {
	}

	/**
	 * The reference of an export.
	 *
	 * @param int $recalculation_id Recalculation.
	 * @param int $export_id        Export.
	 */
	public static function reference( int $recalculation_id, int $export_id ): string {
		return Recalculation::reference_of( $recalculation_id ) . '-E' . str_pad( (string) $export_id, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Creates an export of a recalculation.
	 *
	 * @param int         $recalculation_id Recalculation.
	 * @param string|null $category         Only this group (active, former, manual); null = all.
	 * @param int|null    $creator_id       Only this creator; null = all.
	 * @return int Export id.
	 * @throws PayoutException When not authorized, the recalculation does not exist or a filter is invalid; a failed read or write is a DatabaseException, and nothing is stored.
	 */
	public function create( int $recalculation_id, ?string $category = null, ?int $creator_id = null ): int {
		self::authorize();

		if ( ( null !== $category && ! in_array( $category, array( RecalculationItem::ACTIVE, RecalculationItem::FORMER, RecalculationItem::MANUAL ), true ) ) || ( null !== $creator_id && $creator_id < 1 ) ) {
			throw new PayoutException( PayoutException::INVALID_FILTER );
		}

		$run = $this->recalculations->find( $recalculation_id );

		if ( null === $run ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		$filters = self::filters( $category, $creator_id );

		return $this->transaction->run(
			function () use ( $run, $category, $creator_id, $filters ): int {
				$now      = ( $this->clock )();
				$user     = get_current_user_id();
				$id       = $this->exports->reserve( $run->id, $filters, $user > 0 ? $user : null, $now );
				$built    = $this->build( $run, $id, $category, $creator_id, $filters, $now, $user > 0 ? $user : null );
				$manifest = $built['files'][ self::MANIFEST ];
				$sums     = $built['files'][ self::SUMS ];

				$this->exports->fill( $id, $built['files'], hash( 'sha256', $manifest ), hash( 'sha256', $sums ) );
				$this->audit->record(
					AuditAction::PayoutRecalculationExported,
					null === $creator_id ? null : $creator_id,
					array(
						'reference' => $run->reference(),
						'export'    => self::reference( $run->id, $id ),
						'filters'   => $filters,
						'complete'  => null === $category && null === $creator_id,
						'items'     => $built['items'],
						'rows'      => $built['rows'],
					)
				);

				return $id;
			}
		);
	}

	/**
	 * The integrity of every file of an export: `ok`, `changed` or `missing`.
	 *
	 * @param int $export_id Export.
	 * @return array<string, string> File name => status, in file order.
	 * @throws PayoutException When the export does not exist; a failed read is a DatabaseException.
	 */
	public function verify( int $export_id ): array {
		$export = $this->exports->find( $export_id );

		if ( null === $export ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		return self::check( $export['files'] ?? array(), $export['manifest_sha256'], $export['sums_sha256'] );
	}

	/**
	 * Checks files against the stored checksums of the manifest and of
	 * SHA256SUMS, the manifest's checksums of the data files and SHA256SUMS.
	 *
	 * @param array<string, string> $files           File name => content.
	 * @param string                $manifest_sha256 Stored SHA-256 of the manifest.
	 * @param string                $sums_sha256     Stored SHA-256 of SHA256SUMS.
	 * @return array<string, string> File name => `ok`, `changed` or `missing`.
	 */
	public static function check( array $files, string $manifest_sha256, string $sums_sha256 ): array {
		$sums     = isset( $files[ self::SUMS ] ) ? self::parse_sums( $files[ self::SUMS ] ) : array();
		$manifest = array();

		try {
			$decoded  = isset( $files[ self::MANIFEST ] ) ? json_decode( $files[ self::MANIFEST ], true, 16, JSON_THROW_ON_ERROR ) : null;
			$manifest = is_array( $decoded ) && is_array( $decoded['files'] ?? null ) ? $decoded['files'] : array();
		} catch ( JsonException ) {
			$manifest = array();
		}

		$listed = array();

		foreach ( $manifest as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['name'] ?? null ) && is_string( $entry['sha256'] ?? null ) ) {
				$listed[ $entry['name'] ] = $entry['sha256'];
			}
		}

		$status = array();

		foreach ( self::DATA_FILES as $name ) {
			if ( ! isset( $files[ $name ] ) ) {
				$status[ $name ] = 'missing';
				continue;
			}

			$hash            = hash( 'sha256', $files[ $name ] );
			$status[ $name ] = ( $listed[ $name ] ?? '' ) === $hash && ( $sums[ $name ] ?? '' ) === $hash ? 'ok' : 'changed';
		}

		$status[ self::MANIFEST ] = ! isset( $files[ self::MANIFEST ] ) ? 'missing' : ( hash_equals( $manifest_sha256, hash( 'sha256', $files[ self::MANIFEST ] ) ) && ( $sums[ self::MANIFEST ] ?? '' ) === $manifest_sha256 ? 'ok' : 'changed' );
		$status[ self::SUMS ]     = ! isset( $files[ self::SUMS ] ) ? 'missing' : ( hash_equals( $sums_sha256, hash( 'sha256', $files[ self::SUMS ] ) ) ? 'ok' : 'changed' );

		return $status;
	}

	/**
	 * Lines of SHA256SUMS as file name => checksum.
	 *
	 * @param string $sums Content of SHA256SUMS.
	 * @return array<string, string>
	 */
	public static function parse_sums( string $sums ): array {
		$result = array();

		foreach ( explode( "\n", $sums ) as $line ) {
			if ( 1 === preg_match( '/^([0-9a-f]{64})  (\S+)$/', $line, $match ) ) {
				$result[ $match[2] ] = $match[1];
			}
		}

		return $result;
	}

	/**
	 * One file of an export, after the integrity check, with audit.
	 *
	 * @param int    $export_id Export.
	 * @param string $name      File name.
	 * @return array{name: string, type: string, content: string, reference: string}
	 * @throws PayoutException When not authorized, not found, or the export does not pass its integrity check; a failed read or write is a DatabaseException.
	 */
	public function download( int $export_id, string $name ): array {
		self::authorize();

		if ( ! in_array( $name, array_merge( self::DATA_FILES, array( self::MANIFEST, self::SUMS ) ), true ) ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		$export = $this->exports->find( $export_id );

		if ( null === $export ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		$files = $export['files'] ?? array();

		// The whole export must pass, not only the requested file.
		if ( array( 'ok' ) !== array_values( array_unique( self::check( $files, $export['manifest_sha256'], $export['sums_sha256'] ) ) ) ) {
			throw new PayoutException( PayoutException::EXPORT_INTEGRITY );
		}

		$reference = self::reference( $export['recalculation_id'], $export['id'] );
		$this->audit->record(
			AuditAction::PayoutRecalculationExportDownloaded,
			null,
			array(
				'reference' => Recalculation::reference_of( $export['recalculation_id'] ),
				'export'    => $reference,
				'file'      => $name,
			)
		);

		return array(
			'name'      => $reference . '-' . $name,
			'type'      => match ( true ) {
				str_ends_with( $name, '.csv' )  => 'text/csv; charset=utf-8',
				str_ends_with( $name, '.json' ) => 'application/json; charset=utf-8',
				default                         => 'text/plain; charset=utf-8',
			},
			'content'   => $files[ $name ],
			'reference' => $reference,
		);
	}

	/**
	 * The files of an export.
	 *
	 * @param Recalculation     $run        Recalculation.
	 * @param int               $id         Export id.
	 * @param string|null       $category   Group filter.
	 * @param int|null          $creator_id Creator filter.
	 * @param string            $filters    Filters as JSON.
	 * @param DateTimeImmutable $now        Time of the export.
	 * @param int|null          $user       Acting user.
	 * @return array{files: array<string, string>, items: int, rows: int}
	 * @throws DatabaseException When a read fails or the manifest cannot be encoded.
	 */
	private function build( Recalculation $run, int $id, ?string $category, ?int $creator_id, string $filters, DateTimeImmutable $now, ?int $user ): array {
		$all         = $this->recalculations->items( $run->id );
		$items       = array_values( array_filter( $all, static fn( RecalculationItem $item ): bool => ( null === $category || $category === $item->category ) && ( null === $creator_id || $creator_id === $item->creator_id ) ) );
		$adjustments = $this->exports->adjustments( $run->id );
		$item_lines  = array( array( 'item_id', 'creator_id', 'creator_name', 'currency', 'group', 'reason', 'status', 'failure', 'rows', 'original', 'recalculated', 'difference', 'executed_at', 'executed_by', 'adjustment_id', 'adjustment_amount', 'settled_by_document' ) );
		$row_lines   = array( array( 'item_id', 'creator_id', 'currency', 'group', 'document_number', 'event_key', 'order_id', 'type', 'occurred_at', 'share_bps', 'price_decimals', 'net', 'tax', 'profit', 'original', 'recalculated', 'difference' ) );
		$rows        = 0;
		$period      = array();

		foreach ( $items as $item ) {
			$adjustment   = $adjustments[ $item->id ] ?? null;
			$creator      = $this->creators->find( $item->creator_id );
			$item_lines[] = array( (string) $item->id, (string) $item->creator_id, self::text( null === $creator ? '' : $creator->display_name ), $item->currency, $item->category, $item->reason, $item->status, $item->failure, (string) $item->row_count, self::amount( $item->original_units ), self::amount( $item->recalculated_units ), self::amount( $item->difference_units ), null === $item->executed_at ? '' : PayoutRecalculationRepository::utc( $item->executed_at ), null === $item->executed_by ? '' : (string) $item->executed_by, null === $adjustment ? '' : (string) $adjustment['id'], null === $adjustment ? '' : $adjustment['amount'], null === $adjustment ? '' : $adjustment['document'] );

			foreach ( is_array( $item->detail['rows'] ?? null ) ? $item->detail['rows'] : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$cell        = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
				$row_lines[] = array( (string) $item->id, (string) $item->creator_id, $item->currency, $item->category, $cell( 'document_number' ), $cell( 'event_key' ), $cell( 'order_id' ), $cell( 'type' ), $cell( 'occurred_at' ), $cell( 'share_bps' ), $cell( 'price_decimals' ), $cell( 'net' ), $cell( 'tax' ), $cell( 'profit' ), $cell( 'original' ), $cell( 'recalculated' ), $cell( 'difference' ) );
				$period[]    = $cell( 'occurred_at' );
				++$rows;
			}
		}

		$timeline = array( array( 'time_utc', 'action', 'actor_user_id', 'creator_id', 'context' ) );

		foreach ( $this->exports->timeline( $run->reference() ) as $event ) {
			$timeline[] = array( $event['created_at'], $event['action'], null === $event['actor_user_id'] ? '' : (string) $event['actor_user_id'], null === $event['creator_id'] ? '' : (string) $event['creator_id'], $event['context'] );
		}

		$files = array(
			'items.csv'    => self::csv( $item_lines ),
			'rows.csv'     => self::csv( $row_lines ),
			'timeline.csv' => self::csv( $timeline ),
		);

		$period   = array_values( array_filter( $period, static fn( string $time ): bool => '' !== $time ) );
		$complete = null === $category && null === $creator_id;
		$previous = $this->exports->previous( $run->id, $filters, $id );
		$listed   = array();
		$compare  = array();

		foreach ( self::DATA_FILES as $name ) {
			$hash     = hash( 'sha256', $files[ $name ] );
			$listed[] = array(
				'name'   => $name,
				'sha256' => $hash,
				'bytes'  => strlen( $files[ $name ] ),
				'lines'  => substr_count( $files[ $name ], "\n" ),
			);

			if ( null !== $previous && is_array( $previous['files'] ) ) {
				$compare[ $name ] = isset( $previous['files'][ $name ] ) && hash( 'sha256', $previous['files'][ $name ] ) === $hash ? 'unchanged' : 'changed';
			}
		}

		$limitations = array(
			'Amounts recalculate only the removal of the 20 % deduction (ADR-063); every other stored value is unchanged.',
			'Items in the group "manual" are not recalculated: their rows do not reproduce from the stored values, or their payouts have no settlement document.',
			'Former creators are reported only; nothing is paid or offset for them automatically.',
			'The legal right to offset and the accounting and VAT treatment are not validated (ADR-062, ADR-063).',
			'The statuses are those at the time of the export.',
		);

		if ( ! $complete ) {
			array_unshift( $limitations, 'Filtered export: it contains only the items that match the filters and is not the complete recalculation.' );
		}

		try {
			$manifest = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR.
				array(
					'format'                 => self::FORMAT,
					'format_version'         => self::FORMAT_VERSION,
					'export'                 => self::reference( $run->id, $id ),
					'created_at_utc'         => PayoutRecalculationRepository::utc( $now ),
					'created_by'             => $user,
					'recalculation'          => array(
						'reference'           => $run->reference(),
						'status'              => $run->status,
						'rule'                => $run->rule,
						'calculation_version' => $run->calculation_version,
						'created_at_utc'      => PayoutRecalculationRepository::utc( $run->created_at ),
						'decided_at_utc'      => null === $run->decided_at ? null : PayoutRecalculationRepository::utc( $run->decided_at ),
						'closed_at_utc'       => null === $run->closed_at ? null : PayoutRecalculationRepository::utc( $run->closed_at ),
						'reopens'             => null === $run->reopens_id ? null : Recalculation::reference_of( $run->reopens_id ),
					),
					'scope'                  => array(
						'filters'        => array(
							'group'      => $category,
							'creator_id' => $creator_id,
						),
						'complete'       => $complete,
						'items_total'    => count( $all ),
						'items_exported' => count( $items ),
						'rows_exported'  => $rows,
						'period_utc'     => array(
							'from' => array() === $period ? null : min( $period ),
							'to'   => array() === $period ? null : max( $period ),
						),
					),
					'versions'               => array(
						'plugin'         => defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : '',
						'schema'         => (string) get_option( Migrator::VERSION_OPTION, '' ),
						'export_format'  => self::FORMAT_VERSION,
						'recalculation'  => RecalculationRule::RULE,
						'legacy_payouts' => RecalculationRule::LEGACY_CALCULATION,
					),
					'files'                  => $listed,
					'previous_export'        => null === $previous ? null : self::reference( $previous['recalculation_id'], $previous['id'] ),
					'changes_since_previous' => null === $previous ? null : $compare,
					'limitations'            => $limitations,
					'verification'           => self::VERIFICATION,
				),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "\n";
		} catch ( JsonException ) {
			throw new DatabaseException( 'Could not encode the export manifest.' );
		}

		$files[ self::MANIFEST ] = $manifest;
		$sums                    = '';

		foreach ( array_merge( self::DATA_FILES, array( self::MANIFEST ) ) as $name ) {
			$sums .= hash( 'sha256', $files[ $name ] ) . '  ' . $name . "\n";
		}

		$files[ self::SUMS ] = $sums;

		return array(
			'files' => $files,
			'items' => count( $items ),
			'rows'  => $rows,
		);
	}

	/**
	 * Filters as stable JSON.
	 *
	 * @param string|null $category   Group.
	 * @param int|null    $creator_id Creator.
	 */
	private static function filters( ?string $category, ?int $creator_id ): string {
		return '{"group":' . ( null === $category ? 'null' : '"' . $category . '"' ) . ',"creator_id":' . ( null === $creator_id ? 'null' : (string) $creator_id ) . '}';
	}

	/**
	 * CSV (RFC 4180 quoting, LF line ends).
	 *
	 * @phpstan-param list<list<string>> $lines
	 *
	 * @param string[][] $lines Lines of cells.
	 */
	private static function csv( array $lines ): string {
		$out = '';

		foreach ( $lines as $cells ) {
			$out .= implode( ',', array_map( static fn( string $cell ): string => 1 === preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell, $cells ) ) . "\n";
		}

		return $out;
	}

	/**
	 * A free text cell: a leading =, +, - or @ is prefixed with ' so a
	 * spreadsheet never reads it as a formula.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $text ) ? "'" . $text : $text;
	}

	/**
	 * An amount cell.
	 *
	 * @param int|null $units Units.
	 */
	private static function amount( ?int $units ): string {
		return null === $units ? '' : SalesAmount::format( $units );
	}

	/**
	 * Requires `merchboard_manage_payouts`.
	 *
	 * @throws PayoutException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}
	}
}
