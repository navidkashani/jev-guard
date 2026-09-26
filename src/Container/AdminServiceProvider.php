<?php
/**
 * Admin services.
 *
 * @package SpamLens
 */

namespace SpamLens\Container;

use SpamLens\Service\Admin\AdminManager;
use SpamLens\Service\Admin\CommentsScreen;
use SpamLens\Service\Admin\Notices;
use SpamLens\Service\Assets\AssetManager;

defined( 'ABSPATH' ) || exit;

/**
 * Services used only in wp-admin: the settings screen, its assets, the comments list additions and notices.
 */
class AdminServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function register( ServiceContainer $container ) {
		$container->register(
			'admin',
			static function () {
				return new AdminManager();
			}
		);
		$container->register(
			'assets',
			static function () {
				return new AssetManager();
			}
		);
		$container->register(
			'comments_screen',
			static function ( ServiceContainer $c ) {
				return new CommentsScreen( $c->get( 'integrations' ) );
			}
		);
		$container->register(
			'notices',
			static function () {
				return new Notices();
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ServiceContainer $container Container.
	 */
	public function boot( ServiceContainer $container ) {
		if ( ! is_admin() ) {
			return;
		}
		foreach ( array( 'admin', 'assets', 'comments_screen', 'notices' ) as $id ) {
			$container->get( $id )->register();
		}
	}
}
