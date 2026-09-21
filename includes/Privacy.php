<?php
/**
 * Privacy helpers.
 *
 * @package JevGuard
 */

namespace JevGuard;

use JevGuard\Api\Providers;
use JevGuard\Integrations\Comments;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy-policy suggestion, optional comment-form notice and export exclusions.
 */
class Privacy {

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_action( 'comment_form', array( $this, 'comment_form_notice' ) );
		add_filter( 'wxr_export_skip_commentmeta', array( $this, 'skip_export_meta' ), 10, 2 );
	}

	/**
	 * Suggested privacy-policy text (Settings → Privacy → Policy Guide).
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text, adjust the provider name to the one selected in Settings → Jev Guard.', 'jev-guard' ) . '</p>';
		$content .= '<p>' . esc_html__( 'We use Jev, a decision model by TypeSafe AI, to detect spam in comments, reviews and contact-form submissions. When you submit one, its text, the name and website you entered, details of the page it was posted on (title, type, tags and categories and, depending on our configuration, an excerpt and headings), the comment you are replying to and, depending on our configuration, your email address, IP address and browser user agent are sent to the service that hosts the model (TypeSafe AI, Vercel AI Gateway or OpenRouter) for the sole purpose of estimating whether the submission is spam. The service returns probabilities only; we store those probabilities alongside the comment and do not store any additional personal data from the check.', 'jev-guard' ) . '</p>';
		$content .= '<ul>';
		foreach ( array(
			'typesafe'   => 'https://typesafe.ai/privacy',
			'vercel'     => 'https://vercel.com/legal/privacy-policy',
			'openrouter' => 'https://openrouter.ai/privacy',
		) as $id => $url ) {
			$preset = Providers::get( $id );
			if ( $preset ) {
				$content .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $preset['label'] ) . '</a></li>';
			}
		}
		$content .= '</ul>';

		wp_add_privacy_policy_content( __( 'Jev Guard', 'jev-guard' ), wp_kses_post( $content ) );
	}

	/**
	 * Optional notice under the comment form.
	 */
	public function comment_form_notice() {
		if ( ! Settings::get( 'privacy_notice' ) || ! Settings::integration_enabled( 'comments' ) ) {
			return;
		}
		$policy = get_privacy_policy_url();
		$text   = __( 'This site uses Jev by TypeSafe AI to reduce spam.', 'jev-guard' );
		echo '<p class="jev-guard-privacy-notice">' . esc_html( $text );
		if ( $policy ) {
			echo ' <a href="' . esc_url( $policy ) . '">' . esc_html__( 'Learn how your comment data is processed.', 'jev-guard' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Keeps operational meta out of WXR exports (the verdict itself is exported with the comment).
	 *
	 * @param bool   $skip     Whether to skip.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public function skip_export_meta( $skip, $meta_key ) {
		if ( in_array( $meta_key, array( Comments::META_HISTORY, Comments::META_ERROR, Comments::META_RECHECKING ), true ) ) {
			return true;
		}
		return $skip;
	}
}
