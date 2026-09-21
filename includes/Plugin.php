<?php
/**
 * Plugin bootstrap.
 *
 * @package JevGuard
 */

namespace JevGuard;

use JevGuard\Admin\CommentsScreen;
use JevGuard\Admin\Notices;
use JevGuard\Admin\SettingsPage;
use JevGuard\Api\Client;
use JevGuard\Integrations\Comments;
use JevGuard\Integrations\ContactForm7;
use JevGuard\Integrations\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires settings, integrations, admin screens and cron together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered integrations keyed by id.
	 *
	 * @var Integration[]
	 */
	private $integrations = array();

	/**
	 * Shared classifier.
	 *
	 * @var Classifier
	 */
	private $classifier;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * `plugins_loaded` callback.
	 */
	public static function boot_instance() {
		self::instance()->boot();
	}

	/**
	 * Deactivation hook: drop the retry cron event (comment meta is kept until uninstall).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Comments::CRON_HOOK );
	}

	/**
	 * Wires everything up. Safe to call once.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->classifier = new Classifier( new Client() );

		$integrations = array(
			new Comments( $this->classifier ),
			new ContactForm7( $this->classifier ),
		);

		/**
		 * Filters the list of integrations. Third parties can add adapters implementing
		 * `JevGuard\Integrations\Integration`.
		 *
		 * @param Integration[] $integrations Integration instances.
		 * @param Classifier    $classifier   Shared classifier.
		 */
		$integrations = apply_filters( 'jev_guard_integrations', $integrations, $this->classifier );

		foreach ( $integrations as $integration ) {
			if ( ! $integration instanceof Integration ) {
				continue;
			}
			$this->integrations[ $integration->id() ] = $integration;
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}

		( new Privacy() )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new CommentsScreen( $this ) )->register();
			( new Notices() )->register();
		}
	}

	/**
	 * All integrations (available or not), keyed by id.
	 *
	 * @return Integration[]
	 */
	public function integrations(): array {
		return $this->integrations;
	}

	/**
	 * Returns one integration by id.
	 *
	 * @param string $id Integration id.
	 * @return Integration|null
	 */
	public function integration( string $id ) {
		return isset( $this->integrations[ $id ] ) ? $this->integrations[ $id ] : null;
	}

	/**
	 * Shared classifier.
	 *
	 * @return Classifier
	 */
	public function classifier(): Classifier {
		return $this->classifier;
	}
}
