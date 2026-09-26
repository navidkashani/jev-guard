<?php
/**
 * Integration registry.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Integrations;

use SpamLens\Service\Classifier\Classifier;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of integrations (comments, Contact Form 7 and any added through `spamlens_integrations`) and
 * registers the ones whose plugin is present.
 */
class IntegrationManager {

	/**
	 * Shared classifier.
	 *
	 * @var Classifier
	 */
	private $classifier;

	/**
	 * Integrations keyed by id, available or not.
	 *
	 * @var Integration[]
	 */
	private $integrations = array();

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Classifier $classifier Shared classifier.
	 */
	public function __construct( Classifier $classifier ) {
		$this->classifier = $classifier;
	}

	/**
	 * Collects the integrations and adds the hooks of those that are available. Safe to call once.
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$integrations = array(
			new Comments( $this->classifier ),
			new ContactForm7( $this->classifier ),
		);

		/**
		 * Filters the list of integrations. Third parties can add adapters implementing
		 * `SpamLens\Service\Integrations\Integration`.
		 *
		 * @param Integration[] $integrations Integration instances.
		 * @param Classifier    $classifier   Shared classifier.
		 */
		$integrations = apply_filters( 'spamlens_integrations', $integrations, $this->classifier );

		foreach ( (array) $integrations as $integration ) {
			if ( ! $integration instanceof Integration ) {
				continue;
			}
			$this->integrations[ $integration->id() ] = $integration;
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}
	}

	/**
	 * All integrations (available or not), keyed by id.
	 *
	 * @return Integration[]
	 */
	public function all(): array {
		return $this->integrations;
	}

	/**
	 * One integration by id.
	 *
	 * @param string $id Integration id.
	 * @return Integration|null
	 */
	public function get( string $id ) {
		return isset( $this->integrations[ $id ] ) ? $this->integrations[ $id ] : null;
	}

	/**
	 * The comments integration, when registered.
	 *
	 * @return Comments|null
	 */
	public function comments() {
		$comments = $this->get( 'comments' );
		return $comments instanceof Comments ? $comments : null;
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
