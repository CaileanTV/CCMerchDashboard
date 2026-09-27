<?php
/**
 * Labels for the ids an audit page's context refers to.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What AuditLogListTable looked up once per page (in bulk) for the ids in the
 * records' context, so AuditLogFormatter never queries per record. A missing
 * id means the object no longer exists; the formatter then shows "#id".
 */
final readonly class AuditContextLabels {

	/**
	 * Constructor.
	 *
	 * @phpstan-param array<int, array{name: string, url: string|null}> $products
	 *
	 * @param array<int, string>      $users      "Display Name (login)" of existing users.
	 * @param array<int, string|null> $creators   Display names of creators (null: not found).
	 * @param array[]                 $products   Existing products: name and, when the viewer may edit it, the edit URL.
	 * @param array<int, string>      $categories Names of existing product categories.
	 */
	public function __construct(
		public array $users = array(),
		public array $creators = array(),
		public array $products = array(),
		public array $categories = array()
	) {
	}
}
