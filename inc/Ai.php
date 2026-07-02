<?php
/**
 * Ai — AI text translation.
 *
 * Wraps the native WordPress AI Client (WP 7.0+, provider configured under
 * Settings → Connectors). One method translates an array of strings; the AJAX
 * endpoint (snel_translate) is what the admin grid + menu "Translate with AI"
 * buttons call.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

use Snel\Translations\Core\LocaleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ai {

	/** Register the AJAX endpoint. Called from Boot when live. */
	public static function register(): void {
		add_action( 'wp_ajax_snel_translate', [ self::class, 'ajax' ] );
	}

	/** AJAX: translate texts[] from source → target. */
	public static function ajax(): void {
		check_ajax_referer( 'snel_translate_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$texts  = $_POST['texts'] ?? [];
		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? 'en' ) );
		$source = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		if ( ! $source ) {
			$source = LocaleManager::default();
		}

		if ( empty( $texts ) || ! is_array( $texts ) ) {
			wp_send_json_error( 'No texts provided' );
		}

		$texts = array_map( 'wp_kses_post', wp_unslash( $texts ) );
		$texts = array_values( array_filter( $texts, function ( $t ) { return trim( $t ) !== ''; } ) );
		if ( empty( $texts ) ) {
			wp_send_json_error( 'No non-empty texts provided' );
		}

		$out = self::translate( $texts, $source, $target );
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( $out->get_error_message() );
		}

		wp_send_json_success( [ 'translations' => $out ] );
	}

	/**
	 * Translate an array of strings. Returns an aligned array, or WP_Error.
	 *
	 * Segments are separated by a rare sentinel (not a numbered list) so
	 * multi-line / HTML values survive the round trip.
	 *
	 * @return array|\WP_Error
	 */
	public static function translate( array $texts, string $source, string $target ) {
		$texts = array_values( $texts );
		if ( empty( $texts ) ) {
			return [];
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'snel_ai_unavailable', 'AI Client unavailable. Requires WordPress 7.0+ with a provider configured under Settings → Connectors.' );
		}

		$lang_names = [
			'nl' => 'Dutch',   'en' => 'English', 'de' => 'German',
			'fr' => 'French',  'es' => 'Spanish', 'it' => 'Italian',
		];
		$source_name = $lang_names[ $source ] ?? $source;
		$target_name = $lang_names[ $target ] ?? $target;

		$delim = '@@@SNEL_SEG@@@';

		$prompt = "You are a professional translator. Translate accurately and naturally, preserving HTML tags.\n\n"
			. "Translate the following segments from {$source_name} to {$target_name}.\n"
			. "Segments are separated by a line containing exactly {$delim}.\n"
			. "Return ONLY the translated segments in the same order, separated by that same {$delim} line.\n"
			. "Do not add, remove, merge or reorder segments. Keep HTML tags, whitespace and formatting intact.\n\n"
			. implode( "\n{$delim}\n", $texts );

		// Don't set temperature — some newer models reject the parameter.
		$builder = wp_ai_client_prompt( $prompt );

		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error( 'snel_ai_no_provider', 'No AI provider configured. Add one under Settings → Connectors.' );
		}

		$output = $builder->generate_text();
		if ( is_wp_error( $output ) ) {
			return new \WP_Error( 'snel_ai_failed', 'AI request failed: ' . $output->get_error_message() );
		}

		$translations = array_map(
			function ( $part ) { return trim( $part, "\r\n" ); },
			explode( $delim, (string) $output )
		);

		if ( count( $translations ) > count( $texts ) && end( $translations ) === '' ) {
			array_pop( $translations );
		}

		if ( count( $translations ) !== count( $texts ) ) {
			return new \WP_Error( 'snel_ai_mismatch', 'Translation count mismatch. Expected ' . count( $texts ) . ', got ' . count( $translations ) );
		}

		return $translations;
	}
}
