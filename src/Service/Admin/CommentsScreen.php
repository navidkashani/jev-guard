<?php
/**
 * Comments list & edit screen additions.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Admin;

use SpamLens\Service\Api\Client;
use SpamLens\Service\Classifier\Verdict;
use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Integrations\IntegrationManager;
use SpamLens\Service\Settings\Settings;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * "SpamLens" column, "Re-check with SpamLens" row action, "Check for Spam" button on the Pending view,
 * and the history meta box on the comment edit screen. The buttons call the REST routes in RestController.
 */
class CommentsScreen {

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
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_column' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'comment_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'manage_comments_nav', array( $this, 'bulk_button' ), 10, 2 );
		add_action( 'add_meta_boxes_comment', array( $this, 'add_meta_box' ) );
	}

	/**
	 * The comments integration.
	 *
	 * @return Comments|null
	 */
	private function comments() {
		return $this->integrations->comments();
	}

	// --- Column ---

	/**
	 * Adds the "SpamLens" column before the date column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['spamlens'] = __( 'SpamLens', 'spamlens' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['spamlens'] ) ) {
			$out['spamlens'] = __( 'SpamLens', 'spamlens' );
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
		if ( 'spamlens' !== $column ) {
			return;
		}
		echo self::cell_html( (int) $comment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_html().
	}

	/**
	 * Column cell markup (also returned by the recheck REST route).
	 *
	 * @param int $comment_id Comment id.
	 * @return string
	 */
	public static function cell_html( int $comment_id ): string {
		$meta    = get_comment_meta( $comment_id, Comments::META, true );
		$status  = 'none';
		$pill    = '';
		$details = array();
		$title   = '';

		if ( is_array( $meta ) && ! empty( $meta['skipped'] ) ) {
			$status    = 'skipped';
			$pill      = __( 'Skipped', 'spamlens' );
			$details[] = self::skip_label( (string) $meta['skipped'] );
		} elseif ( is_array( $meta ) && ! empty( $meta['error'] ) ) {
			$status    = 'error';
			$title     = (string) ( $meta['error']['message'] ?? '' );
			$pill      = get_comment_meta( $comment_id, Comments::META_ERROR, true ) ? __( 'Awaiting retry', 'spamlens' ) : __( 'Error', 'spamlens' );
			$details[] = Client::error_label( (string) ( $meta['error']['code'] ?? '' ) );
		} elseif ( is_array( $meta ) && isset( $meta['decision'] ) ) {
			$verdict = Verdict::from_array( $meta );
			$status  = $verdict->decision;
			$pill    = self::pill_text( $verdict );
			$details = self::detail_lines( $verdict );
			$title   = sprintf(
				/* translators: 1: reason, 2: model id, 3: date */
				__( 'Reason: %1$s. Model %2$s, %3$s', 'spamlens' ),
				$verdict->reason_label(),
				$verdict->model,
				$verdict->time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $verdict->time ) : ''
			);
		}

		$inner = '' === $pill ? esc_html__( 'Not checked', 'spamlens' ) : '<span class="spamlens-pill">' . esc_html( $pill ) . '</span>';
		foreach ( $details as $line ) {
			if ( '' !== $line ) {
				$inner .= '<span class="spamlens-detail">' . esc_html( $line ) . '</span>';
			}
		}

		return sprintf(
			'<span class="spamlens-cell spamlens-cell--%1$s" data-comment-id="%2$d"%3$s>%4$s</span>',
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
			return sprintf( __( 'Spam %d%%', 'spamlens' ), $pct );
		}
		if ( $verdict->is_hold() ) {
			/* translators: %d: spam probability as a percentage */
			return sprintf( __( 'Held %d%%', 'spamlens' ), $pct );
		}
		/* translators: %d: spam probability as a percentage */
		return sprintf( __( '%d%% spam', 'spamlens' ), $pct );
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
			'already_flagged'     => __( 'flagged by another plugin', 'spamlens' ),
			'disallowed'          => __( 'disallowed keys', 'spamlens' ),
			'empty'               => __( 'empty', 'spamlens' ),
			'moderator'           => __( 'moderator', 'spamlens' ),
			'previously_approved' => __( 'previously approved author', 'spamlens' ),
			'pingback'            => __( 'pingback', 'spamlens' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : str_replace( '_', ' ', $reason );
	}

	// --- Row action & bulk button ---

	/**
	 * Adds "Re-check with SpamLens".
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
		$actions['spamlens_recheck'] = sprintf(
			'<a href="#" class="spamlens-recheck" data-comment-id="%d" aria-label="%s">%s</a>',
			(int) $comment->comment_ID,
			esc_attr__( 'Re-check this comment with SpamLens', 'spamlens' ),
			esc_html__( 'Re-check with SpamLens', 'spamlens' )
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
		echo '<div class="alignleft actions spamlens-bulk">';
		echo '<button type="button" class="button" id="spamlens-check-all">' . esc_html__( 'Check for Spam', 'spamlens' ) . '</button> ';
		echo '<span id="spamlens-bulk-status" class="spamlens-inline-result" aria-live="polite"></span>';
		echo '</div>';
	}

	// --- Meta box ---

	/**
	 * Registers the meta box on the comment edit screen.
	 */
	public function add_meta_box() {
		add_meta_box( 'spamlens', __( 'SpamLens', 'spamlens' ), array( $this, 'render_meta_box' ), 'comment', 'normal', 'default' );
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

		echo '<p>' . self::cell_html( $id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_html().

		if ( is_array( $meta ) && isset( $meta['decision'] ) && empty( $meta['error'] ) && empty( $meta['skipped'] ) ) {
			$verdict = Verdict::from_array( $meta );
			$rows    = array(
				__( 'Decision', 'spamlens' )          => $verdict->decision . ' — ' . $verdict->reason_label(),
				__( 'Spam probability', 'spamlens' )  => null === $verdict->spam ? '—' : number_format_i18n( $verdict->spam, 3 ),
				__( 'Responds to page', 'spamlens' )  => null === $verdict->genuine ? '—' : number_format_i18n( $verdict->genuine, 3 ),
				__( 'Abuse score (0–2)', 'spamlens' ) => null === $verdict->abuse ? '—' : number_format_i18n( $verdict->abuse, 2 ),
				__( 'Category', 'spamlens' )          => $verdict->category_label() . ( null !== $verdict->category_confidence ? ' (' . number_format_i18n( $verdict->category_confidence * 100 ) . '%)' : '' ),
				__( 'Links', 'spamlens' )             => (string) $verdict->links,
				__( 'Model', 'spamlens' )             => $verdict->model,
				__( 'Checked', 'spamlens' )           => $verdict->time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $verdict->time ) : '—',
				__( 'Latency', 'spamlens' )           => $verdict->cached ? __( 'cached', 'spamlens' ) : $verdict->latency_ms . ' ms',
			);
			echo '<table class="widefat striped spamlens-verdict"><tbody>';
			foreach ( $rows as $label => $value ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<details><summary>' . esc_html__( 'Raw answers', 'spamlens' ) . '</summary><pre class="spamlens-raw">' . esc_html( wp_json_encode( $verdict->answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
		}

		$history = $comments ? $comments->history( $id ) : array();
		if ( ! empty( $history ) ) {
			echo '<h4>' . esc_html__( 'History', 'spamlens' ) . '</h4><ul class="spamlens-history">';
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
			echo '<p><button type="button" class="button spamlens-recheck" data-comment-id="' . (int) $id . '" data-reload="1">' . esc_html__( 'Re-check with SpamLens', 'spamlens' ) . '</button> <span class="spamlens-inline-result"></span></p>';
		}
	}
}
