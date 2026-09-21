<?php
/**
 * Plugin Name:       Jev Guard
 * Plugin URI:        https://github.com/navidkashani/jev-guard
 * Description:       Spam protection for comments, reviews and Contact Form 7 powered by Jev, TypeSafe AI's decision model.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Navid Kashani
 * Author URI:        https://github.com/navidkashani
 * Update URI:        https://github.com/navidkashani/jev-guard
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jev-guard
 * Domain Path:       /languages
 *
 * @package JevGuard
 */

defined( 'ABSPATH' ) || exit;

define( 'JEV_GUARD_VERSION', '1.0.0' );
define( 'JEV_GUARD_FILE', __FILE__ );
define( 'JEV_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'JEV_GUARD_URL', plugin_dir_url( __FILE__ ) );
define( 'JEV_GUARD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for the JevGuard namespace (maps to includes/).
 *
 * @param string $class_name Fully qualified class name.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'JevGuard\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = JEV_GUARD_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_deactivation_hook( __FILE__, array( 'JevGuard\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'JevGuard\\Plugin', 'boot_instance' ) );
