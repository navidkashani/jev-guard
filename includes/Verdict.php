<?php
/**
 * Verdict value object.
 *
 * @package JevGuard
 */

namespace JevGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Parsed answers plus the decision tier derived from them. Serialisable to comment meta.
 */
class Verdict {

	const SPAM  = 'spam';
	const HOLD  = 'hold';
	const ALLOW = 'allow';

	const DEFAULT_ABUSE_THRESHOLD = 1.5;

	/**
	 * Per-check GUID used to match `pre_comment_approved` with `preprocess_comment`.
	 *
	 * @var string
	 */
	public $guid = '';

	/**
	 * Versioned model id returned by the provider (e.g. `jev-1.13.0`).
	 *
	 * @var string
	 */
	public $model = '';

	/**
	 * Raw answers as returned.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Usage block as returned.
	 *
	 * @var array
	 */
	public $usage = array();

	/**
	 * `spam`, `hold` or `allow`.
	 *
	 * @var string
	 */
	public $decision = self::ALLOW;

	/**
	 * Machine-readable reason for the decision (`threshold`, `link_heavy`, `conflict`, `borderline`, `abusive`, `clear`).
	 *
	 * @var string
	 */
	public $reason = '';

	/**
	 * Spam probability 0..1.
	 *
	 * @var float|null
	 */
	public $spam = null;

	/**
	 * Probability that the text responds to this specific page.
	 *
	 * @var float|null
	 */
	public $genuine = null;

	/**
	 * Abuse score 0..2 (index into the legend).
	 *
	 * @var float|null
	 */
	public $abuse = null;

	/**
	 * Category id.
	 *
	 * @var string
	 */
	public $category = '';

	/**
	 * Category confidence 0..1.
	 *
	 * @var float|null
	 */
	public $category_confidence = null;

	/**
	 * Number of links found in the content.
	 *
	 * @var int
	 */
	public $links = 0;

	/**
	 * Unix time of the check.
	 *
	 * @var int
	 */
	public $time = 0;

	/**
	 * Whether the answers came from the transient cache.
	 *
	 * @var bool
	 */
	public $cached = false;

	/**
	 * Round-trip latency in ms (0 when cached).
	 *
	 * @var int
	 */
	public $latency_ms = 0;

	/**
	 * Builds a verdict from API answers.
	 *
	 * @param array  $answers Answers map.
	 * @param string $model   Versioned model id.
	 * @param array  $usage   Usage block.
	 * @return Verdict
	 */
	public static function from_answers( array $answers, string $model = '', array $usage = array() ): Verdict {
		$v          = new self();
		$v->answers = $answers;
		$v->model   = $model;
		$v->usage   = $usage;
		$v->time    = time();

		$v->spam    = self::read_float( $answers['spam'] ?? null, array( 'noul', 'value', 'probability' ) );
		$v->genuine = self::read_float( $answers['genuine'] ?? null, array( 'noul', 'value', 'probability' ) );
		$v->abuse   = self::read_float( $answers['abuse'] ?? null, array( 'score', 'value' ) );

		if ( isset( $answers['category'] ) && is_array( $answers['category'] ) ) {
			$choice = $answers['category']['choice'] ?? ( $answers['category']['value'] ?? '' );
			if ( is_string( $choice ) ) {
				$v->category = sanitize_key( $choice );
			}
			$v->category_confidence = self::read_float( $answers['category'], array( 'confidence' ) );
		}

		return $v;
	}

	/**
	 * Reads the first numeric key from an answer.
	 *
	 * @param mixed $answer Answer block.
	 * @param array $keys   Candidate keys.
	 * @return float|null
	 */
	private static function read_float( $answer, array $keys ) {
		if ( is_numeric( $answer ) ) {
			return (float) $answer;
		}
		if ( ! is_array( $answer ) ) {
			return null;
		}
		foreach ( $keys as $key ) {
			if ( isset( $answer[ $key ] ) && is_numeric( $answer[ $key ] ) ) {
				return (float) $answer[ $key ];
			}
		}
		return null;
	}

	/**
	 * Applies the 3-tier policy.
	 *
	 * @param array $opts `spam_threshold`, `hold_threshold`, `hold_abusive`, `abuse_threshold`, `link_count`, `max_links`.
	 * @return string The decision.
	 */
	public function decide( array $opts ): string {
		$spam_threshold  = (float) ( $opts['spam_threshold'] ?? 0.85 );
		$hold_threshold  = (float) ( $opts['hold_threshold'] ?? 0.5 );
		$hold_abusive    = ! empty( $opts['hold_abusive'] );
		$abuse_threshold = (float) ( $opts['abuse_threshold'] ?? self::DEFAULT_ABUSE_THRESHOLD );
		$link_count      = (int) ( $opts['link_count'] ?? $this->links );
		$max_links       = (int) ( $opts['max_links'] ?? 0 );

		$p = null === $this->spam ? 0.0 : (float) $this->spam;

		if ( $max_links > 0 && $link_count >= $max_links && $p >= 0.5 ) {
			$decision = self::SPAM;
			$reason   = 'link_heavy';
		} elseif ( $p >= $spam_threshold ) {
			if ( null !== $this->genuine && $this->genuine >= 0.5 ) {
				$decision = self::HOLD;
				$reason   = 'conflict';
			} else {
				$decision = self::SPAM;
				$reason   = 'threshold';
			}
		} elseif ( $p >= $hold_threshold ) {
			$decision = self::HOLD;
			$reason   = 'borderline';
		} elseif ( $hold_abusive && null !== $this->abuse && $this->abuse >= $abuse_threshold ) {
			$decision = self::HOLD;
			$reason   = 'abusive';
		} else {
			$decision = self::ALLOW;
			$reason   = 'clear';
		}

		/**
		 * Filters the decision tier. Return `spam`, `hold` or `allow`.
		 *
		 * @param string  $decision Decision.
		 * @param Verdict $verdict  Verdict (answers populated, decision not yet stored).
		 * @param array   $opts     Options used.
		 */
		$filtered = apply_filters( 'jev_guard_decision', $decision, $this, $opts );
		if ( in_array( $filtered, array( self::SPAM, self::HOLD, self::ALLOW ), true ) ) {
			if ( $filtered !== $decision ) {
				$reason = 'filter';
			}
			$decision = $filtered;
		}

		$this->decision = $decision;
		$this->reason   = $reason;
		return $decision;
	}

	/**
	 * Spam probability (0 when unknown).
	 *
	 * @return float
	 */
	public function probability(): float {
		return null === $this->spam ? 0.0 : (float) $this->spam;
	}

	/**
	 * Whether the decision is spam.
	 *
	 * @return bool
	 */
	public function is_spam(): bool {
		return self::SPAM === $this->decision;
	}

	/**
	 * Whether the decision is hold.
	 *
	 * @return bool
	 */
	public function is_hold(): bool {
		return self::HOLD === $this->decision;
	}

	/**
	 * Whether the decision is allow.
	 *
	 * @return bool
	 */
	public function is_allow(): bool {
		return self::ALLOW === $this->decision;
	}

	/**
	 * Category id → label.
	 *
	 * @return array<string, string>
	 */
	public static function category_labels(): array {
		return array(
			'legitimate'           => __( 'Legitimate', 'jev-guard' ),
			'commercial_promotion' => __( 'Commercial promotion', 'jev-guard' ),
			'seo_link_spam'        => __( 'SEO link spam', 'jev-guard' ),
			'scam_or_phishing'     => __( 'Scam or phishing', 'jev-guard' ),
			'gibberish_or_bot'     => __( 'Gibberish or bot', 'jev-guard' ),
			'abusive'              => __( 'Abusive', 'jev-guard' ),
		);
	}

	/**
	 * Human label for the category.
	 *
	 * @return string
	 */
	public function category_label(): string {
		$labels = self::category_labels();
		if ( isset( $labels[ $this->category ] ) ) {
			return $labels[ $this->category ];
		}
		return '' !== $this->category ? ucfirst( str_replace( '_', ' ', $this->category ) ) : '';
	}

	/**
	 * Reason → label.
	 *
	 * @return string
	 */
	public function reason_label(): string {
		$labels = array(
			'threshold'  => __( 'above the spam threshold', 'jev-guard' ),
			'link_heavy' => __( 'link-heavy with a spam signal', 'jev-guard' ),
			'conflict'   => __( 'looks like spam but responds to the page', 'jev-guard' ),
			'borderline' => __( 'borderline probability', 'jev-guard' ),
			'abusive'    => __( 'abusive or harassing', 'jev-guard' ),
			'clear'      => __( 'below the hold threshold', 'jev-guard' ),
			'filter'     => __( 'changed by a filter', 'jev-guard' ),
		);
		return isset( $labels[ $this->reason ] ) ? $labels[ $this->reason ] : $this->reason;
	}

	/**
	 * Short label such as "94% spam · SEO link spam".
	 *
	 * @return string
	 */
	public function label(): string {
		$pct   = (int) round( $this->probability() * 100 );
		$parts = array();

		if ( $this->is_spam() ) {
			/* translators: %d: spam probability as a percentage */
			$parts[] = sprintf( __( '%d%% spam', 'jev-guard' ), $pct );
		} else {
			/* translators: %d: spam probability as a percentage */
			$parts[] = sprintf( __( '%d%%', 'jev-guard' ), $pct );
		}

		$category = $this->category_label();
		if ( '' !== $category ) {
			$parts[] = $category;
		}
		if ( $this->is_hold() ) {
			$parts[] = __( 'held', 'jev-guard' );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Serialises for comment meta / JSON.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'guid'                => $this->guid,
			'model'               => $this->model,
			'decision'            => $this->decision,
			'reason'              => $this->reason,
			'spam'                => $this->spam,
			'genuine'             => $this->genuine,
			'abuse'               => $this->abuse,
			'category'            => $this->category,
			'category_confidence' => $this->category_confidence,
			'links'               => $this->links,
			'time'                => $this->time,
			'cached'              => $this->cached,
			'latency_ms'          => $this->latency_ms,
			'answers'             => $this->answers,
			'usage'               => $this->usage,
		);
	}

	/**
	 * Restores from `to_array()` output.
	 *
	 * @param array $data Data.
	 * @return Verdict
	 */
	public static function from_array( array $data ): Verdict {
		$v = new self();
		foreach ( array( 'guid', 'model', 'decision', 'reason', 'category' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$v->$key = (string) $data[ $key ];
			}
		}
		foreach ( array( 'spam', 'genuine', 'abuse', 'category_confidence' ) as $key ) {
			$v->$key = isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? (float) $data[ $key ] : null;
		}
		$v->links      = (int) ( $data['links'] ?? 0 );
		$v->time       = (int) ( $data['time'] ?? 0 );
		$v->cached     = ! empty( $data['cached'] );
		$v->latency_ms = (int) ( $data['latency_ms'] ?? 0 );
		$v->answers    = is_array( $data['answers'] ?? null ) ? $data['answers'] : array();
		$v->usage      = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		if ( ! in_array( $v->decision, array( self::SPAM, self::HOLD, self::ALLOW ), true ) ) {
			$v->decision = self::ALLOW;
		}
		return $v;
	}
}
