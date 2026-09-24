<?php
/**
 * Access module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creator access (phase 8): registers CreatorContext, the policy every
 * creator-facing feature uses. Login redirect, the wp-admin guard and the
 * dashboard follow in later roadmap phases. Depends on the Creators module
 * for CreatorRepository.
 */
final class AccessModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'access';
	}

	/**
	 * Registers the access policy (no hooks yet).
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			CreatorContext::class,
			static fn( Container $c ): CreatorContext => new CreatorContext(
				$c->get( CreatorRepository::class ),
				$c->get( Logger::class )
			)
		);
	}
}
