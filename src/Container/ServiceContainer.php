<?php
/**
 * Service container.
 *
 * @package SpamLens
 */

namespace SpamLens\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy-loading singleton service container.
 *
 * Stores factories (callables) and resolved instances. A factory runs the first time its service is requested.
 */
class ServiceContainer {

	/**
	 * Singleton instance.
	 *
	 * @var ServiceContainer|null
	 */
	private static $instance = null;

	/**
	 * Factory closures keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Resolved instances keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Use getInstance().
	 */
	private function __construct() {
	}

	/**
	 * The container.
	 *
	 * @return ServiceContainer
	 */
	public static function getInstance(): ServiceContainer { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Forge container API.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers a lazy factory. It receives the container and returns the service.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory.
	 * @return ServiceContainer
	 */
	public function register( string $id, callable $factory ): ServiceContainer {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
		return $this;
	}

	/**
	 * A service by id, built on first use. Null when nothing is registered under that id.
	 *
	 * @param string $id Service id.
	 * @return mixed|null
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
			return $this->instances[ $id ];
		}
		return null;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return array_key_exists( $id, $this->instances ) || isset( $this->factories[ $id ] );
	}
}
