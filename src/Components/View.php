<?php
/**
 * Template loader.
 *
 * @package SpamLens
 */

namespace SpamLens\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a PHP template from views/.
 */
class View {

	/**
	 * Prints views/<name>.php with $args available as `$args`.
	 *
	 * @param string $name Template name without extension, e.g. `admin/app`.
	 * @param array  $args Variables for the template.
	 */
	public static function load( string $name, array $args = array() ) {
		$file = SPAMLENS_DIR . 'views/' . $name . '.php';
		if ( ! preg_match( '#^[a-z0-9/_-]+$#', $name ) || ! file_exists( $file ) ) {
			return;
		}
		( static function ( $spamlens_view_file, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Used by the template.
			include $spamlens_view_file;
		} )( $file, $args );
	}
}
