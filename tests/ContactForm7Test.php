<?php
/**
 * Contact Form 7 integration tests (run only when CF7 is installed in the test site).
 *
 * @package JevGuard
 */

use JevGuard\Integrations\ContactForm7;
use JevGuard\Settings;
use JevGuard\Stats;

/**
 * `wpcf7_spam` behaviour.
 */
class ContactForm7Test extends JevGuard_TestCase {

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			$this->markTestSkipped( 'Contact Form 7 is not installed.' );
		}
		$this->reset_submission_singleton();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test)';
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		$this->reset_submission_singleton();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * CF7 keeps one submission per request; reset it between tests.
	 */
	private function reset_submission_singleton() {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$property = new ReflectionProperty( 'WPCF7_Submission', 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, null );
	}

	/**
	 * Creates the default contact form and submits it with the given message.
	 *
	 * @param string $message Message.
	 * @return WPCF7_Submission
	 */
	private function submit( string $message ) {
		$form = WPCF7_ContactForm::get_template( array( 'title' => 'Test form' ) );
		$form->save();
		$form = WPCF7_ContactForm::get_instance( $form->id() );

		$_POST = array(
			'_wpcf7'          => $form->id(),
			'_wpcf7_unit_tag' => 'wpcf7-f' . $form->id() . '-p0-o1',
			'your-name'       => 'Sam Sender',
			'your-email'      => 'sam@example.com',
			'your-subject'    => 'Hello',
			'your-message'    => $message,
		);

		return WPCF7_Submission::get_instance( $form, array( 'skip_mail' => true ) );
	}

	public function test_spam_verdict_marks_submission_spam_with_log() {
		JevGuard_HttpStub::queue_answers( 0.95, 0.05, 0.0, 'commercial_promotion' );
		$submission = $this->submit( 'Buy cheap watches http://cheap.example.com/' );

		$this->assertSame( 'spam', $submission->get_status(), wp_json_encode( $submission->get_invalid_fields() ) );
		$log = $submission->get_spam_log();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'jev_guard', $log[0]['agent'] );
		$this->assertStringContainsString( '95% spam', $log[0]['reason'] );

		$body = JevGuard_HttpStub::last_body();
		$this->assertSame( 'contact_form', $body['state']['kind'] );
		$this->assertSame( 'Test form', $body['state']['context']['form_name'] );
		$this->assertSame( 'Buy cheap watches http://cheap.example.com/', $body['state']['submission'] );
		$this->assertSame( 'Sam Sender', $body['state']['author']['name'] );
		$this->assertSame( 'sam@example.com', $body['state']['author']['email'] );
		$this->assertSame( array( 'your-subject' => 'Hello' ), $body['state']['context']['fields'] );
		$this->assertSame( 1, Stats::get()['integrations']['cf7']['spam'] );
	}

	public function test_borderline_is_delivered_unless_filtered() {
		JevGuard_HttpStub::queue_answers( 0.6, 0.5 );
		$submission = $this->submit( 'Interesting, tell me more about pricing.' );
		$this->assertSame( 'mail_sent', $submission->get_status() );
		$this->assertSame( 1, Stats::get()['integrations']['cf7']['held'] );

		$this->reset_submission_singleton();
		add_filter( 'jev_guard_cf7_treat_hold_as_spam', '__return_true' );
		JevGuard_HttpStub::queue_answers( 0.6, 0.5 );
		$submission = $this->submit( 'Interesting, tell me more about pricing again.' );
		$this->assertSame( 'spam', $submission->get_status() );
		remove_filter( 'jev_guard_cf7_treat_hold_as_spam', '__return_true' );
	}

	public function test_api_error_fails_open() {
		JevGuard_HttpStub::queue_error();
		$submission = $this->submit( 'Hello there, just a question.' );
		$this->assertSame( 'mail_sent', $submission->get_status() );
		$this->assertSame( 1, Stats::get()['integrations']['cf7']['errors'] );
	}

	public function test_disabled_integration_makes_no_request() {
		Settings::update( array( 'integrations' => array( 'comments' => true, 'cf7' => false ) ) );
		$submission = $this->submit( 'Hello there.' );
		$this->assertSame( 'mail_sent', $submission->get_status() );
		$this->assertCount( 0, JevGuard_HttpStub::$requests );
	}

	public function test_already_flagged_submission_is_not_rechecked() {
		$integration = new ContactForm7( JevGuard\Plugin::instance()->classifier() );
		$this->assertTrue( $integration->filter_spam( true, null ) );
		$this->assertCount( 0, JevGuard_HttpStub::$requests );
	}
}
