<?php
/**
 * REST route tests.
 *
 * @package SpamLens
 */

use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Rest\RestController;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;

/**
 * Permissions, payloads and side effects of the spamlens/v1 routes.
 */
class RestControllerTest extends SpamLens_TestCase {

	/**
	 * Administrator id.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Subscriber id.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		do_action( 'rest_api_init' );
	}

	/**
	 * Runs a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under the namespace.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestController::NAMESPACE . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		foreach ( array( '/settings', '/test', '/calibration/sample', '/calibration/batch', '/stats', '/stats/reset', '/comments/check-pending' ) as $route ) {
			$this->assertArrayHasKey( '/' . RestController::NAMESPACE . $route, $routes, $route );
		}
	}

	public function test_settings_routes_need_manage_options() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/settings' )->get_status() );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->request( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/settings', array( 'settings' => array( 'provider' => 'vercel' ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/stats/reset' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/test' )->get_status() );
		$this->assertSame( 'typesafe', Settings::get( 'provider' ) );
	}

	public function test_get_settings_never_returns_the_key() {
		Settings::update( array( 'api_key' => 'sk-super-secret-value-1234' ) );
		wp_set_current_user( $this->admin );

		$response = $this->request( 'GET', '/settings' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'api_key', $data['values'] );
		$this->assertStringNotContainsString( 'super-secret', wp_json_encode( $data ) );
		$this->assertTrue( $data['key']['stored'] );
		$this->assertSame( 'sk-s', substr( $data['key']['hint'], 0, 4 ) );
		$this->assertSame( '1234', substr( $data['key']['hint'], -4 ) );
		$this->assertNotEmpty( $data['providers'] );
		$this->assertSame( array( 'comments', 'cf7' ), wp_list_pluck( $data['integrations'], 'id' ) );
	}

	public function test_save_settings_sanitises_and_keeps_the_key() {
		wp_set_current_user( $this->admin );

		$response = $this->request(
			'POST',
			'/settings',
			array(
				'settings' => array(
					'provider'       => 'vercel',
					'api_key'        => '',
					'spam_threshold' => 0.9,
					'hold_threshold' => 0.95,
					'site_context'   => '<b>Cooking</b> blog',
					'integrations'   => array( 'comments' => true ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'vercel', Settings::get( 'provider' ) );
		$this->assertSame( 'test-key', Settings::api_key() );
		$this->assertSame( 0.9, Settings::get( 'hold_threshold' ), 'Hold threshold is capped at the spam threshold.' );
		$this->assertSame( 'Cooking blog', Settings::get( 'site_context' ) );
		$this->assertFalse( Settings::integration_enabled( 'cf7' ) );
		$this->assertSame( 'vercel', $response->get_data()['values']['provider'] );
	}

	public function test_save_settings_can_replace_and_clear_the_key() {
		wp_set_current_user( $this->admin );

		$this->request( 'POST', '/settings', array( 'settings' => array( 'api_key' => 'new-key-5678' ) ) );
		$this->assertSame( 'new-key-5678', Settings::api_key() );

		$data = $this->request( 'POST', '/settings', array( 'settings' => array( 'clear_api_key' => true ) ) )->get_data();
		$this->assertSame( '', Settings::api_key() );
		$this->assertFalse( $data['key']['stored'] );
	}

	public function test_test_connection_uses_unsaved_values() {
		wp_set_current_user( $this->admin );
		SpamLens_HttpStub::queue_answers( 0.97 );

		$response = $this->request(
			'POST',
			'/test',
			array(
				'provider' => 'openrouter',
				'api_key'  => 'unsaved-key',
			)
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'jev-1.13.0', $data['model'] );
		$this->assertEqualsWithDelta( 0.97, $data['probability'], 0.001 );
		$request = SpamLens_HttpStub::last_request();
		$this->assertStringContainsString( 'openrouter.ai', $request['url'] );
		$this->assertSame( 'Bearer unsaved-key', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'test-key', Settings::api_key(), 'Testing does not save.' );
		$this->assertSame( array( 'openrouter' => array( 'jev-1.13.0' ) ), $this->request( 'GET', '/settings' )->get_data()['models'], 'The versioned model is offered for pinning, with the provider that answered.' );
	}

	public function test_test_connection_reports_provider_errors() {
		wp_set_current_user( $this->admin );
		SpamLens_HttpStub::queue( 401, array( 'error' => array( 'message' => 'Invalid key' ) ) );

		$response = $this->request( 'POST', '/test', array( 'provider' => 'typesafe' ) );
		$data     = $response->get_data();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'spamlens_auth', $data['code'] );
		$this->assertSame( 'Invalid key', $data['message'] );
		$this->assertSame( 'Authentication failed', $data['data']['label'] );
	}

	public function test_test_connection_after_remove_key_reports_no_key() {
		wp_set_current_user( $this->admin );
		$response = $this->request(
			'POST',
			'/test',
			array(
				'provider'      => 'typesafe',
				'clear_api_key' => true,
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'spamlens_no_api_key', $response->get_data()['code'] );
		$this->assertSame( array(), SpamLens_HttpStub::$requests, 'Nothing is sent.' );
	}

	public function test_stats_and_reset() {
		wp_set_current_user( $this->admin );
		Stats::bump( 'checked', 'comments', 3 );
		Stats::bump( 'spam', 'comments' );

		$data = $this->request( 'GET', '/stats' )->get_data();
		$this->assertSame( 3, $data['totals']['checked'] );
		$this->assertSame( 1, $data['integrations']['comments']['spam'] );
		$this->assertSame( 0, $data['integrations']['cf7']['checked'] );

		$data = $this->request( 'POST', '/stats/reset' )->get_data();
		$this->assertSame( 0, $data['totals']['checked'] );
		$this->assertSame( 0, Stats::get()['spam'] );
	}

	public function test_calibration_sample_and_batch_are_read_only() {
		wp_set_current_user( $this->admin );
		$post = self::factory()->post->create();
		$spam = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'spam',
				'comment_content'  => 'Buy cheap pills',
			)
		);
		$ham  = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
				'comment_content'  => 'Thanks for the recipe',
			)
		);

		$sample = $this->request( 'POST', '/calibration/sample', array( 'n' => 25 ) )->get_data();
		$this->assertContains( $spam, $sample['spam'] );
		$this->assertContains( $ham, $sample['ham'] );

		SpamLens_HttpStub::queue_answers( 0.99 );
		SpamLens_HttpStub::queue_answers( 0.02, 0.9, 0.0, 'legitimate' );
		$data = $this->request(
			'POST',
			'/calibration/batch',
			array(
				'items' => array(
					array(
						'id'       => $spam,
						'expected' => 'spam',
					),
					array(
						'id'       => $ham,
						'expected' => 'ham',
					),
				),
			)
		)->get_data();

		$this->assertNull( $data['halt'] );
		$this->assertCount( 2, $data['results'] );
		$this->assertEqualsWithDelta( 0.99, $data['results'][0]['p'], 0.001 );
		$this->assertSame( 'ham', $data['results'][1]['expected'] );
		$this->assertSame( 'spam', get_comment( $spam )->comment_approved );
		$this->assertSame( '1', get_comment( $ham )->comment_approved );
		$this->assertSame( '', get_comment_meta( $ham, Comments::META, true ) );
	}

	public function test_calibration_batch_halts_on_auth_errors() {
		wp_set_current_user( $this->admin );
		$comment = self::factory()->comment->create( array( 'comment_approved' => '1' ) );
		SpamLens_HttpStub::queue( 401, array( 'message' => 'bad key' ) );

		$data = $this->request(
			'POST',
			'/calibration/batch',
			array(
				'items' => array(
					array(
						'id'       => $comment,
						'expected' => 'ham',
					),
				),
			)
		)->get_data();

		$this->assertSame( 'auth', $data['halt']['code'] );
		$this->assertSame( array(), $data['results'] );
	}

	public function test_calibration_batch_rejects_oversized_batches() {
		wp_set_current_user( $this->admin );
		$items = array_fill(
			0,
			RestController::CALIBRATION_BATCH + 1,
			array(
				'id'       => 1,
				'expected' => 'ham',
			)
		);
		$this->assertSame( 400, $this->request( 'POST', '/calibration/batch', array( 'items' => $items ) )->get_status() );
	}

	public function test_recheck_needs_edit_comment() {
		$comment = self::factory()->comment->create( array( 'comment_approved' => '0' ) );

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->request( 'POST', '/comments/' . $comment . '/recheck' )->get_status() );

		wp_set_current_user( $this->admin );
		SpamLens_HttpStub::queue_answers( 0.99 );
		$response = $this->request( 'POST', '/comments/' . $comment . '/recheck' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'spam', $data['decision'] );
		$this->assertTrue( $data['moved'] );
		$this->assertStringContainsString( 'spamlens-cell--spam', $data['html'] );
		$this->assertSame( 'spam', get_comment( $comment )->comment_approved );
	}

	public function test_check_pending_sweeps_the_queue() {
		$post = self::factory()->post->create();
		$ids  = self::factory()->comment->create_many(
			3,
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '0',
			)
		);

		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->request( 'POST', '/comments/check-pending' )->get_status() );

		wp_set_current_user( $this->admin );
		SpamLens_HttpStub::queue_answers( 0.99 );
		SpamLens_HttpStub::queue_answers( 0.10, 0.9, 0.0, 'legitimate' );
		SpamLens_HttpStub::queue_answers( 0.99 );

		$data = $this->request(
			'POST',
			'/comments/check-pending',
			array(
				'after' => 0,
				'token' => 'run1',
			)
		)->get_data();

		$this->assertSame( 3, $data['processed'] );
		$this->assertSame( 2, $data['spam'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertTrue( $data['done'] );
		$this->assertSame( max( $ids ), $data['lastId'] );
		$this->assertFalse( get_transient( RestController::BULK_LOCK ) );
	}

	public function test_check_pending_refuses_a_second_run() {
		wp_set_current_user( $this->admin );
		set_transient( RestController::BULK_LOCK, 'other', MINUTE_IN_SECONDS );

		$response = $this->request( 'POST', '/comments/check-pending', array( 'token' => 'mine' ) );
		$this->assertSame( 409, $response->get_status() );
		delete_transient( RestController::BULK_LOCK );
	}
}
