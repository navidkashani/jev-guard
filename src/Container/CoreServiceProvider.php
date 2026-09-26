<?php
/**
 * Core services.
 *
 * @package SpamLens
 */

namespace SpamLens\Container;

use SpamLens\Service\Api\Client;
use SpamLens\Service\Classifier\Classifier;
use SpamLens\Service\Integrations\IntegrationManager;
use SpamLens\Service\Privacy\Privacy;
use SpamLens\Service\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Services every request needs: the classifier, the integrations that feed it, privacy hooks and the REST API.
 */
class CoreServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function register( ServiceContainer $container ) {
		$container->register(
			'classifier',
			static function () {
				return new Classifier( new Client() );
			}
		);
		$container->register(
			'integrations',
			static function ( ServiceContainer $c ) {
				return new IntegrationManager( $c->get( 'classifier' ) );
			}
		);
		$container->register(
			'privacy',
			static function () {
				return new Privacy();
			}
		);
		$container->register(
			'rest',
			static function ( ServiceContainer $c ) {
				return new RestController( $c->get( 'integrations' ) );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function boot( ServiceContainer $container ) {
		$container->get( 'integrations' )->register();
		$container->get( 'privacy' )->register();
		$container->get( 'rest' )->register();
	}
}
