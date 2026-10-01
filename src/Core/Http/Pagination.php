<?php
/**
 * Page and page size of a paginated REST list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Http;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The requested page of a list route (docs/API.md#paginering). The REST
 * argument schema (RestController::pagination_args()) has already rejected
 * invalid values with 400; the bounds are enforced here again so a caller can
 * never ask the database for more than MAX_PER_PAGE rows.
 */
final readonly class Pagination {

	/**
	 * Default page size.
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Largest page size.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Page, from 1.
	 *
	 * @var int
	 */
	public int $page;

	/**
	 * Page size, 1 to MAX_PER_PAGE.
	 *
	 * @var int
	 */
	public int $per_page;

	/**
	 * Constructor.
	 *
	 * @param int $page     Page, from 1.
	 * @param int $per_page Page size.
	 */
	public function __construct( int $page, int $per_page ) {
		$this->page     = max( 1, $page );
		$this->per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * The pagination of a request validated against pagination_args().
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function from_request( WP_REST_Request $request ): self {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		return new self(
			is_int( $page ) ? $page : 1,
			is_int( $per_page ) ? $per_page : self::DEFAULT_PER_PAGE
		);
	}

	/**
	 * Rows to skip.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Number of pages for a total: 0 when there is nothing to list.
	 *
	 * @param int $total Total number of items.
	 */
	public function total_pages( int $total ): int {
		return $total > 0 ? intdiv( $total + $this->per_page - 1, $this->per_page ) : 0;
	}
}
