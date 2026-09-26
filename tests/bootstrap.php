<?php
/**
 * PHPUnit bootstrap for the WordPress test suite (wp-env `tests-cli`).
 *
 * @package SpamLens
 */

$spamlens_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $spamlens_tests_dir ) {
	$spamlens_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $spamlens_tests_dir ) {
	$spamlens_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $spamlens_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$spamlens_tests_dir}. Run the suite via `npm run test:php` (wp-env) or set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $spamlens_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/spamlens.php';
		$cf7 = WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php';
		if ( file_exists( $cf7 ) ) {
			require $cf7;
		}
	}
);

require_once __DIR__ . '/HttpStub.php';

require $spamlens_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
