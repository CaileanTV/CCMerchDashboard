<?php
/**
 * The setup of the creator's overlay settings page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\PageLayout;

defined( 'ABSPATH' ) || exit;

/**
 * How the creator's page "Overlay settings" is laid out (step 25.9.6, the
 * project owner's choices 2026-10-23): which cards stand in full width at
 * the top and which in the left or right column, in which order, the order
 * of the fields in each card, and whether the "Designs" card hides its
 * preview until the creator opens it. Nothing is hidden: every card and
 * field is always on the page. The images card always follows the
 * animations card, and the designer is always last (not part of the setup).
 *
 * Always complete: from_array() drops unknown and repeated keys and adds a
 * missing card or field at its default place (also a card added in a later
 * version). The dashboard normalizes the same way (page-layout.ts).
 */
final class CreatorPageLayout {

	/**
	 * Version of the stored shape.
	 */
	public const VERSION = 1;

	/**
	 * The zones, top to bottom on a narrow screen.
	 */
	public const ZONES = array( 'full', 'left', 'right' );

	/**
	 * The default place of every card.
	 */
	public const DEFAULT_CARDS = array(
		'full'  => array( 'designs' ),
		'left'  => array( 'browser_source', 'sounds', 'alert_text', 'text_style' ),
		'right' => array( 'box_image', 'animations', 'timing' ),
	);

	/**
	 * The fields of the cards that have fields to order, in default order.
	 */
	public const FIELDS = array(
		'alert_text' => array( 'template', 'secondary_template', 'show_secondary' ),
		'text_style' => array( 'font', 'font_size', 'font_weight', 'text_color', 'text_align', 'text_fit', 'text_shadow' ),
		'box_image'  => array( 'bg_color', 'bg_opacity', 'border_radius', 'padding', 'image_size' ),
		'animations' => array( 'main_text', 'secondary_text' ),
		'timing'     => array( 'display_from_sound', 'display_duration', 'anim_duration' ),
	);

	/**
	 * Constructor; use defaults() or from_array().
	 *
	 * @param array<string, list<string>> $cards           Zone => cards.
	 * @param array<string, list<string>> $fields          Card => fields.
	 * @param bool                        $designs_compact Whether "Designs" hides its preview.
	 */
	private function __construct( public readonly array $cards, public readonly array $fields, public readonly bool $designs_compact ) {
	}

	/**
	 * The default setup (the page as before step 25.9.6).
	 */
	public static function defaults(): self {
		return new self( self::DEFAULT_CARDS, self::FIELDS, false );
	}

	/**
	 * A complete setup from stored or submitted values.
	 *
	 * @param mixed $value Values.
	 */
	public static function from_array( mixed $value ): self {
		$value = is_array( $value ) ? $value : array();
		$given = is_array( $value['cards'] ?? null ) ? $value['cards'] : array();
		$all   = array_merge( ...array_values( self::DEFAULT_CARDS ) );
		$seen  = array();
		$cards = array();

		foreach ( self::ZONES as $zone ) {
			$cards[ $zone ] = array();

			foreach ( is_array( $given[ $zone ] ?? null ) ? $given[ $zone ] : array() as $card ) {
				if ( is_string( $card ) && in_array( $card, $all, true ) && ! isset( $seen[ $card ] ) ) {
					$seen[ $card ]    = true;
					$cards[ $zone ][] = $card;
				}
			}
		}

		foreach ( self::DEFAULT_CARDS as $zone => $defaults ) {
			foreach ( $defaults as $index => $card ) {
				if ( ! isset( $seen[ $card ] ) ) {
					$cards[ $zone ] = self::insert( $cards[ $zone ], $card, $index );
				}
			}
		}

		$given  = is_array( $value['fields'] ?? null ) ? $value['fields'] : array();
		$fields = array();

		foreach ( self::FIELDS as $card => $defaults ) {
			$order = array();

			foreach ( is_array( $given[ $card ] ?? null ) ? $given[ $card ] : array() as $field ) {
				if ( is_string( $field ) && in_array( $field, $defaults, true ) && ! in_array( $field, $order, true ) ) {
					$order[] = $field;
				}
			}

			foreach ( $defaults as $index => $field ) {
				if ( ! in_array( $field, $order, true ) ) {
					$order = self::insert( $order, $field, $index );
				}
			}

			$fields[ $card ] = $order;
		}

		$compact = $value['designs_compact'] ?? false;

		return new self( $cards, $fields, true === $compact );
	}

	/**
	 * The setup as stored and as the dashboard reads it.
	 *
	 * @return array{version: int, cards: array<string, list<string>>, fields: array<string, list<string>>, designs_compact: bool}
	 */
	public function to_array(): array {
		return array(
			'version'         => self::VERSION,
			'cards'           => $this->cards,
			'fields'          => $this->fields,
			'designs_compact' => $this->designs_compact,
		);
	}

	/**
	 * A list with an item inserted at an index (at the end when shorter).
	 *
	 * @phpstan-param list<string> $items
	 * @phpstan-return list<string>
	 *
	 * @param string[] $items List.
	 * @param string   $item  Item.
	 * @param int      $index Index.
	 * @return string[]
	 */
	private static function insert( array $items, string $item, int $index ): array {
		array_splice( $items, min( $index, count( $items ) ), 0, array( $item ) );

		return $items;
	}
}
