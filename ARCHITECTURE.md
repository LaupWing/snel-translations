# Snel Translations — How It Works

A concept-level walkthrough of the engine. Read this top to bottom once (~20
min) and you understand the plugin well enough to review any change to it.
Code references are `file :: function` pointers — you don't need to open them
to follow along.

---

## 1. The core idea

**One WordPress post per language.** No custom tables, no cloned "translation
objects", no shadow content. The English version of a Dutch page is just…
another page. Two hidden meta fields link them into a family:

| meta key | meaning | example |
|---|---|---|
| `_snel_lang` | the language this post is written in | `en` |
| `_snel_group` | the family ID all siblings share (by convention: the source post's ID) | `852` |

A real family from this site — the homepage:

| ID | Title | `_snel_lang` | `_snel_group` | status |
|---|---|---|---|---|
| 852 | Homepagina | `nl` | `852` | publish |
| 1004 | Homepage | `en` | `852` | draft |
| 1005 | Page d'accueil | `fr` | `852` | draft |

Everything the plugin does is a consequence of this model:

- "Give me the English version of 852" = *find the post where group=852 and
  lang=en* → 1004. (`TranslationGroup :: translation`)
- A post with **no meta at all** is treated as default-language, family of one.
  That's why the plugin can be activated on an existing site without touching
  anything.
- **Categories/tags are NOT duplicated.** One term, shared by all siblings; only
  its *label* is translated (stored in term meta). A post's translation
  automatically has the same categories. (`TermTranslation`)

**Why this beats the WPML model:** nothing is special. Every language version
is a plain post — editable, queryable, deletable with normal WP tools. Delete
the plugin and you're left with a working site that simply shows all languages
mixed. There is no lock-in and no migration.

URLs: the default language lives at the root (`/over-ons/`), every other
language gets a prefix (`/en/about-us/`). Each post keeps its own natural slug
— the slug is *content*, not configuration.

---

## 2. Life of a request: `/en/about-us`

The single most important thing to understand. Say the site has:

| ID | slug | lang | group |
|---|---|---|---|
| 10 | `over-ons` | `nl` | `10` |
| 11 | `about-us` | `en` | `10` |

Someone opens `https://site.nl/en/about-us`. Five steps:

**Step 1 — Rewrite rule turns the URL into query vars.**
On `init` the plugin registered a rule per language:
`^en/(.+?)/?$  →  index.php?lang=en&pagename=$matches[1]`.
So WordPress now holds: `{ lang: "en", pagename: "about-us" }`.
(`Router :: registerRewriteRules`)

**Step 2 — Safety net.** If WP matched some *other* rule first (the attachment
rule is a classic thief), a `request` filter re-reads the raw URL, sees it
starts with `/en/`, and pins the vars back. Usually a no-op.
(`Router :: interceptLanguageUrl`)

**Step 3 — Resolve to the right sibling.** WP would now look up a post by slug
`about-us` — and it finds post 11. The plugin checks: *is the found post's
language the requested language?* Here yes (11 is `en`) → done. But if the
visitor had typed `/en/over-ons` (the NL slug), WP would find post 10 (`nl`),
and the plugin swaps in its `en` sibling: group of 10 → siblings → `en` → 11.
(`Router :: resolveLanguagePost`)

**Step 4 — Pin the concrete ID.** The slug vars are replaced by the actual post
ID: `{ lang: "en", page_id: 11 }`. From here WordPress proceeds 100% normally —
the plugin got out of the way before the main query even ran.
(`Router :: pinPost`)

**Step 5 — Render.** The theme renders post 11 like any page. Meanwhile:
- `get_locale()` returns `en_US` because the URL said `/en/` → `<html
  lang="en-US">`, English dates. (`LocaleManager :: filterLocale`)
- Every permalink printed on the page passes through a filter that prefixes
  `/en/` for non-default posts — so menus and links keep you inside English.
  (`TranslationGroup :: filterPermalink`)
- hreflang tags in `<head>` list the published siblings. (`Hreflang :: output`)

**Draft fallback:** had post 11 been a draft, step 3 keeps post 10 — the URL
shows the Dutch source rather than a 404. Unpublished work is never visible,
but URLs never break either.

### The two special pages

WordPress has exactly **one** front page and **one** posts page
(Settings → Reading), and they're the default-language ones. The plugin filters
the *options themselves*:

- On `/en/`, `get_option('page_on_front')` answers 1004 (the EN sibling)
  instead of 852. WP core believes the English homepage was the front page all
  along. Same trick for the blog page. (`Router :: filterFrontPageId /
  filterPostsPageId`)
- Consequence to remember: **anything comparing IDs against those options is
  language-dependent.** This caused a real bug (see §7).

### Archives (`/en/blog/`, `/en/category/seo/`)

No sibling lookup — an archive isn't *a* post. The rewrite rule just adds
`lang=en`, and a query filter constrains the listing to posts where
`_snel_lang = en`. Same listing page, different content per language.
(`TranslationGroup :: filterArchives`)

---

## 3. The invariants — review any change against these

If a PR (yours, Claude's, anyone's) violates one of these, reject it.

1. **A draft/unpublished translation never renders on the front end.** It falls
   back to the source. Applies to routing, the switcher, hreflang, front page.
2. **The default language never has a URL prefix.** `/nl/over-ons/` must not
   exist; `/over-ons/` must not redirect to it.
3. **Admin is never language-filtered.** Every front-end filter starts with an
   `is_admin()` bail. Losing this = clients "losing" posts in wp-admin.
4. **A post with no meta behaves as default-language.** Activation on existing
   content must be a no-op.
5. **hreflang only advertises URLs that actually exist** (published siblings).
   Never point hreflang at a fallback.
6. **The plugin resolves; the theme renders.** No HTML output from the plugin
   except head tags. No routing logic in the theme.
7. **Siblings share one group ID and one term set.** Never duplicate terms per
   language.
8. **Everything degrades to monolingual.** Plugin off = site still works
   (theme's guarded fallbacks); one language configured = zero filters output.
9. **Deactivation leaves no debris.** Meta keys stay (harmless), rewrite rules
   flush, nothing else persists.

---

## 4. The write side — "Translate with AI"

What happens when you click it on post 10 (NL) targeting `en`
(`Create :: translate_one`):

1. **Duplicate** post 10 as a **draft** — content, meta, terms copied.
2. **Link**: new post gets `_snel_lang = en`, `_snel_group = 10`.
3. **Collect translatable strings**: the title, text inside block HTML, block
   *attributes* declared by the theme (`snel_block_text_attrs` — attributes are
   invisible inside HTML, so the theme must name them), declared meta keys.
4. **Translate in batches** via the WP AI Client, segments separated by a
   sentinel string so HTML survives. Count-checked: if the model merges or
   drops a segment, the batch fails loudly rather than misaligning. (`Ai`)
5. **Stamp a source hash** (`_snel_src_hash`): a fingerprint of the source's
   translatable content at translation time.
6. **Store translation memory** (`_snel_tm`): a `{source string → translated
   string}` map on the translation.

The hash and the memory power the maintenance loop:

- **"Needs update" detection**: current fingerprint of source ≠ stored hash →
  the admin UI flags the translation (amber). You edited the Dutch page; the
  English one is now stale. No cron, no events — just comparison on view.
- **Cheap re-sync**: on "sync", strings still present in memory are reused
  (free, instant); only *changed* strings hit the AI. Editing one paragraph
  re-translates one paragraph.

**Theme strings** (`snel__('Lees meer')`) are a separate, simpler system: a
site-wide dictionary in one option, editable in the admin grid, AI-fillable.
Lookup: admin override → theme defaults → the string itself. Untranslated =
shows the source text, never breaks. (`Translator`)

---

## 5. Query filtering — why languages don't leak

Two layers:

- **Main query** (the page WP is building): always filtered on listings —
  blog, archives, search get `meta: _snel_lang = current` (default language
  also matches *missing* meta, per invariant 4).
  (`TranslationGroup :: filterArchives`)
- **Secondary queries** (a block/widget running its own `get_posts`): filtered
  **only for post types on the allowlist** — `post`, `page`, plus whatever the
  theme adds via `snel_translatable_post_types`.

Why an allowlist and not "filter everything"? Because of **shared CPTs**.
Partner logos exist once, in no language. Filter them by `_snel_lang = en` and
every English page shows an empty logo strip. So:

| CPT kind | example | correct handling |
|---|---|---|
| Translated | services, cases | allowlist (strict) **or** a theme helper that falls back to the source when no translation exists |
| Shared | partners, logos | **nothing** — keep it off the allowlist |

Escape hatch for one deliberate cross-language query:
`new WP_Query([ …, 'snel_lang' => false ])`.

This table is the answer to 90% of "why is this block empty / showing Dutch?"

---

## 6. The theme contract (summary)

Full version: `THEME-INTEGRATION.md`. The split:

**Plugin alone:** routing, sibling resolution, front/blog page swap, locale,
permalinks, hreflang, canonical redirects, term labels, admin UI, AI engine.

**Theme must:** guarded fallbacks in `functions.php` (site survives plugin
deactivation — invariant 8), wrap static strings in `snel__()`, declare block
text attributes, render the switcher with `snel_lang_url()`, handle custom
queries per the table in §5.

---

## 7. Debugging map — when X breaks, look at Y

| Symptom | First suspect | Where |
|---|---|---|
| `/en/...` is a 404 | Stale rewrite rules — flush (Settings → Permalinks → Save) | `Router :: registerRewriteRules` |
| URL shows the wrong language's content | Sibling resolution — check both posts' `_snel_lang`/`_snel_group` meta first; nine times out of ten the *meta* is wrong, not the code | `Router :: resolveLanguagePost` |
| `/en/` shows the Dutch homepage | EN front page is a **draft** — that's the designed fallback, publish it | `Router :: fixFrontPage` |
| A block lists posts from other languages | Post type not on the allowlist | §5, `filterSecondaryQueries` |
| A block renders **empty** on `/en/` only | A *shared* CPT got onto the allowlist | §5 — remove it |
| A link on the page points at the wrong language | Permalink filter | `TranslationGroup :: filterPermalink` |
| Redirect loop or redirect stripping `/en/` | Canonical handling | `Router :: fixCanonicalRedirect` |
| Wrong `<html lang>` / dates in wrong language | Locale filter; for fr/de/es also check the WP language pack is installed | `LocaleManager :: filterLocale` |
| Menus in wp-admin look normal but front end links are odd | Nav resolution | `Nav :: item` |
| "Translate with AI" fails midway | Provider quota/rate limit — the error message says which; batches are count-checked so partial output is refused, not saved | `Ai :: translate_chunk` |
| Translation not flagged stale after editing source | Hash comparison | `Create :: source_signature` |

**Case study (real bug, fixed July 2026):** on `/en/`, the language switcher's
NL link pointed at `/homepagina/` instead of `/`. Chain: the plugin filters
`page_on_front` to the EN sibling (1004) → WP core compares page 852 against
that option, no longer recognizes it as the front page → gives it a slug
permalink. Classic §2-special-pages consequence: *ID comparisons against
filtered options are language-dependent.* Fix: front-page detection by
**group**, not ID. If you understood why that broke, you understand the plugin.

---

## What NOT to bother understanding deeply

- `src/admin/` React app — plain CRUD UI over the REST endpoints.
- `Rest.php` / `Controller.php` / `Model.php` — standard layered CRUD
  (`Rest` routes → `Controller` validates → `Model` touches the DB, never
  crossing layers).
- `vendor/` — Composer + the GitHub auto-update checker.
- `AdminColumns.php` — the language chips in the posts list.

The engine is `Router` + `TranslationGroup` (+ `LocaleManager` for "what
language is this request"). If you own §2 and §3, you own the plugin.
