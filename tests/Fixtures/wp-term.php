<?php
/**
 * Minimal stand-in for WP_Term (the properties the plugin reads). Loaded only
 * in isolated test processes.
 *
 * @package MerchBoard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Mirrors WordPress' global class.

/**
 * WordPress term.
 */
final class WP_Term {

	/**
	 * Term id.
	 *
	 * @var int
	 */
	public $term_id;

	/**
	 * Taxonomy.
	 *
	 * @var string
	 */
	public $taxonomy;

	/**
	 * Term taxonomy id (the term id unless given).
	 *
	 * @var int
	 */
	public $term_taxonomy_id;

	/**
	 * Name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Published objects in the term.
	 *
	 * @var int
	 */
	public $count;

	/**
	 * Constructor.
	 *
	 * @param object $term Term data (term_id, taxonomy, optional term_taxonomy_id, name, count).
	 */
	public function __construct( $term ) {
		$this->term_id          = (int) $term->term_id;
		$this->taxonomy         = (string) $term->taxonomy;
		$this->term_taxonomy_id = (int) ( $term->term_taxonomy_id ?? $term->term_id );
		$this->name             = (string) ( $term->name ?? 'Term ' . $term->term_id );
		$this->count            = (int) ( $term->count ?? 0 );
	}
}
