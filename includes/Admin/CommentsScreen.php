<?php
/**
 * Comments list & edit screen additions.
 *
 * @package JevGuard
 */

namespace JevGuard\Admin;

use JevGuard\Api\Client;
use JevGuard\Integrations\Comments;
use JevGuard\Plugin;
use JevGuard\Settings;
use JevGuard\Verdict;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * "Jev" column, "Re-check with Jev" row action, "Check for Spam" button on the Pending view,
 * and the history meta box on the comment edit screen.
 */
class CommentsScreen {

	const BULK_BATCH  = 20;
	const BULK_BUDGET = 20;
	const BULK_LOCK   = 'jev_guard_bulk_lock';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_column' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'comment_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'manage_comments_nav', array( $this, 'bulk_button' ), 10, 2 );
		add_action( 'add_meta_boxes_comment', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_jev_guard_recheck', array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_jev_guard_bulk_batch', array( $this, 'ajax_bulk_batch' ) );
	}

	/**
	 * Enqueues assets on the comments screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( in_array( $hook, array( 'edit-comments.php', 'comment.php' ), true ) ) {
			SettingsPage::enqueue_assets( array( 'screen' => 'comments' ) );
		}
	}

	/**
	 * The comments integration.
	 *
	 * @return Comments|null
	 */
	private function comments() {
		$integration = $this->plugin->integration( 'comments' );
		return $integration instanceof Comments ? $integration : null;
	}

	// --- Column ---

	/**
	 * Adds the "Jev" column before the date column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['jev_guard'] = __( 'Jev', 'jev-guard' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['jev_guard'] ) ) {
			$out['jev_guard'] = __( 'Jev', 'jev-guard' );
		}
		return $out;
	}

	/**
	 * Renders the column cell.
	 *
	 * @param string $column     Column name.
	 * @param int    $comment_id Comment id.
	 */
	public function render_column( $column, $comment_id ) {
		if ( 'jev_guard' !== $column ) {
			return;
		}
		echo $this->cell_html( (int) $comment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_html().
	}

	/**
	 * Column cell markup (also returned by the recheck AJAX handler).
	 *
	 * @param int $comment_id Comment id.
	 * @return string
	 */
	public function cell_html( int $comment_id ): string {
		$meta    = get_comment_meta( $comment_id, Comments::META, true );
		$status  = 'none';
		$pill    = '';
		$details = array();
		$title   = '';

		if ( is_array( $meta ) && ! empty( $meta['skipped'] ) ) {
			$status    = 'skipped';
			$pill      = __( 'Skipped', 'jev-guard' );
			$details[] = self::skip_label( (string) $meta['skipped'] );
		} elseif ( is_array( $meta ) && ! empty( $meta['error'] ) ) {
			$status    = 'error';
			$title     = (string) ( $meta['error']['message'] ?? '' );
			$pill      = get_comment_meta( $comment_id, Comments::META_ERROR, true ) ? __( 'Awaiting retry', 'jev-guard' ) : __( 'Error', 'jev-guard' );
			$details[] = Client::error_label( (string) ( $meta['error']['code'] ?? '' ) );
		} elseif ( is_array( $meta ) && isset( $meta['decision'] ) ) {
			$verdict = Verdict::from_array( $meta );
			$status  = $verdict->decision;
			$pill    = self::pill_text( $verdict );
			$details = self::detail_lines( $verdict );
			$title   = sprintf(
				/* translators: 1: reason, 2: model id, 3: date */
				__( 'Reason: %1$s. Model %2$s, %3$s', 'jev-guard' ),
				$verdict->reason_label(),
				$verdict->model,
				$verdict->time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $verdict->time ) : ''
			);
		}

		$inner = '' === $pill ? esc_html__( 'Not checked', 'jev-guard' ) : '<span class="jev-guard-pill">' . esc_html( $pill ) . '</span>';
		foreach ( $details as $line ) {
			if ( '' !== $line ) {
				$inner .= '<span class="jev-guard-detail">' . esc_html( $line ) . '</span>';
			}
		}

		return sprintf(
			'<span class="jev-guard-cell jev-guard-cell--%1$s" data-comment-id="%2$d"%3$s>%4$s</span>',
			esc_attr( $status ),
			$comment_id,
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			$inner
		);
	}

	/**
	 * Decision-first pill text: "Spam 99%", "Held 63%", "6% spam".
	 *
	 * @param Verdict $verdict Verdict.
	 * @return string
	 */
	private static function pill_text( Verdict $verdict ): string {
		$pct = (int) round( $verdict->probability() * 100 );
		if ( $verdict->is_spam() ) {
			/* translators: %d: spam probability as a percentage */
			return sprintf( __( 'Spam %d%%', 'jev-guard' ), $pct );
		}
		if ( $verdict->is_hold() ) {
			/* translators: %d: spam probability as a percentage */
			return sprintf( __( 'Held %d%%', 'jev-guard' ), $pct );
		}
		/* translators: %d: spam probability as a percentage */
		return sprintf( __( '%d%% spam', 'jev-guard' ), $pct );
	}

	/**
	 * Lines under the pill: the category (unless an allowed comment is simply "legitimate") and,
	 * when the decision did not follow from the probability alone, the reason.
	 *
	 * @param Verdict $verdict Verdict.
	 * @return string[]
	 */
	private static function detail_lines( Verdict $verdict ): array {
		$lines    = array();
		$category = $verdict->category_label();
		if ( '' !== $category && ! ( $verdict->is_allow() && 'legitimate' === $verdict->category ) ) {
			$lines[] = $category;
		}
		if ( in_array( $verdict->reason, array( 'conflict', 'link_heavy', 'abusive', 'filter' ), true ) ) {
			$lines[] = $verdict->reason_label();
		}
		return $lines;
	}

	/**
	 * Skip reason → label.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	public static function skip_label( string $reason ): string {
		$labels = array(
			'already_flagged'     => __( 'flagged by another plugin', 'jev-guard' ),
			'disallowed'          => __( 'disallowed keys', 'jev-guard' ),
			'empty'               => __( 'empty', 'jev-guard' ),
			'moderator'           => __( 'moderator', 'jev-guard' ),
			'previously_approved' => __( 'previously approved author', 'jev-guard' ),
			'pingback'            => __( 'pingback', 'jev-guard' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : str_replace( '_', ' ', $reason );
	}

	// --- Row action & bulk button ---

	/**
	 * Adds "Re-check with Jev".
	 *
	 * @param array      $actions Actions.
	 * @param WP_Comment $comment Comment.
	 * @return array
	 */
	public function row_actions( $actions, $comment ) {
		if ( ! Settings::is_configured() || ! Settings::integration_enabled( 'comments' ) ) {
			return $actions;
		}
		if ( ! $comment instanceof WP_Comment || ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return $actions;
		}
		$actions['jev_guard_recheck'] = sprintf(
			'<a href="#" class="jev-guard-recheck" data-comment-id="%d" aria-label="%s">%s</a>',
			(int) $comment->comment_ID,
			esc_attr__( 'Re-check this comment with Jev', 'jev-guard' ),
			esc_html__( 'Re-check with Jev', 'jev-guard' )
		);
		return $actions;
	}

	/**
	 * "Check for Spam" on the Pending view.
	 *
	 * @param string $comment_status Current view.
	 * @param string $which          top|bottom.
	 */
	public function bulk_button( $comment_status, $which ) {
		if ( 'moderated' !== $comment_status || 'top' !== $which ) {
			return;
		}
		if ( ! current_user_can( 'moderate_comments' ) || ! Settings::is_configured() || ! Settings::integration_enabled( 'comments' ) ) {
			return;
		}
		echo '<div class="alignleft actions jev-guard-bulk">';
		echo '<button type="button" class="button" id="jev-guard-check-all">' . esc_html__( 'Check for Spam', 'jev-guard' ) . '</button> ';
		echo '<span id="jev-guard-bulk-status" class="jev-guard-inline-result" aria-live="polite"></span>';
		echo '</div>';
	}

	// --- Meta box ---

	/**
	 * Registers the meta box on the comment edit screen.
	 */
	public function add_meta_box() {
		add_meta_box( 'jev-guard', __( 'Jev Guard', 'jev-guard' ), array( $this, 'render_meta_box' ), 'comment', 'normal', 'default' );
	}

	/**
	 * Renders the meta box.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	public function render_meta_box( $comment ) {
		$comments = $this->comments();
		$id       = (int) $comment->comment_ID;
		$meta     = get_comment_meta( $id, Comments::META, true );

		echo '<p>' . $this->cell_html( $id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_html().

		if ( is_array( $meta ) && isset( $meta['decision'] ) && empty( $meta['error'] ) && empty( $meta['skipped'] ) ) {
			$verdict = Verdict::from_array( $meta );
			$rows    = array(
				__( 'Decision', 'jev-guard' )          => $verdict->decision . ' — ' . $verdict->reason_label(),
				__( 'Spam probability', 'jev-guard' )  => null === $verdict->spam ? '—' : number_format_i18n( $verdict->spam, 3 ),
				__( 'Responds to page', 'jev-guard' )  => null === $verdict->genuine ? '—' : number_format_i18n( $verdict->genuine, 3 ),
				__( 'Abuse score (0–2)', 'jev-guard' ) => null === $verdict->abuse ? '—' : number_format_i18n( $verdict->abuse, 2 ),
				__( 'Category', 'jev-guard' )          => $verdict->category_label() . ( null !== $verdict->category_confidence ? ' (' . number_format_i18n( $verdict->category_confidence * 100 ) . '%)' : '' ),
				__( 'Links', 'jev-guard' )             => (string) $verdict->links,
				__( 'Model', 'jev-guard' )             => $verdict->model,
				__( 'Checked', 'jev-guard' )           => $verdict->time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $verdict->time ) : '—',
				__( 'Latency', 'jev-guard' )           => $verdict->cached ? __( 'cached', 'jev-guard' ) : $verdict->latency_ms . ' ms',
			);
			echo '<table class="widefat striped jev-guard-verdict"><tbody>';
			foreach ( $rows as $label => $value ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<details><summary>' . esc_html__( 'Raw answers', 'jev-guard' ) . '</summary><pre class="jev-guard-raw">' . esc_html( wp_json_encode( $verdict->answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
		}

		$history = $comments ? $comments->history( $id ) : array();
		if ( ! empty( $history ) ) {
			echo '<h4>' . esc_html__( 'History', 'jev-guard' ) . '</h4><ul class="jev-guard-history">';
			foreach ( array_reverse( $history ) as $entry ) {
				$line = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) . ' — ' . $entry['event'];
				if ( isset( $entry['p'] ) && is_numeric( $entry['p'] ) ) {
					$line .= ' (' . number_format_i18n( (float) $entry['p'] * 100 ) . '%)';
				}
				if ( ! empty( $entry['message'] ) ) {
					$line .= ': ' . $entry['message'];
				}
				if ( ! empty( $entry['user'] ) ) {
					$user  = get_userdata( (int) $entry['user'] );
					$line .= $user ? ' — ' . $user->display_name : '';
				}
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $comments && Settings::is_configured() && current_user_can( 'edit_comment', $id ) ) {
			echo '<p><button type="button" class="button jev-guard-recheck" data-comment-id="' . (int) $id . '" data-reload="1">' . esc_html__( 'Re-check with Jev', 'jev-guard' ) . '</button> <span class="jev-guard-inline-result"></span></p>';
		}
	}

	// --- AJAX ---

	/**
	 * Re-checks one comment.
	 */
	public function ajax_recheck() {
		check_ajax_referer( SettingsPage::NONCE, 'nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$id = isset( $_POST['comment_id'] ) ? (int) $_POST['comment_id'] : 0;
		if ( $id <= 0 || ! current_user_can( 'edit_comment', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'jev-guard' ) ), 403 );
		}
		$comments = $this->comments();
		if ( ! $comments ) {
			wp_send_json_error( array( 'message' => __( 'Comments integration unavailable.', 'jev-guard' ) ) );
		}
		$before  = get_comment( $id );
		$verdict = $comments->recheck( $id, 'recheck' );
		if ( is_wp_error( $verdict ) ) {
			wp_send_json_error(
				array(
					'code'    => $verdict->get_error_code(),
					'message' => Client::error_label( $verdict->get_error_code() ) . ' — ' . $verdict->get_error_message(),
					'html'    => $this->cell_html( $id ),
				)
			);
		}
		$after = get_comment( $id );
		wp_send_json_success(
			array(
				'html'     => $this->cell_html( $id ),
				'decision' => $verdict->decision,
				'label'    => $verdict->label(),
				'moved'    => $before && $after && $before->comment_approved !== $after->comment_approved,
				'status'   => $after ? $after->comment_approved : '',
			)
		);
	}

	/**
	 * Processes one batch of pending comments (cursor = last comment id).
	 */
	public function ajax_bulk_batch() {
		check_ajax_referer( SettingsPage::NONCE, 'nonce' );
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'jev-guard' ) ), 403 );
		}
		$comments = $this->comments();
		if ( ! $comments ) {
			wp_send_json_error( array( 'message' => __( 'Comments integration unavailable.', 'jev-guard' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$after = isset( $_POST['after'] ) ? (int) $_POST['after'] : 0;
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable

		$lock = get_transient( self::BULK_LOCK );
		if ( $lock && $lock !== $token ) {
			wp_send_json_error( array( 'message' => __( 'Another Check for Spam run is in progress.', 'jev-guard' ) ) );
		}
		set_transient( self::BULK_LOCK, $token, 2 * MINUTE_IN_SECONDS );

		global $wpdb;
		$total = 0;
		if ( 0 === $after ) {
			$total = (int) wp_count_comments()->moderated;
		}
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
				if ( in_array( $code, array( 'rate_limited', 'overloaded' ), true ) ) {
					$halt = array(
						'code'        => $code,
						'message'     => $verdict->get_error_message(),
						'retry_after' => (float) ( $verdict->get_error_data()['retry_after'] ?? 0 ),
					);
					break; // Retry this id after the pause.
				}
				if ( in_array( $code, array( 'auth', 'payment_required', 'no_api_key', 'not_configured' ), true ) ) {
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

		$done = ( count( $ids ) < self::BULK_BATCH && $last_id >= (int) end( $ids ) && null === $halt ) || empty( $ids );
		if ( $done || ( $halt && ! empty( $halt['fatal'] ) ) ) {
			delete_transient( self::BULK_LOCK );
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'spam'      => $spam,
				'errors'    => $errors,
				'last_id'   => $last_id,
				'total'     => $total,
				'done'      => $done,
				'halt'      => $halt,
			)
		);
	}
}
