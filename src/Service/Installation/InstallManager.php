<?php
/**
 * Activation and deactivation.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Installation;

use SpamLens\Service\Admin\Notices;
use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Settings\Settings;
use SpamLens\Service\Stats\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing to create on activation: settings fall back to defaults until they are saved. Deactivation drops the
 * retry cron event; comment meta and settings stay until the plugin is deleted (uninstall.php).
 */
class InstallManager {

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 */
	public static function activate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		if ( false === get_option( 'spamlens_version' ) ) {
			add_option( 'spamlens_version', SPAMLENS_VERSION, '', false );
		}
		self::import_jev_guard();
	}

	/**
	 * SpamLens was called Jev Guard up to 1.0.0 (GitHub only, folder jev-guard). On activation:
	 *
	 * - Jev Guard is switched off if it is active, so a comment is not checked (and paid for) twice;
	 * - its settings and counters are copied when SpamLens has none of its own;
	 * - its comment meta (verdicts, history, the retry queue) and the dismissed-notice flag move to SpamLens's keys,
	 *   and comments that were waiting for a retry are scheduled again.
	 *
	 * The old options stay for Jev Guard's own uninstall. Safe to run more than once.
	 */
	public static function import_jev_guard() {
		$old_plugin = 'jev-guard/jev-guard.php';
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $old_plugin ) ) {
			deactivate_plugins( $old_plugin, true );
		}
		wp_clear_scheduled_hook( 'jev_guard_retry' );

		foreach ( array(
			'jev_guard_settings' => Settings::OPTION,
			'jev_guard_stats'    => Stats::OPTION,
		) as $old => $new ) {
			$value = get_option( $old );
			if ( is_array( $value ) && false === get_option( $new ) ) {
				add_option( $new, $value, '', Settings::OPTION === $new );
			}
		}

		global $wpdb;
		$keys  = array(
			'_jev_guard'            => Comments::META,
			'_jev_guard_decision'   => Comments::META_DECISION,
			'_jev_guard_history'    => Comments::META_HISTORY,
			'_jev_guard_error'      => Comments::META_ERROR,
			'_jev_guard_rechecking' => Comments::META_RECHECKING,
		);
		$moved = 0;
		foreach ( $keys as $old => $new ) {
			// One-off rename of this plugin's own meta keys; no API renames a meta key.
			$moved += (int) $wpdb->update( $wpdb->commentmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}
		// Same, for the dismissed-notice flag.
		$wpdb->update( $wpdb->usermeta, array( 'meta_key' => Notices::DISMISS_META ), array( 'meta_key' => 'jev_guard_dismissed_setup' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		if ( $moved > 0 ) {
			wp_cache_flush_group( 'comment_meta' );
			if ( get_comments(
				array(
					'meta_key' => Comments::META_ERROR, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'number'   => 1,
					'fields'   => 'ids',
					'status'   => 'all',
				)
			) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Comments::CRON_HOOK );
			}
		}
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Comments::CRON_HOOK );
	}
}
