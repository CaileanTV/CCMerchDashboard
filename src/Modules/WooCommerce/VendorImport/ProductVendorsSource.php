<?php
/**
 * WooCommerce Product Vendors' data, as the migration reads it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of WooCommerce Product Vendors (ADR-074 pkt. 2). The
 * migration never writes Product Vendors' data.
 */
interface ProductVendorsSource {

	/**
	 * Product Vendors' role of a vendor administrator.
	 */
	public const ADMIN_ROLE = 'wc_product_vendors_admin_vendor';

	/**
	 * Product Vendors' role of a vendor manager.
	 */
	public const MANAGER_ROLE = 'wc_product_vendors_manager_vendor';

	/**
	 * Product Vendors' role of a vendor that is not approved yet.
	 */
	public const PENDING_ROLE = 'wc_product_vendors_pending_vendor';

	/**
	 * Product Vendors' three roles.
	 */
	public const ROLES = array( self::ADMIN_ROLE, self::MANAGER_ROLE, self::PENDING_ROLE );

	/**
	 * Whether Product Vendors is active, so its own API can be read.
	 */
	public function active(): bool;

	/**
	 * Product Vendors' version ('' when unknown).
	 */
	public function version(): string;

	/**
	 * Every vendor, by name.
	 *
	 * @return list<array{term_id: int, name: string}>
	 * @throws VendorImportException When the vendors cannot be read.
	 */
	public function vendors(): array;

	/**
	 * The user ids of a vendor's administrators (existing users only).
	 *
	 * @param int $term_id Vendor.
	 * @return list<int>
	 */
	public function admins( int $term_id ): array;

	/**
	 * Product Vendors' roles of a user.
	 *
	 * @param int $user_id User.
	 * @return list<string>
	 */
	public function roles( int $user_id ): array;

	/**
	 * A vendor's parent products (no variations, trash or auto-drafts).
	 *
	 * @param int $term_id Vendor.
	 * @return list<int>
	 */
	public function product_ids( int $term_id ): array;
}
