<?php
/**
 * Plugin constants.
 *
 * @package SpamLens
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SPAMLENS_VERSION' ) ) {
	define( 'SPAMLENS_VERSION', '1.1.0' );
}

if ( ! defined( 'SPAMLENS_FILE' ) ) {
	define( 'SPAMLENS_FILE', dirname( __DIR__ ) . '/spamlens.php' );
}

if ( ! defined( 'SPAMLENS_DIR' ) ) {
	define( 'SPAMLENS_DIR', plugin_dir_path( SPAMLENS_FILE ) );
}

if ( ! defined( 'SPAMLENS_URL' ) ) {
	define( 'SPAMLENS_URL', plugin_dir_url( SPAMLENS_FILE ) );
}

if ( ! defined( 'SPAMLENS_BASENAME' ) ) {
	define( 'SPAMLENS_BASENAME', plugin_basename( SPAMLENS_FILE ) );
}
