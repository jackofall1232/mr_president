# Prioritized TODOs

## Next
- [ ] Maintainer review of the 0.1.0 branch (review-gated: REST permission callbacks, save ownership, `class-activator.php`, `uninstall.php`).
- [ ] Optional: run `phpcs` with WPCS against `config/phpcs.xml.dist` and clean any style findings (no functional impact).
- [ ] Balance pass on drift constants after a few full-year playthroughs (approval trends slightly negative under all-first-choice play).

## Later
- [ ] Guest/local-storage save store behind `SaveStoreInterface`.
- [ ] Real AI provider (OpenAI/Anthropic) behind `AIProviderInterface`, admin-configured key.
- [ ] Interactive world map (country selection, crisis markers, alliance coloring).
- [ ] Election system, deeper economy model, emergency events breaking turn boundaries.
- [ ] Historical scenario packs with sourced research.

## Done
- 2026-09-08 — l00prite protocol scaffolded (Planning Mode).
- 2026-09-08 — 0.1.0 skeleton built: engine core + systems, 8 event cards, advisors/countries/outlets, WordPress layer (dbDelta table, REST, shortcode, full-screen page, admin), template AI seam, command-center UI, developer mode, 60-check test suite, README/docs/CI. Verified end-to-end in a browser on a real WordPress.
