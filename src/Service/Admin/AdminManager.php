<?php
/**
 * Settings screen registration.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Admin;

use SpamLens\Components\View;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → SpamLens. PHP prints the mount point only; the screen is the React app in resources/react
 * (built into public/app/, enqueued by AssetManager) and talks to the REST routes in RestController.
 */
class AdminManager {

	const PAGE = 'spamlens';
	const HOOK = 'settings_page_spamlens';
	const CAP  = 'manage_options';

	/**
	 * Screens of the app, the first is the default.
	 */
	const TABS = array( 'overview', 'settings', 'calibration' );

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ), 100 );
		add_filter( 'update_footer', array( $this, 'footer_text' ), 100 );
		add_filter( 'plugin_action_links_' . SPAMLENS_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Adds the Settings submenu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'SpamLens', 'spamlens' ),
			__( 'SpamLens', 'spamlens' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the mount point.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		View::load(
			'admin/app',
			array(
				'built' => file_exists( SPAMLENS_DIR . 'public/app/settings.js' ),
			)
		);
	}

	/**
	 * Whether the current screen is the plugin's own.
	 *
	 * @return bool
	 */
	public static function is_own_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::HOOK === $screen->id;
	}

	/**
	 * Marks the plugin's screen so the app's stylesheet can take the whole content area.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		return self::is_own_screen() ? $classes . ' spamlens-screen' : $classes;
	}

	/**
	 * The app draws its own footer, so WordPress's footer lines step aside on its screen.
	 *
	 * @param string $text Footer text.
	 * @return string
	 */
	public function footer_text( $text ) {
		return self::is_own_screen() ? '' : $text;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'spamlens' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of one of the app's screens.
	 *
	 * @param string $tab overview|settings|calibration.
	 * @return string
	 */
	public static function url( string $tab = '' ): string {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		return in_array( $tab, self::TABS, true ) && 'overview' !== $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}
}
