<?php
/**
 * helpers.php — global template functions the theme calls.
 *
 * These are the public API of the plugin. Themes stay unchanged: they keep
 * calling snel__(), snel_url(), snel_nav_item(), etc. — the plugin just
 * provides them now instead of the theme.
 *
 * Each is a thin wrapper that delegates to a core class:
 *   snel__($text)               → Translator::translate()      UI string
 *   snel_url($url)              → UrlGenerator::url()          language-aware URL
 *   snel_lang_url($lang)        → UrlGenerator::langUrl()      switch-language URL
 *   snel_get_lang()             → LocaleManager::current()
 *   snel_get_default_lang()     → LocaleManager::default()
 *   snel_get_supported_langs()  → LocaleManager::supported()
 *   snel_post_lang($id)         → TranslationGroup::langOf()
 *   snel_get_translation($id,$l)→ TranslationGroup::translation()
 *   snel_get_translations($id)  → TranslationGroup::siblings()
 *   snel_term_name($t,$l)       → TermTranslation::name()
 *   snel_nav_item($item)        → nav resolution
 *
 * @package Snel\Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TODO: define the global helper functions here (skeleton for now).
