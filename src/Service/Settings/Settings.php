<?php
/**
 * Plugin settings.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Settings;

use SpamLens\Service\Api\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Single autoloaded option `spamlens_settings` with defaults, sanitising and a constant override for the key.
 */
class Settings {

	const OPTION = 'spamlens_settings';

	const MIN_SPAM_THRESHOLD = 0.5;
	const MIN_HOLD_THRESHOLD = 0.1;

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
	 * Sanitises and saves a full submission.
	 *
	 * @param array $input Raw input.
	 * @return array The saved settings.
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean );
		return $clean;
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
	 * API key, preferring the SPAMLENS_API_KEY constant.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( self::api_key_is_constant() ) {
			return self::constant_key();
		}
		return (string) self::get( 'api_key', '' );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant(): bool {
		return '' !== self::constant_key();
	}

	/**
	 * The key from wp-config.php: SPAMLENS_API_KEY, or JEV_GUARD_API_KEY from before the plugin was renamed.
	 *
	 * @return string
	 */
	private static function constant_key(): string {
		foreach ( array( 'SPAMLENS_API_KEY', 'JEV_GUARD_API_KEY' ) as $name ) {
			if ( defined( $name ) && '' !== (string) constant( $name ) ) {
				return (string) constant( $name );
			}
		}
		return '';
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
	 * Sanitises a full settings submission from the admin app. Also used by "Test connection" to normalise
	 * unsaved form values.
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

		// An empty or masked submission keeps the stored key; `clear_api_key` removes it.
		$key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( ! empty( $input['clear_api_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' === $key || false !== strpos( $key, '•' ) ) {
			$out['api_key'] = $current['api_key'];
		} else {
			$out['api_key'] = sanitize_text_field( $key );
		}

		$out['model']           = sanitize_text_field( $input['model'] ?? '' );
		$out['custom_endpoint'] = esc_url_raw( trim( (string) ( $input['custom_endpoint'] ?? '' ) ), array( 'http', 'https' ) );

		// Floors match the form: a spam threshold under 0.5 or a hold threshold under 0.1 would send ordinary comments to
		// Spam or moderation, so an empty or mistyped field can never do that.
		$out['spam_threshold'] = self::clamp( $input['spam_threshold'] ?? $defaults['spam_threshold'], self::MIN_SPAM_THRESHOLD, 1.0, $defaults['spam_threshold'] );
		$out['hold_threshold'] = self::clamp( $input['hold_threshold'] ?? $defaults['hold_threshold'], self::MIN_HOLD_THRESHOLD, 1.0, $defaults['hold_threshold'] );
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
	 * Page context level → label.
	 *
	 * @return array<string, string>
	 */
	public static function page_context_labels(): array {
		return array(
			'title'    => __( 'Title only', 'spamlens' ),
			'standard' => __( 'Standard (recommended)', 'spamlens' ),
			'extended' => __( 'Extended', 'spamlens' ),
		);
	}

	/**
	 * Page context level → what it sends.
	 *
	 * @return array<string, string>
	 */
	public static function page_context_help(): array {
		return array(
			'title'    => __( 'Title, type, tags and categories; no text from the page. Choose this if you sell access to your content or it must not leave the server.', 'spamlens' ),
			'standard' => __( 'Also the excerpt (or the first ~300 characters) and the page\'s headings.', 'spamlens' ),
			'extended' => __( 'The first ~1,200 characters plus headings, about four times as much page text. Try it when spam is on-topic and well written. Save, then run the Calibration tool to compare.', 'spamlens' ),
		);
	}

	/**
	 * The settings as the admin app sees them: everything except the key itself, which is replaced by a masked hint.
	 *
	 * @return array
	 */
	public static function for_app(): array {
		$settings = self::all();
		$stored   = (string) $settings['api_key'];
		unset( $settings['api_key'] );
		$settings['integrations'] = array_map( 'boolval', (array) $settings['integrations'] );
		return array(
			'values' => $settings,
			'key'    => array(
				'stored'   => '' !== self::api_key(),
				'hint'     => self::mask_key( self::api_key_is_constant() ? self::api_key() : $stored ),
				'constant' => self::api_key_is_constant(),
			),
		);
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
