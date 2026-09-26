<?php
/**
 * HTTP client for the TypeSafe System One wire format.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Api;

use SpamLens\Service\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin `wp_remote_post()` wrapper: builds the request from the active provider preset,
 * retries once on transient failures, normalises errors and remembers the last one.
 */
class Client {

	const LAST_ERROR_OPTION = 'spamlens_last_error';
	const MODELS_OPTION     = 'spamlens_models';
	const MODELS_KEEP       = 10;
	const RETRY_DELAY_US    = 750000;

	/**
	 * Settings override (used by the admin "Test connection" with unsaved values). Null → live settings.
	 *
	 * @var array|null
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Optional settings override.
	 */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Effective settings.
	 *
	 * @return array
	 */
	public function settings(): array {
		if ( null === $this->settings ) {
			return Settings::all();
		}
		return array_merge( Settings::all(), $this->settings );
	}

	/**
	 * Effective API key.
	 *
	 * @return string
	 */
	private function api_key(): string {
		if ( null !== $this->settings && ! empty( $this->settings['api_key'] ) && ! Settings::api_key_is_constant() ) {
			return (string) $this->settings['api_key'];
		}
		return Settings::api_key();
	}

	/**
	 * Evaluates questions against a state.
	 *
	 * @param string|array $state     Application state (string or object).
	 * @param array        $questions Question map.
	 * @param array        $args      Optional: `timeout` (seconds), `no_retry` (bool).
	 * @return array|WP_Error `['model', 'answers', 'usage', 'raw', 'latency_ms']` or a WP_Error with one of the codes
	 *                        no_api_key, not_configured, auth, payment_required, invalid_request, rate_limited,
	 *                        overloaded, timeout, http, bad_response.
	 */
	public function evaluate( $state, array $questions, array $args = array() ) {
		$settings = $this->settings();
		$api_key  = $this->api_key();

		if ( '' === $api_key ) {
			return new WP_Error( 'no_api_key', __( 'No API key is configured.', 'spamlens' ) );
		}

		/**
		 * Filters the endpoint URL.
		 *
		 * @param string $endpoint Endpoint.
		 * @param array  $settings Effective settings.
		 */
		$endpoint = (string) apply_filters( 'spamlens_endpoint', Providers::endpoint( $settings ), $settings );

		/**
		 * Filters the model id sent with each request.
		 *
		 * @param string $model    Model id.
		 * @param array  $settings Effective settings.
		 */
		$model = (string) apply_filters( 'spamlens_model', Providers::model( $settings ), $settings );

		if ( ! self::is_valid_endpoint( $endpoint ) ) {
			return $this->remember( new WP_Error( 'not_configured', __( 'The endpoint URL is missing or invalid.', 'spamlens' ) ), $settings );
		}
		if ( '' === $model ) {
			return $this->remember( new WP_Error( 'not_configured', __( 'No model id is configured.', 'spamlens' ) ), $settings );
		}

		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) $settings['timeout'];
		$timeout = max( 1.0, $timeout );

		$headers = array_merge(
			array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			Providers::headers( (string) $settings['provider'] )
		);

		$request = array(
			'method'      => 'POST',
			'timeout'     => $timeout,
			'headers'     => $headers,
			'body'        => wp_json_encode(
				array(
					'model'     => $model,
					'state'     => $state,
					'questions' => $questions,
				)
			),
			'sslverify'   => true,
			'user-agent'  => sprintf( 'SpamLens/%s; WordPress/%s; %s', SPAMLENS_VERSION, get_bloginfo( 'version' ), home_url( '/' ) ),
			'data_format' => 'body',
		);

		/**
		 * Filters the `wp_remote_post()` arguments.
		 *
		 * @param array  $request  Request arguments.
		 * @param string $endpoint Endpoint URL.
		 * @param array  $settings Effective settings.
		 */
		$request = apply_filters( 'spamlens_request_args', $request, $endpoint, $settings );

		$started  = microtime( true );
		$deadline = $started + $timeout;

		$result = $this->parse( wp_remote_post( $endpoint, $request ) );

		if ( is_wp_error( $result ) && empty( $args['no_retry'] ) && $this->is_retryable( $result ) ) {
			$remaining   = $deadline - microtime( true ) - ( self::RETRY_DELAY_US / 1000000 );
			$retry_after = (float) ( $result->get_error_data()['retry_after'] ?? 0 );
			if ( $remaining >= 1.0 && $retry_after <= $remaining ) {
				usleep( self::RETRY_DELAY_US );
				$request['timeout'] = $remaining;
				$result             = $this->parse( wp_remote_post( $endpoint, $request ) );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $this->remember( $result, $settings );
		}

		$this->forget();
		self::remember_model( (string) $settings['provider'], (string) $result['model'] );
		$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		return $result;
	}

	/**
	 * Structural URL check (scheme + host). No DNS lookup: presets are trusted and the custom
	 * endpoint is set by an administrator.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_valid_endpoint( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Whether an error is worth one retry.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private function is_retryable( WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'rate_limited', 'overloaded' ), true );
	}

	/**
	 * Normalises a `wp_remote_post()` result.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return array|WP_Error
	 */
	private function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) ? 'timeout' : 'http';
			return new WP_Error( $code, $message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		$provider_message = '';
		$error_type       = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$provider_message = $data['message'];
			} elseif ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$provider_message = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$provider_message = $data['error'];
			}
			if ( isset( $data['error_type'] ) && is_string( $data['error_type'] ) ) {
				$error_type = $data['error_type'];
			} elseif ( isset( $data['error']['type'] ) && is_string( $data['error']['type'] ) ) {
				$error_type = $data['error']['type'];
			} elseif ( isset( $data['error']['code'] ) && is_string( $data['error']['code'] ) ) {
				$error_type = $data['error']['code'];
			}
		}
		$provider_message = sanitize_text_field( mb_substr( $provider_message, 0, 500 ) );
		$error_data       = array(
			'status'     => $status,
			'error_type' => $error_type,
		);

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'bad_response', __( 'The service returned a response that is not JSON.', 'spamlens' ), $error_data );
			}
			if ( empty( $data['answers'] ) || ! is_array( $data['answers'] ) ) {
				return new WP_Error( 'bad_response', __( 'The service response contains no answers.', 'spamlens' ), $error_data );
			}
			return array(
				'model'   => isset( $data['model'] ) ? (string) $data['model'] : '',
				'answers' => $data['answers'],
				'usage'   => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
				'raw'     => $data,
			);
		}

		switch ( $status ) {
			case 401:
			case 403:
				/* translators: %d: HTTP status code */
				$fallback = sprintf( __( 'Authentication failed (HTTP %d). Check the API key.', 'spamlens' ), $status );
				return new WP_Error( 'auth', '' !== $provider_message ? $provider_message : $fallback, $error_data );
			case 402:
				$fallback = __( 'The provider reports insufficient credit (HTTP 402).', 'spamlens' );
				return new WP_Error( 'payment_required', '' !== $provider_message ? $provider_message : $fallback, $error_data );
			case 400:
			case 422:
				/* translators: %d: HTTP status code */
				$fallback = sprintf( __( 'The provider rejected the request (HTTP %d).', 'spamlens' ), $status );
				return new WP_Error( 'invalid_request', '' !== $provider_message ? $provider_message : $fallback, $error_data );
			case 429:
				$retry_after               = wp_remote_retrieve_header( $response, 'retry-after' );
				$error_data['retry_after'] = is_numeric( $retry_after ) ? (float) $retry_after : 0;
				$fallback                  = __( 'The provider rate limit was hit (HTTP 429).', 'spamlens' );
				return new WP_Error( 'rate_limited', '' !== $provider_message ? $provider_message : $fallback, $error_data );
			case 502:
			case 503:
			case 529:
				/* translators: %d: HTTP status code */
				$fallback = sprintf( __( 'The provider is overloaded (HTTP %d).', 'spamlens' ), $status );
				return new WP_Error( 'overloaded', '' !== $provider_message ? $provider_message : $fallback, $error_data );
		}

		/* translators: %d: HTTP status code */
		$fallback = sprintf( __( 'Unexpected HTTP %d from the provider.', 'spamlens' ), $status );
		return new WP_Error( 'http', '' !== $provider_message ? $provider_message : $fallback, $error_data );
	}

	/**
	 * Stores the last error for admin notices and returns it.
	 *
	 * @param WP_Error $error    Error.
	 * @param array    $settings Effective settings.
	 * @return WP_Error
	 */
	private function remember( WP_Error $error, array $settings ): WP_Error {
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'code'     => $error->get_error_code(),
				'message'  => $error->get_error_message(),
				'time'     => time(),
				'provider' => (string) ( $settings['provider'] ?? '' ),
			)
		);
		return $error;
	}

	/**
	 * Clears the last error after a successful call.
	 */
	private function forget() {
		if ( get_option( self::LAST_ERROR_OPTION ) ) {
			delete_option( self::LAST_ERROR_OPTION );
		}
	}

	/**
	 * Remembers a versioned model id that answered, per provider and newest first, so the settings screen can offer it
	 * for pinning with that provider only (each provider names the model its own way). Writes only when the id is new.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id from the response.
	 */
	private static function remember_model( string $provider, string $model ) {
		$provider = sanitize_key( $provider );
		$model    = sanitize_text_field( $model );
		if ( '' === $provider || '' === $model || strlen( $model ) > 100 ) {
			return;
		}
		$all  = self::seen_models();
		$list = $all[ $provider ] ?? array();
		if ( in_array( $model, $list, true ) ) {
			return;
		}
		array_unshift( $list, $model );
		$all[ $provider ] = array_slice( $list, 0, self::MODELS_KEEP );
		update_option( self::MODELS_OPTION, $all );
	}

	/**
	 * Versioned model ids that answered on this site, keyed by provider id, newest first.
	 *
	 * @return array<string, string[]>
	 */
	public static function seen_models(): array {
		$stored = get_option( self::MODELS_OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $provider => $models ) {
			if ( is_string( $provider ) && is_array( $models ) ) {
				$out[ $provider ] = array_values( array_filter( array_map( 'strval', $models ) ) );
			}
		}
		return $out;
	}

	/**
	 * Last recorded error, if any.
	 *
	 * @return array|null `['code', 'message', 'time', 'provider']`.
	 */
	public static function last_error() {
		$error = get_option( self::LAST_ERROR_OPTION );
		return is_array( $error ) && ! empty( $error['code'] ) ? $error : null;
	}

	/**
	 * Human-readable label for an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_label( string $code ): string {
		$labels = array(
			'no_api_key'       => __( 'No API key', 'spamlens' ),
			'not_configured'   => __( 'Not configured', 'spamlens' ),
			'auth'             => __( 'Authentication failed', 'spamlens' ),
			'payment_required' => __( 'Insufficient credit', 'spamlens' ),
			'invalid_request'  => __( 'Request rejected', 'spamlens' ),
			'rate_limited'     => __( 'Rate limited', 'spamlens' ),
			'overloaded'       => __( 'Provider overloaded', 'spamlens' ),
			'timeout'          => __( 'Timed out', 'spamlens' ),
			'http'             => __( 'Connection error', 'spamlens' ),
			'bad_response'     => __( 'Unexpected response', 'spamlens' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}
}
