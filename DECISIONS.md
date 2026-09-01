# DECISIONS.md — why things are the way they are

One block per decision. Newest on top. Keep each under ~10 lines: the call,
the context, what we rejected, what we accepted as the cost.

---

## 2026-08 — Disabled-language posts never resolve publicly (resolution-points audit)

Context: snelstack.com dropped de/fr/es/it but their posts stayed published —
Yoast sitemaps listed hundreds of dead-language URLs and the singles rendered
200 at the root. Cause: `langOf()` maps a disabled language to the default, so
the Router's disabled-language guard was unreachable and `siblings()` let a de
post shadow the real nl one.
Decision: every *identity* comparison at a resolution point uses raw meta
(`rawLangOf()`, now public): `siblings()` keys, `translation()`'s shortcut, the
Router guard. New `DisabledLanguages` class excludes disabled-language posts
from Yoast sitemaps, core sitemaps, and public REST listings/search — but NOT
from REST context=edit (admin is never language-filtered, invariant 3).
Singles: 301 to the redirect-target sibling when published, else 404.

## 2026-08 — Slug uniqueness compares RAW language meta, not langOf() (v0.10.1)

Context: on snelstack.com, giving the NL blog page the slug `blog` produced
`blog-2` even though only disabled-language siblings (de/fr/es/it) held `blog`.
Cause: `langOf()` maps a disabled language back to the default, so those
siblings *read as Dutch* → fake same-language collision.
Decision: identity checks (slug uniqueness) use raw `_snel_lang` meta via
`rawLangOf()`; `langOf()` stays for anything that renders. No precedence rules
needed — the language prefix (`/blog` vs `/en/blog`) already disambiguates.

## 2026-08 — Docs system: CLAUDE.md + DATA.md + DECISIONS.md, same names in every project

Context: code was outgrowing Loc's head; AI writes a growing share of it.
Decision: three fixed files — CLAUDE.md (index + rules), DATA.md (real data
shapes + gotchas), DECISIONS.md (this file) — same names across all Snelstack
projects. `SOT:` grep-markers on canonical implementations.
Rejected: numbered FLOW chains (rot when steps are inserted), diagram wikis
(too far from code). Cost: the files must be updated when shapes change.

## 2026-07 — Front-page detection by GROUP, not ID

Context: plugin filters `page_on_front` to the current language's sibling, so
WP core no longer recognises the other siblings as the front page (switcher
linked `/homepagina/` instead of `/`).
Decision: anything answering "is this the front page?" compares translation
groups, never raw IDs. Full story: ARCHITECTURE.md §7 case study.

## (backfill as they come up)

When an old choice gets questioned during work, write the answer down here
instead of re-deriving it next time.
