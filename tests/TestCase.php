<?php
/**
 * Base test case.
 *
 * @package SpamLens
 */

use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;

/**
 * Shared setup: stubbed HTTP, configured key, clean options.
 */
abstract class SpamLens_TestCase extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		SpamLens_HttpStub::reset();
		SpamLens_HttpStub::install();
		delete_option( Settings::OPTION );
		delete_option( Stats::OPTION );
		delete_option( 'spamlens_last_error' );
		delete_option( 'spamlens_models' );
		Settings::update( array( 'api_key' => 'test-key' ) );
		// Strings, as core compares option values strictly ('1' === get_option()).
		update_option( 'comment_moderation', '0' );
		update_option( 'comment_previously_approved', '0' );
		update_option( 'comment_max_links', '2' );
		update_option( 'disallowed_keys', '' );
		add_filter( 'comment_flood_filter', '__return_false' );
		Comments::reset_request_state();
		wp_clear_scheduled_hook( Comments::CRON_HOOK );
		$this->flush_transients();
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( 'SpamLens_HttpStub', 'intercept' ), 10 );
		remove_filter( 'comment_flood_filter', '__return_false' );
		wp_clear_scheduled_hook( Comments::CRON_HOOK );
		Comments::reset_request_state();
		$this->flush_transients();
		parent::tear_down();
	}

	/**
	 * Removes plugin transients (verdict cache, locks).
	 */
	protected function flush_transients() {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_spamlens_%' OR option_name LIKE '_transient_timeout_spamlens_%'" ); // phpcs:ignore
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		wp_cache_flush();
	}

	/**
	 * Comment data for `wp_new_comment()`.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	protected function comment_data( array $overrides = array() ): array {
		static $n = 0;
		++$n;
		$post_id = isset( $overrides['comment_post_ID'] ) ? $overrides['comment_post_ID'] : self::factory()->post->create();
		return array_merge(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Visitor ' . $n,
				'comment_author_email' => 'visitor' . $n . '@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Test comment number ' . $n . ' with some words.',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
				'comment_author_IP'    => '203.0.113.' . ( $n % 250 ),
				'comment_agent'        => 'Mozilla/5.0 (Test)',
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', 1 ),
			),
			$overrides
		);
	}

	/**
	 * The comments integration instance.
	 *
	 * @return Comments
	 */
	protected function comments(): Comments {
		return SpamLens\Bootstrap::get( 'integrations' )->comments();
	}

	/**
	 * History event slugs for a comment.
	 *
	 * @param int $comment_id Comment id.
	 * @return string[]
	 */
	protected function history_events( int $comment_id ): array {
		return array_map(
			static function ( $entry ) {
				return $entry['event'];
			},
			$this->comments()->history( $comment_id )
		);
	}
}
