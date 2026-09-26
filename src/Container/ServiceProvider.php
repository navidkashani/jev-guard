<?php
/**
 * Service provider contract.
 *
 * @package SpamLens
 */

namespace SpamLens\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Providers are listed in Bootstrap and run in two phases: register() binds factories, boot() wires hooks.
 */
interface ServiceProvider {

	/**
	 * Binds factories into the container.
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function register( ServiceContainer $container );

	/**
	 * Resolves services and adds hooks. Runs after every provider registered.
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function boot( ServiceContainer $container );
}
