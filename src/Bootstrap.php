<?php
/**
 * Plugin bootstrap.
 *
 * @package SpamLens
 */

namespace SpamLens;

use SpamLens\Container\AdminServiceProvider;
use SpamLens\Container\CoreServiceProvider;
use SpamLens\Container\ServiceContainer;
use SpamLens\Container\ServiceProvider;
use SpamLens\Service\Installation\InstallManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers lifecycle hooks and, on `plugins_loaded`, runs the service providers.
 */
class Bootstrap {

	/**
	 * Whether init() already ran.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Whether setup() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Service providers, registered in order.
	 *
	 * @var string[]
	 */
	private static $providers = array(
		CoreServiceProvider::class,
		AdminServiceProvider::class,
	);

	/**
	 * Entry point, called once from the main plugin file.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		register_activation_hook( SPAMLENS_FILE, array( InstallManager::class, 'activate' ) );
		register_deactivation_hook( SPAMLENS_FILE, array( InstallManager::class, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'setup' ) );
	}

	/**
	 * `plugins_loaded` callback: registers and boots every provider, then fires `spamlens_loaded`.
	 */
	public static function setup() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$container = self::container();
		$providers = array();
		foreach ( self::$providers as $class ) {
			$provider = new $class();
			if ( $provider instanceof ServiceProvider ) {
				$provider->register( $container );
				$providers[] = $provider;
			}
		}
		foreach ( $providers as $provider ) {
			$provider->boot( $container );
		}

		/**
		 * Fires after SpamLens has registered its services.
		 *
		 * @param ServiceContainer $container The service container.
		 */
		do_action( 'spamlens_loaded', $container );
	}

	/**
	 * The service container.
	 *
	 * @return ServiceContainer
	 */
	public static function container(): ServiceContainer {
		return ServiceContainer::getInstance();
	}

	/**
	 * A service from the container.
	 *
	 * @param string $id Service id (classifier, integrations, rest, admin, assets, ...).
	 * @return mixed|null
	 */
	public static function get( string $id ) {
		return self::container()->get( $id );
	}
}
