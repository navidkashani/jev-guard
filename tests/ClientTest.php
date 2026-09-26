<?php
/**
 * Client tests.
 *
 * @package SpamLens
 */

use SpamLens\Service\Api\Client;
use SpamLens\Service\Settings\Settings;

/**
 * HTTP client behaviour with stubbed responses.
 */
class ClientTest extends SpamLens_TestCase {

	/**
	 * Minimal questions.
	 *
	 * @return array
	 */
	private function questions(): array {
		return array(
			'spam' => array(
				'type'         => 'noul',
				'instructions' => 'Is `comment` spam?',
			),
		);
	}

	public function test_success_returns_normalised_answers_and_versioned_model() {
		SpamLens_HttpStub::queue_answers( 0.94 );
		$client = new Client();
		$result = $client->evaluate( array( 'comment' => 'x' ), $this->questions() );

		$this->assertIsArray( $result );
		$this->assertSame( 'jev-1.13.0', $result['model'] );
		$this->assertSame( 0.94, $result['answers']['spam']['noul'] );
		$this->assertSame( 480, $result['usage']['input_tokens'] );
		$this->assertArrayHasKey( 'latency_ms', $result );

		$request = SpamLens_HttpStub::last_request();
		$this->assertSame( 'https://api.typesafe.ai/v1/systemone', $request['url'] );
		$this->assertSame( 'Bearer test-key', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertStringStartsWith( 'SpamLens/', $request['args']['user-agent'] );
		$this->assertTrue( $request['args']['sslverify'] );
		$this->assertSame( 5.0, (float) $request['args']['timeout'] );

		$body = SpamLens_HttpStub::last_body();
		$this->assertSame( 'jev-latest', $body['model'] );
		$this->assertSame( array( 'comment' => 'x' ), $body['state'] );
		$this->assertArrayHasKey( 'spam', $body['questions'] );
		$this->assertNull( Client::last_error() );
	}

	public function test_401_returns_auth_error_and_records_last_error() {
		SpamLens_HttpStub::queue( 401, array( 'message' => 'Invalid API key' ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );

		$this->assertWPError( $result );
		$this->assertSame( 'auth', $result->get_error_code() );
		$this->assertSame( 'Invalid API key', $result->get_error_message() );

		$last = Client::last_error();
		$this->assertSame( 'auth', $last['code'] );
		$this->assertSame( 'typesafe', $last['provider'] );

		// A later success clears it.
		SpamLens_HttpStub::queue_answers( 0.1 );
		( new Client() )->evaluate( 'y', $this->questions() );
		$this->assertNull( Client::last_error() );
	}

	public function test_402_maps_to_payment_required() {
		SpamLens_HttpStub::queue( 402, array( 'error' => array( 'message' => 'Insufficient credits' ) ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'payment_required', $result->get_error_code() );
		$this->assertSame( 'Insufficient credits', $result->get_error_message() );
	}

	public function test_422_passes_provider_message_through() {
		SpamLens_HttpStub::queue( 422, array( 'message' => 'state too long', 'error_type' => 'invalid_request' ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 'state too long', $result->get_error_message() );
	}

	public function test_429_is_retried_once_then_rate_limited() {
		SpamLens_HttpStub::queue( 429, '', array( 'retry-after' => '1' ) );
		SpamLens_HttpStub::queue( 429, '' );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );

		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertCount( 2, SpamLens_HttpStub::$requests );
	}

	public function test_429_then_success_recovers() {
		SpamLens_HttpStub::queue( 429, '' );
		SpamLens_HttpStub::queue_answers( 0.5 );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertIsArray( $result );
		$this->assertCount( 2, SpamLens_HttpStub::$requests );
	}

	public function test_529_maps_to_overloaded() {
		SpamLens_HttpStub::queue( 529, '' );
		SpamLens_HttpStub::queue( 529, '' );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'overloaded', $result->get_error_code() );
	}

	public function test_missing_answers_is_bad_response() {
		SpamLens_HttpStub::queue( 200, array( 'model' => 'jev-1.13.0' ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'bad_response', $result->get_error_code() );

		SpamLens_HttpStub::queue( 200, '<html>not json</html>' );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_network_timeout_maps_to_timeout() {
		SpamLens_HttpStub::queue_error( 'cURL error 28: Operation timed out after 5000 milliseconds' );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'timeout', $result->get_error_code() );
		$this->assertCount( 1, SpamLens_HttpStub::$requests );
	}

	public function test_openrouter_preset_adds_referer_and_title() {
		Settings::update( array( 'provider' => 'openrouter' ) );
		SpamLens_HttpStub::queue_answers( 0.2 );
		( new Client() )->evaluate( 'x', $this->questions() );

		$request = SpamLens_HttpStub::last_request();
		$this->assertSame( 'https://openrouter.ai/api/alpha/decisions', $request['url'] );
		$this->assertSame( home_url( '/' ), $request['args']['headers']['HTTP-Referer'] );
		$this->assertSame( 'SpamLens', $request['args']['headers']['X-Title'] );
		$this->assertSame( 'typesafe/jev-latest', SpamLens_HttpStub::last_body()['model'] );
	}

	public function test_vercel_preset_uses_gateway_model() {
		Settings::update( array( 'provider' => 'vercel' ) );
		SpamLens_HttpStub::queue_answers( 0.2 );
		( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'https://ai-gateway.vercel.sh/typesafe/v1/systemone', SpamLens_HttpStub::last_request()['url'] );
		$this->assertSame( 'typesafe-ai/jev', SpamLens_HttpStub::last_body()['model'] );
	}

	public function test_custom_preset_uses_configured_url_and_model() {
		Settings::update(
			array(
				'provider'        => 'custom',
				'custom_endpoint' => 'https://proxy.example.com/v1/systemone',
				'model'           => 'jev-1.13.0',
			)
		);
		SpamLens_HttpStub::queue_answers( 0.2 );
		( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'https://proxy.example.com/v1/systemone', SpamLens_HttpStub::last_request()['url'] );
		$this->assertSame( 'jev-1.13.0', SpamLens_HttpStub::last_body()['model'] );
	}

	public function test_custom_preset_without_url_is_not_configured() {
		Settings::update( array( 'provider' => 'custom' ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'not_configured', $result->get_error_code() );
		$this->assertCount( 0, SpamLens_HttpStub::$requests );
	}

	public function test_pinned_model_overrides_preset_default() {
		Settings::update( array( 'model' => 'jev-1.12.0' ) );
		SpamLens_HttpStub::queue_answers( 0.2 );
		( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'jev-1.12.0', SpamLens_HttpStub::last_body()['model'] );
	}

	public function test_timeout_arg_is_honoured() {
		SpamLens_HttpStub::queue_answers( 0.2 );
		( new Client() )->evaluate( 'x', $this->questions(), array( 'timeout' => 15 ) );
		$this->assertSame( 15.0, (float) SpamLens_HttpStub::last_request()['args']['timeout'] );
	}

	public function test_missing_key_short_circuits() {
		Settings::update( array( 'api_key' => '' ) );
		$result = ( new Client() )->evaluate( 'x', $this->questions() );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
		$this->assertCount( 0, SpamLens_HttpStub::$requests );
	}

	public function test_settings_override_is_used_for_unsaved_values() {
		$client = new Client(
			array(
				'provider' => 'openrouter',
				'api_key'  => 'other-key',
			)
		);
		SpamLens_HttpStub::queue_answers( 0.2 );
		$client->evaluate( 'x', $this->questions() );
		$request = SpamLens_HttpStub::last_request();
		$this->assertSame( 'Bearer other-key', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'https://openrouter.ai/api/alpha/decisions', $request['url'] );
	}
}
