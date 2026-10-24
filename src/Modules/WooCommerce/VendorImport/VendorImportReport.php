<?php
/**
 * What the migration from Product Vendors does.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

defined( 'ABSPATH' ) || exit;

/**
 * The dry run of the migration (steps 26.1 and 26.2.1, ADR-074): per vendor
 * what happens, with the chosen user, the product category and the
 * products. The fingerprint ties the administrator's confirmation to
 * exactly this dry run, chosen users and categories included.
 *
 * @phpstan-type VendorUser array{id: int, name: string, role: string, creator_id: int}
 * @phpstan-type VendorCategory array{id: int, name: string, count: int}
 * @phpstan-type VendorRow array{term_id: int, name: string, status: string, users: list<VendorUser>, user_id: int, creator_id: int, creator_name: string, same_name: bool, products: array{link: int, same: int, conflict: int}, settlement: string|null, categories: list<VendorCategory>, category_id: int, category: string|null}
 */
final readonly class VendorImportReport {

	/**
	 * Vendor statuses. NEW, MATCHED, IMPORTED and NO_ADMIN are migrated; the
	 * others are shown and skipped.
	 */
	public const NEW        = 'new';
	public const MATCHED    = 'matched';
	public const IMPORTED   = 'imported';
	public const NO_ADMIN   = 'no_admin';
	public const PENDING    = 'pending';
	public const USER_TAKEN = 'user_taken';

	/**
	 * Product category statuses (null for a skipped vendor): MAP is linked to
	 * the creator; MAPPED is the creator's category already; TAKEN is mapped
	 * to another creator or chosen for another vendor; NONE is no category.
	 */
	public const CATEGORY_MAP    = 'map';
	public const CATEGORY_MAPPED = 'mapped';
	public const CATEGORY_TAKEN  = 'taken';
	public const CATEGORY_NONE   = 'none';

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<VendorRow> $vendors
	 *
	 * @param string  $version Product Vendors' version.
	 * @param array[] $vendors Per vendor.
	 */
	public function __construct( public string $version, public array $vendors ) {
	}

	/**
	 * Whether a vendor status is migrated.
	 *
	 * @param string $status Status.
	 */
	public static function migrates( string $status ): bool {
		return in_array( $status, array( self::NEW, self::MATCHED, self::IMPORTED, self::NO_ADMIN ), true );
	}

	/**
	 * Identifies this dry run (sha256 of its content).
	 */
	public function fingerprint(): string {
		return hash( 'sha256', (string) wp_json_encode( array( $this->version, $this->vendors ) ) );
	}

	/**
	 * The chosen user per vendor.
	 *
	 * @return array<int, int>
	 */
	public function choices(): array {
		$choices = array();

		foreach ( $this->vendors as $vendor ) {
			if ( $vendor['user_id'] > 0 ) {
				$choices[ $vendor['term_id'] ] = $vendor['user_id'];
			}
		}

		return $choices;
	}

	/**
	 * The product category per migrated vendor (0: none), for the
	 * confirmation form.
	 *
	 * @return array<int, int>
	 */
	public function category_choices(): array {
		$choices = array();

		foreach ( $this->vendors as $vendor ) {
			if ( null !== $vendor['category'] ) {
				$choices[ $vendor['term_id'] ] = self::CATEGORY_NONE === $vendor['category'] ? 0 : $vendor['category_id'];
			}
		}

		return $choices;
	}

	/**
	 * Counts for the audit log (no names).
	 *
	 * @return array<string, int>
	 */
	public function audit_context(): array {
		$context = array(
			'vendors'    => 0,
			'skipped'    => 0,
			'products'   => 0,
			'conflict'   => 0,
			'categories' => 0,
		);

		foreach ( $this->vendors as $vendor ) {
			if ( self::migrates( $vendor['status'] ) ) {
				++$context['vendors'];
				$context['products']   += $vendor['products']['link'];
				$context['conflict']   += $vendor['products']['conflict'];
				$context['categories'] += self::CATEGORY_MAP === $vendor['category'] ? 1 : 0;
			} else {
				++$context['skipped'];
			}
		}

		return $context;
	}
}
