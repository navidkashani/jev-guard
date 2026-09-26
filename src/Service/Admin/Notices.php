<?php
/**
 * Admin notices.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Admin;

use SpamLens\Service\Api\Client;
use SpamLens\Service\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Needs an API key" (dismissible per user) and a persistent notice while the last error is auth or credit related.
 */
class Notices {

	const DISMISS_META  = 'spamlens_dismissed_setup';
	const DISMISS_NONCE = 'spamlens_dismiss_setup';

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Prints notices.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) || AdminManager::is_own_screen() ) {
			return;
		}

		if ( ! Settings::is_configured() ) {
			if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
				return;
			}
			$dismiss = wp_nonce_url( add_query_arg( 'spamlens_dismiss', 'setup' ), self::DISMISS_NONCE );
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
				esc_html__( 'SpamLens needs an API key before it can check comments.', 'spamlens' ),
				esc_url( AdminManager::url( 'settings' ) ),
				esc_html__( 'Open the settings', 'spamlens' ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'spamlens' )
			);
			return;
		}

		$error = Client::last_error();
		if ( $error && in_array( $error['code'], array( 'auth', 'payment_required' ), true ) ) {
			$help = __( 'Check the key and the account balance in the provider console.', 'spamlens' );
			if ( 'vercel' === ( $error['provider'] ?? '' ) ) {
				$help = __( 'On Vercel a valid payment method must be on file (nothing is charged unless you buy credits). Add one under Team Settings → Billing, then create the key again under AI Gateway → API Keys.', 'spamlens' );
			}
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s %s <a href="%s">%s</a></p></div>',
				esc_html__( 'SpamLens cannot reach the spam service:', 'spamlens' ),
				esc_html( Client::error_label( $error['code'] ) . ' — ' . $error['message'] ),
				esc_html( $help ),
				esc_url( AdminManager::url( 'settings' ) ),
				esc_html__( 'Settings', 'spamlens' )
			);
		}
	}

	/**
	 * Remembers a dismissed setup notice for the current user, then returns to the same screen.
	 */
	public function handle_dismiss() {
		if ( ! isset( $_GET['spamlens_dismiss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
			return;
		}
		check_admin_referer( self::DISMISS_NONCE );
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		}
		wp_safe_redirect( remove_query_arg( array( 'spamlens_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
