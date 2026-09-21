<?php
/**
 * Settings page.
 *
 * @package JevGuard
 */

namespace JevGuard\Admin;

use JevGuard\Api\Client;
use JevGuard\Api\Providers;
use JevGuard\Classifier;
use JevGuard\Integrations\Comments;
use JevGuard\Plugin;
use JevGuard\Settings;
use JevGuard\Stats;
use JevGuard\Submission;
use JevGuard\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Jev Guard: classic Settings API page plus Test connection, Calibration and Statistics.
 */
class SettingsPage {

	const PAGE  = 'jev-guard';
	const GROUP = 'jev_guard';
	const CAP   = 'manage_options';
	const NONCE = 'jev_guard_admin';

	const CALIBRATION_BATCH  = 10;
	const CALIBRATION_BUDGET = 20;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_jev_guard_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_jev_guard_calibration_sample', array( $this, 'ajax_calibration_sample' ) );
		add_action( 'wp_ajax_jev_guard_calibration_batch', array( $this, 'ajax_calibration_batch' ) );
	}

	/**
	 * Registers the admin script/style once and localises shared data. Safe to call multiple times.
	 *
	 * @param array $extra Extra data merged into `jevGuard`.
	 */
	public static function enqueue_assets( array $extra = array() ) {
		static $done = false;

		if ( ! wp_script_is( 'jev-guard-admin', 'registered' ) ) {
			wp_register_style( 'jev-guard-admin', JEV_GUARD_URL . 'assets/admin.css', array(), JEV_GUARD_VERSION );
			wp_register_script( 'jev-guard-admin', JEV_GUARD_URL . 'assets/admin.js', array(), JEV_GUARD_VERSION, true );
		}

		if ( ! $done ) {
			$done      = true;
			$providers = array();
			foreach ( Providers::all() as $id => $preset ) {
				$providers[ $id ] = array(
					'label'    => $preset['label'],
					'model'    => $preset['model'],
					'endpoint' => $preset['endpoint'],
					'keys_url' => $preset['keys_url'] ?? '',
				);
			}
			$data = array_merge(
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( self::NONCE ),
					'providers'   => $providers,
					'pageContext' => self::page_context_labels()[ Settings::get( 'page_context', 'standard' ) ] ?? '',
					'i18n'        => array(
						'testing'         => __( 'Testing…', 'jev-guard' ),
						/* translators: 1: model id, 2: latency in ms, 3: spam probability as a percentage, 4: category */
						'testOk'          => __( 'Connected. Model %1$s answered in %2$d ms and rated the sample %3$d%% spam (%4$s).', 'jev-guard' ),
						/* translators: %s: error message */
						'testFail'        => __( 'Failed: %s', 'jev-guard' ),
						'recheck'         => __( 'Re-checking…', 'jev-guard' ),
						'movedToSpam'     => __( 'Moved to Spam', 'jev-guard' ),
						'bulkStart'       => __( 'Checking pending comments…', 'jev-guard' ),
						/* translators: 1: number checked, 2: total, 3: number moved to spam */
						'bulkProgress'    => __( '%1$d of %2$d checked, %3$d moved to Spam', 'jev-guard' ),
						/* translators: 1: number checked, 2: number moved to spam */
						'bulkDone'        => __( 'Done: %1$d checked, %2$d moved to Spam. Reload to see the changes.', 'jev-guard' ),
						'bulkNothing'     => __( 'No pending comments to check.', 'jev-guard' ),
						/* translators: %d: seconds */
						'rateLimited'     => __( 'Provider rate limit — pausing for %d s…', 'jev-guard' ),
						/* translators: %s: error message */
						'error'           => __( 'Error: %s', 'jev-guard' ),
						'calSampling'     => __( 'Collecting sample…', 'jev-guard' ),
						/* translators: 1: number classified, 2: total */
						'calProgress'     => __( '%1$d of %2$d comments classified', 'jev-guard' ),
						'calNoData'       => __( 'Not enough comments: the calibration needs at least a few comments in both the Spam and Approved lists.', 'jev-guard' ),
						'calAccuracy'     => __( 'Accuracy', 'jev-guard' ),
						'calFalsePos'     => __( 'False positives (approved comments that would be marked spam)', 'jev-guard' ),
						'calHeldHam'      => __( 'Approved comments that would be held', 'jev-guard' ),
						'calMissed'       => __( 'Missed spam (spam that would be allowed)', 'jev-guard' ),
						'calHeldSpam'     => __( 'Spam that would be held for moderation', 'jev-guard' ),
						'calCaught'       => __( 'Spam caught', 'jev-guard' ),
						/* translators: 1: spam threshold, 2: hold threshold, 3: false positives, 4: spam caught, 5: total spam */
						'calSuggest'      => __( 'On this sample, a spam threshold of %1$s and a hold threshold of %2$s would give %3$d false positives and catch %4$d of %5$d spam.', 'jev-guard' ),
						'calNote'         => __( 'This reflects past comments only; nothing was changed. Comments Jev scored differently from your moderation:', 'jev-guard' ),
						/* translators: %d: number of comments */
						'calErrors'       => __( '%d comments could not be classified.', 'jev-guard' ),
						'calAllAgree'     => __( 'Jev agreed with every moderated comment in the sample.', 'jev-guard' ),
						/* translators: %s: model id */
						'calModel'        => __( 'Model: %s', 'jev-guard' ),
						/* translators: %s: page context level, e.g. "Standard" */
						'calContext'      => __( 'Page context: %s', 'jev-guard' ),
						'colComment'      => __( 'Comment', 'jev-guard' ),
						'colModeration'   => __( 'Your moderation', 'jev-guard' ),
						'colJev'          => __( 'Jev', 'jev-guard' ),
						'labelSpam'       => __( 'Spam', 'jev-guard' ),
						'labelApproved'   => __( 'Approved', 'jev-guard' ),
						'labelHold'       => __( 'Hold', 'jev-guard' ),
						'labelAllow'      => __( 'Allow', 'jev-guard' ),
						/* translators: %s: model id */
						'defaultModelFmt' => __( 'Default: %s', 'jev-guard' ),
					),
				),
				$extra
			);
			wp_localize_script( 'jev-guard-admin', 'jevGuard', $data );
		}

		wp_enqueue_style( 'jev-guard-admin' );
		wp_enqueue_script( 'jev-guard-admin' );
	}

	/**
	 * Enqueues assets on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			self::enqueue_assets( array( 'screen' => 'settings' ) );
		}
	}

	/**
	 * Adds the Settings submenu.
	 */
	public function add_menu() {
		add_options_page(
			__( 'Jev Guard', 'jev-guard' ),
			__( 'Jev Guard', 'jev-guard' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option, sections and fields.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section( 'jev_guard_connection', __( 'Connection', 'jev-guard' ), array( $this, 'section_connection' ), self::PAGE );
		$this->field( 'provider', __( 'Provider', 'jev-guard' ), 'jev_guard_connection' );
		$this->field( 'api_key', __( 'API key', 'jev-guard' ), 'jev_guard_connection' );
		$this->field( 'model', __( 'Model', 'jev-guard' ), 'jev_guard_connection' );
		$this->field( 'custom_endpoint', __( 'Endpoint URL', 'jev-guard' ), 'jev_guard_connection', array( 'class' => 'jev-guard-row-custom' ) );
		$this->field( 'timeout', __( 'Timeout', 'jev-guard' ), 'jev_guard_connection' );
		$this->field( 'test_connection', __( 'Test connection', 'jev-guard' ), 'jev_guard_connection' );

		add_settings_section( 'jev_guard_detection', __( 'Detection', 'jev-guard' ), array( $this, 'section_detection' ), self::PAGE );
		$this->field( 'spam_threshold', __( 'Spam threshold', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'hold_threshold', __( 'Hold threshold', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'hold_abusive', __( 'Abusive comments', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'on_error', __( 'When the service is unavailable', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'site_context', __( 'About this site', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'page_context', __( 'Page context', 'jev-guard' ), 'jev_guard_detection' );
		$this->field( 'skip_rules', __( 'Skip checks for', 'jev-guard' ), 'jev_guard_detection' );

		add_settings_section( 'jev_guard_privacy', __( 'Privacy', 'jev-guard' ), array( $this, 'section_privacy' ), self::PAGE );
		$this->field( 'privacy_data', __( 'Also send', 'jev-guard' ), 'jev_guard_privacy' );
		$this->field( 'privacy_notice', __( 'Comment form notice', 'jev-guard' ), 'jev_guard_privacy' );

		add_settings_section( 'jev_guard_integrations', __( 'Integrations', 'jev-guard' ), '__return_null', self::PAGE );
		$this->field( 'integrations', __( 'Check submissions from', 'jev-guard' ), 'jev_guard_integrations' );
	}

	/**
	 * Registers one field rendered by `render_field_<id>()`.
	 *
	 * @param string $id      Field id.
	 * @param string $label   Label.
	 * @param string $section Section id.
	 * @param array  $args    Extra args.
	 */
	private function field( string $id, string $label, string $section, array $args = array() ) {
		$args = array_merge( array( 'label_for' => 'jev_guard_' . $id ), $args );
		add_settings_field( 'jev_guard_' . $id, $label, array( $this, 'render_field_' . $id ), self::PAGE, $section, $args );
	}

	// --- Sections ---

	/**
	 * Connection section intro.
	 */
	public function section_connection() {
		echo '<p>' . esc_html__( 'Jev is a decision model by TypeSafe AI. Pick the service that hosts it for you and paste an API key from that service.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Detection section intro.
	 */
	public function section_detection() {
		echo '<p>' . esc_html__( 'Each submission gets a spam probability from 0 to 1. Above the spam threshold it goes to the Spam folder; above the hold threshold it waits for moderation; below that WordPress decides as usual. Jev never approves anything on its own.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Privacy section intro.
	 */
	public function section_privacy() {
		echo '<p>' . esc_html__( 'Every check sends the submission text, the author name and website, and details of the page it belongs to (title, type, tags and categories and, depending on the Page context setting, an excerpt and headings; for replies, the parent comment) to the selected provider. Nothing else is stored from the response except the probabilities.', 'jev-guard' ) . '</p>';
	}

	// --- Fields ---

	/**
	 * Provider select with per-provider notes.
	 */
	public function render_field_provider() {
		$settings = Settings::all();
		echo '<select id="jev_guard_provider" name="' . esc_attr( Settings::OPTION ) . '[provider]">';
		foreach ( Providers::all() as $id => $preset ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $settings['provider'], $id, false ), esc_html( $preset['label'] ) );
		}
		echo '</select>';
		foreach ( Providers::all() as $id => $preset ) {
			$active = $settings['provider'] === $id ? ' is-active' : '';
			echo '<p class="description jev-guard-provider-note' . esc_attr( $active ) . '" data-provider="' . esc_attr( $id ) . '">';
			echo esc_html( $preset['note'] ?? '' );
			if ( ! empty( $preset['keys_url'] ) ) {
				echo ' <a href="' . esc_url( $preset['keys_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get a key', 'jev-guard' ) . '</a>';
			}
			if ( ! empty( $preset['free_url'] ) ) {
				echo ' · <a href="' . esc_url( $preset['free_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Free-tier model list', 'jev-guard' ) . '</a>';
			}
			echo '</p>';
		}
	}

	/**
	 * API key (masked placeholder; empty submit keeps the stored key).
	 */
	public function render_field_api_key() {
		if ( Settings::api_key_is_constant() ) {
			printf(
				'<input type="text" id="jev_guard_api_key" class="regular-text" value="%s" disabled /><p class="description">%s</p>',
				esc_attr( Settings::mask_key( Settings::api_key() ) ),
				esc_html__( 'Defined by the JEV_GUARD_API_KEY constant in wp-config.php.', 'jev-guard' )
			);
			return;
		}
		$stored = (string) Settings::get( 'api_key', '' );
		printf(
			'<input type="password" id="jev_guard_api_key" name="%s[api_key]" class="regular-text" value="" autocomplete="new-password" spellcheck="false" placeholder="%s" />',
			esc_attr( Settings::OPTION ),
			esc_attr( '' !== $stored ? Settings::mask_key( $stored ) : __( 'Paste your API key', 'jev-guard' ) )
		);
		if ( '' !== $stored ) {
			echo '<p class="description">' . esc_html__( 'A key is stored. Leave the field empty to keep it, or paste a new one to replace it. You can also define JEV_GUARD_API_KEY in wp-config.php.', 'jev-guard' ) . '</p>';
		}
	}

	/**
	 * Model id.
	 */
	public function render_field_model() {
		$settings = Settings::all();
		$preset   = Providers::get( $settings['provider'] );
		printf(
			'<input type="text" id="jev_guard_model" name="%s[model]" class="regular-text code" value="%s" placeholder="%s" />',
			esc_attr( Settings::OPTION ),
			esc_attr( $settings['model'] ),
			esc_attr( $preset ? $preset['model'] : '' )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to use the provider default. Once your thresholds are calibrated, pin the versioned model id shown by "Test connection" (for example jev-1.13.0) so a model upgrade cannot shift your scores.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Custom endpoint URL (custom provider only).
	 */
	public function render_field_custom_endpoint() {
		printf(
			'<input type="url" id="jev_guard_custom_endpoint" name="%s[custom_endpoint]" class="regular-text code" value="%s" placeholder="https://example.com/v1/systemone" />',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::get( 'custom_endpoint', '' ) )
		);
	}

	/**
	 * Timeout.
	 */
	public function render_field_timeout() {
		printf(
			'<input type="number" id="jev_guard_timeout" name="%s[timeout]" class="small-text" value="%d" min="1" max="30" step="1" /> %s',
			esc_attr( Settings::OPTION ),
			(int) Settings::get( 'timeout', 5 ),
			esc_html__( 'seconds', 'jev-guard' )
		);
		echo '<p class="description">' . esc_html__( 'Checks run while the visitor waits, so keep this short. Typical answers take 1–2 seconds.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Test connection button.
	 */
	public function render_field_test_connection() {
		echo '<button type="button" class="button" id="jev-guard-test-connection">' . esc_html__( 'Test connection', 'jev-guard' ) . '</button> ';
		echo '<span id="jev-guard-test-result" class="jev-guard-inline-result" aria-live="polite"></span>';
		echo '<p class="description">' . esc_html__( 'Sends a canned spam comment using the values above (unsaved changes included) and shows the model version, latency and probability.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Spam threshold.
	 */
	public function render_field_spam_threshold() {
		printf(
			'<input type="number" id="jev_guard_spam_threshold" name="%s[spam_threshold]" class="small-text" value="%s" min="0.5" max="1" step="0.01" />',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::get( 'spam_threshold' ) )
		);
		echo '<p class="description">' . esc_html__( 'Probability at or above which a submission is marked as spam. 0.85 is a good default for English sites; raise it for other languages or after a calibration run shows false positives.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Hold threshold.
	 */
	public function render_field_hold_threshold() {
		printf(
			'<input type="number" id="jev_guard_hold_threshold" name="%s[hold_threshold]" class="small-text" value="%s" min="0.1" max="1" step="0.01" />',
			esc_attr( Settings::OPTION ),
			esc_attr( Settings::get( 'hold_threshold' ) )
		);
		echo '<p class="description">' . esc_html__( 'Comments between this and the spam threshold are held for moderation. Comments that look like spam but clearly respond to the page are held too. Contact-form entries have no queue and are delivered.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Hold abusive.
	 */
	public function render_field_hold_abusive() {
		$this->checkbox( 'hold_abusive', __( 'Hold comments Jev rates as abusive, hateful, threatening or harassing, even when they are not spam', 'jev-guard' ) );
	}

	/**
	 * On error.
	 */
	public function render_field_on_error() {
		$value = Settings::get( 'on_error', 'allow' );
		echo '<fieldset>';
		printf(
			'<label><input type="radio" name="%1$s[on_error]" value="allow"%2$s /> %3$s</label><br />',
			esc_attr( Settings::OPTION ),
			checked( $value, 'allow', false ),
			esc_html__( 'Let WordPress decide as usual (recommended) — the comment is re-checked automatically within 20 minutes and moved to Spam if needed', 'jev-guard' )
		);
		printf(
			'<label><input type="radio" name="%1$s[on_error]" value="hold"%2$s /> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( $value, 'hold', false ),
			esc_html__( 'Hold the comment for moderation', 'jev-guard' )
		);
		echo '</fieldset>';
	}

	/**
	 * Site context textarea.
	 */
	public function render_field_site_context() {
		printf(
			'<textarea id="jev_guard_site_context" name="%s[site_context]" class="large-text" rows="3" maxlength="2000" placeholder="%s">%s</textarea>',
			esc_attr( Settings::OPTION ),
			esc_attr__( 'e.g. A German-language cooking blog for home cooks; readers often share links to their own recipes; product reviews are in English.', 'jev-guard' ),
			esc_textarea( Settings::get( 'site_context', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Sent with every check. Describe what the site is about, who comments, and which languages are expected — this helps Jev most on non-English sites.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Page context level.
	 */
	public function render_field_page_context() {
		$value  = Settings::get( 'page_context', 'standard' );
		$labels = self::page_context_labels();
		$help   = array(
			'title'    => __( 'Title, type, tags and categories; no text from the page. Choose this if you sell access to your content or it must not leave the server.', 'jev-guard' ),
			'standard' => __( 'Also the excerpt (or the first ~300 characters) and the page\'s headings.', 'jev-guard' ),
			'extended' => __( 'The first ~1,200 characters plus headings, about four times as much page text. Try it when spam is on-topic and well written. Save, then run the Calibration tool to compare.', 'jev-guard' ),
		);
		echo '<fieldset>';
		foreach ( Settings::page_context_levels() as $level ) {
			printf(
				'<label><input type="radio" name="%1$s[page_context]" value="%2$s"%3$s /> <strong>%4$s</strong> — %5$s</label><br />',
				esc_attr( Settings::OPTION ),
				esc_attr( $level ),
				checked( $value, $level, false ),
				esc_html( $labels[ $level ] ),
				esc_html( $help[ $level ] )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'The text of password-protected, private and unpublished pages is never sent at any level; the parent comment of a reply always is. Developers can adjust or blank the page context with the jev_guard_post_context filter.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Page context level → label.
	 *
	 * @return array<string, string>
	 */
	public static function page_context_labels(): array {
		return array(
			'title'    => __( 'Title only', 'jev-guard' ),
			'standard' => __( 'Standard (recommended)', 'jev-guard' ),
			'extended' => __( 'Extended', 'jev-guard' ),
		);
	}

	/**
	 * Skip rules.
	 */
	public function render_field_skip_rules() {
		echo '<fieldset>';
		$this->checkbox( 'skip_moderators', __( 'Users who can moderate comments', 'jev-guard' ) );
		echo '<br />';
		$this->checkbox( 'skip_previously_approved', __( 'Authors with a previously approved comment', 'jev-guard' ) );
		echo '<br />';
		$this->checkbox( 'check_pingbacks', __( 'Do check pingbacks and trackbacks', 'jev-guard' ) );
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Comments matching the Disallowed Comment Keys list and comments already flagged by another spam plugin are never sent.', 'jev-guard' ) . '</p>';
	}

	/**
	 * Privacy data toggles.
	 */
	public function render_field_privacy_data() {
		echo '<fieldset>';
		$this->checkbox( 'send_email', __( 'Author email address (helps with repeat offenders)', 'jev-guard' ) );
		echo '<br />';
		$this->checkbox( 'send_ip', __( 'IP address', 'jev-guard' ) );
		echo '<br />';
		$this->checkbox( 'send_user_agent', __( 'Browser user agent and referer (strong bot signal)', 'jev-guard' ) );
		echo '</fieldset>';
	}

	/**
	 * Privacy notice toggle.
	 */
	public function render_field_privacy_notice() {
		$this->checkbox( 'privacy_notice', __( 'Show "This site uses Jev by TypeSafe AI to reduce spam" under the comment form, linking to your privacy policy', 'jev-guard' ) );
		$url = admin_url( 'options-privacy.php?tab=policyguide' );
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: URL of the privacy policy guide */
				__( 'Suggested privacy-policy wording is available in the <a href="%s">Policy Guide</a>.', 'jev-guard' ),
				esc_url( $url )
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	/**
	 * Integration toggles.
	 */
	public function render_field_integrations() {
		$settings = Settings::all();
		echo '<fieldset>';
		foreach ( $this->plugin->integrations() as $id => $integration ) {
			$available = $integration->is_available();
			$checked   = ! empty( $settings['integrations'][ $id ] );
			printf(
				'<label><input type="checkbox" name="%1$s[integrations][%2$s]" value="1"%3$s%4$s /> %5$s%6$s</label><br />',
				esc_attr( Settings::OPTION ),
				esc_attr( $id ),
				checked( $checked, true, false ),
				$available ? '' : ' disabled',
				esc_html( $integration->label() ),
				$available ? '' : ' <em>' . esc_html__( '(not installed)', 'jev-guard' ) . '</em>'
			);
			if ( ! $available && $checked ) {
				// Keep the stored value for a plugin that is temporarily deactivated.
				printf( '<input type="hidden" name="%1$s[integrations][%2$s]" value="1" />', esc_attr( Settings::OPTION ), esc_attr( $id ) );
			}
		}
		echo '</fieldset>';
	}

	/**
	 * Prints one checkbox bound to a boolean setting.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function checkbox( string $key, string $label ) {
		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1"%4$s /> %5$s</label>',
			esc_attr( 'jev_guard_' . $key ),
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( (bool) Settings::get( $key ), true, false ),
			esc_html( $label )
		);
	}

	// --- Page ---

	/**
	 * Renders the page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$stats = Stats::get();
		?>
		<div class="wrap jev-guard-settings">
			<h1><?php esc_html_e( 'Jev Guard', 'jev-guard' ); ?></h1>

			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'DISABLE_WP_CRON is set. The automatic re-check of comments that could not be classified depends on WP-Cron; make sure a system cron job runs wp-cron.php.', 'jev-guard' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect. ?>
			<?php if ( ! empty( $_GET['jev-guard-reset'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Statistics were reset.', 'jev-guard' ); ?></p></div>
			<?php endif; ?>

			<form action="options.php" method="post" id="jev-guard-form">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Calibration', 'jev-guard' ); ?></h2>
			<p><?php esc_html_e( 'Runs Jev on a sample of comments you already moderated (newest Spam and Approved comments, excluding those written by moderators) and compares the results with your decisions at the thresholds saved above. Read-only: no comment is changed and nothing is stored.', 'jev-guard' ); ?></p>
			<?php if ( ! Settings::is_configured() ) : ?>
				<p><em><?php esc_html_e( 'Save an API key first.', 'jev-guard' ); ?></em></p>
			<?php else : ?>
				<p>
					<label for="jev-guard-calibration-size"><?php esc_html_e( 'Sample size per class', 'jev-guard' ); ?></label>
					<select id="jev-guard-calibration-size">
						<option value="25">25</option>
						<option value="50" selected>50</option>
						<option value="100">100</option>
					</select>
					<button type="button" class="button button-secondary" id="jev-guard-calibrate"><?php esc_html_e( 'Run calibration', 'jev-guard' ); ?></button>
					<span id="jev-guard-calibration-status" class="jev-guard-inline-result" aria-live="polite"></span>
				</p>
				<div id="jev-guard-calibration-progress" class="jev-guard-progress" hidden><div class="jev-guard-progress-bar"></div></div>
				<div id="jev-guard-calibration-results"
					data-spam-threshold="<?php echo esc_attr( Settings::get( 'spam_threshold' ) ); ?>"
					data-hold-threshold="<?php echo esc_attr( Settings::get( 'hold_threshold' ) ); ?>"
					data-hold-abusive="<?php echo Settings::get( 'hold_abusive' ) ? '1' : '0'; ?>"
					data-max-links="<?php echo (int) get_option( 'comment_max_links', 2 ); ?>"></div>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Statistics', 'jev-guard' ); ?></h2>
			<table class="widefat striped jev-guard-stats" style="max-width: 720px">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Total', 'jev-guard' ); ?></th>
						<?php foreach ( $this->plugin->integrations() as $id => $integration ) : ?>
							<th><?php echo esc_html( $integration->label() ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$rows = array(
						'checked' => __( 'Checked', 'jev-guard' ),
						'spam'    => __( 'Marked as spam', 'jev-guard' ),
						'held'    => __( 'Held for moderation', 'jev-guard' ),
						'errors'  => __( 'Errors (service unavailable)', 'jev-guard' ),
						'fp'      => __( 'False positives (un-spammed by a moderator)', 'jev-guard' ),
						'fn'      => __( 'Missed spam (spammed by a moderator)', 'jev-guard' ),
					);
					foreach ( $rows as $key => $label ) :
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo (int) ( $stats[ $key ] ?? 0 ); ?></td>
							<?php foreach ( $this->plugin->integrations() as $id => $integration ) : ?>
								<td><?php echo (int) ( $stats['integrations'][ $id ][ $key ] ?? 0 ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date */
					esc_html__( 'Counting since %s.', 'jev-guard' ),
					esc_html( wp_date( get_option( 'date_format' ), (int) $stats['since'] ) )
				);
				?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=' . self::PAGE . '&jev_guard_action=reset_stats' ), 'jev_guard_reset_stats' ) ); ?>"><?php esc_html_e( 'Reset', 'jev-guard' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handles the reset-stats link.
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'], $_GET['jev_guard_action'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		if ( 'reset_stats' !== $_GET['jev_guard_action'] ) {
			return;
		}
		check_admin_referer( 'jev_guard_reset_stats' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'jev-guard' ) );
		}
		Stats::reset();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&jev-guard-reset=1' ) );
		exit;
	}

	// --- AJAX ---

	/**
	 * Verifies nonce + capability for AJAX handlers.
	 */
	private function guard_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'jev-guard' ) ), 403 );
		}
	}

	/**
	 * Test connection with the (possibly unsaved) form values.
	 */
	public function ajax_test_connection() {
		$this->guard_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard_ajax().
		$input = Settings::sanitize( wp_unslash( $_POST ) );
		$keys  = array( 'provider', 'api_key', 'model', 'custom_endpoint', 'timeout' );
		$over  = array_intersect_key( $input, array_flip( $keys ) );

		$classifier = new Classifier( new Client( $over ) );
		$verdict    = $classifier->classify(
			Submission::sample(),
			array(
				'skip_cache' => true,
				'timeout'    => 15,
			)
		);

		if ( is_wp_error( $verdict ) ) {
			wp_send_json_error(
				array(
					'code'    => $verdict->get_error_code(),
					'label'   => Client::error_label( $verdict->get_error_code() ),
					'message' => $verdict->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'model'       => $verdict->model,
				'latency_ms'  => $verdict->latency_ms,
				'probability' => $verdict->probability(),
				'category'    => $verdict->category_label(),
				'decision'    => $verdict->decision,
				'usage'       => $verdict->usage,
			)
		);
	}

	/**
	 * Returns the calibration sample ids.
	 */
	public function ajax_calibration_sample() {
		$this->guard_ajax();
		$comments = $this->plugin->integration( 'comments' );
		if ( ! $comments instanceof Comments ) {
			wp_send_json_error( array( 'message' => __( 'Comments integration unavailable.', 'jev-guard' ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard_ajax().
		$n      = isset( $_POST['n'] ) ? (int) $_POST['n'] : 50;
		$sample = $comments->calibration_sample( $n );
		wp_send_json_success( $sample );
	}

	/**
	 * Classifies up to 10 comments read-only and returns the raw numbers.
	 */
	public function ajax_calibration_batch() {
		$this->guard_ajax();
		$comments = $this->plugin->integration( 'comments' );
		if ( ! $comments instanceof Comments ) {
			wp_send_json_error( array( 'message' => __( 'Comments integration unavailable.', 'jev-guard' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in guard_ajax(); JSON is decoded and every field is cast below.
		$raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
		// phpcs:enable
		$items = json_decode( is_string( $raw ) ? $raw : '[]', true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$items = array_slice( $items, 0, self::CALIBRATION_BATCH );

		$started = microtime( true );
		$results = array();
		$halt    = null;

		foreach ( $items as $item ) {
			$id       = (int) ( $item['id'] ?? 0 );
			$expected = ( 'spam' === ( $item['expected'] ?? '' ) ) ? 'spam' : 'ham';
			if ( $id <= 0 ) {
				continue;
			}
			if ( microtime( true ) - $started > self::CALIBRATION_BUDGET ) {
				break;
			}

			$comment = get_comment( $id );
			$row     = array(
				'id'       => $id,
				'expected' => $expected,
				'title'    => $comment ? wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 80, '…' ) : '',
				'author'   => $comment ? $comment->comment_author : '',
				'link'     => get_edit_comment_link( $id ),
			);

			$verdict = $comments->classify_only( $id );
			if ( is_wp_error( $verdict ) ) {
				$code = $verdict->get_error_code();
				if ( in_array( $code, array( 'rate_limited', 'overloaded', 'auth', 'payment_required', 'no_api_key', 'not_configured' ), true ) ) {
					$halt = array(
						'code'        => $code,
						'label'       => Client::error_label( $code ),
						'message'     => $verdict->get_error_message(),
						'retry_after' => (float) ( $verdict->get_error_data()['retry_after'] ?? 0 ),
					);
					break;
				}
				$row['error'] = $verdict->get_error_message();
				$results[]    = $row;
				continue;
			}

			$row['p']        = $verdict->spam;
			$row['genuine']  = $verdict->genuine;
			$row['abuse']    = $verdict->abuse;
			$row['links']    = $verdict->links;
			$row['category'] = $verdict->category_label();
			$row['decision'] = $verdict->decision;
			$row['model']    = $verdict->model;
			$results[]       = $row;
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'halt'    => $halt,
			)
		);
	}
}
