# Snel Translations — Theme Integration Guide

How to make a WordPress theme work with the **Snel Translations** plugin.

The plugin is a lightweight "one post per language" multilingual layer. The
**engine is theme-agnostic** — routing, per-language pages, query filtering,
permalinks, and term translation all work on any theme with zero code. But a
**fully translated site** needs the theme to cooperate in a few well-defined
places. This document is that contract.

---

## TL;DR — what the theme must do

1. **Add guarded fallbacks** for the plugin's functions (so the theme never
   fatals if the plugin is deactivated).
2. **Wrap static UI text** in `snel__()`.
3. **Declare translatable block fields** via filters.
4. **Render the language switcher** using the plugin's helpers.
5. **Be careful with custom queries** (see [Cautions](#cautions)).

Everything else — routing, front/blog page per language, categories, permalinks,
the admin UI — the plugin handles.

---

## 1. Guarded fallbacks (do this first)

Once the theme calls `snel__()` / `snel_get_lang()` etc., it **depends on the
plugin**. If the plugin is ever off, the site white-screens. Add no-op fallbacks
at the top of `functions.php` so it degrades to monolingual instead:

```php
if ( ! function_exists( 'snel__' ) ) {
    function snel__( $text ) { return $text; }
}
if ( ! function_exists( 'snel_url' ) ) {
    function snel_url( $url ) { return $url; }
}
if ( ! function_exists( 'snel_lang_url' ) ) {
    function snel_lang_url( $lang ) { return home_url( '/' ); }
}
if ( ! function_exists( 'snel_get_default_lang' ) ) {
    function snel_get_default_lang() { return 'nl'; }
}
if ( ! function_exists( 'snel_get_lang' ) ) {
    function snel_get_lang() { return 'nl'; }
}
if ( ! function_exists( 'snel_get_supported_langs' ) ) {
    function snel_get_supported_langs() { return [ 'nl' ]; }
}
if ( ! function_exists( 'snel_get_languages_config' ) ) {
    function snel_get_languages_config() {
        return [ 'nl' => [ 'label' => 'NL', 'locale' => 'nl_NL', 'default' => true ] ];
    }
}
if ( ! function_exists( 'snel_nav_item' ) ) {
    function snel_nav_item( $item ) {
        return [ 'url' => $item->url ?? '#', 'title' => $item->title ?? '' ];
    }
}
```

The plugin loads first (`plugins_loaded`) and its real versions win; the
fallbacks only run when the plugin is inactive.

---

## 2. Static UI text → `snel__()`

Any hardcoded string the theme prints (labels, buttons, empty states, footer,
nav chrome) must go through `snel__()`:

```php
// Before
esc_html_e( 'Read more', 'your-textdomain' );
// After
echo esc_html( snel__( 'Read more' ) );

// sprintf works too
echo esc_html( sprintf( snel__( 'Back to %s' ), $label ) );
```

Then **register those strings** so they appear in the Theme Strings grid for
translation:

```php
add_filter( 'snel_theme_string_defaults', function ( $groups ) {
    $groups['Blocks']['Read more']       = [];
    $groups['Footer']['Privacy policy']  = [];
    return $groups;
} );
```

Strings are **not** auto-translated — translate them **once** in
**Translations → Settings → Theme strings** (there's a "Translate with AI"
button). After that they're stored.

---

## 3. Translatable block fields

The plugin translates page content automatically, but for **Gutenberg blocks it
needs to know which attributes hold text** (attributes aren't in the block's
inner HTML). Declare them:

```php
// Simple text attributes.
add_filter( 'snel_block_text_attrs', function ( $map ) {
    $map['yourtheme/statement'] = [ 'heading', 'paragraph' ];
    $map['yourtheme/button']    = [ 'label' ];
    return $map;
} );

// Repeater attributes (arrays of items with text fields).
add_filter( 'snel_block_repeater_attrs', function ( $map ) {
    $map['yourtheme/process']  = [ 'steps' => [ 'title', 'body' ] ];
    $map['yourtheme/features'] = [ 'cards' => [ 'heading', 'body' ] ];
    return $map;
} );
```

Blocks whose text lives in inner HTML need nothing — they translate out of the box.

---

## 4. Language switcher

Render it yourself using the helpers:

```php
foreach ( snel_get_supported_langs() as $code ) {
    $cfg   = snel_get_languages_config();
    $label = $cfg[ $code ]['label'] ?? strtoupper( $code );
    printf( '<a href="%s">%s</a>', esc_url( snel_lang_url( $code ) ), esc_html( $label ) );
}
```

`snel_lang_url( $code )` returns the current page's URL in that language — the
translated post if one exists, otherwise that language's home.

---

## Cautions

> The gotchas that cause 90% of the "why isn't this translated?" questions.

### ⚠️ Custom queries (the big one)

The plugin language-filters:
- the **main query** on every page, and
- **secondary queries** (a block/widget running its own `WP_Query`/`get_posts`)
  — but **only for post types in the allowlist**, default `post` and `page`.

So:

| Your block queries… | Behaviour | What you do |
|---|---|---|
| `post` / `page` | Auto language-filtered | Nothing — just write normal `get_posts`/`WP_Query` |
| A **shared** CPT (partners, logos, clients) | **Shown in every language** (not filtered) | Nothing — do **not** add it to the allowlist |
| A **translated** CPT (services, cases) | Not auto-filtered | Use a **helper** that resolves to the sibling (below), **or** add it to the allowlist (strict, no fallback) |

**Never add a shared CPT (partners) to `snel_translatable_post_types`** — it
would ask for `_snel_lang=es` items, find none, and render empty on every
non-default language.

Opt a single query **out** of filtering (e.g. a deliberately cross-language
list):

```php
$q = new WP_Query( [ 'post_type' => 'post', 'snel_lang' => false ] );
```

Add a translated CPT to the allowlist (strict — it will only show items that
have a translation in the current language):

```php
add_filter( 'snel_translatable_post_types', function ( $types ) {
    $types[] = 'service';
    return $types;
} );
```

Prefer a **fallback helper** for CPTs that should still show the source when a
translation is missing:

```php
function yourtheme_get_services( array $args = [] ) {
    $default = snel_get_default_lang();
    // Fetch the source-language items…
    $items = get_posts( array_merge( [
        'post_type'  => 'service',
        'meta_query' => [
            'relation' => 'OR',
            [ 'key' => '_snel_lang', 'value' => $default ],
            [ 'key' => '_snel_lang', 'compare' => 'NOT EXISTS' ],
        ],
    ], $args ) );

    $lang = snel_get_lang();
    if ( $lang === $default ) {
        return $items;
    }
    // …then swap each to its sibling, falling back to the source.
    return array_map( function ( $p ) use ( $lang ) {
        $sib = snel_get_translation( $p->ID, $lang );
        return ( $sib && ( $t = get_post( $sib ) ) ) ? $t : $p;
    }, $items );
}
```

### ⚠️ Front page & blog (posts) page

WordPress only knows **one** front page and **one** posts page (Settings →
Reading), always the default language's. The plugin swaps them to the current
language's translation automatically — **you don't do anything**, but:
- **Publish** the translated Front/Blog pages. Drafts fall back to the source.
- Don't hardcode `get_option('page_on_front')` expecting a specific ID per
  language; the plugin filters that option contextually.

### ⚠️ Draft translations don't render

A translation that is a **draft** never shows on the front end (blog list,
switcher target, etc.) and falls back to the source. The posts-list "Languages"
column shows draft translations as an **amber chip** — publish them to go live.

### ⚠️ Blocks register from `build/`, not `src/`

If your theme compiles blocks (wp-scripts), the active `render.php` is in
`build/`. Editing `src/blocks/**/render.php` has no effect until you **rebuild**.
(Theme-specific, but a common "my change didn't apply" trap.)

### ⚠️ Categories / taxonomy terms

Terms are **shared** (one term, translated label) — a post's translation keeps
the **same** category automatically. Translate the label/slug once per term on
the category edit screen (**Name / Slug / Description** tabs + Translate all).
**Nested subcategories** (`parent/child`) are **not** routed yet — flat
categories only.

---

## Filters reference

| Filter | Purpose |
|---|---|
| `snel_theme_string_defaults` | Register static strings for the Theme Strings grid |
| `snel_block_text_attrs` | Declare block attributes holding translatable text |
| `snel_block_repeater_attrs` | Declare repeater attributes (arrays of text items) |
| `snel_translatable_post_types` | Post types the secondary query filter language-scopes (default `post`, `page`) |
| `snel_translatable_meta_keys` | Post meta keys to AI-translate (or use the **Custom Fields** admin tab) |
| `snel_cpt_slugs` | Per-language CPT archive slugs (or use the **URLs** admin tab) |
| `snel_translations_parent_menu` | Admin menu slug to nest the Translations page under |

## Helper functions reference

| Function | Returns |
|---|---|
| `snel__( $text )` | The string in the current language (or `$text` if untranslated) |
| `snel_url( $url )` | An internal URL prefixed for the current language |
| `snel_lang_url( $lang )` | The current page's URL in `$lang` (sibling, or that lang's home) |
| `snel_get_lang()` | Current language code |
| `snel_get_default_lang()` | Default (source) language code |
| `snel_get_supported_langs()` | Enabled language codes, e.g. `['nl','en']` |
| `snel_get_languages_config()` | Full language config keyed by code |
| `snel_get_translation( $id, $lang )` | Sibling post ID in `$lang`, or `0` |
| `snel_get_translations( $id )` | `[ lang => post_id ]` for all siblings |
| `snel_post_lang( $id )` | A post's language code |
| `snel_nav_item( $item )` | `[ 'url', 'title' ]` for a nav menu item, language-aware |

---

## Checklist for a new theme

- [ ] Guarded fallbacks in `functions.php`
- [ ] Static text wrapped in `snel__()` + registered via `snel_theme_string_defaults`
- [ ] Block text/repeater attributes declared
- [ ] Language switcher rendered with `snel_lang_url()`
- [ ] Custom queries reviewed: `post`/`page` auto-filtered, shared CPTs left alone,
      translated CPTs use a fallback helper
- [ ] Translated Front/Blog pages **published**
- [ ] CPT slugs / translatable meta set (admin tabs or filters)
