<?php
/**
 * REST routes for the admin app and the comments screen.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Rest;

use SpamLens\Service\Admin\CommentsScreen;
use SpamLens\Service\Api\Client;
use SpamLens\Service\Api\Providers;
use SpamLens\Service\Classifier\Classifier;
use SpamLens\Service\Classifier\Submission;
use SpamLens\Service\Integrations\IntegrationManager;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under `spamlens/v1`. Settings, test, calibration and statistics need `manage_options`; the comment
 * re-check needs `edit_comment` on that comment and the pending sweep needs `moderate_comments`.
 */
class RestController {

	const NAMESPACE = 'spamlens/v1';

	const CALIBRATION_BATCH  = 10;
	const CALIBRATION_BUDGET = 20;

	const BULK_BATCH  = 20;
	const BULK_BUDGET = 20;
	const BULK_LOCK   = 'spamlens_bulk_lock';

	/**
	 * Provider errors worth waiting out: the run pauses and tries the same comment again.
	 */
	const RETRY_CODES = array( 'rate_limited', 'overloaded' );

	/**
	 * Errors every later request would hit too: the run stops.
	 */
	const FATAL_CODES = array( 'auth', 'payment_required', 'no_api_key', 'not_configured' );

	/**
	 * Integrations.
	 *
	 * @var IntegrationManager
	 */
	private $integrations;

	/**
	 * Constructor.
	 *
	 * @param IntegrationManager $integrations Integrations.
	 */
	public function __construct( IntegrationManager $integrations ) {
		$this->integrations = $integrations;
	}

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes() {
		$admin = array( $this, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $admin,
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/calibration/sample',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calibration_sample' ),
				'permission_callback' => $admin,
				'args'                => array(
					'n' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/calibration/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calibration_batch' ),
				'permission_callback' => $admin,
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => self::CALIBRATION_BATCH,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'expected' => array(
									'type' => 'string',
									'enum' => array( 'spam', 'ham' ),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_stats' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/comments/(?P<id>\d+)/recheck',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recheck_comment' ),
				'permission_callback' => array( $this, 'can_edit_comment' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/comments/check-pending',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_pending' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'token' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	// --- Permissions ---

	/**
	 * Settings, test, calibration and statistics.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Re-checking one comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_comment( WP_REST_Request $request ): bool {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'edit_comment', $id );
	}

	/**
	 * Sweeping the Pending queue.
	 *
	 * @return bool
	 */
	public function can_moderate(): bool {
		return current_user_can( 'moderate_comments' );
	}

	// --- Settings ---

	/**
	 * Everything the settings screen needs to render.
	 *
	 * @return array
	 */
	public function settings_payload(): array {
		$providers = array();
		foreach ( Providers::all() as $id => $preset ) {
			$providers[] = array(
				'id'       => (string) $id,
				'label'    => (string) ( $preset['label'] ?? $id ),
				'model'    => (string) ( $preset['model'] ?? '' ),
				'endpoint' => (string) ( $preset['endpoint'] ?? '' ),
				'note'     => (string) ( $preset['note'] ?? '' ),
				'keysUrl'  => (string) ( $preset['keys_url'] ?? '' ),
				'freeUrl'  => (string) ( $preset['free_url'] ?? '' ),
			);
		}

		$integrations = array();
		foreach ( $this->integrations->all() as $id => $integration ) {
			$integrations[] = array(
				'id'        => (string) $id,
				'label'     => $integration->label(),
				'available' => $integration->is_available(),
			);
		}

		$levels = array();
		$help   = Settings::page_context_help();
		foreach ( Settings::page_context_labels() as $id => $label ) {
			$levels[] = array(
				'id'    => $id,
				'label' => $label,
				'help'  => $help[ $id ] ?? '',
			);
		}

		$error = Client::last_error();

		return array_merge(
			Settings::for_app(),
			array(
				'providers'    => $providers,
				'integrations' => $integrations,
				'pageContext'  => $levels,
				'maxLinks'     => (int) get_option( 'comment_max_links', 2 ),
				'models'       => Client::seen_models(),
				'lastError'    => $error ? array(
					'code'    => (string) $error['code'],
					'label'   => Client::error_label( (string) $error['code'] ),
					'message' => (string) $error['message'],
					'time'    => (int) ( $error['time'] ?? 0 ),
				) : null,
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return $this->settings_payload();
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function save_settings( WP_REST_Request $request ): array {
		$input = (array) $request->get_param( 'settings' );
		Settings::save( $input );
		return $this->settings_payload();
	}

	/**
	 * POST /test: classifies a canned spam comment with the (possibly unsaved) connection values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function test_connection( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		if ( ! empty( $params['clear_api_key'] ) && ! Settings::api_key_is_constant() ) {
			// The form is about to remove the key: test what will be left, which is no key.
			return $this->error( new WP_Error( 'no_api_key', __( 'No API key is configured.', 'spamlens' ) ) );
		}
		$input = Settings::sanitize( $params );
		$over  = array_intersect_key( $input, array_flip( array( 'provider', 'api_key', 'model', 'custom_endpoint', 'timeout' ) ) );

		$classifier = new Classifier( new Client( $over ) );
		$verdict    = $classifier->classify(
			Submission::sample(),
			array(
				'skip_cache' => true,
				'timeout'    => 15,
			)
		);

		if ( is_wp_error( $verdict ) ) {
			return $this->error( $verdict );
		}

		return array(
			'model'       => $verdict->model,
			'latencyMs'   => $verdict->latency_ms,
			'probability' => $verdict->probability(),
			'category'    => $verdict->category_label(),
			'decision'    => $verdict->decision,
		);
	}

	// --- Calibration ---

	/**
	 * POST /calibration/sample: ids of the newest spam and approved comments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function calibration_sample( WP_REST_Request $request ) {
		$comments = $this->integrations->comments();
		if ( ! $comments ) {
			return new WP_Error( 'unavailable', __( 'Comments integration unavailable.', 'spamlens' ), array( 'status' => 400 ) );
		}
		$sample = $comments->calibration_sample( (int) $request['n'] );
		return array(
			'spam' => array_map( 'intval', (array) ( $sample['spam'] ?? array() ) ),
			'ham'  => array_map( 'intval', (array) ( $sample['ham'] ?? array() ) ),
		);
	}

	/**
	 * POST /calibration/batch: classifies up to 10 comments read-only and returns the raw numbers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function calibration_batch( WP_REST_Request $request ) {
		$comments = $this->integrations->comments();
		if ( ! $comments ) {
			return new WP_Error( 'unavailable', __( 'Comments integration unavailable.', 'spamlens' ), array( 'status' => 400 ) );
		}

		$items   = array_slice( (array) $request['items'], 0, self::CALIBRATION_BATCH );
		$started = microtime( true );
		$results = array();
		$halt    = null;

		foreach ( $items as $item ) {
			$id       = (int) ( $item['id'] ?? 0 );
			$expected = ( 'spam' === ( $item['expected'] ?? '' ) ) ? 'spam' : 'ham';
			if ( $id <= 0 ) {
				continue;
			}
			if ( microtime( true ) - $started > self::CALIBRATION_BUDGET ) {
				break;
			}

			$comment = get_comment( $id );
			if ( ! $comment ) {
				continue;
			}
			$row = array(
				'id'       => $id,
				'expected' => $expected,
				'title'    => wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 80, '…' ),
				'author'   => $comment->comment_author,
				'link'     => (string) get_edit_comment_link( $id ),
			);

			$verdict = $comments->classify_only( $id );
			if ( is_wp_error( $verdict ) ) {
				$code = $verdict->get_error_code();
				if ( in_array( $code, self::RETRY_CODES, true ) || in_array( $code, self::FATAL_CODES, true ) ) {
					$halt = array(
						'code'       => $code,
						'label'      => Client::error_label( $code ),
						'message'    => $verdict->get_error_message(),
						'retryAfter' => (float) ( $verdict->get_error_data()['retry_after'] ?? 0 ),
					);
					break;
				}
				$row['error'] = $verdict->get_error_message();
				$results[]    = $row;
				continue;
			}

			$row['p']        = $verdict->spam;
			$row['genuine']  = $verdict->genuine;
			$row['abuse']    = $verdict->abuse;
			$row['links']    = $verdict->links;
			$row['category'] = $verdict->category_label();
			$row['decision'] = $verdict->decision;
			$row['model']    = $verdict->model;
			$results[]       = $row;
		}

		return array(
			'results' => $results,
			'halt'    => $halt,
		);
	}

	// --- Statistics ---

	/**
	 * GET /stats.
	 *
	 * @return array
	 */
	public function get_stats(): array {
		$stats   = Stats::get();
		$columns = array();
		foreach ( $this->integrations->all() as $id => $integration ) {
			$columns[] = array(
				'id'    => (string) $id,
				'label' => $integration->label(),
			);
		}
		$keys   = array( 'checked', 'spam', 'held', 'errors', 'fp', 'fn' );
		$totals = array();
		$per    = array();
		foreach ( $keys as $key ) {
			$totals[ $key ] = (int) ( $stats[ $key ] ?? 0 );
			foreach ( $columns as $column ) {
				$per[ $column['id'] ][ $key ] = (int) ( $stats['integrations'][ $column['id'] ][ $key ] ?? 0 );
			}
		}
		return array(
			'since'        => (int) $stats['since'],
			'sinceLabel'   => wp_date( get_option( 'date_format' ), (int) $stats['since'] ),
			'totals'       => $totals,
			'integrations' => $per,
			'columns'      => $columns,
		);
	}

	/**
	 * POST /stats/reset.
	 *
	 * @return array
	 */
	public function reset_stats(): array {
		Stats::reset();
		return $this->get_stats();
	}

	// --- Comments screen ---

	/**
	 * POST /comments/{id}/recheck.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function recheck_comment( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$comments = $this->integrations->comments();
		if ( ! $comments ) {
			return new WP_Error( 'unavailable', __( 'Comments integration unavailable.', 'spamlens' ), array( 'status' => 400 ) );
		}
		$before = get_comment( $id );
		if ( ! $before ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'spamlens' ), array( 'status' => 404 ) );
		}
		$verdict = $comments->recheck( $id, 'recheck' );
		if ( is_wp_error( $verdict ) ) {
			return $this->error( $verdict, array( 'html' => CommentsScreen::cell_html( $id ) ) );
		}
		$after = get_comment( $id );
		return array(
			'html'     => CommentsScreen::cell_html( $id ),
			'decision' => $verdict->decision,
			'label'    => $verdict->label(),
			'moved'    => $after && $before->comment_approved !== $after->comment_approved,
			'status'   => $after ? $after->comment_approved : '',
		);
	}

	/**
	 * POST /comments/check-pending: one batch of the Pending queue, cursor = last comment id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function check_pending( WP_REST_Request $request ) {
		$comments = $this->integrations->comments();
		if ( ! $comments ) {
			return new WP_Error( 'unavailable', __( 'Comments integration unavailable.', 'spamlens' ), array( 'status' => 400 ) );
		}

		$after = (int) $request['after'];
		$token = (string) $request['token'];

		$lock = get_transient( self::BULK_LOCK );
		if ( $lock && $lock !== $token ) {
			return new WP_Error( 'locked', __( 'Another Check for Spam run is in progress.', 'spamlens' ), array( 'status' => 409 ) );
		}
		set_transient( self::BULK_LOCK, $token, 2 * MINUTE_IN_SECONDS );

		global $wpdb;
		$total = 0 === $after ? (int) wp_count_comments()->moderated : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor pagination on comment_ID; WP_Comment_Query has no "after id" argument.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
				$after,
				self::BULK_BATCH
			)
		);

		$started   = microtime( true );
		$processed = 0;
		$spam      = 0;
		$errors    = 0;
		$last_id   = $after;
		$halt      = null;

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( microtime( true ) - $started > self::BULK_BUDGET ) {
				break;
			}
			$verdict = $comments->recheck( $id, 'bulk' );
			if ( is_wp_error( $verdict ) ) {
				$code = $verdict->get_error_code();
				if ( in_array( $code, self::RETRY_CODES, true ) ) {
					$halt = array(
						'code'       => $code,
						'message'    => $verdict->get_error_message(),
						'retryAfter' => (float) ( $verdict->get_error_data()['retry_after'] ?? 0 ),
						'fatal'      => false,
					);
					break; // Retry this id after the pause.
				}
				if ( in_array( $code, self::FATAL_CODES, true ) ) {
					$halt = array(
						'code'    => $code,
						'message' => Client::error_label( $code ) . ' — ' . $verdict->get_error_message(),
						'fatal'   => true,
					);
					break;
				}
				++$errors;
				$last_id = $id;
				if ( $errors >= 3 && 0 === $processed ) {
					$halt = array(
						'code'    => $code,
						'message' => Client::error_label( $code ) . ' — ' . $verdict->get_error_message(),
						'fatal'   => true,
					);
					break;
				}
				continue;
			}
			++$processed;
			$last_id = $id;
			if ( $verdict->is_spam() ) {
				++$spam;
			}
		}

		$done = empty( $ids ) || ( count( $ids ) < self::BULK_BATCH && $last_id >= (int) end( $ids ) && null === $halt );
		if ( $done || ( $halt && ! empty( $halt['fatal'] ) ) ) {
			delete_transient( self::BULK_LOCK );
		}

		return array(
			'processed' => $processed,
			'spam'      => $spam,
			'errors'    => $errors,
			'lastId'    => $last_id,
			'total'     => $total,
			'done'      => $done,
			'halt'      => $halt,
		);
	}

	/**
	 * A classifier error as a REST error: HTTP 502 for provider problems, with the readable label in the data.
	 *
	 * @param WP_Error $error Error from the client.
	 * @param array    $data  Extra data.
	 * @return WP_Error
	 */
	private function error( WP_Error $error, array $data = array() ): WP_Error {
		$code = (string) $error->get_error_code();
		return new WP_Error(
			'spamlens_' . $code,
			$error->get_error_message(),
			array_merge(
				array(
					'status' => in_array( $code, array( 'no_api_key', 'not_configured' ), true ) ? 400 : 502,
					'code'   => $code,
					'label'  => Client::error_label( $code ),
				),
				$data
			)
		);
	}
}
