<?php
/**
 * Admin assets.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Assets;

use SpamLens\Bootstrap;
use SpamLens\Service\Admin\AdminManager;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the built assets from public/: the settings app (public/app/, from resources/react) on Settings →
 * SpamLens, and the comments-screen script and styles (public/js/, public/css/, from resources/entries and
 * resources/scss) on the comments list and comment edit screen.
 *
 * The bundles never contain @wordpress/i18n or @wordpress/api-fetch: WordPress provides both, so translations
 * loaded with wp_set_script_translations() reach the app and REST requests carry the nonce.
 */
class AssetManager {

	const APP_HANDLE      = 'spamlens-admin';
	const COMMENTS_HANDLE = 'spamlens-comments';

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues on the plugin's screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( AdminManager::HOOK === $hook ) {
			$this->enqueue_app();
		} elseif ( in_array( $hook, array( 'edit-comments.php', 'comment.php' ), true ) ) {
			$this->enqueue_comments();
		}
	}

	/**
	 * The settings app.
	 */
	private function enqueue_app() {
		$js = 'public/app/settings.js';
		if ( ! file_exists( SPAMLENS_DIR . $js ) ) {
			return; // The mount point explains what to run.
		}
		$css = 'public/app/settings.css';
		if ( file_exists( SPAMLENS_DIR . $css ) ) {
			wp_enqueue_style( self::APP_HANDLE, SPAMLENS_URL . $css, array(), self::version( $css ) );
		}
		wp_enqueue_script( self::APP_HANDLE, SPAMLENS_URL . $js, array( 'wp-i18n', 'wp-api-fetch' ), self::version( $js ), true );
		wp_set_script_translations( self::APP_HANDLE, 'spamlens', SPAMLENS_DIR . 'languages' );
		wp_add_inline_script( self::APP_HANDLE, 'window.spamlensAdmin = ' . wp_json_encode( $this->app_data() ) . ';', 'before' );
	}

	/**
	 * What the app reads before its first request: where things are and the initial data, so the first paint
	 * needs no round trip.
	 *
	 * @return array
	 */
	public function app_data(): array {
		$rest = Bootstrap::get( 'rest' );
		return array(
			'version'      => SPAMLENS_VERSION,
			'locale'       => str_replace( '_', '-', determine_locale() ),
			'urls'         => array(
				'overview'     => AdminManager::url( 'overview' ),
				'settings'     => AdminManager::url( 'settings' ),
				'calibration'  => AdminManager::url( 'calibration' ),
				'pending'      => admin_url( 'edit-comments.php?comment_status=moderated' ),
				'privacyGuide' => admin_url( 'options-privacy.php?tab=policyguide' ),
				'support'      => 'https://wordpress.org/support/plugin/spamlens/',
			),
			'cronDisabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'logos'        => array(
				'veronalabs' => self::svg( 'veronalabs.svg' ),
				'logo'       => SPAMLENS_URL . 'resources/assets/logo-dark.png',
			),
			'initial'      => $rest ? array(
				'settings' => $rest->settings_payload(),
				'stats'    => $rest->get_stats(),
			) : null,
		);
	}

	/**
	 * The comments-screen script (Re-check, Check for Spam) and styles (the Jev column, the meta box).
	 */
	private function enqueue_comments() {
		$css = 'public/css/comments.css';
		$js  = 'public/js/comments.js';
		if ( file_exists( SPAMLENS_DIR . $css ) ) {
			wp_enqueue_style( self::COMMENTS_HANDLE, SPAMLENS_URL . $css, array(), self::version( $css ) );
		}
		if ( file_exists( SPAMLENS_DIR . $js ) ) {
			wp_enqueue_script( self::COMMENTS_HANDLE, SPAMLENS_URL . $js, array( 'wp-i18n', 'wp-api-fetch' ), self::version( $js ), true );
			wp_set_script_translations( self::COMMENTS_HANDLE, 'spamlens', SPAMLENS_DIR . 'languages' );
		}
	}

	/**
	 * Cache-busts on the built file's mtime, so a rebuild gets a new URL without a version bump.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function version( string $relative ): string {
		$mtime = filemtime( SPAMLENS_DIR . $relative );
		return $mtime ? SPAMLENS_VERSION . '.' . $mtime : SPAMLENS_VERSION;
	}

	/**
	 * One of the plugin's own SVG files, for inlining (so currentColor applies).
	 *
	 * @param string $file File name in resources/assets/.
	 * @return string
	 */
	private static function svg( string $file ): string {
		$path = SPAMLENS_DIR . 'resources/assets/' . $file;
		return file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The plugin's own file.
	}
}
