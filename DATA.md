# DATA.md — every structure that moves through this plugin

Real shapes, real examples from this site. The `//` comments are the point —
they say what the code can't. Read this before touching anything that handles
data. High-level story: `ARCHITECTURE.md`. Why-decisions: `DECISIONS.md`.

---

## Post meta — the sibling links (THE core model)

Two hidden keys on every language-tagged post:

```
_snel_lang  = "en"    // language this post is WRITTEN in. MISSING = default language (nl).
_snel_group = "852"   // family id — by convention the SOURCE post's own ID. MISSING = family of one.
```

A real family (this site's homepage):

| ID   | title          | `_snel_lang` | `_snel_group` | status  |
|------|----------------|--------------|---------------|---------|
| 852  | Homepagina     | nl           | 852           | publish |
| 1004 | Homepage       | en           | 852           | draft   |
| 1005 | Page d'accueil | fr           | 852           | draft   |

```
// - The SOURCE (group root) is the post whose group == its own ID (852 here).
// - Trash/delete the ROOT → cascades to all siblings. Delete a non-root → only itself.
// - A post with NO meta at all = default-language, family of one. That's a feature
//   (activation on an existing site is a no-op) — never "fix" it by stamping meta.
// - 9/10 wrong-language bugs are wrong META on the post, not wrong code.
//   Check these two keys first (ARCHITECTURE.md §7).
```

## Post meta — translation bookkeeping (lives on the TRANSLATION, not the source)

```
_snel_src_hash = "v2:9b74c9897bac770ffc029102a200c5de"
// - fingerprint of the SOURCE's translatable content at translation time
// - stale check = recompute source signature, compare. No cron — compared on view.
// - "v2:" = recipe version (Create::SIG_VERSION). Different version = UNKNOWN,
//   deliberately NOT flagged outdated (avoids mass-flagging after a plugin update).

_snel_tm = '{"Over ons":"About us","Lees meer":"Read more"}'
// - JSON string: { source string → translated string } = translation memory
// - powers cheap re-sync: unchanged strings reuse this for free, only changed hit the AI
// - read/write via Create::load_memory() / store_memory() (stored wp_slash'd)
```

## Attachment meta — alt text per language

```
_wp_attachment_image_alt = "Eiken kast"   // WP core key = the source (nl) alt
_snel_alt_en             = "Oak cabinet"  // one extra key per non-default language
// Media-tab backlogs are two DIFFERENT numbers (Model::imageBacklogByParentType):
//   noAlt        = no source alt at all → vision AI must look at the image
//   missingTrans = source alt exists but ≥1 language key empty → text translate
```

## Term meta — labels only; terms are NEVER duplicated (invariant 7)

One term, shared by all siblings. Only its labels translate, keyed per language:

```
_snel_name_en      = "Cabinets"        // term name shown in EN
_snel_desc_en      = "..."             // term description in EN
_snel_slug_en      = "cabinets"        // term slug used in EN URLs
_snel_seo_title_en = "Antique cabinets | ..."  // Yoast bridge; blank = Yoast's own title
_snel_seo_desc_en  = "..."             // blank = falls back to translated description
```

## Options (wp_options)

```
snel_languages = '{"nl":{"label":"NL","locale":"nl_NL","default":true},
                   "en":{"label":"EN","locale":"en_US"}}'
// - a JSON STRING, not an array. Empty/absent → config/languages.php seed wins.
// - exactly ONE entry carries "default": true. Default language never gets a URL prefix.

snel_default_lang  = "nl"
snel_enabled_langs = ["nl", "en"]   // PHP array. EMPTY or missing = ALL configured
                                    // languages enabled. Default is always included.

snel_disabled_redirects = ["fr" => "en"]
// - where a DISABLED language's old URLs redirect
// - key must be disabled, target must be enabled — anything else falls back
//   to the default language at request time (Controller re-validates on save)

snel_theme_translations = ["Lees meer" => ["en" => "Read more", "fr" => "Lire la suite"]]
// - { source string → { lang → text } } — the snel__() dictionary
// - saving EMPTY text REMOVES the key (theme default takes over again)
// - lookup order: this option → theme defaults → the source string itself.
//   Untranslated shows the source text — never breaks, never empty.

snel_translatable_meta = ["page" => ["subtitle", "cta_text"]]
// - admin-chosen custom-field keys the AI translates, per post type

snel_cpt_slugs = ["diensten" => ["en" => "services", "de" => "leistungen"]]
// - per-language CPT archive slugs, keyed by the DEFAULT-language slug
// - must stay in sync with the rewrite rules → changing this needs a permalink flush
```

## REST rows (admin React app)

One row per language-tagged post (`Model::translationRows`, grouped by
`Controller` for the UI):

```json
{ "id": 1004, "lang": "en", "group": 852, "title": "Homepage",
  "type": "page", "status": "draft" }
// group falls back to the post's own id when meta is missing (default-lang posts)
```

---

## Adding a new structure to this file

Dump the real thing, don't write it from memory:

```php
error_log( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
```

Hit the flow once with real data, paste it here, then add the two or three
comments only you know. That last part is the actual value of this file.
