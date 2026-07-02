<?php
/**
 * TermTranslation — translated taxonomy labels (shared term).
 *
 * A term is NOT duplicated per language. It keeps its native name/slug/desc;
 * the translated name + description live in term meta:
 *   _snel_name_{lang}  · _snel_desc_{lang}
 * The default language uses the native columns (no meta). A missing translation
 * falls back to the native value — a term never renders blank.
 *
 * On the front end a get_term filter swaps name/description into the current
 * language. In wp-admin the current language is always default, so it's a no-op
 * there and edit screens show the native term.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TermTranslation {

	/** A term's name in a language (default: current). Falls back to native. */
	public static function name( $term, ?string $lang = null ): string {
		$term = get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$lang = $lang ?: LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term->name;
		}

		$value = get_term_meta( $term->term_id, self::nameKey( $lang ), true );
		return $value !== '' ? $value : $term->name;
	}

	/** A term's description in a language (default: current). Falls back to native. */
	public static function description( $term, ?string $lang = null ): string {
		$term = get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$lang = $lang ?: LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term->description;
		}

		$value = get_term_meta( $term->term_id, self::descKey( $lang ), true );
		return $value !== '' ? $value : $term->description;
	}

	/** Meta key for a term name in a language. */
	public static function nameKey( string $lang ): string {
		return '_snel_name_' . $lang;
	}

	/** Meta key for a term description in a language. */
	public static function descKey( string $lang ): string {
		return '_snel_desc_' . $lang;
	}

	/** Register the front-end display filter (no-op in admin). */
	public static function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'get_term', [ self::class, 'filterTerm' ] );
	}

	/** Swap a term's name/description into the current language for display. */
	public static function filterTerm( $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return $term;
		}

		$lang = LocaleManager::current();
		if ( $lang === LocaleManager::default() ) {
			return $term;
		}

		$name = get_term_meta( $term->term_id, self::nameKey( $lang ), true );
		if ( $name !== '' ) {
			$term->name = $name;
		}

		$desc = get_term_meta( $term->term_id, self::descKey( $lang ), true );
		if ( $desc !== '' ) {
			$term->description = $desc;
		}

		return $term;
	}
}
