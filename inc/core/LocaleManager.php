<?php
/**
 * LocaleManager — single source of truth for language config + detection.
 *
 * Answers: what languages exist, which is default, which language is the current
 * request in. Reads the languages list from the snel_languages option, falling
 * back to config/languages.php. Everything else asks LocaleManager, never the
 * raw file.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocaleManager {

	/** Cached languages config array. */
	private static ?array $config = null;

	/**
	 * Register the locale switch. Called from Boot once, when live.
	 *
	 * Makes get_locale() — and everything built on it: language_attributes(),
	 * date_i18n(), core/plugin textdomains — follow the request's language, so
	 * /en/ pages render <html lang="en-US"> with English dates and core strings.
	 * Non-default languages other than English need their WP language pack
	 * installed (Settings → General → Site Language downloads it).
	 */
	public static function register(): void {
		add_filter( 'locale', [ self::class, 'filterLocale' ] );
	}

	/**
	 * Swap the front-end locale to the current language's configured one.
	 * Applies to the default language too, so the front end is consistent even
	 * when wp-admin's Site Language is set to something else. Admin stays as-is.
	 */
	public static function filterLocale( $locale ) {
		if ( is_admin() ) {
			return $locale;
		}

		$config = self::config();
		$lang   = self::current();

		return ! empty( $config[ $lang ]['locale'] ) ? $config[ $lang ]['locale'] : $locale;
	}

	/** Forced language (e.g. for REST rendering); null = detect normally. */
	private static ?string $override = null;

	/** Temporarily force a language, or pass null to clear. */
	public static function setOverride( ?string $lang ): void {
		self::$override = $lang;
	}

	/**
	 * Load + cache the languages config.
	 *
	 * Order: config/languages.php default → snel_languages option override.
	 * Shape: ['nl' => ['label'=>'NL','locale'=>'nl_NL','default'=>true], ...]
	 */
	public static function config(): array {
		if ( self::$config === null ) {
			$config = require SNEL_TR_DIR . 'config/languages.php';

			// Admin-provided JSON override (Languages tab) wins over the file.
			$stored = get_option( 'snel_languages', '' );
			if ( is_string( $stored ) && trim( $stored ) !== '' ) {
				$decoded = json_decode( $stored, true );
				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$config = $decoded;
				}
			}

			self::$config = $config;
		}

		return self::$config;
	}

	/**
	 * Supported (enabled) language codes, e.g. ['nl','en'].
	 * Respects the snel_enabled_langs option; default is always included.
	 */
	public static function supported(): array {
		$all = array_keys( self::config() );

		$enabled = get_option( 'snel_enabled_langs', null );
		if ( ! is_array( $enabled ) || empty( $enabled ) ) {
			return $all; // none configured → all on
		}

		$result = array_values( array_intersect( $all, $enabled ) );

		$default = self::default();
		if ( ! in_array( $default, $result, true ) ) {
			$result[] = $default;
		}

		return $result;
	}

	/**
	 * The default language code. Admin choice (snel_default_lang) wins over the
	 * config's `default => true` flag; falls back to the first configured lang.
	 */
	public static function default(): string {
		$option = get_option( 'snel_default_lang', '' );
		if ( $option && array_key_exists( $option, self::config() ) ) {
			return $option;
		}

		foreach ( self::config() as $code => $lang ) {
			if ( ! empty( $lang['default'] ) ) {
				return $code;
			}
		}

		return array_keys( self::config() )[0];
	}

	/**
	 * The current request's language.
	 *
	 * Order: override → 'lang' query var (set by Router's rewrite rules) →
	 * first URL path segment (covers 404s/edge cases) → default.
	 */
	public static function current(): string {
		if ( self::$override !== null ) {
			return self::$override;
		}

		// The locale filter can run before the main query exists — get_query_var
		// would fatal on a null $wp_query, so only ask once it's there.
		if ( ( $GLOBALS['wp_query'] ?? null ) instanceof \WP_Query ) {
			$lang = get_query_var( 'lang', '' );
			if ( $lang && in_array( $lang, self::supported(), true ) ) {
				return $lang;
			}
		}

		$path          = trim( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		$first_segment = explode( '/', $path )[0] ?? '';
		if ( $first_segment && in_array( $first_segment, self::supported(), true ) ) {
			return $first_segment;
		}

		return self::default();
	}

	/** True if the current language equals $lang. */
	public static function is( string $lang ): bool {
		return self::current() === $lang;
	}
}
