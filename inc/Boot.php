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
		// (TermTranslation, Translator — added next.)

		// ── Request layers (Model, Controller, Rest, Admin) — added later. ────

		// ── Register the runtime ─────────────────────────────────────────────
		// LocaleManager is a static utility — nothing to instantiate/register.
		// Router::register() etc. will be called here once ported.
	}

	/**
	 * Runs on activation. Flush rewrite rules so language-prefixed URLs work
	 * immediately. Later: also create custom tables via Install if we add any.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}
}
