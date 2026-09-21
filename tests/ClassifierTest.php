<?php
/**
 * Classifier and Verdict tests.
 *
 * @package JevGuard
 */

use JevGuard\Api\Client;
use JevGuard\Classifier;
use JevGuard\Settings;
use JevGuard\Submission;
use JevGuard\Verdict;

/**
 * State building, questions, caching and the decision policy.
 */
class ClassifierTest extends JevGuard_TestCase {

	/**
	 * A comment submission on a real post.
	 *
	 * @param array $overrides Property overrides.
	 * @return Submission
	 */
	private function submission( array $overrides = array() ): Submission {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'How to tune spam thresholds',
				'post_content' => str_repeat( 'Some content about thresholds. ', 30 ),
			)
		);
		wp_set_post_terms( $post_id, array( 'spam', 'wordpress' ), 'post_tag' );

		$s               = new Submission();
		$s->post_id      = $post_id;
		$s->content      = 'Great post, visit my site http://cheap-watches.example.com/ and https://www.example.org/page';
		$s->author_name  = 'Bob';
		$s->author_email = 'bob@example.com';
		$s->author_url   = 'http://www.bobs-site.example.net/';
		$s->ip           = '203.0.113.9';
		$s->user_agent   = 'Mozilla/5.0';
		$s->referer      = 'http://example.org/?p=1';
		foreach ( $overrides as $k => $v ) {
			$s->$k = $v;
		}
		return $s;
	}

	public function test_state_contains_site_context_author_and_links() {
		Settings::update( array( 'site_context' => 'A tech blog.' ) );
		$state = ( new Classifier() )->build_state( $this->submission() );

		$this->assertSame( 'comment', $state['kind'] );
		$this->assertSame( 'A tech blog.', $state['site']['notes'] );
		$this->assertSame( get_locale(), $state['site']['language'] );
		$this->assertSame( 'How to tune spam thresholds', $state['context']['post_title'] );
		$this->assertLessThanOrEqual( 301, mb_strlen( $state['context']['post_excerpt'] ) );
		$this->assertEqualSets( array( 'spam', 'wordpress' ), $state['context']['post_tags'] );
		$this->assertFalse( $state['context']['is_reply'] );

		$this->assertSame( 'Bob', $state['author']['name'] );
		$this->assertSame( 'bob@example.com', $state['author']['email'] );
		$this->assertSame( 'bobs-site.example.net', $state['author']['url_host'] );
		$this->assertFalse( $state['author']['is_registered_user'] );
		$this->assertSame( 0, $state['author']['previously_approved_comments'] );

		$this->assertSame( 2, $state['links']['count'] );
		$this->assertEqualSets( array( 'cheap-watches.example.com', 'example.org' ), $state['links']['hosts'] );
		$this->assertTrue( $state['links']['external'] );

		$this->assertArrayHasKey( 'comment', $state );
		$this->assertArrayNotHasKey( 'ip', $state['request'] );
		$this->assertSame( 'Mozilla/5.0', $state['request']['user_agent'] );
	}

	public function test_privacy_toggles_strip_email_ip_and_user_agent() {
		Settings::update(
			array(
				'send_email'      => false,
				'send_ip'         => true,
				'send_user_agent' => false,
			)
		);
		$state = ( new Classifier() )->build_state( $this->submission() );
		$this->assertArrayNotHasKey( 'email', $state['author'] );
		$this->assertSame( '203.0.113.9', $state['request']['ip'] );
		$this->assertArrayNotHasKey( 'user_agent', $state['request'] );
		$this->assertArrayNotHasKey( 'referer', $state['request'] );
	}

	public function test_content_is_capped_at_6000_chars() {
		$state = ( new Classifier() )->build_state( $this->submission( array( 'content' => str_repeat( 'a', 9000 ) ) ) );
		$this->assertSame( 6001, mb_strlen( $state['comment'] ) );
	}

	public function test_previously_approved_count_and_roles() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id = self::factory()->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'user_id'          => $user_id,
				'comment_approved' => 1,
			)
		);
		$state = ( new Classifier() )->build_state( $this->submission( array( 'user_id' => $user_id ) ) );
		$this->assertTrue( $state['author']['is_registered_user'] );
		$this->assertSame( array( 'subscriber' ), $state['author']['roles'] );
		$this->assertSame( 1, $state['author']['previously_approved_comments'] );
	}

	public function test_reply_context_includes_parent_excerpt() {
		$post_id = self::factory()->post->create();
		$parent  = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'Parent says hello',
			)
		);
		$state = ( new Classifier() )->build_state( $this->submission( array( 'parent_id' => $parent ) ) );
		$this->assertTrue( $state['context']['is_reply'] );
		$this->assertSame( 'Parent says hello', $state['context']['in_reply_to'] );
	}

	// --- Page context ---

	/**
	 * State context for a submission on the given post.
	 *
	 * @param int   $post_id   Post id.
	 * @param array $overrides Submission overrides.
	 * @return array
	 */
	private function context_for( int $post_id, array $overrides = array() ): array {
		$state = ( new Classifier() )->build_state( $this->submission( array_merge( array( 'post_id' => $post_id ), $overrides ) ) );
		return $state['context'];
	}

	public function test_non_public_posts_send_type_only() {
		register_post_type( 'jev_secret', array( 'public' => false ) );
		$posts = array(
			'draft'   => self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			'private' => self::factory()->post->create( array( 'post_status' => 'private' ) ),
			'future'  => self::factory()->post->create(
				array(
					'post_status' => 'future',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				)
			),
			'cpt'     => self::factory()->post->create(
				array(
					'post_type'   => 'jev_secret',
					'post_status' => 'publish',
				)
			),
		);
		foreach ( $posts as $case => $post_id ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => 'Secret title',
					'post_content' => str_repeat( 'Secret body text. ', 30 ),
				)
			);
			$ctx = $this->context_for( $post_id );
			$this->assertSame( array( 'post_type', 'is_reply' ), array_keys( $ctx ), $case );
			$this->assertSame( 'cpt' === $case ? 'jev_secret' : 'post', $ctx['post_type'], $case );
		}
		unregister_post_type( 'jev_secret' );
	}

	public function test_password_protected_post_sends_title_and_terms_only() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Members recap',
				'post_content'  => '<h2>Agenda</h2>' . str_repeat( 'Paid content. ', 30 ),
				'post_password' => 'hunter2',
			)
		);
		wp_set_post_terms( $post_id, array( 'members' ), 'post_tag' );

		$ctx = $this->context_for( $post_id );
		$this->assertSame( 'Members recap', $ctx['post_title'] );
		$this->assertSame( array( 'members' ), $ctx['post_tags'] );
		$this->assertArrayNotHasKey( 'post_excerpt', $ctx );
		$this->assertArrayNotHasKey( 'post_outline', $ctx );
	}

	public function test_attachment_inherits_parent_visibility() {
		$draft      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$attachment = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $draft,
				'post_title'  => 'Photo',
			)
		);
		$ctx = $this->context_for( $attachment );
		$this->assertSame( 'attachment', $ctx['post_type'] );
		$this->assertArrayNotHasKey( 'post_title', $ctx );

		$published  = self::factory()->post->create();
		$attachment = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $published,
				'post_title'  => 'Photo',
			)
		);
		$ctx = $this->context_for( $attachment );
		$this->assertSame( 'Photo', $ctx['post_title'] );
	}

	public function test_title_level_omits_excerpt_and_outline() {
		Settings::update( array( 'page_context' => 'title' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Long read',
				'post_content' => '<h2>Part one</h2>' . str_repeat( 'Body text. ', 40 ),
			)
		);
		$parent = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'Parent says hello',
			)
		);
		$ctx = $this->context_for( $post_id, array( 'parent_id' => $parent ) );
		$this->assertSame( 'Long read', $ctx['post_title'] );
		$this->assertArrayNotHasKey( 'post_excerpt', $ctx );
		$this->assertArrayNotHasKey( 'post_outline', $ctx );
		$this->assertSame( 'Parent says hello', $ctx['in_reply_to'] );
	}

	public function test_extended_level_sends_longer_excerpt() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => str_repeat( 'word ', 400 ),
				'post_excerpt' => '',
			)
		);

		$standard = $this->context_for( $post_id );
		$this->assertLessThanOrEqual( 301, mb_strlen( $standard['post_excerpt'] ) );

		Settings::update( array( 'page_context' => 'extended' ) );
		$extended = $this->context_for( $post_id );
		$this->assertGreaterThanOrEqual( 302, mb_strlen( $extended['post_excerpt'] ) );
		$this->assertLessThanOrEqual( 1201, mb_strlen( $extended['post_excerpt'] ) );
	}

	public function test_outline_lists_h2_h3_headings() {
		$content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Main title</h1><!-- /wp:heading -->';
		$content .= '<!-- wp:heading --><h2 class="wp-block-heading">' . str_repeat( 'L', 100 ) . '</h2><!-- /wp:heading -->';
		$content .= '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading"><strong>Tom</strong> &amp; Jerry</h3><!-- /wp:heading -->';
		$content .= '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Deep dive</h4><!-- /wp:heading -->';
		for ( $i = 1; $i <= 10; $i++ ) {
			$content .= '<!-- wp:heading --><h2 class="wp-block-heading">Section ' . $i . '</h2><!-- /wp:heading -->';
		}
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );
		$outline = $this->context_for( $post_id )['post_outline'];

		$this->assertCount( Classifier::MAX_HEADINGS, $outline );
		$this->assertSame( str_repeat( 'L', Classifier::MAX_HEADING ) . '…', $outline[0] );
		$this->assertSame( 'Tom & Jerry', $outline[1] );
		$this->assertSame( 'Section 1', $outline[2] );
		$this->assertNotContains( 'Main title', $outline );
		$this->assertNotContains( 'Deep dive', $outline );
	}

	public function test_excerpt_skips_short_lead_paragraphs() {
		$long    = str_repeat( 'A proper opening paragraph. ', 5 );
		$post_id = self::factory()->post->create(
			array(
				'post_content' => "Updated 2026\n\nThis post contains affiliate links.\n\n" . $long . "\n\nSecond paragraph.",
				'post_excerpt' => '',
			)
		);
		$excerpt = $this->context_for( $post_id )['post_excerpt'];
		$this->assertStringStartsWith( 'A proper opening paragraph.', $excerpt );
		$this->assertStringNotContainsString( 'affiliate', $excerpt );
	}

	public function test_excerpt_keeps_all_short_paragraphs() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => "Roses are red\n\nViolets are blue\n\nSugar is sweet\n\nAnd so are you",
				'post_excerpt' => '',
			)
		);
		$this->assertStringStartsWith( 'Roses are red', $this->context_for( $post_id )['post_excerpt'] );
	}

	public function test_excerpt_is_entity_decoded() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p>Tom &amp; Jerry&#8217;s guide to thresholds, ' . str_repeat( 'and more. ', 10 ) . '</p>',
				'post_excerpt' => '',
			)
		);
		$this->assertStringStartsWith( 'Tom & Jerry’s guide', $this->context_for( $post_id )['post_excerpt'] );
	}

	public function test_post_context_filter_can_blank_context() {
		add_filter( 'jev_guard_post_context', '__return_empty_array' );
		$ctx = $this->context_for( self::factory()->post->create( array( 'post_title' => 'Paywalled' ) ) );
		remove_filter( 'jev_guard_post_context', '__return_empty_array' );
		$this->assertSame( array( 'is_reply' => false ), $ctx );
	}

	public function test_invalid_request_retries_with_title_only_context() {
		update_option( 'comment_max_links', '10' );
		JevGuard_HttpStub::queue( 422, array( 'message' => 'state too large' ) );
		JevGuard_HttpStub::queue_answers( 0.2, 0.9, 0.0, 'legitimate' );

		$verdict = ( new Classifier() )->classify( $this->submission() );

		$this->assertInstanceOf( Verdict::class, $verdict );
		$this->assertSame( 'allow', $verdict->decision );
		$this->assertCount( 2, JevGuard_HttpStub::$requests );

		$first = (array) json_decode( JevGuard_HttpStub::$requests[0]['args']['body'], true );
		$this->assertArrayHasKey( 'post_excerpt', $first['state']['context'] );
		$second = JevGuard_HttpStub::last_body();
		$this->assertSame( 'How to tune spam thresholds', $second['state']['context']['post_title'] );
		$this->assertArrayNotHasKey( 'post_excerpt', $second['state']['context'] );
		$this->assertArrayNotHasKey( 'post_outline', $second['state']['context'] );
	}

	public function test_invalid_request_without_page_text_is_not_retried() {
		Settings::update( array( 'page_context' => 'title' ) );
		JevGuard_HttpStub::queue( 422, '' );
		$result = ( new Classifier() )->classify( $this->submission() );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertCount( 1, JevGuard_HttpStub::$requests );
	}

	public function test_form_state_uses_submission_key_and_fields() {
		$s              = new Submission();
		$s->integration = 'cf7';
		$s->kind        = 'contact_form';
		$s->form_name   = 'Contact';
		$s->content     = 'Hello, I need help with my order.';
		$s->fields      = array( 'your-subject' => 'Order' );
		$state          = ( new Classifier() )->build_state( $s );

		$this->assertSame( 'contact_form', $state['kind'] );
		$this->assertSame( 'Contact', $state['context']['form_name'] );
		$this->assertSame( array( 'your-subject' => 'Order' ), $state['context']['fields'] );
		$this->assertArrayHasKey( 'submission', $state );
		$this->assertArrayNotHasKey( 'comment', $state );
		$this->assertArrayNotHasKey( 'previously_approved_comments', $state['author'] );

		$questions = ( new Classifier() )->questions( 'submission' );
		$this->assertStringContainsString( '`submission`', $questions['spam']['instructions']['question'] );
	}

	public function test_questions_shape_matches_wire_format() {
		$q = ( new Classifier() )->questions( 'comment' );
		$this->assertSame( 'noul', $q['spam']['type'] );
		$this->assertIsArray( $q['spam']['criteria']['true']['includes'] );
		$this->assertSame( 'noul', $q['genuine']['type'] );
		$this->assertSame( 'choice', $q['category']['type'] );
		$this->assertArrayHasKey( 'seo_link_spam', $q['category']['criteria'] );
		$this->assertSame( 'score', $q['abuse']['type'] );
		$this->assertCount( 3, $q['abuse']['criteria'] );

		Settings::update( array( 'hold_abusive' => false ) );
		$q = ( new Classifier() )->questions( 'comment' );
		$this->assertArrayNotHasKey( 'abuse', $q );
	}

	public function test_extract_links_handles_bare_and_html_links() {
		$links = Classifier::extract_links( 'see <a href="https://A.example.com/x">this</a> and www.b.example.com/y and http://a.example.com/z', 'example.org' );
		$this->assertSame( 3, $links['count'] );
		$this->assertEqualSets( array( 'a.example.com', 'b.example.com' ), $links['hosts'] );
		$this->assertTrue( $links['external'] );

		$this->assertSame( array( 'count' => 0 ), Classifier::extract_links( 'no links here', 'example.org' ) );

		$internal = Classifier::extract_links( 'http://example.org/page', 'example.org' );
		$this->assertFalse( $internal['external'] );
	}

	/**
	 * Decision matrix.
	 *
	 * @dataProvider decisions
	 */
	public function test_decide( $spam, $genuine, $abuse, $links, $expected, $reason ) {
		$verdict = Verdict::from_answers( JevGuard_HttpStub::answers_body( $spam, $genuine, $abuse )['answers'], 'jev-1.13.0' );
		$verdict->decide(
			array(
				'spam_threshold' => 0.85,
				'hold_threshold' => 0.5,
				'hold_abusive'   => true,
				'link_count'     => $links,
				'max_links'      => 2,
			)
		);
		$this->assertSame( $expected, $verdict->decision, "spam=$spam genuine=$genuine abuse=$abuse links=$links" );
		$this->assertSame( $reason, $verdict->reason );
	}

	public function decisions() {
		return array(
			'clear'                   => array( 0.10, 0.9, 0.0, 0, 'allow', 'clear' ),
			'just below hold'         => array( 0.49, 0.5, 0.0, 0, 'allow', 'clear' ),
			'hold boundary'           => array( 0.50, 0.5, 0.0, 0, 'hold', 'borderline' ),
			'just below spam'         => array( 0.84, 0.1, 0.0, 0, 'hold', 'borderline' ),
			'spam boundary'           => array( 0.85, 0.1, 0.0, 0, 'spam', 'threshold' ),
			'spam but genuine'        => array( 0.95, 0.8, 0.0, 0, 'hold', 'conflict' ),
			'genuine missing'         => array( 0.95, null, 0.0, 0, 'spam', 'threshold' ),
			'abusive'                 => array( 0.10, 0.9, 1.5, 0, 'hold', 'abusive' ),
			'rude only'               => array( 0.10, 0.9, 1.4, 0, 'allow', 'clear' ),
			'link heavy with signal'  => array( 0.55, 0.9, 0.0, 2, 'spam', 'link_heavy' ),
			'link heavy, no signal'   => array( 0.40, 0.9, 0.0, 3, 'allow', 'clear' ),
			'link heavy beats conflict' => array( 0.95, 0.9, 0.0, 2, 'spam', 'link_heavy' ),
		);
	}

	public function test_link_rule_disabled_when_max_links_is_zero() {
		$verdict = Verdict::from_answers( JevGuard_HttpStub::answers_body( 0.6, 0.9, 0.0 )['answers'] );
		$verdict->decide(
			array(
				'spam_threshold' => 0.85,
				'hold_threshold' => 0.5,
				'link_count'     => 5,
				'max_links'      => 0,
			)
		);
		$this->assertSame( 'hold', $verdict->decision );
	}

	public function test_abuse_ignored_when_hold_abusive_off() {
		$verdict = Verdict::from_answers( JevGuard_HttpStub::answers_body( 0.1, 0.9, 2.0 )['answers'] );
		$verdict->decide(
			array(
				'spam_threshold' => 0.85,
				'hold_threshold' => 0.5,
				'hold_abusive'   => false,
			)
		);
		$this->assertSame( 'allow', $verdict->decision );
	}

	public function test_decision_filter_can_override() {
		add_filter( 'jev_guard_decision', static function () { return 'hold'; } );
		$verdict = Verdict::from_answers( JevGuard_HttpStub::answers_body( 0.99, 0.0, 0.0 )['answers'] );
		$verdict->decide( array() );
		$this->assertSame( 'hold', $verdict->decision );
		$this->assertSame( 'filter', $verdict->reason );
		remove_all_filters( 'jev_guard_decision' );
	}

	public function test_verdict_round_trips_through_array() {
		$verdict           = Verdict::from_answers( JevGuard_HttpStub::answers_body( 0.94, 0.1, 0.0, 'seo_link_spam' )['answers'], 'jev-1.13.0', array( 'input_tokens' => 10 ) );
		$verdict->guid     = 'abc';
		$verdict->links    = 2;
		$verdict->decide( array() );
		$restored = Verdict::from_array( $verdict->to_array() );

		$this->assertSame( 'abc', $restored->guid );
		$this->assertSame( 'spam', $restored->decision );
		$this->assertSame( 0.94, $restored->spam );
		$this->assertSame( 'seo_link_spam', $restored->category );
		$this->assertSame( 0.9, $restored->category_confidence );
		$this->assertSame( 2, $restored->links );
		$this->assertSame( '94% spam · SEO link spam', $restored->label() );
	}

	public function test_classify_calls_api_and_caches() {
		update_option( 'comment_max_links', '10' ); // Keep the link-heavy rule out of this test.
		JevGuard_HttpStub::queue_answers( 0.94 );
		$classifier = new Classifier();
		$s          = $this->submission();

		$verdict = $classifier->classify( $s );
		$this->assertInstanceOf( Verdict::class, $verdict );
		$this->assertSame( 'spam', $verdict->decision );
		$this->assertSame( 'jev-1.13.0', $verdict->model );
		$this->assertFalse( $verdict->cached );
		$this->assertSame( 2, $verdict->links );
		$this->assertCount( 1, JevGuard_HttpStub::$requests );

		// Same submission within 10 minutes: no second request.
		$again = $classifier->classify( $s );
		$this->assertTrue( $again->cached );
		$this->assertSame( 'spam', $again->decision );
		$this->assertCount( 1, JevGuard_HttpStub::$requests );

		// Cached answers are re-decided with the current thresholds.
		Settings::update( array( 'spam_threshold' => 0.99 ) );
		$redecided = $classifier->classify( $s );
		$this->assertTrue( $redecided->cached );
		$this->assertSame( 'hold', $redecided->decision );

		// skip_cache forces a request.
		JevGuard_HttpStub::queue_answers( 0.1 );
		$fresh = $classifier->classify( $s, array( 'skip_cache' => true ) );
		$this->assertFalse( $fresh->cached );
		$this->assertCount( 2, JevGuard_HttpStub::$requests );
	}

	public function test_classify_returns_wp_error_on_failure() {
		JevGuard_HttpStub::queue( 401, '' );
		$result = ( new Classifier() )->classify( $this->submission() );
		$this->assertWPError( $result );
		$this->assertSame( 'auth', $result->get_error_code() );
	}

	public function test_request_body_matches_wire_format() {
		JevGuard_HttpStub::queue_answers( 0.5 );
		( new Classifier() )->classify( $this->submission() );
		$body = JevGuard_HttpStub::last_body();
		$this->assertSame( array( 'model', 'state', 'questions' ), array_keys( $body ) );
		$this->assertSame( 'comment', $body['state']['kind'] );
		$this->assertArrayHasKey( 'comment', $body['state'] );
		$this->assertSame( 'noul', $body['questions']['spam']['type'] );
	}

	public function test_corpus_fixture_produces_expected_tiers() {
		$corpus = json_decode( file_get_contents( __DIR__ . '/fixtures/corpus.json' ), true );
		$this->assertNotEmpty( $corpus['items'] );
		$post_id    = self::factory()->post->create();
		$classifier = new Classifier();

		foreach ( $corpus['items'] as $item ) {
			$stub = $item['stub'];
			JevGuard_HttpStub::queue_answers( $stub['spam'], $stub['genuine'], $stub['abuse'], $stub['category'] );

			$s               = new Submission();
			$s->post_id      = $post_id;
			$s->content      = $item['content'];
			$s->author_name  = $item['author'];
			$s->author_email = $item['email'];
			$s->author_url   = $item['url'];

			$verdict = $classifier->classify( $s, array( 'skip_cache' => true ) );
			$this->assertInstanceOf( Verdict::class, $verdict, $item['id'] );
			$this->assertSame( $item['expected'], $verdict->decision, $item['id'] . ' (' . $verdict->reason . ')' );
		}
	}

	public function test_sample_submission_is_spammy_and_selfcontained() {
		$s     = Submission::sample();
		$state = ( new Classifier( new Client( array( 'api_key' => 'k' ) ) ) )->build_state( $s );
		$this->assertSame( 1, $state['links']['count'] );
		$this->assertArrayNotHasKey( 'post_title', $state['context'] );
	}
}
