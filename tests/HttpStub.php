<?php
/**
 * Queues fake HTTP responses through `pre_http_request` and records requests.
 *
 * @package JevGuard
 */

/**
 * HTTP stub.
 */
class JevGuard_HttpStub {

	/**
	 * Queued responses.
	 *
	 * @var array
	 */
	public static $queue = array();

	/**
	 * Recorded requests: `['url' => ..., 'args' => ...]`.
	 *
	 * @var array
	 */
	public static $requests = array();

	/**
	 * Installs the filter.
	 */
	public static function install() {
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
	}

	/**
	 * Clears queue and log.
	 */
	public static function reset() {
		self::$queue    = array();
		self::$requests = array();
	}

	/**
	 * Queues a raw response.
	 *
	 * @param int          $status  HTTP status.
	 * @param array|string $body    Body (arrays are JSON encoded).
	 * @param array        $headers Headers (lower-case keys).
	 */
	public static function queue( $status, $body = '', array $headers = array() ) {
		self::$queue[] = array(
			'headers'  => $headers,
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
			'response' => array(
				'code'    => (int) $status,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queues a WP_Error (network failure / timeout).
	 *
	 * @param string $message Message.
	 */
	public static function queue_error( $message = 'cURL error 28: Operation timed out' ) {
		self::$queue[] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Queues a successful answers response.
	 *
	 * @param float       $spam     Spam probability.
	 * @param float|null  $genuine  Genuine probability.
	 * @param float|null  $abuse    Abuse score.
	 * @param string      $category Category.
	 * @param string      $model    Versioned model id.
	 */
	public static function queue_answers( $spam, $genuine = 0.1, $abuse = 0.0, $category = 'seo_link_spam', $model = 'jev-1.13.0' ) {
		self::queue( 200, self::answers_body( $spam, $genuine, $abuse, $category, $model ) );
	}

	/**
	 * Builds a response body in the TypeSafe format.
	 *
	 * @param float       $spam     Spam probability.
	 * @param float|null  $genuine  Genuine probability.
	 * @param float|null  $abuse    Abuse score.
	 * @param string      $category Category.
	 * @param string      $model    Model.
	 * @return array
	 */
	public static function answers_body( $spam, $genuine = 0.1, $abuse = 0.0, $category = 'seo_link_spam', $model = 'jev-1.13.0' ) {
		$answers = array(
			'spam'     => array(
				'type' => 'noul',
				'noul' => $spam,
			),
			'category' => array(
				'type'          => 'choice',
				'choice'        => $category,
				'confidence'    => 0.9,
				'probabilities' => array( $category => 0.9 ),
			),
		);
		if ( null !== $genuine ) {
			$answers['genuine'] = array(
				'type' => 'noul',
				'noul' => $genuine,
			);
		}
		if ( null !== $abuse ) {
			$answers['abuse'] = array(
				'type'          => 'score',
				'score'         => $abuse,
				'confidence'    => 0.8,
				'probabilities' => array(),
				'legend'        => array( 'Civil', 'Rude or hostile', 'Abusive' ),
			);
		}
		return array(
			'model'   => $model,
			'answers' => $answers,
			'usage'   => array(
				'input_tokens'  => 480,
				'output_tokens' => 0,
			),
		);
	}

	/**
	 * `pre_http_request` callback.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public static function intercept( $pre, $args, $url ) {
		self::$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( empty( self::$queue ) ) {
			return new WP_Error( 'http_request_failed', 'No stubbed response queued for ' . $url );
		}
		return array_shift( self::$queue );
	}

	/**
	 * Last recorded request.
	 *
	 * @return array|null
	 */
	public static function last_request() {
		return empty( self::$requests ) ? null : end( self::$requests );
	}

	/**
	 * Decoded JSON body of the last request.
	 *
	 * @return array
	 */
	public static function last_body() {
		$request = self::last_request();
		return $request ? (array) json_decode( $request['args']['body'], true ) : array();
	}
}
