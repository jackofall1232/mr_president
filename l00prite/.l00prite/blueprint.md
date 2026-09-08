# Project Blueprint

## Mission
Mr. President is a standalone WordPress plugin (`mr-president-game`) that runs a modern,
cinematic presidential decision simulator as a full-screen web app for logged-in players.
The player is a fictional President starting on Inauguration Day (2001-01-20 in the first
scenario) and plays one month per turn through data-driven event cards, cabinet advice,
and delayed consequences. Success for 0.1.0 is a clean, extensible architecture plus one
playable vertical slice (new game → decision → advance → save → reload → resume). The
deterministic/probabilistic simulation engine owns reality; AI only presents.

## Architecture
See `../CLAUDE.md` §2 for the full tree. Five layers with hard boundaries:
1. WordPress integration — `mr-president-game/includes/` (`MrPresident\Plugin`).
2. Simulation engine — `mr-president-game/engine/` (`MrPresident\Engine`): pure PHP 7.4,
   no WordPress functions, seeded serializable RNG, path-based effects, delayed queue.
3. Content — `mr-president-game/data/` JSON (scenario, events, advisors, countries, media).
4. Presentation — `mr-president-game/assets/` vanilla JS/CSS + `templates/game-shell.php`.
5. Future AI seam — `includes/ai/` interface + `TemplateAIProvider` (no network, no key).
Persistence: custom table `{prefix}mrp_games` via dbDelta; `SaveStoreInterface` so guest /
local-storage saves can be added later. REST: `mr-president/v1`, intent-only mutations.

## Requirements
- [ ] Plugin lifecycle (activate/deactivate/uninstall) clean; dbDelta saves table.
- [ ] Shortcode `[mr_president_game]` renders full-screen shell; optional dedicated page.
- [ ] REST routes `/game/new`, `/game/{id}`, `/game/{id}/decision`, `/game/{id}/advance`, `/game/{id}/save`, `/game/{id}/briefing`, `/games` with auth + nonce + ownership.
- [ ] Authoritative `GameState` (public indicators, structured countries, hidden vars), schema versioned.
- [ ] Seeded serializable RNG; reload never changes determined outcomes.
- [ ] Data-driven event schema; eligibility (conditions, cooldown, exclusivity, expiry); weighted selection.
- [ ] Delayed consequence queue.
- [ ] MemorySystem + History screen.
- [ ] Six fictional advisors, templated positions.
- [ ] `AIProviderInterface` + `TemplateAIProvider`; playable without an API key.
- [ ] Fictional media headlines (1–3 per decision).
- [ ] Scenario "A New Administration" with ≥6 event cards with real tradeoffs.
- [ ] Responsive command-center UI in the specified palette; six navigation views.
- [ ] Administrator-only developer mode.
- [ ] Politically neutral, fully fictional content.

## Definition of Done
- [ ] `php -l` clean on all PHP; `php tests/run.php` passes.
- [ ] 15-step vertical slice works on stock WordPress.
- [ ] No PHP 8-only syntax; no WordPress calls inside `engine/`.
- [ ] Browser sends intent only; server validates every action id.
- [ ] Plugin README complete.

## Non-Execution Boundary
This blueprint is guidance for later implementation loops. Scaffolding tools must not execute the project unless a human explicitly starts an implementation session.
