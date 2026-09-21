<?php
/**
 * Submission value object.
 *
 * @package JevGuard
 */

namespace JevGuard;

use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one thing to classify: a comment, review, pingback or a form entry.
 * Integrations build one of these; the classifier turns it into API state.
 */
class Submission {

	/**
	 * Integration id (`comments`, `cf7`, ...).
	 *
	 * @var string
	 */
	public $integration = 'comments';

	/**
	 * `comment`, `review`, `pingback`, `trackback` or `contact_form`.
	 *
	 * @var string
	 */
	public $kind = 'comment';

	/**
	 * Main text (unslashed).
	 *
	 * @var string
	 */
	public $content = '';

	/**
	 * Author display name.
	 *
	 * @var string
	 */
	public $author_name = '';

	/**
	 * Author email.
	 *
	 * @var string
	 */
	public $author_email = '';

	/**
	 * Author URL.
	 *
	 * @var string
	 */
	public $author_url = '';

	/**
	 * WordPress user id (0 for guests).
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Client IP.
	 *
	 * @var string
	 */
	public $ip = '';

	/**
	 * User agent.
	 *
	 * @var string
	 */
	public $user_agent = '';

	/**
	 * Referer (live requests only).
	 *
	 * @var string
	 */
	public $referer = '';

	/**
	 * Post the comment belongs to.
	 *
	 * @var int
	 */
	public $post_id = 0;

	/**
	 * Parent comment id.
	 *
	 * @var int
	 */
	public $parent_id = 0;

	/**
	 * Comment id when rechecking an existing comment.
	 *
	 * @var int
	 */
	public $comment_id = 0;

	/**
	 * Form title (forms).
	 *
	 * @var string
	 */
	public $form_name = '';

	/**
	 * Other form fields, label => value (forms).
	 *
	 * @var array<string, string>
	 */
	public $fields = array();

	/**
	 * Key used for the main text inside the API state, so questions can reference it.
	 *
	 * @return string
	 */
	public function content_key(): string {
		return 'contact_form' === $this->kind ? 'submission' : 'comment';
	}

	/**
	 * Builds a submission from comment data (`preprocess_comment` passes it slashed, REST does not).
	 *
	 * @param array $commentdata Comment data.
	 * @param bool  $slashed     Whether the data is slashed.
	 * @return Submission
	 */
	public static function from_commentdata( array $commentdata, bool $slashed = true ): Submission {
		$data = $slashed ? wp_unslash( $commentdata ) : $commentdata;
		$s    = new self();

		$s->integration  = 'comments';
		$s->content      = (string) ( $data['comment_content'] ?? '' );
		$s->author_name  = (string) ( $data['comment_author'] ?? '' );
		$s->author_email = (string) ( $data['comment_author_email'] ?? '' );
		$s->author_url   = (string) ( $data['comment_author_url'] ?? '' );
		$s->user_id      = (int) ( $data['user_id'] ?? $data['user_ID'] ?? 0 );
		$s->ip           = (string) ( $data['comment_author_IP'] ?? '' );
		$s->user_agent   = (string) ( $data['comment_agent'] ?? '' );
		$s->post_id      = (int) ( $data['comment_post_ID'] ?? 0 );
		$s->parent_id    = (int) ( $data['comment_parent'] ?? 0 );
		$s->kind         = self::kind_for( (string) ( $data['comment_type'] ?? '' ), $s->post_id );

		$referer = wp_get_raw_referer();
		if ( $referer ) {
			$s->referer = (string) $referer;
		}

		return $s;
	}

	/**
	 * Builds a submission from a stored comment (recheck, calibration).
	 *
	 * @param WP_Comment $comment Comment.
	 * @return Submission
	 */
	public static function from_comment( WP_Comment $comment ): Submission {
		$s = new self();

		$s->integration  = 'comments';
		$s->comment_id   = (int) $comment->comment_ID;
		$s->content      = (string) $comment->comment_content;
		$s->author_name  = (string) $comment->comment_author;
		$s->author_email = (string) $comment->comment_author_email;
		$s->author_url   = (string) $comment->comment_author_url;
		$s->user_id      = (int) $comment->user_id;
		$s->ip           = (string) $comment->comment_author_IP;
		$s->user_agent   = (string) $comment->comment_agent;
		$s->post_id      = (int) $comment->comment_post_ID;
		$s->parent_id    = (int) $comment->comment_parent;
		$s->kind         = self::kind_for( (string) $comment->comment_type, $s->post_id );

		return $s;
	}

	/**
	 * Canned spam sample used by the admin "Test connection" button.
	 *
	 * @return Submission
	 */
	public static function sample(): Submission {
		$s               = new self();
		$s->kind         = 'comment';
		$s->author_name  = 'WatchDeals';
		$s->author_email = 'promo@example.com';
		$s->author_url   = 'http://cheap-replica-watches.example.com/';
		$s->content      = 'Great post! Buy cheap replica watches and get 70% off today at http://cheap-replica-watches.example.com — limited offer, act now!!!';
		$s->user_agent   = 'Mozilla/5.0 (compatible; SpamBot/1.0)';
		return $s;
	}

	/**
	 * Maps a comment type (and post type) to a submission kind.
	 *
	 * @param string $comment_type Comment type.
	 * @param int    $post_id      Post id.
	 * @return string
	 */
	private static function kind_for( string $comment_type, int $post_id ): string {
		if ( in_array( $comment_type, array( 'pingback', 'trackback' ), true ) ) {
			return $comment_type;
		}
		if ( 'review' === $comment_type ) {
			return 'review';
		}
		if ( $post_id > 0 && 'product' === get_post_type( $post_id ) ) {
			return 'review';
		}
		return 'comment';
	}
}
