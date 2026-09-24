<?php
/**
 * Creators module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Container;
use MerchBoard\Core\Module;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Creator domain. Phase 3 provides the data layer only; service, admin UI and
 * access follow in later roadmap phases (see docs/ROADMAP.md).
 */
final class CreatorsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'creators';
	}

	/**
	 * Registers the creator repository.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			CreatorRepository::class,
			static fn( Container $c ): CreatorRepository => new CreatorRepository( $c->get( wpdb::class ) )
		);
	}
}
