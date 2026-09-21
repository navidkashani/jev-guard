<?php
/**
 * Plugin settings.
 *
 * @package JevGuard
 */

namespace JevGuard;

use JevGuard\Api\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Single autoloaded option `jev_guard_settings` with defaults, sanitising and a constant override for the key.
 */
class Settings {

	const OPTION = 'jev_guard_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'provider'                 => 'typesafe',
			'api_key'                  => '',
			'model'                    => '',
			'custom_endpoint'          => '',
			'spam_threshold'           => 0.85,
			'hold_threshold'           => 0.50,
			'hold_abusive'             => true,
			'on_error'                 => 'allow',
			'site_context'             => '',
			'page_context'             => 'standard',
			'send_email'               => true,
			'send_ip'                  => false,
			'send_user_agent'          => true,
			'skip_moderators'          => true,
			'skip_previously_approved' => false,
			'check_pingbacks'          => true,
			'privacy_notice'           => false,
			'integrations'             => array(
				'comments' => true,
				'cf7'      => true,
			),
			'timeout'                  => 5,
		);
	}

	/**
	 * Full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings                 = array_merge( self::defaults(), $stored );
		$settings['integrations'] = array_merge( self::defaults()['integrations'], (array) ( $stored['integrations'] ?? array() ) );
		return $settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persists a partial update.
	 *
	 * @param array $values Values to merge.
	 */
	public static function update( array $values ) {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::OPTION, array_merge( $current, $values ) );
	}

	/**
	 * API key, preferring the JEV_GUARD_API_KEY constant.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( self::api_key_is_constant() ) {
			return (string) JEV_GUARD_API_KEY;
		}
		return (string) self::get( 'api_key', '' );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant(): bool {
		return defined( 'JEV_GUARD_API_KEY' ) && '' !== (string) JEV_GUARD_API_KEY;
	}

	/**
	 * True when a key is available.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Whether an integration is switched on.
	 *
	 * @param string $id Integration id.
	 * @return bool
	 */
	public static function integration_enabled( string $id ): bool {
		$integrations = self::get( 'integrations', array() );
		return ! empty( $integrations[ $id ] );
	}

	/**
	 * Masks a key for display: first 4 + bullets + last 4.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', min( 20, $len - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * Sanitise callback for the Settings API. Also used by the AJAX "Test connection"
	 * handler to normalise unsaved form values.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$current  = self::all();
		$out      = array();

		$providers       = array_keys( Providers::all() );
		$out['provider'] = in_array( $input['provider'] ?? '', $providers, true ) ? $input['provider'] : $defaults['provider'];

		// An empty or masked submission keeps the stored key.
		$key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( '' === $key || false !== strpos( $key, '•' ) ) {
			$out['api_key'] = $current['api_key'];
		} else {
			$out['api_key'] = sanitize_text_field( $key );
		}

		$out['model']           = sanitize_text_field( $input['model'] ?? '' );
		$out['custom_endpoint'] = esc_url_raw( trim( (string) ( $input['custom_endpoint'] ?? '' ) ), array( 'http', 'https' ) );

		$out['spam_threshold'] = self::clamp( $input['spam_threshold'] ?? $defaults['spam_threshold'], 0.01, 1.0, $defaults['spam_threshold'] );
		$out['hold_threshold'] = self::clamp( $input['hold_threshold'] ?? $defaults['hold_threshold'], 0.01, 1.0, $defaults['hold_threshold'] );
		if ( $out['hold_threshold'] > $out['spam_threshold'] ) {
			$out['hold_threshold'] = $out['spam_threshold'];
		}

		$out['on_error'] = ( 'hold' === ( $input['on_error'] ?? '' ) ) ? 'hold' : 'allow';

		$context             = sanitize_textarea_field( (string) ( $input['site_context'] ?? '' ) );
		$out['site_context'] = mb_substr( $context, 0, 2000 );

		$out['page_context'] = in_array( $input['page_context'] ?? '', self::page_context_levels(), true ) ? $input['page_context'] : $defaults['page_context'];

		foreach ( array( 'hold_abusive', 'send_email', 'send_ip', 'send_user_agent', 'skip_moderators', 'skip_previously_approved', 'check_pingbacks', 'privacy_notice' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$integrations = array();
		foreach ( array_keys( $defaults['integrations'] ) as $id ) {
			$integrations[ $id ] = ! empty( $input['integrations'][ $id ] );
		}
		// Preserve toggles for third-party integrations we do not know about.
		foreach ( (array) ( $input['integrations'] ?? array() ) as $id => $enabled ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && ! isset( $integrations[ $id ] ) ) {
				$integrations[ $id ] = ! empty( $enabled );
			}
		}
		$out['integrations'] = $integrations;

		$timeout        = (int) ( $input['timeout'] ?? $defaults['timeout'] );
		$out['timeout'] = max( 1, min( 30, $timeout ) );

		return $out;
	}

	/**
	 * Valid "Page context" levels, least to most page text.
	 *
	 * @return string[]
	 */
	public static function page_context_levels(): array {
		return array( 'title', 'standard', 'extended' );
	}

	/**
	 * Clamps a float, falling back when not numeric.
	 *
	 * @param mixed $value    Input.
	 * @param float $min      Minimum.
	 * @param float $max      Maximum.
	 * @param float $fallback Fallback.
	 * @return float
	 */
	private static function clamp( $value, float $min, float $max, float $fallback ): float {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return round( max( $min, min( $max, (float) $value ) ), 2 );
	}
}
