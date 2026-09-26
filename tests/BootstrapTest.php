<?php
/**
 * Bootstrap, container and admin wiring tests.
 *
 * @package SpamLens
 */

use SpamLens\Bootstrap;
use SpamLens\Container\ServiceContainer;
use SpamLens\Service\Admin\AdminManager;
use SpamLens\Service\Assets\AssetManager;
use SpamLens\Service\Classifier\Classifier;
use SpamLens\Service\Integrations\Comments;
use SpamLens\Service\Integrations\IntegrationManager;
use SpamLens\Service\Integrations\Integration;
use SpamLens\Service\Settings\Settings;

/**
 * The plugin boots through the container and the admin pieces expose what they should.
 */
class BootstrapTest extends SpamLens_TestCase {

	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'SPAMLENS_VERSION' ) );
		$this->assertSame( realpath( dirname( __DIR__ ) . '/spamlens.php' ), realpath( SPAMLENS_FILE ) );
		$this->assertStringEndsWith( '/', SPAMLENS_DIR );
	}

	public function test_version_matches_the_plugin_header() {
		$header = get_file_data( SPAMLENS_FILE, array( 'Version' => 'Version' ) );
		$this->assertSame( SPAMLENS_VERSION, $header['Version'] );
	}

	public function test_core_services_are_in_the_container() {
		$this->assertInstanceOf( ServiceContainer::class, Bootstrap::container() );
		$this->assertInstanceOf( Classifier::class, Bootstrap::get( 'classifier' ) );
		$this->assertInstanceOf( IntegrationManager::class, Bootstrap::get( 'integrations' ) );
		$this->assertSame( Bootstrap::get( 'classifier' ), Bootstrap::get( 'integrations' )->classifier() );
		$this->assertNull( Bootstrap::get( 'nope' ) );
		$this->assertSame( 1, did_action( 'spamlens_loaded' ) );
	}

	public function test_integrations_are_registered() {
		$integrations = Bootstrap::get( 'integrations' );
		$this->assertInstanceOf( Comments::class, $integrations->comments() );
		$this->assertArrayHasKey( 'cf7', $integrations->all() );
		$this->assertNotFalse( has_filter( 'preprocess_comment', array( $integrations->comments(), 'preprocess' ) ) );
	}

	public function test_container_factories_are_lazy_and_shared() {
		$container = Bootstrap::container();
		$calls     = 0;
		$container->register(
			'spamlens_test_service',
			static function () use ( &$calls ) {
				++$calls;
				return new stdClass();
			}
		);
		$this->assertSame( 0, $calls );
		$this->assertSame( $container->get( 'spamlens_test_service' ), $container->get( 'spamlens_test_service' ) );
		$this->assertSame( 1, $calls );
		$this->assertTrue( $container->has( 'spamlens_test_service' ) );
	}

	public function test_third_party_integrations_can_be_added() {
		$custom = new class() implements Integration {
			/**
			 * Whether register() ran.
			 *
			 * @var bool
			 */
			public $registered = false;

			public function id(): string {
				return 'custom';
			}

			public function label(): string {
				return 'Custom';
			}

			public function is_available(): bool {
				return true;
			}

			public function register() {
				$this->registered = true;
			}
		};
		$filter = static function ( $integrations ) use ( $custom ) {
			$integrations[] = $custom;
			$integrations[] = 'not an integration';
			return $integrations;
		};
		add_filter( 'spamlens_integrations', $filter );

		$manager = new IntegrationManager( Bootstrap::get( 'classifier' ) );
		$manager->register();
		remove_filter( 'spamlens_integrations', $filter );

		$this->assertSame( array( 'comments', 'cf7', 'custom' ), array_keys( $manager->all() ) );
		$this->assertTrue( $custom->registered );
	}

	public function test_admin_urls_and_action_link() {
		$this->assertSame( admin_url( 'options-general.php?page=spamlens' ), AdminManager::url() );
		$this->assertSame( admin_url( 'options-general.php?page=spamlens&tab=calibration' ), AdminManager::url( 'calibration' ) );
		$this->assertSame( AdminManager::url(), AdminManager::url( 'bogus' ) );

		$links = ( new AdminManager() )->action_links( array( 'deactivate' => 'x' ) );
		$this->assertStringContainsString( 'page=spamlens', $links[0] );
	}

	public function test_app_data_never_contains_the_key() {
		Settings::update( array( 'api_key' => 'sk-live-very-secret-9999' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data = ( new AssetManager() )->app_data();

		$this->assertStringNotContainsString( 'very-secret', wp_json_encode( $data ) );
		$this->assertTrue( $data['initial']['settings']['key']['stored'] );
		$this->assertSame( SPAMLENS_VERSION, $data['version'] );
		$this->assertArrayHasKey( 'settings', $data['initial'] );
		$this->assertArrayHasKey( 'stats', $data['initial'] );
	}

	public function test_deactivation_clears_the_retry_event() {
		wp_schedule_single_event( time() + 60, Comments::CRON_HOOK );
		SpamLens\Service\Installation\InstallManager::deactivate();
		$this->assertFalse( wp_next_scheduled( Comments::CRON_HOOK ) );
	}

	public function test_jev_guard_settings_are_imported_once() {
		delete_option( 'spamlens_settings' );
		update_option( 'jev_guard_settings', array( 'provider' => 'vercel', 'api_key' => 'old-key' ) );
		SpamLens\Service\Installation\InstallManager::import_jev_guard();
		$this->assertSame( 'vercel', Settings::get( 'provider' ) );
		$this->assertSame( 'old-key', Settings::api_key() );

		update_option( 'jev_guard_settings', array( 'provider' => 'openrouter' ) );
		SpamLens\Service\Installation\InstallManager::import_jev_guard();
		$this->assertSame( 'vercel', Settings::get( 'provider' ), 'Existing SpamLens settings are never overwritten.' );
		delete_option( 'jev_guard_settings' );
	}

	public function test_jev_guard_comment_data_moves_to_spamlens() {
		$comment = self::factory()->comment->create( array( 'comment_approved' => '1' ) );
		$user    = self::factory()->user->create();
		add_comment_meta( $comment, '_jev_guard', array( 'decision' => 'allow' ) );
		add_comment_meta( $comment, '_jev_guard_error', 1 );
		add_user_meta( $user, 'jev_guard_dismissed_setup', 1 );

		SpamLens\Service\Installation\InstallManager::import_jev_guard();
		wp_cache_flush();

		$this->assertSame( array( 'decision' => 'allow' ), get_comment_meta( $comment, Comments::META, true ) );
		$this->assertSame( '1', get_comment_meta( $comment, Comments::META_ERROR, true ) );
		$this->assertSame( '', get_comment_meta( $comment, '_jev_guard', true ) );
		$this->assertSame( '1', get_user_meta( $user, 'spamlens_dismissed_setup', true ) );
		$this->assertNotFalse( wp_next_scheduled( Comments::CRON_HOOK ), 'Comments waiting for a retry are scheduled again.' );
	}
}
