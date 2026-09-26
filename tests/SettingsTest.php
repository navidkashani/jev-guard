<?php
/**
 * Settings tests.
 *
 * @package SpamLens
 */

use SpamLens\Service\Settings\Settings;

/**
 * Sanitising and key handling.
 */
class SettingsTest extends SpamLens_TestCase {

	public function test_defaults_are_merged() {
		$this->assertSame( 0.85, Settings::get( 'spam_threshold' ) );
		$this->assertTrue( Settings::integration_enabled( 'comments' ) );
		$this->assertTrue( Settings::integration_enabled( 'cf7' ) );
		$this->assertFalse( Settings::integration_enabled( 'unknown' ) );
	}

	public function test_empty_key_keeps_stored_key() {
		$out = Settings::sanitize( array( 'api_key' => '' ) );
		$this->assertSame( 'test-key', $out['api_key'] );

		$out = Settings::sanitize( array( 'api_key' => 'test••••••-key' ) );
		$this->assertSame( 'test-key', $out['api_key'] );

		$out = Settings::sanitize( array( 'api_key' => ' new-key ' ) );
		$this->assertSame( 'new-key', $out['api_key'] );
	}

	public function test_thresholds_are_clamped_and_ordered() {
		$out = Settings::sanitize(
			array(
				'spam_threshold' => '0.7',
				'hold_threshold' => '0.9',
			)
		);
		$this->assertSame( 0.7, $out['spam_threshold'] );
		$this->assertSame( 0.7, $out['hold_threshold'] );

		$out = Settings::sanitize( array( 'spam_threshold' => 'abc' ) );
		$this->assertSame( 0.85, $out['spam_threshold'] );
	}

	public function test_thresholds_have_floors() {
		$out = Settings::sanitize(
			array(
				'spam_threshold' => '0',
				'hold_threshold' => '',
			)
		);
		$this->assertSame( 0.5, $out['spam_threshold'] );
		$this->assertSame( 0.5, $out['hold_threshold'], 'Empty falls back to the default, then is capped at the spam threshold.' );

		$out = Settings::sanitize( array( 'hold_threshold' => '0.01' ) );
		$this->assertSame( 0.1, $out['hold_threshold'] );
	}

	public function test_provider_whitelist_and_timeout_bounds() {
		$out = Settings::sanitize(
			array(
				'provider' => 'evil',
				'timeout'  => 99,
			)
		);
		$this->assertSame( 'typesafe', $out['provider'] );
		$this->assertSame( 30, $out['timeout'] );
	}

	public function test_page_context_is_whitelisted() {
		$this->assertSame( 'standard', Settings::get( 'page_context' ) );
		$this->assertSame( 'extended', Settings::sanitize( array( 'page_context' => 'extended' ) )['page_context'] );
		$this->assertSame( 'title', Settings::sanitize( array( 'page_context' => 'title' ) )['page_context'] );
		$this->assertSame( 'standard', Settings::sanitize( array( 'page_context' => 'everything' ) )['page_context'] );
		$this->assertSame( 'standard', Settings::sanitize( array() )['page_context'] );
	}

	public function test_integration_toggles() {
		$out = Settings::sanitize( array( 'integrations' => array( 'cf7' => '1' ) ) );
		$this->assertFalse( $out['integrations']['comments'] );
		$this->assertTrue( $out['integrations']['cf7'] );
	}

	public function test_mask_key() {
		$this->assertSame( 'sk-1' . str_repeat( '•', 15 ) . 'wxyz', Settings::mask_key( 'sk-1234567890abcdefwxyz' ) );
		$this->assertSame( '••••', Settings::mask_key( 'abcd' ) );
	}
}
