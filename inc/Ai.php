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

		// Translate in batches so each request stays small enough to return
		// before the HTTP timeout, and to reduce segment-count drift on long input.
		$chunk_size = (int) apply_filters( 'snel_ai_chunk_size', 20 );
		if ( $chunk_size < 1 ) {
			$chunk_size = 20;
		}

		// Give the AI call more room than WP's 30s default (best effort — an
		// explicit provider timeout still wins; batching is the real fix).
		add_filter( 'http_request_timeout', [ self::class, 'raiseTimeout' ], 99 );

		$out = [];
		foreach ( array_chunk( $texts, $chunk_size ) as $chunk ) {
			$res = self::translate_chunk( $chunk, $source, $target );
			if ( is_wp_error( $res ) ) {
				remove_filter( 'http_request_timeout', [ self::class, 'raiseTimeout' ], 99 );
				return $res; // propagate (quota code etc.)
			}
			$out = array_merge( $out, $res );
		}

		remove_filter( 'http_request_timeout', [ self::class, 'raiseTimeout' ], 99 );
		return $out;
	}

	/** Translate one batch of strings in a single AI request. @return array|\WP_Error */
	private static function translate_chunk( array $texts, string $source, string $target ) {
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

		// Retry transient rate limits with backoff; fail fast on quota/billing.
		$max_attempts = 3;
		$output       = null;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$output = $builder->generate_text();
			if ( ! is_wp_error( $output ) ) {
				break;
			}
			$msg = $output->get_error_message();

			if ( self::is_quota_error( $msg ) ) {
				return new \WP_Error( 'snel_ai_quota', 'AI provider is out of quota/credits — add billing and retry. (' . $msg . ')' );
			}
			if ( self::is_rate_limit( $msg ) && $attempt < $max_attempts ) {
				sleep( 2 * $attempt ); // 2s, then 4s
				continue;
			}
			return new \WP_Error( 'snel_ai_failed', 'AI request failed: ' . $msg );
		}
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

	/** Raise WP's HTTP timeout for AI calls (never lowers it). */
	public static function raiseTimeout( $timeout ) {
		return max( (int) $timeout, 60 );
	}

	/** Quota/billing exhausted — permanent, don't retry, stop the batch. */
	private static function is_quota_error( string $msg ): bool {
		$m = strtolower( $msg );
		return strpos( $m, 'insufficient_quota' ) !== false
			|| strpos( $m, 'exceeded your current quota' ) !== false
			|| strpos( $m, 'billing' ) !== false;
	}

	/** Transient rate limit — safe to retry after a short wait. */
	private static function is_rate_limit( string $msg ): bool {
		$m = strtolower( $msg );
		return strpos( $m, '429' ) !== false
			|| strpos( $m, 'too many requests' ) !== false
			|| strpos( $m, 'rate limit' ) !== false;
	}
}
