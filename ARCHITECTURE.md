# Snel Translations — How It Works

- First visit: read §1–§3 (~5 min). §4–§5 when touching the write side / queries.
- §3 (invariants) and §7 (debugging map) are **lookup tables** — don't read linearly.
- Code refs are `file :: function` pointers. Data shapes with examples: `DATA.md`.

## TL;DR

- One WP post per language. Two meta keys link siblings: `_snel_lang` + `_snel_group`.
- No meta = default language. No custom tables, no lock-in.
- Default language at the root (`/over-ons/`), others prefixed (`/en/about-us/`).
- Router resolves URL → right sibling → pins the ID → WP proceeds normally.
- Terms are shared, never duplicated — only labels translate (term meta).
- AI translate = duplicate as draft + link + translate strings + stamp hash + store memory.
- Stale detection = source fingerprint ≠ stored hash. No cron.
- Listings filtered to current language; secondary queries only for allowlisted types.

---

## 1. The core idea

- **One WordPress post per language.** The English version of a Dutch page is just another page.
- Two hidden meta fields make the family:

| meta key | meaning | example |
|---|---|---|
| `_snel_lang` | language this post is written in | `en` |
| `_snel_group` | family ID all siblings share (= source post's ID) | `852` |

- Real family (this site's homepage):

| ID | Title | lang | group | status |
|---|---|---|---|---|
| 852 | Homepagina | `nl` | `852` | publish |
| 1004 | Homepage | `en` | `852` | draft |
| 1005 | Page d'accueil | `fr` | `852` | draft |

- Everything follows from this model:
  - "English version of 852" = *find post where group=852, lang=en* → 1004. (`TranslationGroup :: translation`)
  - **No meta at all = default language, family of one** → activating on an existing site touches nothing.
  - **Terms are NOT duplicated** — one category/tag shared by all siblings, only the *label* translated (term meta). (`TermTranslation`)
  - Every language version is a plain post: editable, queryable, deletable with normal WP tools. Delete the plugin → working site, all languages mixed.
- URLs: default language at the root (`/over-ons/`), others prefixed (`/en/about-us/`).
  - Each post keeps its own natural slug — the slug is *content*, not configuration.

---

## 2. Life of a request: `/en/about-us`

The single most important thing to understand. Given:

| ID | slug | lang | group |
|---|---|---|---|
| 10 | `over-ons` | `nl` | `10` |
| 11 | `about-us` | `en` | `10` |

Visitor opens `/en/about-us` — five steps:

1. **Rewrite rule → query vars.** Per-language rule registered on `init`:
   `^en/(.+?)/?$ → index.php?lang=en&pagename=$matches[1]` → WP holds `{lang:"en", pagename:"about-us"}`. (`Router :: registerRewriteRules`)
2. **Safety net.** If another rule matched first (attachment rule is the classic thief), a `request` filter re-reads the raw URL and pins the vars back. Usually a no-op. (`Router :: interceptLanguageUrl`)
3. **Resolve the right sibling.** WP finds post 11 by slug → language matches → done.
   - If the visitor typed `/en/over-ons` (NL slug): WP finds post 10 (`nl`) → plugin swaps in the `en` sibling: group of 10 → siblings → `en` → 11. (`Router :: resolveLanguagePost`)
4. **Pin the ID.** Slug vars replaced with `{lang:"en", page_id:11}` → WP proceeds 100% normally from here. (`Router :: pinPost`)
5. **Render.** Theme renders post 11 like any page. Meanwhile:
   - `get_locale()` → `en_US` → `<html lang="en-US">`, English dates. (`LocaleManager :: filterLocale`)
   - Every printed permalink gets the `/en/` prefix → menus/links stay in English. (`TranslationGroup :: filterPermalink`)
   - hreflang tags list the published siblings. (`Hreflang :: output`)

- **Draft fallback:** if post 11 is a draft, step 3 keeps post 10 — the URL shows the Dutch source, never a 404. Unpublished work is never visible; URLs never break.

### The two special pages

- WP has **one** front page + **one** posts page (Settings → Reading) — the default-language ones.
- The plugin filters the *options themselves*: on `/en/`, `get_option('page_on_front')` answers 1004, not 852 → WP core believes the EN homepage was the front page all along. Same for the blog page. (`Router :: filterFrontPageId / filterPostsPageId`)
- ⚠ Consequence: **any ID comparison against those options is language-dependent.** Caused a real bug — see §7 case study.

### Archives (`/en/blog/`, `/en/category/seo/`)

- No sibling lookup — an archive isn't *a* post.
- Rewrite rule just adds `lang=en`; a query filter constrains the listing to `_snel_lang = en`. Same page, different content per language. (`TranslationGroup :: filterArchives`)

---

## 3. The invariants — review any change against these

If a PR (yours, Claude's, anyone's) violates one, reject it.

1. A draft/unpublished translation **never renders on the front end** — falls back to the source. (Routing, switcher, hreflang, front page.)
2. The default language **never has a URL prefix.** `/nl/over-ons/` must not exist or be redirected to.
3. **Admin is never language-filtered.** Every front-end filter bails on `is_admin()`. Losing this = clients "losing" posts.
4. A post with **no meta behaves as default-language.** Activation on existing content = no-op.
5. hreflang **only advertises URLs that exist** (published siblings). Never point it at a fallback.
6. **Plugin resolves; theme renders.** No HTML from the plugin except head tags. No routing in the theme.
7. Siblings share **one group ID and one term set.** Never duplicate terms per language.
8. **Everything degrades to monolingual.** Plugin off = site works; one language = zero filter output.
9. **Deactivation leaves no debris.** Meta stays (harmless), rewrite rules flush, nothing else persists.

---

## 4. The write side — "Translate with AI"

Clicking it on post 10 (NL), target `en` (`Create :: translate_one`):

1. **Duplicate** post 10 as a **draft** — content, meta, terms copied.
2. **Link**: `_snel_lang = en`, `_snel_group = 10`.
3. **Collect strings**: title + text inside block HTML + block *attributes* declared by the theme (`snel_block_text_attrs` — attributes are invisible inside HTML, the theme must name them) + declared meta keys.
4. **Translate in batches** (WP AI Client), segments split by a sentinel so HTML survives. Count-checked: merged/dropped segment → batch fails loudly, never misaligns. (`Ai`)
5. **Stamp** `_snel_src_hash` — fingerprint of the source's translatable content right now.
6. **Store** `_snel_tm` — `{source → translated}` map on the translation.

The hash + memory power the maintenance loop:

- **"Needs update"**: current source fingerprint ≠ stored hash → amber flag in admin. No cron — just comparison on view.
- **Cheap re-sync**: unchanged strings reuse the memory (free); only *changed* strings hit the AI. Edit one paragraph → re-translate one paragraph.

**Theme strings** (`snel__('Lees meer')`) — separate, simpler system:

- Site-wide dictionary in one option, editable in the admin grid, AI-fillable.
- Lookup: admin override → theme defaults → the string itself. Untranslated = source text shows, never breaks. (`Translator`)

---

## 5. Query filtering — why languages don't leak

Two layers:

- **Main query** (the page WP is building): listings — blog, archives, search — always get `_snel_lang = current` (default also matches *missing* meta, invariant 4). (`TranslationGroup :: filterArchives`)
- **Secondary queries** (a block/widget's own `get_posts`): filtered **only for allowlisted post types** — `post`, `page`, + theme additions via `snel_translatable_post_types`.

Why an allowlist, not "filter everything"? **Shared CPTs.** Partner logos exist once, in no language — filter them by `_snel_lang = en` and every English page shows an empty logo strip.

| CPT kind | example | correct handling |
|---|---|---|
| Translated | services, cases | allowlist (strict) **or** theme helper that falls back to source |
| Shared | partners, logos | **nothing** — keep it OFF the allowlist |

- Escape hatch for one deliberate cross-language query: `new WP_Query([ …, 'snel_lang' => false ])`.
- This table answers 90% of "why is this block empty / showing Dutch?"

---

## 6. The theme contract

- **Plugin alone:** routing, sibling resolution, front/blog swap, locale, permalinks, hreflang, canonicals, term labels, admin UI, AI engine.
- **Theme must:** guarded fallbacks in `functions.php` (invariant 8), wrap strings in `snel__()`, declare block text attributes, render the switcher with `snel_lang_url()`, follow the §5 table for custom queries.
- Full version: `THEME-INTEGRATION.md`.

---

## 7. Debugging map — when X breaks, look at Y

| Symptom | First suspect | Where |
|---|---|---|
| `/en/...` is a 404 | Stale rewrite rules — flush (Settings → Permalinks → Save) | `Router :: registerRewriteRules` |
| URL shows wrong language's content | Check both posts' `_snel_lang`/`_snel_group` meta FIRST — 9/10 it's the meta, not the code | `Router :: resolveLanguagePost` |
| `/en/` shows the Dutch homepage | EN front page is a **draft** — designed fallback, publish it | `Router :: fixFrontPage` |
| Block lists posts from other languages | Post type not on the allowlist | §5, `filterSecondaryQueries` |
| Block renders **empty** on `/en/` only | A *shared* CPT got onto the allowlist | §5 — remove it |
| Link points at the wrong language | Permalink filter | `TranslationGroup :: filterPermalink` |
| Redirect loop / redirect strips `/en/` | Canonical handling | `Router :: fixCanonicalRedirect` |
| Wrong `<html lang>` / dates | Locale filter; fr/de/es also need the WP language pack | `LocaleManager :: filterLocale` |
| Admin menus fine, front-end links odd | Nav resolution | `Nav :: item` |
| "Translate with AI" fails midway | Provider quota/rate limit — error says which; partial output is refused, not saved | `Ai :: translate_chunk` |
| Not flagged stale after editing source | Hash comparison | `Create :: source_signature` |

**Case study (real bug, July 2026):** switcher's NL link on `/en/` pointed at `/homepagina/` instead of `/`.

- Chain: plugin filters `page_on_front` to EN sibling (1004) → core compares page 852 against it → no longer recognizes 852 as front page → gives it a slug permalink.
- Classic §2-special-pages consequence: *ID comparisons against filtered options are language-dependent.*
- Fix: front-page detection by **group**, not ID. Understand why this broke = understand the plugin. (Also logged in `DECISIONS.md`.)

---

## What NOT to bother understanding deeply

- `src/admin/` React app — plain CRUD UI over the REST endpoints.
- `Rest.php` / `Controller.php` / `Model.php` — layered CRUD (routes → validation → DB), never crossing layers.
- `vendor/` — Composer + GitHub auto-update checker.
- `AdminColumns.php` — the language chips in the posts list.

The engine is `Router` + `TranslationGroup` (+ `LocaleManager`). Own §2 + §3 = own the plugin.
