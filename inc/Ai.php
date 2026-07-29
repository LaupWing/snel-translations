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

	/** Log lines collected during this request, for showing in the admin UI. */
	private static array $buffer = [];

	/**
	 * Debug log for the translate flow. Kept in a buffer (returned to the
	 * admin UI via logs()) and mirrored to the PHP error log, prefix [snel-tr].
	 */
	public static function log( string $msg ): void {
		self::$buffer[] = $msg;
		error_log( '[snel-tr] ' . $msg );
	}

	/** All log lines collected this request. */
	public static function logs(): array {
		return self::$buffer;
	}

	/** Compact one-line preview of a text segment for the log. */
	private static function preview( string $text, int $max = 200 ): string {
		$text = str_replace( [ "\r", "\n" ], '\n', $text );
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…(' . strlen( $text ) . ' chars)' : $text;
	}

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

		self::log( sprintf( 'translate() %s→%s, %d segment(s)', $source, $target, count( $texts ) ) );
		foreach ( $texts as $i => $t ) {
			self::log( sprintf( '  in[%d]: "%s"', $i, self::preview( (string) $t ) ) );
		}

		// Empty segments confuse the model (it drops them and the separator,
		// causing a count mismatch). Translate only non-empty texts and put
		// empty strings back in their original positions afterwards.
		$non_empty = [];
		foreach ( $texts as $i => $text ) {
			if ( trim( (string) $text ) !== '' ) {
				$non_empty[ $i ] = (string) $text;
			}
		}
		if ( empty( $non_empty ) ) {
			self::log( 'all segments empty — skipping AI call, returning empty strings' );
			return array_fill( 0, count( $texts ), '' );
		}
		if ( count( $non_empty ) !== count( $texts ) ) {
			self::log( sprintf( 'stripped %d empty segment(s), sending %d to AI', count( $texts ) - count( $non_empty ), count( $non_empty ) ) );
			$translated = self::translate( array_values( $non_empty ), $source, $target );
			if ( is_wp_error( $translated ) ) {
				return $translated;
			}
			$out  = array_fill( 0, count( $texts ), '' );
			$keys = array_keys( $non_empty );
			foreach ( $keys as $pos => $i ) {
				$out[ $i ] = $translated[ $pos ];
			}
			return $out;
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

		// Pin the provider. Left unpinned, the AI Client picks whichever provider
		// registered first — on SiteGround that is sg-ai-studio, which is not
		// authenticated for us and answers every request with a 401.
		$provider = (string) apply_filters( 'snel_ai_provider', 'openai' );
		if ( '' !== $provider ) {
			try {
				$builder = $builder->usingProvider( $provider );
			} catch ( \Throwable $e ) {
				self::log( sprintf( 'provider "%s" unavailable (%s) — falling back to the default', $provider, $e->getMessage() ) );
			}
		}

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
			self::log( sprintf( 'AI error (attempt %d/%d): %s', $attempt, $max_attempts, $msg ) );

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

		// Some providers hand back a transport error as plain text instead of a
		// WP_Error. Left unchecked it gets split as if it were a translation and
		// surfaces as a baffling "count mismatch" — report the real cause.
		$raw = trim( (string) $output );
		if ( preg_match( '/^Unexpected response code:\s*(\d{3})/i', $raw, $m ) ) {
			$code = (int) $m[1];
			if ( 401 === $code || 403 === $code ) {
				return new \WP_Error(
					'snel_ai_auth',
					sprintf(
						'AI provider rejected the credentials (HTTP %d). Check the API key under Settings → Connectors.',
						$code
					)
				);
			}
			return new \WP_Error( 'snel_ai_failed', 'AI request failed: ' . $raw );
		}

		$translations = array_map(
			function ( $part ) { return trim( $part, "\r\n" ); },
			explode( $delim, (string) $output )
		);

		if ( count( $translations ) > count( $texts ) && end( $translations ) === '' ) {
			array_pop( $translations );
		}

		self::log( sprintf( 'chunk: sent %d, AI returned %d segment(s)', count( $texts ), count( $translations ) ) );
		foreach ( $translations as $i => $t ) {
			self::log( sprintf( '  out[%d]: "%s"', $i, self::preview( $t ) ) );
		}

		if ( count( $translations ) !== count( $texts ) ) {
			// Log the raw, unsplit AI response — this is the evidence that shows
			// whether the model dropped, merged or reworded the delimiter.
			self::log( 'MISMATCH — raw AI output follows:' );
			self::log( '>>> ' . self::preview( (string) $output, 4000 ) );
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
