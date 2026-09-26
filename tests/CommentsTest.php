<?php
/**
 * Comments integration tests.
 *
 * @package SpamLens
 */

use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;

/**
 * End-to-end through `wp_new_comment()`, REST, rechecks, retry cron and moderator feedback.
 */
class CommentsTest extends SpamLens_TestCase {

	public function test_high_probability_comment_goes_to_spam_with_meta_and_history() {
		SpamLens_HttpStub::queue_answers( 0.95, 0.05, 0.0, 'seo_link_spam' );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );

		$this->assertIsInt( $id );
		$comment = get_comment( $id );
		$this->assertSame( 'spam', $comment->comment_approved );

		$meta = get_comment_meta( $id, Comments::META, true );
		$this->assertSame( 'spam', $meta['decision'] );
		$this->assertSame( 0.95, $meta['spam'] );
		$this->assertSame( 'jev-1.13.0', $meta['model'] );
		$this->assertNotEmpty( $meta['guid'] );
		$this->assertSame( 'spam', get_comment_meta( $id, Comments::META_DECISION, true ) );
		$this->assertSame( array( 'check-spam' ), $this->history_events( $id ) );

		$stats = Stats::get();
		$this->assertSame( 1, $stats['checked'] );
		$this->assertSame( 1, $stats['spam'] );
		$this->assertSame( 1, $stats['integrations']['comments']['spam'] );
	}

	public function test_borderline_comment_is_held() {
		SpamLens_HttpStub::queue_answers( 0.6, 0.4 );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$this->assertSame( array( 'check-hold' ), $this->history_events( $id ) );
		$this->assertSame( 1, Stats::get()['held'] );
	}

	public function test_low_probability_comment_keeps_site_default() {
		SpamLens_HttpStub::queue_answers( 0.1, 0.9, 0.0, 'legitimate' );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertSame( array( 'check-ham' ), $this->history_events( $id ) );

		// With moderation on, "allow" still means WordPress decides.
		update_option( 'comment_moderation', '1' );
		SpamLens_HttpStub::queue_answers( 0.1, 0.9, 0.0, 'legitimate' );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
	}

	public function test_api_error_fails_open_and_schedules_retry() {
		SpamLens_HttpStub::queue_error();
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );

		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertNotEmpty( get_comment_meta( $id, Comments::META_ERROR, true ) );
		$this->assertSame( 'error', get_comment_meta( $id, Comments::META_DECISION, true ) );
		$this->assertSame( array( 'check-error' ), $this->history_events( $id ) );
		$this->assertNotFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
		$this->assertSame( 1, Stats::get()['errors'] );
	}

	public function test_api_error_can_hold_instead() {
		Settings::update( array( 'on_error' => 'hold' ) );
		SpamLens_HttpStub::queue( 529, '' );
		SpamLens_HttpStub::queue( 529, '' );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
	}

	public function test_trash_from_disallowed_list_is_never_overridden_and_api_not_called() {
		update_option( 'disallowed_keys', 'viagra' );
		$id = wp_new_comment( wp_slash( $this->comment_data( array( 'comment_content' => 'buy viagra now' ) ) ), true );
		$this->assertSame( 'trash', get_comment( $id )->comment_approved );
		$this->assertCount( 0, SpamLens_HttpStub::$requests );
		$this->assertSame( array( 'skipped-disallowed' ), $this->history_events( $id ) );
	}

	public function test_moderator_and_empty_and_flagged_are_skipped() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = wp_new_comment( wp_slash( $this->comment_data( array( 'user_id' => $admin ) ) ), true );
		$this->assertSame( array( 'skipped-moderator' ), $this->history_events( $id ) );

		$id = wp_new_comment( wp_slash( $this->comment_data( array( 'akismet_result' => 'true' ) ) ), true );
		$this->assertSame( array( 'skipped-already_flagged' ), $this->history_events( $id ) );

		$this->assertCount( 0, SpamLens_HttpStub::$requests );

		$data = $this->comment_data( array( 'comment_content' => '   ' ) );
		$this->assertSame( 'empty', $this->comments()->skip_reason( $data ) );
	}

	public function test_previously_approved_author_skipped_only_when_enabled() {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author_email' => 'regular@example.com',
				'comment_approved'     => 1,
			)
		);
		$data = $this->comment_data( array( 'comment_author_email' => 'regular@example.com' ) );
		$this->assertSame( '', $this->comments()->skip_reason( $data ) );

		Settings::update( array( 'skip_previously_approved' => true ) );
		$this->assertSame( 'previously_approved', $this->comments()->skip_reason( $data ) );
	}

	public function test_pingbacks_respect_setting() {
		$data = $this->comment_data( array( 'comment_type' => 'pingback' ) );
		$this->assertSame( '', $this->comments()->skip_reason( $data ) );
		Settings::update( array( 'check_pingbacks' => false ) );
		$this->assertSame( 'pingback', $this->comments()->skip_reason( $data ) );
	}

	public function test_disabled_integration_or_missing_key_leaves_comment_untouched() {
		Settings::update( array( 'integrations' => array( 'comments' => false, 'cf7' => true ) ) );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '', get_comment_meta( $id, Comments::META, true ) );
		$this->assertCount( 0, SpamLens_HttpStub::$requests );

		Settings::update( array( 'integrations' => array( 'comments' => true, 'cf7' => true ), 'api_key' => '' ) );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '', get_comment_meta( $id, Comments::META, true ) );
		$this->assertCount( 0, SpamLens_HttpStub::$requests );
	}

	public function test_rest_path_sets_status_and_meta() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$post_id = self::factory()->post->create();

		SpamLens_HttpStub::queue_answers( 0.97, 0.02, 0.0, 'scam_or_phishing' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'content', 'Claim your prize at http://prize.example.com/' );
		$request->set_param( 'author', $user );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = (int) $response->get_data()['id'];
		$this->assertSame( 'spam', get_comment( $id )->comment_approved );
		$this->assertSame( 'spam', get_comment_meta( $id, Comments::META_DECISION, true ) );
		$this->assertSame( array( 'check-spam' ), $this->history_events( $id ) );

		wp_set_current_user( 0 );
	}

	public function test_recheck_moves_to_spam_only_when_new_verdict_is_spam() {
		SpamLens_HttpStub::queue_answers( 0.1, 0.9, 0.0, 'legitimate' );
		$id = wp_new_comment( wp_slash( $this->comment_data() ), true );
		$this->assertSame( '1', get_comment( $id )->comment_approved );

		SpamLens_HttpStub::queue_answers( 0.6, 0.5 );
		$verdict = $this->comments()->recheck( $id, 'recheck' );
		$this->assertSame( 'hold', $verdict->decision );
		$this->assertSame( '1', get_comment( $id )->comment_approved, 'hold on recheck must not change status' );

		SpamLens_HttpStub::queue_answers( 0.95, 0.05 );
		$verdict = $this->comments()->recheck( $id, 'recheck' );
		$this->assertSame( 'spam', $verdict->decision );
		$this->assertSame( 'spam', get_comment( $id )->comment_approved );
		$this->assertSame( array( 'check-ham', 'recheck-hold', 'recheck-spam' ), $this->history_events( $id ) );
		$this->assertSame( '', get_comment_meta( $id, Comments::META_RECHECKING, true ) );
		// Our own status change is not a moderator override.
		$this->assertSame( 0, Stats::get()['fn'] );
	}

	public function test_recheck_error_keeps_original_error_timestamp_and_schedules_retry() {
		$id = self::factory()->comment->create( array( 'comment_approved' => 1 ) );
		update_comment_meta( $id, Comments::META_ERROR, 1000 );

		SpamLens_HttpStub::queue_error();
		$result = $this->comments()->recheck( $id, 'recheck' );
		$this->assertWPError( $result );
		$this->assertSame( '1000', (string) get_comment_meta( $id, Comments::META_ERROR, true ) );
		$this->assertSame( array( 'recheck-error' ), $this->history_events( $id ) );
		$this->assertNotFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
	}

	public function test_retry_queue_spams_approved_comment_and_leaves_held_one() {
		$approved = self::factory()->comment->create( array( 'comment_approved' => 1, 'comment_content' => 'retry me A' ) );
		$held     = self::factory()->comment->create( array( 'comment_approved' => 0, 'comment_content' => 'retry me B' ) );
		$spammed  = self::factory()->comment->create( array( 'comment_approved' => 'spam', 'comment_content' => 'retry me C' ) );
		$stale    = self::factory()->comment->create( array( 'comment_approved' => 0, 'comment_content' => 'retry me D' ) );
		update_comment_meta( $approved, Comments::META_ERROR, time() - 100 );
		update_comment_meta( $held, Comments::META_ERROR, time() - 50 );
		update_comment_meta( $spammed, Comments::META_ERROR, time() - 10 );
		update_comment_meta( $stale, Comments::META_ERROR, time() - 20 * DAY_IN_SECONDS );

		SpamLens_HttpStub::queue_answers( 0.95, 0.05 ); // $approved (oldest error first)
		SpamLens_HttpStub::queue_answers( 0.3, 0.7, 0.0, 'legitimate' ); // $held

		$this->comments()->run_retry_queue();

		$this->assertSame( 'spam', get_comment( $approved )->comment_approved );
		$this->assertSame( array( 'retry-spam' ), $this->history_events( $approved ) );
		$this->assertSame( '', get_comment_meta( $approved, Comments::META_ERROR, true ) );

		$this->assertSame( '0', get_comment( $held )->comment_approved );
		$this->assertSame( array( 'retry-ham' ), $this->history_events( $held ) );
		$this->assertSame( '', get_comment_meta( $held, Comments::META_ERROR, true ) );

		$this->assertSame( '', get_comment_meta( $spammed, Comments::META_ERROR, true ), 'spam rows are dropped without a request' );
		$this->assertSame( '', get_comment_meta( $stale, Comments::META_ERROR, true ), 'stale rows are dropped' );
		$this->assertCount( 2, SpamLens_HttpStub::$requests );
		$this->assertFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
	}

	public function test_retry_queue_stops_at_first_failure_and_reschedules() {
		$a = self::factory()->comment->create( array( 'comment_approved' => 1, 'comment_content' => 'retry X' ) );
		$b = self::factory()->comment->create( array( 'comment_approved' => 1, 'comment_content' => 'retry Y' ) );
		update_comment_meta( $a, Comments::META_ERROR, time() - 100 );
		update_comment_meta( $b, Comments::META_ERROR, time() - 50 );

		SpamLens_HttpStub::queue( 401, array( 'message' => 'bad key' ) );
		$this->comments()->run_retry_queue();

		$this->assertCount( 1, SpamLens_HttpStub::$requests );
		$this->assertNotEmpty( get_comment_meta( $a, Comments::META_ERROR, true ) );
		$this->assertNotEmpty( get_comment_meta( $b, Comments::META_ERROR, true ) );
		$this->assertNotFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
		$this->assertFalse( get_transient( Comments::RETRY_LOCK ), 'lock released' );
	}

	public function test_unspam_counts_false_positive_and_spam_counts_missed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$fp = self::factory()->comment->create( array( 'comment_approved' => 'spam' ) );
		update_comment_meta( $fp, Comments::META_DECISION, 'spam' );
		wp_unspam_comment( $fp );
		$this->assertSame( 1, Stats::get()['fp'] );
		$this->assertSame( array( 'user-unspam' ), $this->history_events( $fp ) );

		$fn = self::factory()->comment->create( array( 'comment_approved' => 1 ) );
		update_comment_meta( $fn, Comments::META_DECISION, 'allow' );
		wp_spam_comment( $fn );
		$this->assertSame( 1, Stats::get()['fn'] );
		$this->assertSame( array( 'user-spam' ), $this->history_events( $fn ) );

		// Trashing a Jev-spammed comment is not a false positive; spamming a held one is not a miss.
		$other = self::factory()->comment->create( array( 'comment_approved' => 'spam' ) );
		update_comment_meta( $other, Comments::META_DECISION, 'spam' );
		wp_trash_comment( $other );
		$held = self::factory()->comment->create( array( 'comment_approved' => 0 ) );
		update_comment_meta( $held, Comments::META_DECISION, 'hold' );
		wp_spam_comment( $held );
		$this->assertSame( 1, Stats::get()['fp'] );
		$this->assertSame( 1, Stats::get()['fn'] );

		wp_set_current_user( 0 );
	}

	public function test_classify_only_writes_nothing() {
		$id = self::factory()->comment->create( array( 'comment_approved' => 1 ) );
		SpamLens_HttpStub::queue_answers( 0.99, 0.01 );
		$verdict = $this->comments()->classify_only( $id );

		$this->assertSame( 'spam', $verdict->decision );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertSame( '', get_comment_meta( $id, Comments::META, true ) );
		$this->assertSame( array(), $this->history_events( $id ) );
		$this->assertSame( 0, Stats::get()['checked'] );
	}

	public function test_calibration_sample_excludes_moderators_and_pingbacks() {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create();
		$spam    = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 'spam' ) );
		$ham     = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 1 ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 1, 'user_id' => $admin ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 'spam', 'comment_type' => 'pingback' ) );

		$sample = $this->comments()->calibration_sample( 10 );
		$this->assertSame( array( $spam ), $sample['spam'] );
		$this->assertSame( array( $ham ), $sample['ham'] );
	}

	public function test_duplicate_submission_hits_cache_before_wordpress_rejects_it() {
		SpamLens_HttpStub::queue_answers( 0.95, 0.05 );
		$data = $this->comment_data( array( 'comment_content' => 'Same spam twice http://x.example.com/' ) );
		$first = wp_new_comment( wp_slash( $data ), true );
		$this->assertIsInt( $first );

		// preprocess_comment runs before the duplicate check, so the second attempt is classified again — from the cache.
		$second = wp_new_comment( wp_slash( $data ), true );
		$this->assertWPError( $second );
		$this->assertSame( 'comment_duplicate', $second->get_error_code() );
		$this->assertCount( 1, SpamLens_HttpStub::$requests );
	}
}
