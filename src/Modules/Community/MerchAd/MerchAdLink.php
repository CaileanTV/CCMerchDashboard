<?php
/**
 * The name in the merch ad's OBS link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use MerchBoard\Core\Settings\CommunityLink;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The query parameter of the merch ad's OBS link, `?<name>=<id>` (ADR-113;
 * the owner's wish of 2026-11-15). An administrator chooses it under
 * Settings → Community (default `merch_ad`). Links already in OBS keep
 * working: the original name `merchboard_merch_ad` and up to 10 earlier
 * names are still read.
 */
final class MerchAdLink {

	/**
	 * The name of the first links.
	 */
	public const LEGACY = 'merchboard_merch_ad';

	/**
	 * The default name.
	 */
	public const DEFAULT = 'merch_ad';

	/**
	 * Whether a name may be used: the community links' rules, and not a name
	 * the gift and giveaway links use.
	 *
	 * @param string $name Name.
	 */
	public static function valid( string $name ): bool {
		return CommunityLink::valid( $name ) && ! in_array( $name, CommunityLink::names(), true );
	}

	/**
	 * The name new links use.
	 */
	public static function name(): string {
		return Settings::all()[ Settings::COMMUNITY_AD_LINK_PARAM ];
	}

	/**
	 * Every name a link may use: the current one, the earlier ones and the
	 * original one.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		$settings = Settings::all();

		return array_values( array_unique( array_merge( array( $settings[ Settings::COMMUNITY_AD_LINK_PARAM ] ), $settings[ Settings::COMMUNITY_AD_LINK_PREVIOUS ], array( self::LEGACY ) ) ) );
	}

	/**
	 * The overlay id of a request's query, under any name a link may use; ''
	 * for none.
	 *
	 * @param array<mixed> $query Unslashed query arguments.
	 */
	public static function id( array $query ): string {
		foreach ( self::names() as $name ) {
			if ( is_string( $query[ $name ] ?? null ) && '' !== $query[ $name ] ) {
				return sanitize_key( $query[ $name ] );
			}
		}

		return '';
	}

	/**
	 * The earlier names after a change: the old name first, at most
	 * CommunityLink::MAX_PREVIOUS, without the new name and the original one.
	 *
	 * @phpstan-param list<string> $previous
	 *
	 * @param string[] $previous The earlier names so far.
	 * @param string   $old      The name before the change.
	 * @param string   $next     The new name.
	 * @return list<string>
	 */
	public static function previous_after( array $previous, string $old, string $next ): array {
		$names = array_filter(
			array_unique( array_merge( array( $old ), $previous ) ),
			static fn( string $name ): bool => $name !== $next && self::LEGACY !== $name && CommunityLink::valid( $name )
		);

		return array_slice( array_values( $names ), 0, CommunityLink::MAX_PREVIOUS );
	}
}
