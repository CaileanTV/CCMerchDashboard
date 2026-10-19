<?php
/**
 * A Google Fonts family.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * A font family of the Google Fonts catalogue (ADR-067): its name, its
 * category and the weights it has, so the stylesheet URL asks only for
 * weights that exist (Google answers 400 for a missing one).
 */
final readonly class AlertFont {

	/**
	 * Font categories of the Google Fonts catalogue.
	 */
	public const CATEGORIES = array( 'sans-serif', 'serif', 'display', 'handwriting', 'monospace' );

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $weights
	 *
	 * @param string $family   Family name (validated by from_array()).
	 * @param string $category One of CATEGORIES.
	 * @param int[]  $weights  Upright weights, ascending.
	 */
	private function __construct( public string $family, public string $category, public array $weights ) {
	}

	/**
	 * A font from stored or fetched data, or null when it is invalid.
	 *
	 * @param mixed $data `{family, category, weights}` or Google's `{family, category, variants}`.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['family'] ?? null ) || ! self::valid_family( $data['family'] ) ) {
			return null;
		}

		$category = is_string( $data['category'] ?? null ) && in_array( $data['category'], self::CATEGORIES, true ) ? $data['category'] : 'sans-serif';
		$weights  = array();
		$source   = is_array( $data['weights'] ?? null ) ? $data['weights'] : ( is_array( $data['variants'] ?? null ) ? $data['variants'] : array() );

		foreach ( $source as $variant ) {
			$weight = 'regular' === $variant ? 400 : ( is_int( $variant ) || ( is_string( $variant ) && 1 === preg_match( '/^[1-9]00$/D', $variant ) ) ? (int) $variant : 0 );

			if ( $weight >= 100 && $weight <= 900 && 0 === $weight % 100 && ! in_array( $weight, $weights, true ) ) {
				$weights[] = $weight;
			}
		}

		sort( $weights );

		return new self( $data['family'], $category, array() === $weights ? array( 400 ) : $weights );
	}

	/**
	 * Whether a string is a plain family name: letters, digits, spaces and
	 * hyphens, as every Google Fonts family is. Nothing else can reach a
	 * stylesheet or a URL.
	 *
	 * @param string $family Candidate.
	 */
	public static function valid_family( string $family ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/D', $family ) && 'inherit' !== strtolower( $family );
	}

	/**
	 * The Google Fonts stylesheet URL with exactly this family's weights.
	 */
	public function css_url(): string {
		return 'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $this->family ) . ':wght@' . implode( ';', $this->weights ) . '&display=swap';
	}

	/**
	 * Data for storage.
	 *
	 * @return array{family: string, category: string, weights: list<int>}
	 */
	public function to_array(): array {
		return array(
			'family'   => $this->family,
			'category' => $this->category,
			'weights'  => $this->weights,
		);
	}
}
