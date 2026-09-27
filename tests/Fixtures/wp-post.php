<?php
/**
 * Stand-in for WordPress' WP_Post (only what the plugin reads). Loaded only
 * in isolated test processes (a global class cannot be undone).
 *
 * @package MerchBoard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Mirrors WordPress' global class.

/**
 * A post.
 */
final class WP_Post {

	/**
	 * Post id.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public $post_type;

	/**
	 * Constructor.
	 *
	 * @param object $post Post data (ID, post_type).
	 */
	public function __construct( $post ) {
		$this->ID        = (int) $post->ID;
		$this->post_type = (string) $post->post_type;
	}
}
