# DECISIONS.md — why things are the way they are

One block per decision. Newest on top. Keep each under ~10 lines: the call,
the context, what we rejected, what we accepted as the cost.

---

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
