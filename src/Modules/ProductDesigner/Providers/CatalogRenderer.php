<?php
/**
 * A provider that renders a catalog product for the import.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Printful or Gelato as CatalogImport uses them (review 30.15): what the
 * administrator can pick from a catalog product, and two renders of it, one
 * blank and one with a marker per side. A render's images are keyed by a
 * slot (Printful: the placement; Gelato: the image's position among a
 * variant's images) and the colour key. Methods throw the provider's
 * exception (PrintfulException or GelatoException).
 *
 * The import data passed in holds `ref` (the provider's product or
 * template), `sides` (each with `placement`, `width` and `height`: the
 * print file in pixels), `colors` (the variant rendered per colour key),
 * `blank` and `markers` (the URLs of the transparent and marker files).
 */
interface CatalogRenderer {

	/**
	 * Whether the provider is switched on and has its credentials.
	 */
	public function available(): bool;

	/**
	 * What the administrator can pick from a product. `flat` marks a
	 * product designed on its flat pattern: no render, and each side names its
	 * `template` (optional), its size and its print `area` in it. `groups`
	 * names the groups of sizes with the same print sizes (empty when there is
	 * one), and `group` is the one offered. `photos` are the mockup styles the
	 * administrator can choose (Printful v2), with the ones ticked by default.
	 *
	 * @param string $ref   The provider's product (Printful) or template (Gelato).
	 * @param int    $group The group of sizes.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique?: string, style?: int, template?: string, template_width?: int, template_height?: int, area?: array{x: int, y: int, width: int, height: int}}>, variants: array<string, array<string, string>>, flat?: bool, groups?: list<string>, group?: int, options?: list<array{name: string, value: string}>, photos?: list<array{id: int, category: string, view: string, default: bool}>}
	 */
	public function choice( string $ref, int $group = 0 ): array;

	/**
	 * The renders of an import: `marker` (the first colour with a marker
	 * colour per side), and `blank` (every colour with transparent files) or
	 * `blank:<colour key>` per colour for a provider that gives one image
	 * per product.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string>
	 */
	public function render_kinds( array $import ): array;

	/**
	 * Starts a render.
	 *
	 * @param string               $kind   One of render_kinds().
	 * @param array<string, mixed> $import Import data.
	 * @return string The render's key at the provider.
	 */
	public function render( string $kind, array $import ): string;

	/**
	 * A render's state: `pending`, `failed` (with `error`) or `done` with
	 * its image URLs by slot and colour key, and the provider's own state
	 * in `note` for the administrator.
	 *
	 * @param string               $key    The render's key.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, note?: string, images?: array<int|string, array<string, string>>}
	 */
	public function check( string $key, array $import ): array;

	/**
	 * The provider's answer about a render, for the administrator's
	 * diagnosis (no credentials).
	 *
	 * @param string $key The render's key.
	 * @return array<string, mixed>
	 */
	public function render_answer( string $key ): array;

	/**
	 * A file the provider returned.
	 *
	 * @param string $url URL from the provider's answer.
	 * @return string Bytes.
	 */
	public function download( string $url ): string;

	/**
	 * Seconds between two looks at a pending render.
	 */
	public function poll_delay(): int;

	/**
	 * The provider's answers about a product, for the administrator's
	 * diagnosis (no credentials).
	 *
	 * @param string $ref The provider's product or template.
	 * @return array<string, mixed>
	 */
	public function diagnose( string $ref ): array;
}
