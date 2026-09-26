<?php
/**
 * Provider presets.
 *
 * @package SpamLens
 */

namespace SpamLens\Service\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Table of services that front Jev. All speak the same TypeSafe wire format
 * (`POST {model, state, questions}` → `{model, answers, usage}`); only the URL, default model
 * id and a couple of headers differ.
 */
class Providers {

	/**
	 * Preset table, filterable via `spamlens_providers`.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$providers = array(
			'typesafe'   => array(
				'label'    => __( 'TypeSafe AI (direct)', 'spamlens' ),
				'endpoint' => 'https://api.typesafe.ai/v1/systemone',
				'model'    => 'jev-latest',
				'keys_url' => 'https://console.typesafe.ai/keys',
				'docs_url' => 'https://docs.typesafe.ai/',
				'headers'  => array(),
				'note'     => __( 'Create a key in the TypeSafe console. Pay-as-you-go, currently about 1,200 requests per minute — the best choice for large Check-for-Spam sweeps.', 'spamlens' ),
			),
			'vercel'     => array(
				'label'    => __( 'Vercel AI Gateway', 'spamlens' ),
				'endpoint' => 'https://ai-gateway.vercel.sh/typesafe/v1/systemone',
				'model'    => 'typesafe-ai/jev',
				'keys_url' => 'https://vercel.com/d?to=%2F%5Bteam%5D%2F~%2Fai-gateway%2Fapi-keys',
				'docs_url' => 'https://vercel.com/docs/ai-gateway',
				'headers'  => array(),
				'note'     => __( 'Every Vercel team currently gets a $5 monthly AI Gateway credit (a payment method must be on file, nothing is charged unless you buy credits). The free tier is rate-limited: single comments are fine, but bulk Check-for-Spam and Calibration runs will pause on 429 and resume slowly. Buying credits lifts the limits.', 'spamlens' ),
				'free_url' => 'https://vercel.com/d?to=%2F%5Bteam%5D%2F~%2Fai-gateway%2Fmodels%3FfreeTier%3Dtrue',
			),
			'openrouter' => array(
				'label'    => __( 'OpenRouter', 'spamlens' ),
				'endpoint' => 'https://openrouter.ai/api/alpha/decisions',
				'model'    => 'typesafe/jev-latest',
				'keys_url' => 'https://openrouter.ai/settings/keys',
				'docs_url' => 'https://openrouter.ai/docs',
				'headers'  => array(
					'HTTP-Referer' => '__HOME_URL__',
					'X-Title'      => 'SpamLens',
				),
				'note'     => __( 'Uses OpenRouter\'s alpha decisions endpoint. Pay-as-you-go at list price; no free tier for Jev.', 'spamlens' ),
			),
			'custom'     => array(
				'label'    => __( 'Custom TypeSafe-compatible endpoint', 'spamlens' ),
				'endpoint' => '',
				'model'    => 'jev-latest',
				'keys_url' => '',
				'docs_url' => '',
				'headers'  => array(),
				'note'     => __( 'A proxy or another gateway that accepts the TypeSafe System One request format. Enter the full URL and, if it expects one, the model id (defaults to jev-latest).', 'spamlens' ),
			),
		);

		/**
		 * Filters the provider preset table.
		 *
		 * @param array $providers Presets keyed by id.
		 */
		$filtered = apply_filters( 'spamlens_providers', $providers );
		return is_array( $filtered ) && ! empty( $filtered ) ? $filtered : $providers;
	}

	/**
	 * One preset.
	 *
	 * @param string $id Provider id.
	 * @return array|null
	 */
	public static function get( string $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Resolves the endpoint for a settings array.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function endpoint( array $settings ): string {
		$preset = self::get( (string) ( $settings['provider'] ?? 'typesafe' ) );
		if ( 'custom' === ( $settings['provider'] ?? '' ) ) {
			return (string) ( $settings['custom_endpoint'] ?? '' );
		}
		return $preset ? (string) $preset['endpoint'] : '';
	}

	/**
	 * Resolves the model id for a settings array (empty setting → preset default).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function model( array $settings ): string {
		$model = trim( (string) ( $settings['model'] ?? '' ) );
		if ( '' !== $model ) {
			return $model;
		}
		$preset = self::get( (string) ( $settings['provider'] ?? 'typesafe' ) );
		return $preset ? (string) $preset['model'] : '';
	}

	/**
	 * Extra request headers for a provider, with placeholders resolved.
	 *
	 * @param string $id Provider id.
	 * @return array<string, string>
	 */
	public static function headers( string $id ): array {
		$preset  = self::get( $id );
		$headers = array();
		foreach ( (array) ( $preset['headers'] ?? array() ) as $name => $value ) {
			$headers[ $name ] = str_replace( '__HOME_URL__', home_url( '/' ), (string) $value );
		}
		return $headers;
	}
}
