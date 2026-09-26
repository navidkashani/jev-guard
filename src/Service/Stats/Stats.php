<?php
/**
 * Counters.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Simple counters in the `spamlens_stats` option.
 */
class Stats {

	const OPTION = 'spamlens_stats';

	/**
	 * Empty counters.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'since'        => time(),
			'checked'      => 0,
			'spam'         => 0,
			'held'         => 0,
			'errors'       => 0,
			'fp'           => 0,
			'fn'           => 0,
			'integrations' => array(),
		);
	}

	/**
	 * Current counters.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stats = get_option( self::OPTION, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		return array_merge( self::defaults(), $stats );
	}

	/**
	 * Increments a counter (globally and, when given, per integration).
	 *
	 * @param string $key         checked|spam|held|errors|fp|fn.
	 * @param string $integration Integration id.
	 * @param int    $by          Increment.
	 */
	public static function bump( string $key, string $integration = '', int $by = 1 ) {
		$stats = self::get();
		if ( ! isset( $stats[ $key ] ) || ! is_int( $stats[ $key ] ) ) {
			$stats[ $key ] = 0;
		}
		$stats[ $key ] += $by;

		if ( '' !== $integration ) {
			if ( ! isset( $stats['integrations'][ $integration ] ) || ! is_array( $stats['integrations'][ $integration ] ) ) {
				$stats['integrations'][ $integration ] = array();
			}
			$current                                       = (int) ( $stats['integrations'][ $integration ][ $key ] ?? 0 );
			$stats['integrations'][ $integration ][ $key ] = $current + $by;
		}

		update_option( self::OPTION, $stats, false );
	}

	/**
	 * Resets all counters.
	 */
	public static function reset() {
		update_option( self::OPTION, self::defaults(), false );
	}
}
