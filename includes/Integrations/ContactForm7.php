<?php
/**
 * Contact Form 7 integration.
 *
 * @package JevGuard
 */

namespace JevGuard\Integrations;

use JevGuard\Classifier;
use JevGuard\Settings;
use JevGuard\Stats;
use JevGuard\Submission;
use JevGuard\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks `wpcf7_spam`. Spam → CF7's spam response (no mail, Flamingo logs the reason);
 * hold/allow/error → untouched unless a filter says otherwise.
 */
class ContactForm7 implements Integration {

	/**
	 * Classifier.
	 *
	 * @var Classifier
	 */
	private $classifier;

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
		return 'cf7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Contact Form 7', 'jev-guard' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		add_filter( 'wpcf7_spam', array( $this, 'filter_spam' ), 10, 2 );
	}

	/**
	 * `wpcf7_spam` callback.
	 *
	 * @param bool   $spam       Whether CF7 (reCAPTCHA, Akismet, disallowed list) already flagged it.
	 * @param object $submission WPCF7_Submission.
	 * @return bool
	 */
	public function filter_spam( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;
		}
		if ( ! Settings::integration_enabled( 'cf7' ) || ! Settings::is_configured() ) {
			return $spam;
		}
		if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return $spam;
		}

		$s = $this->build_submission( $submission );
		if ( '' === trim( $s->content ) ) {
			return $spam;
		}

		$verdict = $this->classifier->classify( $s );

		if ( is_wp_error( $verdict ) ) {
			Stats::bump( 'errors', 'cf7' );
			return $spam;
		}

		Stats::bump( 'checked', 'cf7' );

		/**
		 * Fires after a Contact Form 7 submission was classified.
		 *
		 * @param Verdict $verdict    Verdict.
		 * @param object  $submission WPCF7_Submission.
		 */
		do_action( 'jev_guard_cf7_verdict', $verdict, $submission );

		if ( $verdict->is_spam() ) {
			Stats::bump( 'spam', 'cf7' );
			$this->log_spam( $submission, $verdict );
			return true;
		}

		if ( $verdict->is_hold() ) {
			Stats::bump( 'held', 'cf7' );
			/**
			 * Filters whether a borderline (hold) verdict should be treated as spam for forms,
			 * which have no moderation queue.
			 *
			 * @param bool    $treat_as_spam Default false.
			 * @param Verdict $verdict       Verdict.
			 * @param object  $submission    WPCF7_Submission.
			 */
			if ( apply_filters( 'jev_guard_cf7_treat_hold_as_spam', false, $verdict, $submission ) ) {
				$this->log_spam( $submission, $verdict );
				return true;
			}
		}

		return $spam;
	}

	/**
	 * Writes the CF7 spam log entry (shown by Flamingo).
	 *
	 * @param object  $submission WPCF7_Submission.
	 * @param Verdict $verdict    Verdict.
	 */
	private function log_spam( $submission, Verdict $verdict ) {
		if ( ! method_exists( $submission, 'add_spam_log' ) ) {
			return;
		}
		$submission->add_spam_log(
			array(
				'agent'  => 'jev_guard',
				'reason' => $verdict->label(),
			)
		);
	}

	/**
	 * Builds a Submission from the posted form data.
	 *
	 * @param object $submission WPCF7_Submission.
	 * @return Submission
	 */
	public function build_submission( $submission ): Submission {
		$s              = new Submission();
		$s->integration = 'cf7';
		$s->kind        = 'contact_form';
		$s->user_id     = get_current_user_id();

		$form = method_exists( $submission, 'get_contact_form' ) ? $submission->get_contact_form() : null;
		if ( is_object( $form ) ) {
			$s->form_name = method_exists( $form, 'title' ) ? (string) $form->title() : '';
		}

		$skip_keys = array( 'g-recaptcha-response', 'cf-turnstile-response', 'h-captcha-response' );
		$textareas = array();
		if ( is_object( $form ) && method_exists( $form, 'scan_form_tags' ) ) {
			foreach ( (array) $form->scan_form_tags( array( 'basetype' => 'file' ) ) as $tag ) {
				$skip_keys[] = (string) $tag->name;
			}
			foreach ( (array) $form->scan_form_tags( array( 'basetype' => 'textarea' ) ) as $tag ) {
				$textareas[] = (string) $tag->name;
			}
		}
		if ( method_exists( $submission, 'uploaded_files' ) ) {
			$skip_keys = array_merge( $skip_keys, array_keys( (array) $submission->uploaded_files() ) );
		}

		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || in_array( $key, $skip_keys, true ) ) {
				continue;
			}
			$value = trim( $this->flatten( $value ) );
			if ( '' === $value ) {
				continue;
			}
			$fields[ $key ] = $value;
		}

		// Email: first value that looks like one. Name: first field whose name mentions "name".
		foreach ( $fields as $key => $value ) {
			if ( '' === $s->author_email && is_email( $value ) ) {
				$s->author_email = $value;
				unset( $fields[ $key ] );
				continue;
			}
			if ( '' === $s->author_name && false !== stripos( $key, 'name' ) && ! is_email( $value ) ) {
				$s->author_name = $value;
				unset( $fields[ $key ] );
			}
		}

		// Main text: textarea fields if any, otherwise the longest remaining value.
		$body = array();
		foreach ( $textareas as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$body[] = $fields[ $name ];
				unset( $fields[ $name ] );
			}
		}
		if ( empty( $body ) && ! empty( $fields ) ) {
			$longest_key = null;
			foreach ( $fields as $key => $value ) {
				if ( null === $longest_key || mb_strlen( $value ) > mb_strlen( $fields[ $longest_key ] ) ) {
					$longest_key = $key;
				}
			}
			$body[] = $fields[ $longest_key ];
			unset( $fields[ $longest_key ] );
		}
		$s->content = implode( "\n\n", $body );

		if ( ! Settings::get( 'send_email' ) ) {
			foreach ( $fields as $key => $value ) {
				if ( is_email( $value ) ) {
					unset( $fields[ $key ] );
				}
			}
		}
		$s->fields = $fields;

		if ( method_exists( $submission, 'get_meta' ) ) {
			$s->ip         = (string) $submission->get_meta( 'remote_ip' );
			$s->user_agent = (string) $submission->get_meta( 'user_agent' );
			$s->referer    = (string) $submission->get_meta( 'url' );
		}

		/**
		 * Filters the Submission built from a CF7 entry.
		 *
		 * @param Submission $s          Submission.
		 * @param object     $submission WPCF7_Submission.
		 */
		$filtered = apply_filters( 'jev_guard_cf7_submission', $s, $submission );
		return $filtered instanceof Submission ? $filtered : $s;
	}

	/**
	 * Flattens nested arrays (checkboxes, selects) to a comma-separated string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function flatten( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$item = $this->flatten( $item );
				if ( '' !== $item ) {
					$parts[] = $item;
				}
			}
			return implode( ', ', $parts );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
