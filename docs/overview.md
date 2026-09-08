# Contributor overview

This repository is the home of the **Mr. President** WordPress plugin. The installable
plugin lives entirely in `mr-president-game/`; everything else is tooling, tests, docs and
the l00prite agent-protocol folder.

```
mr_president/
├── mr-president-game/     the plugin (zip this folder to install it)
├── tests/                 dependency-free test runner: php tests/run.php
├── docs/                  engineering-spec.md (the contract), api.md (REST reference), this file
├── config/                phpcs ruleset
├── l00prite/              agent memory + protocol (see l00prite/CLAUDE.md)
└── .github/workflows/     CI: php tests/run.php on PHP 7.4 and 8.3
```

Start with `mr-president-game/README.md` for the product and architecture, then
`docs/engineering-spec.md` for the exact contracts every module is written against. If code
and the spec disagree, fix one in the same change.

## Layers, one line each

| Layer | Where | Rule |
|---|---|---|
| WordPress integration | `includes/` | The only layer that may call WordPress. |
| Simulation engine | `engine/` | Pure PHP 7.4. No WordPress, globals, clock or unseeded randomness. Owns reality. |
| Content | `data/` | JSON only. Events, advisors, countries, outlets, scenarios. Fictional, neutral. |
| Presentation | `assets/`, `templates/` | Vanilla JS/CSS, builds DOM with text nodes, sends intent only. |
| AI seam | `includes/ai/` | Receives arrays, returns text. Never touches a `GameState`. |

## Local development

- PHP 7.4+ on the path. Run `php tests/run.php` before every commit.
- For a real WordPress, symlink `mr-president-game/` into `wp-content/plugins/`, activate,
  and use Settings → Mr. President → Create game page.
- Coding standards: WordPress style in `includes/` and `templates/`, PSR-12-ish in
  `engine/`. `config/phpcs.xml.dist` encodes this if you have `phpcs` with WPCS installed;
  it is optional.

## Working with the l00prite protocol

Agents (and humans) read `l00prite/.l00prite/` before working and update it before
stopping: `ledger.md` (what was built and verified), `state.json`, `todos.md`,
`failures.md`. The blueprint in `l00prite/CLAUDE.md` carries the requirements, definition of
done, and review gates (REST permission callbacks, save ownership, database schema,
`uninstall.php`, and any network-calling AI provider all need human review).
