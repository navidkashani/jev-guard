<?php
/**
 * Uninstall tests.
 *
 * @package SpamLens
 */

use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;

/**
 * uninstall.php removes everything the plugin stored.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends SpamLens_TestCase {

	public function test_uninstall_removes_plugin_data() {
		$comment = self::factory()->comment->create();
		$user    = self::factory()->user->create();
		Settings::update( array( 'provider' => 'vercel' ) );
		Stats::bump( 'checked' );
		update_option( 'spamlens_version', '1.0.0' );
		update_option( 'spamlens_models', array( 'jev-1.13.0' ) );
		update_option( 'spamlens_last_error', array( 'code' => 'auth' ) );
		update_comment_meta( $comment, Comments::META, array( 'decision' => 'spam' ) );
		update_comment_meta( $comment, Comments::META_HISTORY, array() );
		update_user_meta( $user, 'spamlens_dismissed_setup', 1 );
		set_transient( 'spamlens_cache_test', 1, 60 );
		wp_schedule_single_event( time() + 60, Comments::CRON_HOOK );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'spamlens/spamlens.php' );
		}
		include dirname( __DIR__ ) . '/uninstall.php';
		wp_cache_flush();

		foreach ( array( Settings::OPTION, Stats::OPTION, 'spamlens_version', 'spamlens_last_error', 'spamlens_models' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( '', get_comment_meta( $comment, Comments::META, true ) );
		$this->assertSame( '', get_user_meta( $user, 'spamlens_dismissed_setup', true ) );
		$this->assertFalse( get_transient( 'spamlens_cache_test' ) );
		$this->assertFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
	}
}
