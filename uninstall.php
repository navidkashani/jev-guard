<?php
/**
 * Uninstall routine: removes every option, comment meta, transient and cron event the plugin created.
 *
 * @package SpamLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'spamlens_settings', 'spamlens_stats', 'spamlens_last_error', 'spamlens_version', 'spamlens_models' ) as $spamlens_option ) {
	delete_option( $spamlens_option );
}

foreach ( array( '_spamlens', '_spamlens_decision', '_spamlens_history', '_spamlens_error', '_spamlens_rechecking' ) as $spamlens_meta_key ) {
	delete_metadata( 'comment', 0, $spamlens_meta_key, '', true );
}

delete_metadata( 'user', 0, 'spamlens_dismissed_setup', '', true );

// Transients: verdict cache, locks and cron nudges are all prefixed.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup at uninstall.
$spamlens_transient_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_spamlens_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spamlens_' ) . '%'
	)
);
foreach ( (array) $spamlens_transient_names as $spamlens_name ) {
	delete_option( $spamlens_name );
}

wp_clear_scheduled_hook( 'spamlens_retry' );
