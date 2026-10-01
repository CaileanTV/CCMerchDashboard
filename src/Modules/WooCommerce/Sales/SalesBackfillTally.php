<?php
/**
 * Counts of a backfill run or dry run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Only counts (ADR-038, status note "ROADMAP trin 7", D3/D5): orders scanned,
 * orders per outcome, planned rows per entry type and failures per code. No
 * amounts, no order ids and no personal data.
 */
final readonly class SalesBackfillTally {

	/**
	 * Constructor.
	 *
	 * @param int                $scanned  Orders looked at.
	 * @param array<string, int> $outcomes Orders per SalesBackfillStatus value.
	 * @param array<string, int> $rows     Planned rows per SalesEntryType value.
	 * @param array<string, int> $failures Failed orders per SalesFailureCode value (only codes that occurred).
	 */
	private function __construct(
		public int $scanned,
		public array $outcomes,
		public array $rows,
		public array $failures
	) {
	}

	/**
	 * Nothing counted yet.
	 */
	public static function empty(): self {
		return new self( 0, self::zeros( SalesBackfillStatus::cases() ), self::zeros( SalesEntryType::cases() ), array() );
	}

	/**
	 * These counts plus one order's outcome.
	 *
	 * @param SalesBackfillOutcome $outcome Outcome.
	 */
	public function with( SalesBackfillOutcome $outcome ): self {
		$outcomes = $this->outcomes;
		$rows     = $this->rows;
		$failures = $this->failures;

		++$outcomes[ $outcome->status->value ];

		foreach ( $outcome->rows as $type => $count ) {
			if ( isset( $rows[ $type ] ) ) {
				$rows[ $type ] += $count;
			}
		}

		if ( null !== $outcome->failure ) {
			$failures[ $outcome->failure->value ] = ( $failures[ $outcome->failure->value ] ?? 0 ) + 1;
			ksort( $failures );
		}

		return new self( $this->scanned + 1, $outcomes, $rows, $failures );
	}

	/**
	 * Orders with this outcome.
	 *
	 * @param SalesBackfillStatus $status Outcome.
	 */
	public function count( SalesBackfillStatus $status ): int {
		return $this->outcomes[ $status->value ];
	}

	/**
	 * The counts as stored in a job payload and in the audit record:
	 * `scanned`, one key per outcome, `rows` and `failures`.
	 *
	 * @return array<string, int|array<string, int>>
	 */
	public function to_array(): array {
		return array( 'scanned' => $this->scanned ) + $this->outcomes + array(
			'rows'     => $this->rows,
			'failures' => $this->failures,
		);
	}

	/**
	 * Counts from to_array().
	 *
	 * @param array<mixed> $data Stored counts.
	 * @throws InvalidArgumentException When the data is not valid counts.
	 */
	public static function from_array( array $data ): self {
		$empty    = self::empty();
		$outcomes = self::counts( $data, array_keys( $empty->outcomes ) );
		$rows     = self::counts( is_array( $data['rows'] ?? null ) ? $data['rows'] : array(), array_keys( $empty->rows ) );
		$failures = array();
		$stored   = $data['failures'] ?? null;

		if ( ! is_int( $data['scanned'] ?? null ) || $data['scanned'] < 0 || ! is_array( $data['rows'] ?? null ) || ! is_array( $stored ) ) {
			throw new InvalidArgumentException( 'Invalid sales backfill counts.' );
		}

		foreach ( $stored as $code => $count ) {
			if ( null === SalesFailureCode::tryFrom( (string) $code ) || ! is_int( $count ) || $count < 1 ) {
				throw new InvalidArgumentException( 'Invalid sales backfill failure count.' );
			}

			$failures[ (string) $code ] = $count;
		}

		ksort( $failures );

		return new self( $data['scanned'], $outcomes, $rows, $failures );
	}

	/**
	 * Zero for each case.
	 *
	 * @param array<\BackedEnum> $cases Enum cases.
	 * @return array<string, int>
	 */
	private static function zeros( array $cases ): array {
		$zeros = array();

		foreach ( $cases as $case ) {
			$zeros[ (string) $case->value ] = 0;
		}

		return $zeros;
	}

	/**
	 * Non-negative integer counts for exactly these keys.
	 *
	 * @phpstan-param list<string> $keys
	 *
	 * @param array<mixed> $data Stored values.
	 * @param string[]     $keys Required keys.
	 * @return array<string, int>
	 * @throws InvalidArgumentException When a count is missing or invalid.
	 */
	private static function counts( array $data, array $keys ): array {
		$counts = array();

		foreach ( $keys as $key ) {
			if ( ! is_int( $data[ $key ] ?? null ) || $data[ $key ] < 0 ) {
				throw new InvalidArgumentException( 'Invalid sales backfill count.' );
			}

			$counts[ $key ] = $data[ $key ];
		}

		return $counts;
	}
}
