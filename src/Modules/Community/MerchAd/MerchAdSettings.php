<?php
/**
 * A creator's merch ad overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's ad in the stream (ADR-113): on/off, the design, the chosen
 * products in order (none: the newest), the pill, how often and how long
 * it shows, the animations, the colours, the secret overlay id, the
 * background-free images per product and when "show now" was pressed.
 */
final class MerchAdSettings {

	/**
	 * The designs.
	 */
	public const DESIGNS = array( 'spotlight', 'shelf', 'carousel', 'corner', 'showcase' );

	/**
	 * The animations in and out.
	 */
	public const ANIMATIONS = array( 'fade', 'slide', 'pop', 'zoom', 'blur' );

	/**
	 * The card's look.
	 */
	public const THEMES = array( 'dark', 'light' );

	/**
	 * The most products.
	 */
	public const MAX_PRODUCTS = 12;

	/**
	 * Minutes between two showings.
	 */
	public const MIN_EVERY = 1;

	/**
	 * Minutes between two showings, at most.
	 */
	public const MAX_EVERY = 60;

	/**
	 * Seconds a showing lasts.
	 */
	public const MIN_SECONDS = 5;

	/**
	 * Seconds a showing lasts, at most.
	 */
	public const MAX_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $product_ids
	 * @phpstan-param array<int, string> $cutouts
	 *
	 * @param bool               $enabled     Whether the overlay shows the ad.
	 * @param string             $design      One of DESIGNS.
	 * @param int[]              $product_ids Chosen parent products, in order.
	 * @param string             $note        The pill's text; '' for none.
	 * @param int                $every       Minutes between two showings.
	 * @param int                $seconds     Seconds a showing lasts.
	 * @param string             $enter       Animation in.
	 * @param string             $leave       Animation out.
	 * @param string             $accent      Accent colour, #rrggbb.
	 * @param string             $theme       One of THEMES.
	 * @param string             $overlay_id  The secret id in the OBS link ('' before the first save).
	 * @param array<int, string> $cutouts     Background-free image file per product.
	 * @param int                $show_at     When "show now" was pressed (Unix time; 0 never).
	 */
	public function __construct(
		public bool $enabled = false,
		public string $design = 'spotlight',
		public array $product_ids = array(),
		public string $note = '',
		public int $every = 10,
		public int $seconds = 15,
		public string $enter = 'slide',
		public string $leave = 'fade',
		public string $accent = '#9146ff',
		public string $theme = 'dark',
		public string $overlay_id = '',
		public array $cutouts = array(),
		public int $show_at = 0
	) {
	}

	/**
	 * From creator meta; anything unreadable gets its default.
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): self {
		$s       = is_array( $stored ) ? $stored : array();
		$default = new self();
		$ids     = is_array( $s['product_ids'] ?? null ) ? $s['product_ids'] : array();
		$ids     = array_values( array_unique( array_filter( array_map( static fn( mixed $id ): int => is_int( $id ) ? $id : 0, $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$cutouts = array();

		foreach ( is_array( $s['cutouts'] ?? null ) ? $s['cutouts'] : array() as $product => $file ) {
			if ( is_int( $product ) && $product > 0 && is_string( $file ) && 1 === preg_match( '/^p\d+-[0-9a-f]{12}\.png$/', $file ) ) {
				$cutouts[ $product ] = $file;
			}
		}

		$note  = is_string( $s['note'] ?? null ) ? $s['note'] : '';
		$pick  = static fn( string $key, array $allowed, string $fallback ): string => is_string( $s[ $key ] ?? null ) && in_array( $s[ $key ], $allowed, true ) ? $s[ $key ] : $fallback;
		$range = static fn( string $key, int $min, int $max, int $fallback ): int => is_int( $s[ $key ] ?? null ) && $s[ $key ] >= $min && $s[ $key ] <= $max ? $s[ $key ] : $fallback;
		$id    = is_string( $s['overlay_id'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $s['overlay_id'] ) ? $s['overlay_id'] : '';

		return new self(
			true === ( $s['enabled'] ?? false ),
			$pick( 'design', self::DESIGNS, $default->design ),
			array_slice( $ids, 0, self::MAX_PRODUCTS ),
			null === TwitchExtensionSettings::note_error( $note ) ? $note : '',
			$range( 'every', self::MIN_EVERY, self::MAX_EVERY, $default->every ),
			$range( 'seconds', self::MIN_SECONDS, self::MAX_SECONDS, $default->seconds ),
			$pick( 'enter', self::ANIMATIONS, $default->enter ),
			$pick( 'leave', self::ANIMATIONS, $default->leave ),
			is_string( $s['accent'] ?? null ) && 1 === preg_match( '/^#[0-9a-f]{6}$/', $s['accent'] ) ? $s['accent'] : $default->accent,
			$pick( 'theme', self::THEMES, $default->theme ),
			$id,
			$cutouts,
			is_int( $s['show_at'] ?? null ) && $s['show_at'] > 0 ? $s['show_at'] : 0
		);
	}

	/**
	 * For creator meta.
	 *
	 * @return array<string, mixed>
	 */
	public function to_stored(): array {
		return array(
			'enabled'     => $this->enabled,
			'design'      => $this->design,
			'product_ids' => $this->product_ids,
			'note'        => $this->note,
			'every'       => $this->every,
			'seconds'     => $this->seconds,
			'enter'       => $this->enter,
			'leave'       => $this->leave,
			'accent'      => $this->accent,
			'theme'       => $this->theme,
			'overlay_id'  => $this->overlay_id,
			'cutouts'     => $this->cutouts,
			'show_at'     => $this->show_at,
		);
	}

	/**
	 * The fields the creator's form sets.
	 *
	 * @return list<string>
	 */
	public static function form_fields(): array {
		return array( 'enabled', 'design', 'product_ids', 'note', 'every', 'seconds', 'enter', 'leave', 'accent', 'theme' );
	}
}
