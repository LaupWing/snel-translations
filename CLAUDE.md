# Snel Translations

Multilingual engine for WordPress. One post per language, linked by two post
meta keys. No custom tables, no lock-in — deactivate and the site still works.

## Read first, in this order

1. **ARCHITECTURE.md** — how the engine works, top to bottom. §3 is the list of
   invariants: any change that violates one is wrong, reject it.
2. **DATA.md** — every data structure with real examples and gotchas. Read
   before touching anything that handles data.
3. **DECISIONS.md** — why things are the way they are. When we make a call
   worth remembering, add an entry.
4. **THEME-INTEGRATION.md** — the plugin/theme contract (only when working
   near the theme).

## Orientation

- The engine is `inc/core/Router.php` + `inc/core/TranslationGroup.php`
  (+ `LocaleManager` for "what language is this request"). Own those, own the plugin.
- The write side (AI translate) is `inc/Create.php` + `inc/Ai.php`.
- `inc/Rest.php` → `Controller.php` → `Model.php` is standard layered CRUD —
  routes → validation → DB. Never cross layers.
- `grep -rn "SOT:" inc/` finds the canonical implementation of a concept.

## Rules

- Check the change against ARCHITECTURE.md §3 invariants before and after.
- Debugging: ARCHITECTURE.md §7 is the symptom → suspect map. Wrong-language
  bugs: check `_snel_lang` / `_snel_group` meta before suspecting code.
- Every front-end filter starts with an `is_admin()` bail (invariant 3).
- After building something non-trivial, explain it at architecture level —
  Loc must be able to explain every line that gets committed.

## Commands

- Smoke tests: `tests/smoke.sh`
- URL changes (rewrite rules, CPT slugs) need a permalink flush:
  Settings → Permalinks → Save.
