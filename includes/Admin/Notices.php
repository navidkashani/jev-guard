<?php
/**
 * Admin notices and plugin action links.
 *
 * @package JevGuard
 */

namespace JevGuard\Admin;

use JevGuard\Api\Client;
use JevGuard\Api\Providers;
use JevGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Needs an API key" (dismissible) and a persistent notice while the last error is auth/credit related.
 */
class Notices {

	const DISMISS_META = 'jev_guard_dismissed_setup';

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_jev_guard_dismiss_notice', array( $this, 'ajax_dismiss' ) );
		add_filter( 'plugin_action_links_' . JEV_GUARD_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . SettingsPage::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'jev-guard' ) . '</a>' );
		return $links;
	}

	/**
	 * Prints notices.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen      = get_current_screen();
		$on_our_page = $screen && 'settings_page_' . SettingsPage::PAGE === $screen->id;
		$url         = admin_url( 'options-general.php?page=' . SettingsPage::PAGE );

		if ( ! Settings::is_configured() ) {
			if ( ! $on_our_page && ! get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
				SettingsPage::enqueue_assets();
				printf(
					'<div class="notice notice-info is-dismissible jev-guard-notice" data-dismiss="setup"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Jev Guard needs an API key before it can check comments.', 'jev-guard' ),
					esc_url( $url ),
					esc_html__( 'Open the settings', 'jev-guard' )
				);
			}
			return;
		}

		$error = Client::last_error();
		if ( $error && in_array( $error['code'], array( 'auth', 'payment_required' ), true ) ) {
			$preset = Providers::get( (string) ( $error['provider'] ?? '' ) );
			$help   = '';
			if ( 'vercel' === ( $error['provider'] ?? '' ) ) {
				$help = __( 'On Vercel a valid payment method must be on file (nothing is charged unless you buy credits) — add one under Team Settings → Billing, then create the key again under AI Gateway → API Keys.', 'jev-guard' );
			} elseif ( $preset && ! empty( $preset['keys_url'] ) ) {
				$help = __( 'Check the key and the account balance in the provider console.', 'jev-guard' );
			}
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Jev Guard cannot reach the spam service:', 'jev-guard' ),
				esc_html( Client::error_label( $error['code'] ) . ' — ' . $error['message'] ),
				esc_html( $help ),
				esc_url( $url ),
				esc_html__( 'Settings', 'jev-guard' )
			);
		}
	}

	/**
	 * Remembers a dismissed setup notice per user.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'jev_guard_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'jev-guard' ) ), 403 );
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_send_json_success();
	}
}
