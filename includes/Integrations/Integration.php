<?php
/**
 * Integration contract.
 *
 * @package JevGuard
 */

namespace JevGuard\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * An adapter that feeds one source of submissions (comments, a form plugin, ...) into the classifier.
 */
interface Integration {

	/**
	 * Stable id used in settings and stats (`comments`, `cf7`, ...).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the underlying plugin/feature is present on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Adds hooks. Called once on `plugins_loaded` when available; the adapter must check the
	 * per-integration enabled setting at runtime.
	 */
	public function register();
}
