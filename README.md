# Snel Translations

Lightweight multilingual for WordPress. One post per language, linked by post
meta — no per-language page explosion, no heavy plugin.

## Structure

```
snel-translations.php   Plugin header + boot + activation
inc/
  Boot.php              Loads every layer, wires the runtime (the only loader)
  core/                 The engine (runtime, not CRUD)
    LocaleManager.php   Language config + current-language detection
    Router.php          /en/ rewrite rules → resolve request to the sibling post
    TranslationGroup.php  _snel_lang / _snel_group meta, permalink + archive filters
    TermTranslation.php Translated taxonomy labels (shared term)
    Translator.php      snel__() UI-string lookup
  Rest.php              REST routes only  → Controller
  Controller.php        Validation + logic → Model / AI
  Model.php             $wpdb / post meta / options only
  Admin.php             Admin menu page + asset enqueue + data localize
  helpers.php           Global template functions (snel__, snel_url, …)
config/languages.php    Default languages seed (NL + EN)
src/admin/translations/ React admin app (Languages / Settings / Debug)
build/                  Compiled assets
```

## Request flow

```
React → /wp-json/snel-translations/v1/… → Rest → Controller → Model → $wpdb
```

## Rules

- Model never sees a request. Controller never writes SQL. Rest holds no logic.
- Core (routing/locale) is the runtime layer, separate from the REST/CRUD layers.
- Namespace: `Snel\Translations` (core in `Snel\Translations\Core`).
- REST namespace: `snel-translations/v1`.

## Status

Skeleton — files are stubs. Code is being ported from the theme's
`inc/translations/` piece by piece.
