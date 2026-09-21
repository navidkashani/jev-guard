<?php
/**
 * Uninstall routine: removes every option, comment meta, transient and cron event the plugin created.
 *
 * @package JevGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'jev_guard_settings', 'jev_guard_stats', 'jev_guard_last_error' ) as $jev_guard_option ) {
	delete_option( $jev_guard_option );
}

foreach ( array( '_jev_guard', '_jev_guard_decision', '_jev_guard_history', '_jev_guard_error', '_jev_guard_rechecking' ) as $jev_guard_meta_key ) {
	delete_metadata( 'comment', 0, $jev_guard_meta_key, '', true );
}

delete_metadata( 'user', 0, 'jev_guard_dismissed_setup', '', true );

// Transients: verdict cache, locks and cron nudges are all prefixed.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup at uninstall.
$jev_guard_transient_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_jev_guard_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_jev_guard_' ) . '%'
	)
);
foreach ( (array) $jev_guard_transient_names as $jev_guard_name ) {
	delete_option( $jev_guard_name );
}

wp_clear_scheduled_hook( 'jev_guard_retry' );
