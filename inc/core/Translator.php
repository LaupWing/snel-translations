<?php
/**
 * Translator — static UI-string lookup (snel__).
 *
 * Lookup order: snel_theme_translations option (admin overrides) → defaults →
 * the original text. Defaults are NOT a plugin file (strings are theme-specific);
 * a theme/project supplies them via the `snel_theme_string_defaults` filter,
 * grouped by section:
 *   ['General' => ['Lees meer' => ['en' => 'Read more', …]], …]
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translator {

	private static ?array $defaults = null;      // grouped defaults (from filter)
	private static ?array $fileTranslations = null; // flattened defaults
	private static ?array $dbTranslations = null;   // admin overrides

	/** Translate a static string. snel__('Zoeken') → 'Search' when lang=en. */
	public static function translate( string $text ): string {
		$lang    = LocaleManager::current();
		$default = LocaleManager::default();

		// Default language — allow a DB override of the source text itself.
		if ( $lang === $default ) {
			$db       = self::dbTranslations();
			$key      = self::findKey( $db, $text );
			$override = $key !== null ? ( $db[ $key ]['nl'] ?? '' ) : '';
			return ! empty( $override ) ? $override : $text;
		}

		// 1. Database (case-insensitive).
		$db  = self::dbTranslations();
		$key = self::findKey( $db, $text );
		if ( $key !== null && ! empty( $db[ $key ][ $lang ] ) ) {
			return $db[ $key ][ $lang ];
		}

		// 2. Defaults (case-insensitive).
		$file = self::fileTranslations();
		$key  = self::findKey( $file, $text );
		if ( $key !== null && ! empty( $file[ $key ][ $lang ] ) ) {
			return $file[ $key ][ $lang ];
		}

		// 3. Fallback — original.
		return $text;
	}

	/** Case-insensitive key lookup (decodes HTML entities). */
	private static function findKey( array $translations, string $text ): ?string {
		if ( isset( $translations[ $text ] ) ) {
			return $text;
		}

		$normalized = mb_strtolower( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		foreach ( $translations as $key => $val ) {
			if ( mb_strtolower( html_entity_decode( $key, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === $normalized ) {
				return $key;
			}
		}

		return null;
	}

	/** Save one string translation. Empty value removes it (default takes over). */
	public static function save( string $key, string $lang, string $text ): void {
		$translations = get_option( 'snel_theme_translations', [] );
		if ( empty( $text ) ) {
			if ( isset( $translations[ $key ][ $lang ] ) ) {
				unset( $translations[ $key ][ $lang ] );
				if ( empty( $translations[ $key ] ) ) {
					unset( $translations[ $key ] );
				}
			}
		} else {
			if ( ! isset( $translations[ $key ] ) ) {
				$translations[ $key ] = [];
			}
			$translations[ $key ][ $lang ] = $text;
		}
		update_option( 'snel_theme_translations', $translations, false );

		self::$dbTranslations = null; // bust the request cache
	}

	/** Strings grouped by section (defaults merged with DB overrides). Admin grid. */
	public static function grouped(): array {
		$grouped = self::defaults();
		$db      = self::dbTranslations();

		foreach ( $grouped as $group => &$strings ) {
			foreach ( $strings as $nl_key => &$langs ) {
				if ( isset( $db[ $nl_key ] ) ) {
					foreach ( $db[ $nl_key ] as $lang => $text ) {
						if ( ! empty( $text ) ) {
							$langs[ $lang ] = $text;
						}
					}
				}
			}
		}
		unset( $strings, $langs );

		// DB-only strings (not in the defaults) go under "Other".
		$file_keys = [];
		foreach ( $grouped as $section => $strings ) {
			foreach ( $strings as $nl_key => $translations ) {
				$file_keys[ $nl_key ] = true;
			}
		}
		foreach ( $db as $nl_key => $translations ) {
			if ( ! isset( $file_keys[ $nl_key ] ) ) {
				$grouped['Other'][ $nl_key ] = $translations;
			}
		}

		return $grouped;
	}

	/** Grouped string defaults, supplied by the theme via a filter. Cached. */
	private static function defaults(): array {
		if ( self::$defaults === null ) {
			$defaults       = apply_filters( 'snel_theme_string_defaults', [] );
			self::$defaults = is_array( $defaults ) ? $defaults : [];
		}
		return self::$defaults;
	}

	private static function dbTranslations(): array {
		if ( self::$dbTranslations === null ) {
			self::$dbTranslations = get_option( 'snel_theme_translations', [] );
		}
		return self::$dbTranslations;
	}

	/** Flatten the grouped defaults to key => [lang => text] for fast lookup. */
	private static function fileTranslations(): array {
		if ( self::$fileTranslations === null ) {
			self::$fileTranslations = [];
			foreach ( self::defaults() as $group => $strings ) {
				if ( is_array( $strings ) && ! isset( $strings['en'] ) && ! isset( $strings['de'] ) ) {
					self::$fileTranslations = array_merge( self::$fileTranslations, $strings );
				} else {
					self::$fileTranslations[ $group ] = $strings;
				}
			}
		}
		return self::$fileTranslations;
	}
}
