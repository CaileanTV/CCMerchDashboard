<?php
/**
 * A clipart of the administrator's library.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

defined( 'ABSPATH' ) || exit;

/**
 * A row of the kind `clipart` (one image for all base products, as before
 * schema 55) or `artwork` (with a definition, ADR-121 pkt. 9).
 */
final readonly class Clipart {

	/**
	 * Constructor.
	 *
	 * @param int                    $id         Asset id.
	 * @param string                 $kind       CLIPART or ARTWORK.
	 * @param string                 $name       Name shown in the designer.
	 * @param string                 $file       The image; with parts the preview.
	 * @param string                 $mime       Its type.
	 * @param int                    $width      Width.
	 * @param int                    $height     Height.
	 * @param string|null            $retired_at When it was removed (UTC), or null.
	 * @param ClipartDefinition|null $definition An artwork's definition; null for a clipart and for an unreadable one.
	 */
	public function __construct( public int $id, public string $kind, public string $name, public string $file, public string $mime, public int $width, public int $height, public ?string $retired_at, public ?ClipartDefinition $definition ) {
	}

	/**
	 * Whether it can be drawn at all: an artwork without a readable definition
	 * cannot, also not in a design that uses it.
	 */
	public function readable(): bool {
		return DesignerAssetRepository::CLIPART === $this->kind || null !== $this->definition;
	}

	/**
	 * Whether it can be chosen for a new design on a base product.
	 *
	 * @param int $base_id Base product.
	 */
	public function offered_on( int $base_id ): bool {
		if ( null !== $this->retired_at ) {
			return false;
		}

		return DesignerAssetRepository::CLIPART === $this->kind || ( null !== $this->definition && $this->definition->offered_on( $base_id ) );
	}
}
