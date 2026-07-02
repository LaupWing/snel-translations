<?php
/**
 * helpers.php — global template functions (the plugin's public API).
 *
 * Themes stay unchanged: they keep calling snel__(), snel_url(), snel_post_lang()
 * etc. Each function is a thin wrapper delegating to a core class. Guarded with
 * function_exists so the plugin can coexist with a theme that still defines them
 * during the migration.
 *
 * @package Snel\Translations
 */

use Snel\Translations\Core\LocaleManager;
use Snel\Translations\Core\TranslationGroup;
use Snel\Translations\Core\TermTranslation;
use Snel\Translations\Core\Translator;
use Snel\Translations\Core\UrlGenerator;
use Snel\Translations\Nav;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Language config ─────────────────────────────────────────────────────────

if ( ! function_exists( 'snel_get_languages_config' ) ) {
	function snel_get_languages_config() {
		return LocaleManager::config();
	}
}

if ( ! function_exists( 'snel_get_supported_langs' ) ) {
	function snel_get_supported_langs() {
		return LocaleManager::supported();
	}
}

if ( ! function_exists( 'snel_get_default_lang' ) ) {
	function snel_get_default_lang() {
		return LocaleManager::default();
	}
}

if ( ! function_exists( 'snel_get_lang' ) ) {
	function snel_get_lang() {
		return LocaleManager::current();
	}
}

if ( ! function_exists( 'snel_is_lang' ) ) {
	function snel_is_lang( $lang ) {
		return LocaleManager::is( $lang );
	}
}

// ─── URLs ────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'snel_url' ) ) {
	function snel_url( $url ) {
		return UrlGenerator::url( $url );
	}
}

if ( ! function_exists( 'snel_lang_url' ) ) {
	function snel_lang_url( $target_lang ) {
		return UrlGenerator::langUrl( $target_lang );
	}
}

// ─── Theme strings ───────────────────────────────────────────────────────────

if ( ! function_exists( 'snel__' ) ) {
	function snel__( $text ) {
		return Translator::translate( $text );
	}
}

if ( ! function_exists( 'snel_save_translation' ) ) {
	function snel_save_translation( $key, $lang, $text ) {
		Translator::save( $key, $lang, $text );
	}
}

if ( ! function_exists( 'snel_get_grouped_theme_translations' ) ) {
	function snel_get_grouped_theme_translations() {
		return Translator::grouped();
	}
}

// ─── Post helpers (one post per language) ────────────────────────────────────

if ( ! function_exists( 'snel_title' ) ) {
	function snel_title( $post_id = null ) {
		return get_the_title( $post_id ?: get_the_ID() );
	}
}

if ( ! function_exists( 'snel_excerpt' ) ) {
	function snel_excerpt( $post_id = null ) {
		return get_the_excerpt( $post_id ?: get_the_ID() );
	}
}

if ( ! function_exists( 'snel_post_lang' ) ) {
	function snel_post_lang( $post_id = null ) {
		return TranslationGroup::langOf( $post_id ?: get_the_ID() );
	}
}

if ( ! function_exists( 'snel_post_group' ) ) {
	function snel_post_group( $post_id = null ) {
		return TranslationGroup::groupOf( $post_id ?: get_the_ID() );
	}
}

if ( ! function_exists( 'snel_get_translation' ) ) {
	function snel_get_translation( $post_id, $lang ) {
		return TranslationGroup::translation( $post_id, $lang );
	}
}

if ( ! function_exists( 'snel_get_translations' ) ) {
	function snel_get_translations( $post_id = null ) {
		$post_id = $post_id ?: get_the_ID();
		return TranslationGroup::siblings( TranslationGroup::groupOf( $post_id ) );
	}
}

// ─── Taxonomy term helpers (shared term, translated label) ───────────────────

if ( ! function_exists( 'snel_term_name' ) ) {
	function snel_term_name( $term, $lang = null ) {
		return TermTranslation::name( $term, $lang );
	}
}

if ( ! function_exists( 'snel_term_description' ) ) {
	function snel_term_description( $term, $lang = null ) {
		return TermTranslation::description( $term, $lang );
	}
}

// ─── Menu ────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'snel_nav_item' ) ) {
	function snel_nav_item( $item ) {
		return Nav::item( $item );
	}
}
