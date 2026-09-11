# Project Memory

Durable project facts and decisions that future agents should preserve.

## Decisions
- 2026-09-11 user explicitly approved merging the campaign expansion to main and updating/pushing plugin. Plugin package commit 12738d0 includes runtime version 0.2.0 and updated readme; no protocol files, tests or development docs are included.
- 2026-09-11 campaign expansion: schema 2, separate 435-seat House and rotating Senate classes; November approval-based elections; strictly >50 wins reelection, <=50 loses. Transfer dates end play after 48 or 96 months. Profiles are descriptive, not bonuses. Legacy report is deterministic and public-only. Old post-election saves are grandfathered into reelection; RNG remains unchanged. Feature branch only pending publication.
- 2026-09-11 user chose WordPress 7.0 Connectors for site-managed OpenAI credentials. Separate mrp_ai_mode and mrp_ai_model options default to wordpress and gpt-5.6-luna; offline and Terra/Sol/Astra are selectable. This explicitly extends the original template-only 0.1.0 scope. No credentials were created, copied or changed. Daily brief is the first live AI consumer; other prose and all effects remain deterministic.
- `docs/engineering-spec.md` is the module contract; code and spec are changed together.
- The engine (`mr-president-game/engine/`) is pure PHP 7.4 with no WordPress, globals, clock or unseeded randomness; `tests/run.php` enforces this by grep, so it stays portable to a standalone app or native client.
- Turn-advance step order (guard → snapshot → date → election → Economy/Domestic/Diplomacy/Congress/Security → delayed queue → event selection → report) is part of the save format because RNG consumption order matters; reordering requires a `Schema::VERSION` bump.
- Save/reload determinism is asserted in serialized (JSON) form: a JSON round trip may turn `10.0` into `10` without changing any outcome.
- The engine's briefing `summary` is a structured array (`summary_facts` in the client payload); prose comes only from the AI seam (`Template_AI_Provider` today).
- Hidden state (`hidden`, `rng`, `seed`, `flags`, `counters`, `delayed_queue`, `cooldowns`, `seen_events`) and choice effects are stripped by `View_Model` unless the developer-mode option is on AND the user has `manage_options`.
- Ownership is enforced inside every save-store query (`user_id = %d`), never by a caller-side check.
- The fixed-position app root offsets itself under the WordPress admin bar (`body.admin-bar .mrp-app { top: 32px }`, 46px under 782px).
- Tests are dependency-free (`php tests/run.php`); CI runs them on PHP 7.4 and 8.3.

## Facts
- The installable `plugin` branch uses flattened runtime files and no protocol/development files. Its previously published black-screen fix disables theme body animation/filter in fullscreen mode; that fix is also present in the campaign source. Do not copy l00prite into install packages.
- Plugin slug/text domain: `mr-president-game`; namespaces `MrPresident\Engine` (PSR files) and `MrPresident\Plugin` (WordPress `class-*.php` files); options `mrp_developer_mode`, `mrp_game_page_id`, `mrp_db_version`; table `{prefix}mrp_games`.
- The fictional rival state is the Republic of Kavara (`rival_state_a`); outlets are National Wire, Capital Observer, American Ledger, World Desk; advisor ids are `state, defense, treasury, nsa, chief_of_staff, intelligence`.
- A local verification bed exists in the session scratchpad: WordPress 6.8.2 + SQLite integration (cloned from GitHub; wordpress.org is blocked by the sandbox proxy), `php -S 127.0.0.1:8089`, Playwright driver `e2e.js`. It is not committed.

## Avoid
- Do not store random temporary notes, speculative ideas, or stale debugging output here.
