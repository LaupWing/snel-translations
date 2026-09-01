<?php
/**
 * Boot — the plugin's entry point.
 *
 * The ONLY place that requires files and news up classes. Everything else is
 * loaded from here so the wiring lives in one spot.
 *
 * @package Snel\Translations
 */

namespace Snel\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Boot {

	/**
	 * Runs on `plugins_loaded`. Loads every layer and starts the runtime.
	 *
	 * Will (once we port the code):
	 *   - require the core engine  (LocaleManager, Router, TranslationGroup,
	 *     TermTranslation, Translator) + helpers.php (snel__, snel_url, …)
	 *   - require Model, Controller, Rest, Admin
	 *   - register the runtime:
	 *       Router::register();          rewrite rules + resolve request → sibling
	 *       TranslationGroup::register(); permalink prefix + archive filter
	 *       TermTranslation::register();  translated term labels
	 *       new Rest();                   REST endpoints  (/wp-json/snel-translations/v1)
	 *       new Admin();                  admin menu page + asset enqueue
	 */
	public static function init(): void {
		// ── Core engine ──────────────────────────────────────────────────────
		require_once SNEL_TR_DIR . 'inc/core/LocaleManager.php';
		require_once SNEL_TR_DIR . 'inc/core/TranslationGroup.php';
		require_once SNEL_TR_DIR . 'inc/core/UrlGenerator.php';
		require_once SNEL_TR_DIR . 'inc/core/Router.php';
		require_once SNEL_TR_DIR . 'inc/core/Translator.php';
		require_once SNEL_TR_DIR . 'inc/core/TermTranslation.php';
		require_once SNEL_TR_DIR . 'inc/core/SeoBridge.php';
		require_once SNEL_TR_DIR . 'inc/core/DisabledLanguages.php';
		require_once SNEL_TR_DIR . 'inc/core/Hreflang.php';
		require_once SNEL_TR_DIR . 'inc/core/FallbackNotice.php';

		// ── Helpers + request/service layers ─────────────────────────────────
		require_once SNEL_TR_DIR . 'inc/helpers.php';
		require_once SNEL_TR_DIR . 'inc/Model.php';
		require_once SNEL_TR_DIR . 'inc/Controller.php';
		require_once SNEL_TR_DIR . 'inc/Rest.php';
		require_once SNEL_TR_DIR . 'inc/Ai.php';
		require_once SNEL_TR_DIR . 'inc/Create.php';
		require_once SNEL_TR_DIR . 'inc/Nav.php';
		require_once SNEL_TR_DIR . 'inc/Admin.php';
		require_once SNEL_TR_DIR . 'inc/AdminColumns.php';
		require_once SNEL_TR_DIR . 'inc/ClassicMetaBox.php';

		// ── Register the runtime ─────────────────────────────────────────────
		Core\LocaleManager::register();     // front-end locale follows the URL language
		Core\Router::register();            // rewrite rules + resolve request → sibling
		Core\TranslationGroup::register();  // permalink prefix + archive filter + save
		Core\TermTranslation::register();   // translated term labels (front end)
		Core\SeoBridge::register();         // per-language Yoast output on term archives
		Core\DisabledLanguages::register(); // disabled-language posts out of sitemaps + REST
		Core\Hreflang::register();          // <link rel="alternate" hreflang> in <head>
		Core\FallbackNotice::register();    // toast after untranslated-content redirect
		Nav::register();                    // nav menu item resolution
		Ai::register();                     // snel_translate AJAX
		Create::register();                 // create/sync/state AJAX + editor data
		AdminColumns::register();           // list-table language columns + filter
		ClassicMetaBox::register();       // translations box on classic-editor screens

		new Rest();                         // REST endpoints (snel-translations/v1)
		( new Admin() )->register();        // admin menu page + asset enqueue
	}

	/**
	 * Runs on activation. Flush rewrite rules so language-prefixed URLs work
	 * immediately. Later: also create custom tables via Install if we add any.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}
}
