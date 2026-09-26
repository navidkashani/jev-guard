<?php
/**
 * Comments integration.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Integrations;

use SpamLens\Service\Classifier\Classifier;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;
use SpamLens\Service\Classifier\Submission;
use SpamLens\Service\Classifier\Verdict;
use WP_Comment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Comments, WooCommerce reviews and pingbacks: synchronous check in `preprocess_comment`
 * (and `rest_pre_insert_comment`), decision in `pre_comment_approved`, history meta,
 * rechecks, the retry cron and calibration sampling.
 */
class Comments implements Integration {

	const META            = '_spamlens';
	const META_DECISION   = '_spamlens_decision';
	const META_HISTORY    = '_spamlens_history';
	const META_ERROR      = '_spamlens_error';
	const META_RECHECKING = '_spamlens_rechecking';

	const CRON_HOOK       = 'spamlens_retry';
	const RETRY_DELAY     = 20 * MINUTE_IN_SECONDS;
	const RETRY_BATCH     = 100;
	const RETRY_MAX_AGE   = 15 * DAY_IN_SECONDS;
	const RETRY_LOCK      = 'spamlens_retry_lock';
	const NUDGE_TRANSIENT = 'spamlens_cron_nudge';
	const HISTORY_LIMIT   = 50;

	/**
	 * Classifier.
	 *
	 * @var Classifier
	 */
	private $classifier;

	/**
	 * GUID + decision of the check performed in the current request, for `pre_comment_approved`.
	 *
	 * @var array|null
	 */
	private static $last = null;

	/**
	 * Constructor.
	 *
	 * @param Classifier $classifier Classifier.
	 */
	public function __construct( Classifier $classifier ) {
		$this->classifier = $classifier;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'comments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Comments, reviews and pingbacks', 'spamlens' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_filter( 'preprocess_comment', array( $this, 'preprocess' ), 10 );
		add_filter( 'pre_comment_approved', array( $this, 'pre_comment_approved' ), 10, 2 );
		add_filter( 'rest_pre_insert_comment', array( $this, 'rest_pre_insert' ), 10, 2 );
		add_action( 'wp_insert_comment', array( $this, 'on_insert' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'run_retry_queue' ) );
	}

	// --- Incoming comments ---

	/**
	 * `preprocess_comment`: classify and stash the verdict in `comment_meta` (persisted by core).
	 *
	 * @param array $commentdata Slashed comment data.
	 * @return array
	 */
	public function preprocess( $commentdata ) {
		if ( ! is_array( $commentdata ) ) {
			return $commentdata;
		}

		$result = $this->check( $commentdata, true );
		if ( null === $result ) {
			return $commentdata;
		}

		if ( ! isset( $commentdata['comment_meta'] ) || ! is_array( $commentdata['comment_meta'] ) ) {
			$commentdata['comment_meta'] = array();
		}
		// wp_insert_comment() unslashes meta values, so slash them here (wp_new_comment() data is slashed).
		$commentdata['comment_meta'] = array_merge( $commentdata['comment_meta'], wp_slash( $result['meta'] ) );

		self::$last = array(
			'guid'     => $result['guid'],
			'decision' => $result['decision'],
		);

		return $commentdata;
	}

	/**
	 * `pre_comment_approved`: apply the tier for the comment checked in `preprocess()`.
	 *
	 * @param int|string $approved    Current approval status.
	 * @param array      $commentdata Comment data.
	 * @return int|string
	 */
	public function pre_comment_approved( $approved, $commentdata ) {
		if ( null === self::$last || ! is_array( $commentdata ) ) {
			return $approved;
		}
		$guid = $commentdata['comment_meta'][ self::META ]['guid'] ?? '';
		if ( '' === $guid || $guid !== self::$last['guid'] ) {
			return $approved;
		}
		return $this->apply_decision( self::$last['decision'], $approved );
	}

	/**
	 * `rest_pre_insert_comment`: the REST controller bypasses `preprocess_comment`, so check here.
	 * `wp_allow_comment()` already ran, hence the status is set directly.
	 *
	 * @param array|WP_Error   $prepared Prepared comment.
	 * @param \WP_REST_Request $request  Request.
	 * @return array|WP_Error
	 */
	public function rest_pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! is_array( $prepared ) ) {
			return $prepared;
		}

		$result = $this->check( $prepared, false );
		if ( null === $result ) {
			return $prepared;
		}

		if ( ! isset( $prepared['comment_meta'] ) || ! is_array( $prepared['comment_meta'] ) ) {
			$prepared['comment_meta'] = array();
		}
		// The controller slashes the whole array before inserting, so no slashing here.
		$prepared['comment_meta'] = array_merge( $prepared['comment_meta'], $result['meta'] );

		$approved                     = $prepared['comment_approved'] ?? 1;
		$prepared['comment_approved'] = $this->apply_decision( $result['decision'], $approved );

		return $prepared;
	}

	/**
	 * Runs the skip rules and the classifier for one set of comment data.
	 *
	 * @param array $commentdata Comment data (only read).
	 * @param bool  $slashed     Whether the data is slashed (`wp_new_comment()` path) or not (REST path).
	 * @return array|null `['guid', 'decision', 'meta' => [...]]` or null when the integration is off.
	 */
	private function check( array $commentdata, bool $slashed ) {
		if ( ! Settings::integration_enabled( 'comments' ) || ! Settings::is_configured() ) {
			return null;
		}

		$guid = wp_generate_uuid4();
		$skip = $this->skip_reason( $commentdata, $slashed );

		if ( '' !== $skip ) {
			return array(
				'guid'     => $guid,
				'decision' => 'skipped',
				'meta'     => array(
					self::META          => array(
						'guid'    => $guid,
						'skipped' => $skip,
						'time'    => time(),
					),
					self::META_DECISION => 'skipped',
				),
			);
		}

		$submission = Submission::from_commentdata( $commentdata, $slashed );
		$verdict    = $this->classifier->classify( $submission );

		if ( is_wp_error( $verdict ) ) {
			$this->schedule_retry();
			return array(
				'guid'     => $guid,
				'decision' => 'error',
				'meta'     => array(
					self::META          => array(
						'guid'  => $guid,
						'error' => array(
							'code'    => $verdict->get_error_code(),
							'message' => $verdict->get_error_message(),
						),
						'time'  => time(),
					),
					self::META_DECISION => 'error',
					self::META_ERROR    => time(),
				),
			);
		}

		$verdict->guid = $guid;

		return array(
			'guid'     => $guid,
			'decision' => $verdict->decision,
			'meta'     => array(
				self::META          => $verdict->to_array(),
				self::META_DECISION => $verdict->decision,
			),
		);
	}

	/**
	 * Maps a decision onto WordPress' own approval status. Never approves, never overrides trash/spam.
	 *
	 * @param string     $decision spam|hold|allow|error|skipped.
	 * @param int|string $approved Status WordPress arrived at.
	 * @return int|string
	 */
	private function apply_decision( string $decision, $approved ) {
		if ( 'trash' === $approved || 'spam' === $approved ) {
			return $approved;
		}
		switch ( $decision ) {
			case Verdict::SPAM:
				return 'spam';
			case Verdict::HOLD:
				return 0;
			case 'error':
				return 'hold' === Settings::get( 'on_error' ) ? 0 : $approved;
		}
		return $approved;
	}

	/**
	 * Why a comment should not be sent to the API ('' = check it).
	 *
	 * @param array $commentdata Comment data.
	 * @param bool  $slashed     Whether the data is slashed.
	 * @return string
	 */
	public function skip_reason( array $commentdata, bool $slashed = true ): string {
		$data   = $slashed ? wp_unslash( $commentdata ) : $commentdata;
		$reason = '';

		$type = (string) ( $data['comment_type'] ?? '' );
		if ( isset( $data['akismet_result'] ) && 'true' === $data['akismet_result'] ) {
			$reason = 'already_flagged';
		} elseif ( '' === trim( (string) ( $data['comment_content'] ?? '' ) ) ) {
			$reason = 'empty';
		} elseif ( in_array( $type, array( 'pingback', 'trackback' ), true ) && ! Settings::get( 'check_pingbacks' ) ) {
			$reason = 'pingback';
		} elseif ( wp_check_comment_disallowed_list(
			(string) ( $data['comment_author'] ?? '' ),
			(string) ( $data['comment_author_email'] ?? '' ),
			(string) ( $data['comment_author_url'] ?? '' ),
			(string) ( $data['comment_content'] ?? '' ),
			(string) ( $data['comment_author_IP'] ?? '' ),
			(string) ( $data['comment_agent'] ?? '' )
		) ) {
			$reason = 'disallowed';
		} else {
			$user_id = (int) ( $data['user_id'] ?? $data['user_ID'] ?? 0 );
			if ( $user_id > 0 && Settings::get( 'skip_moderators' ) && user_can( $user_id, 'moderate_comments' ) ) {
				$reason = 'moderator';
			} elseif ( Settings::get( 'skip_previously_approved' ) && Classifier::approved_count( (string) ( $data['comment_author_email'] ?? '' ), $user_id ) > 0 ) {
				$reason = 'previously_approved';
			}
		}

		/**
		 * Filters the skip reason. Return a non-empty string to skip the API call, '' to force a check.
		 *
		 * @param string $reason      Reason or ''.
		 * @param array  $commentdata Comment data.
		 */
		$reason = apply_filters( 'spamlens_skip_comment', $reason, $commentdata );
		return is_string( $reason ) ? sanitize_key( $reason ) : '';
	}

	/**
	 * `wp_insert_comment`: write the history entry and bump counters.
	 *
	 * @param int        $comment_id Comment id.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_insert( $comment_id, $comment ) {
		$meta = get_comment_meta( $comment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['guid'] ) ) {
			return;
		}
		$status = $comment instanceof WP_Comment ? (string) $comment->comment_approved : '';

		if ( ! empty( $meta['skipped'] ) ) {
			$this->add_history( $comment_id, 'skipped-' . $meta['skipped'], array( 'status' => $status ) );
			return;
		}
		if ( ! empty( $meta['error'] ) ) {
			$this->add_history(
				$comment_id,
				'check-error',
				array(
					'status'  => $status,
					'message' => (string) ( $meta['error']['message'] ?? '' ),
				)
			);
			Stats::bump( 'errors', 'comments' );
			return;
		}

		$verdict = Verdict::from_array( $meta );
		$this->add_history(
			$comment_id,
			'check-' . $this->tier_slug( $verdict ),
			array(
				'status' => $status,
				'p'      => $verdict->spam,
				'model'  => $verdict->model,
			)
		);
		$this->bump_verdict_stats( $verdict );
	}

	// --- Rechecks & retry queue ---

	/**
	 * Re-classifies an existing comment and updates its meta. Moves it to Spam only when the new
	 * verdict is spam; never approves or un-holds.
	 *
	 * @param int    $comment_id Comment id.
	 * @param string $reason     History prefix: `recheck`, `bulk`, `retry`.
	 * @return Verdict|WP_Error
	 */
	public function recheck( int $comment_id, string $reason = 'recheck' ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'spamlens' ) );
		}
		if ( ! Settings::is_configured() ) {
			return new WP_Error( 'no_api_key', __( 'No API key is configured.', 'spamlens' ) );
		}

		$reason = sanitize_key( $reason );
		update_comment_meta( $comment_id, self::META_RECHECKING, time() );

		$submission = Submission::from_comment( $comment );
		$verdict    = $this->classifier->classify( $submission, array( 'skip_cache' => true ) );

		if ( is_wp_error( $verdict ) ) {
			$existing = get_comment_meta( $comment_id, self::META, true );
			$existing = is_array( $existing ) ? $existing : array();
			update_comment_meta(
				$comment_id,
				self::META,
				wp_slash(
					array(
						'guid'     => wp_generate_uuid4(),
						'error'    => array(
							'code'    => $verdict->get_error_code(),
							'message' => $verdict->get_error_message(),
						),
						'time'     => time(),
						'previous' => isset( $existing['decision'] ) ? $existing['decision'] : '',
					)
				)
			);
			update_comment_meta( $comment_id, self::META_DECISION, 'error' );
			if ( ! get_comment_meta( $comment_id, self::META_ERROR, true ) ) {
				update_comment_meta( $comment_id, self::META_ERROR, time() );
			}
			$this->add_history( $comment_id, $reason . '-error', array( 'message' => $verdict->get_error_message() ) );
			Stats::bump( 'errors', 'comments' );
			delete_comment_meta( $comment_id, self::META_RECHECKING );
			if ( 'retry' !== $reason ) {
				$this->schedule_retry();
			}
			return $verdict;
		}

		$verdict->guid = wp_generate_uuid4();
		update_comment_meta( $comment_id, self::META, wp_slash( $verdict->to_array() ) );
		update_comment_meta( $comment_id, self::META_DECISION, $verdict->decision );
		delete_comment_meta( $comment_id, self::META_ERROR );

		$moved = false;
		if ( $verdict->is_spam() && ! in_array( (string) $comment->comment_approved, array( 'spam', 'trash' ), true ) ) {
			$moved = (bool) wp_spam_comment( $comment_id );
		}

		$this->add_history(
			$comment_id,
			$reason . '-' . $this->tier_slug( $verdict ),
			array(
				'p'     => $verdict->spam,
				'model' => $verdict->model,
				'moved' => $moved,
			)
		);
		$this->bump_verdict_stats( $verdict );

		delete_comment_meta( $comment_id, self::META_RECHECKING );

		return $verdict;
	}

	/**
	 * Classifies a comment without touching meta or status (calibration tool).
	 *
	 * @param int $comment_id Comment id.
	 * @param int $timeout    Request timeout.
	 * @return Verdict|WP_Error
	 */
	public function classify_only( int $comment_id, int $timeout = 8 ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'spamlens' ) );
		}
		return $this->classifier->classify(
			Submission::from_comment( $comment ),
			array(
				'skip_cache' => true,
				'timeout'    => $timeout,
				'no_retry'   => true,
			)
		);
	}

	/**
	 * Schedules the retry event (single, +20 min) if none is pending.
	 *
	 * @param int $delay Seconds from now.
	 */
	public function schedule_retry( int $delay = self::RETRY_DELAY ) {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		if ( $delay <= 0 ) {
			$this->nudge_cron();
		}
	}

	/**
	 * Asks WP-Cron to run now, at most once every 15 s.
	 */
	private function nudge_cron() {
		if ( get_transient( self::NUDGE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::NUDGE_TRANSIENT, 1, 15 );
		spawn_cron();
	}

	/**
	 * Cron handler: rechecks comments that could not be classified, oldest first.
	 */
	public function run_retry_queue() {
		if ( get_transient( self::RETRY_LOCK ) ) {
			return;
		}
		set_transient( self::RETRY_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		try {
			if ( ! Settings::is_configured() || ! Settings::integration_enabled( 'comments' ) ) {
				return;
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded meta scan; WP_Comment_Query cannot include spam/trash rows for cleanup.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.comment_id, m.meta_value, c.comment_approved
					 FROM {$wpdb->commentmeta} m
					 INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
					 WHERE m.meta_key = %s
					 ORDER BY m.meta_value ASC, m.comment_id ASC
					 LIMIT %d",
					self::META_ERROR,
					self::RETRY_BATCH + 1
				)
			);

			$has_more  = count( (array) $rows ) > self::RETRY_BATCH;
			$rows      = array_slice( (array) $rows, 0, self::RETRY_BATCH );
			$processed = 0;
			$failed    = false;

			foreach ( $rows as $row ) {
				$comment_id = (int) $row->comment_id;
				$age        = time() - (int) $row->meta_value;

				if ( ! in_array( (string) $row->comment_approved, array( '0', '1' ), true ) || $age > self::RETRY_MAX_AGE ) {
					delete_comment_meta( $comment_id, self::META_ERROR );
					$this->add_history( $comment_id, 'retry-dropped' );
					continue;
				}

				$result = $this->recheck( $comment_id, 'retry' );
				++$processed;

				if ( is_wp_error( $result ) ) {
					if ( 'invalid_request' === $result->get_error_code() ) {
						// Rejected because of this specific content; do not let it block the queue.
						delete_comment_meta( $comment_id, self::META_ERROR );
						continue;
					}
					$failed = true;
					break;
				}
			}

			/**
			 * Fires after a retry run.
			 *
			 * @param int  $processed Comments rechecked.
			 * @param bool $failed    Whether the run stopped on an error.
			 */
			do_action( 'spamlens_retry_run', $processed, $failed );

			if ( $failed ) {
				$this->schedule_retry();
			} elseif ( $has_more ) {
				$this->schedule_retry( 0 );
			}
		} finally {
			delete_transient( self::RETRY_LOCK );
		}
	}

	// --- Moderator feedback ---

	/**
	 * `transition_comment_status`: count moderator overrides as false positives / missed spam.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_transition( $new_status, $old_status, $comment ) {
		if ( ! $comment instanceof WP_Comment || $new_status === $old_status ) {
			return;
		}
		$comment_id = (int) $comment->comment_ID;
		if ( get_comment_meta( $comment_id, self::META_RECHECKING, true ) ) {
			return;
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		$decision = (string) get_comment_meta( $comment_id, self::META_DECISION, true );
		if ( ! in_array( $decision, array( Verdict::SPAM, Verdict::HOLD, Verdict::ALLOW ), true ) ) {
			return;
		}

		if ( 'spam' === $old_status && ! in_array( $new_status, array( 'spam', 'trash' ), true ) && Verdict::SPAM === $decision ) {
			Stats::bump( 'fp', 'comments' );
			$this->add_history( $comment_id, 'user-unspam', array( 'user' => get_current_user_id() ) );
		} elseif ( 'spam' === $new_status && 'spam' !== $old_status && Verdict::ALLOW === $decision ) {
			Stats::bump( 'fn', 'comments' );
			$this->add_history( $comment_id, 'user-spam', array( 'user' => get_current_user_id() ) );
		}
	}

	// --- Calibration & helpers ---

	/**
	 * Up to `$n` spam and `$n` approved comment ids, newest first, excluding moderators' own.
	 *
	 * @param int $n Per class.
	 * @return array `['spam' => int[], 'ham' => int[]]`.
	 */
	public function calibration_sample( int $n ): array {
		$n          = max( 1, min( 500, $n ) );
		$moderators = get_users(
			array(
				'capability' => 'moderate_comments',
				'fields'     => 'ID',
				'number'     => 200,
			)
		);
		$base       = array(
			'number'       => $n,
			'orderby'      => 'comment_date_gmt',
			'order'        => 'DESC',
			'fields'       => 'ids',
			'type__not_in' => array( 'pingback', 'trackback' ),
		);
		if ( ! empty( $moderators ) ) {
			$base['author__not_in'] = array_map( 'intval', $moderators );
		}

		return array(
			'spam' => array_map( 'intval', (array) get_comments( array_merge( $base, array( 'status' => 'spam' ) ) ) ),
			'ham'  => array_map( 'intval', (array) get_comments( array_merge( $base, array( 'status' => 'approve' ) ) ) ),
		);
	}

	/**
	 * Appends a history entry (non-unique meta, capped).
	 *
	 * @param int    $comment_id Comment id.
	 * @param string $event      Event slug.
	 * @param array  $extra      Extra fields.
	 */
	public function add_history( int $comment_id, string $event, array $extra = array() ) {
		$entry = array_merge(
			array(
				'event' => sanitize_key( $event ),
				'time'  => time(),
			),
			$extra
		);
		add_comment_meta( $comment_id, self::META_HISTORY, wp_slash( $entry ) );

		$all = get_comment_meta( $comment_id, self::META_HISTORY, false );
		if ( is_array( $all ) && count( $all ) > self::HISTORY_LIMIT ) {
			delete_comment_meta( $comment_id, self::META_HISTORY, wp_slash( $all[0] ) );
		}
	}

	/**
	 * History entries for a comment, oldest first.
	 *
	 * @param int $comment_id Comment id.
	 * @return array[]
	 */
	public function history( int $comment_id ): array {
		$entries = get_comment_meta( $comment_id, self::META_HISTORY, false );
		return array_values( array_filter( (array) $entries, 'is_array' ) );
	}

	/**
	 * Tier slug (spam|hold|ham) for history events.
	 *
	 * @param Verdict $verdict Verdict.
	 * @return string
	 */
	private function tier_slug( Verdict $verdict ): string {
		if ( $verdict->is_spam() ) {
			return 'spam';
		}
		return $verdict->is_hold() ? 'hold' : 'ham';
	}

	/**
	 * Counters for a successful verdict.
	 *
	 * @param Verdict $verdict Verdict.
	 */
	private function bump_verdict_stats( Verdict $verdict ) {
		Stats::bump( 'checked', 'comments' );
		if ( $verdict->is_spam() ) {
			Stats::bump( 'spam', 'comments' );
		} elseif ( $verdict->is_hold() ) {
			Stats::bump( 'held', 'comments' );
		}
	}

	/**
	 * Resets the per-request state (tests).
	 */
	public static function reset_request_state() {
		self::$last = null;
	}
}
