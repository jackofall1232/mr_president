# Prioritized TODOs

## Next
- [ ] Review campaign expansion on feature/presidential-campaign; obtain approval before merging/publishing to the runtime-only plugin branch. Verify real WordPress save/create/ending flow after publication.
- [ ] Frontend redesign and presidency systems from the September 11 synopsis; sourced historical/current events remain outstanding.
- [ ] Verify connector settings and a live daily brief on WordPress 7.0 with configured OpenAI access; extend AI beyond daily briefs.
- [ ] Optional: run `phpcs` with WPCS against `config/phpcs.xml.dist` and clean any style findings (no functional impact).
- [ ] Balance pass on drift constants after a few full-year playthroughs (approval trends slightly negative under all-first-choice play).

## Later
- [ ] Guest/local-storage save store behind `SaveStoreInterface`.
- [ ] Real AI provider (OpenAI/Anthropic) behind `AIProviderInterface`, admin-configured key.
- [ ] Interactive world map (country selection, crisis markers, alliance coloring).
- [ ] Deeper economy model and emergency events breaking turn boundaries; balance implemented campaign elections.
- [ ] Historical scenario packs with sourced research.

## Done
- 2026-09-11 — Implemented campaign/profile/chamber UI, midterms, reelection, terminal guards, schema migration and deterministic legacy endings locally. 68 checks pass, including full 48/96-month runs and reload parity. Mobile preview checks pass; no live deployment claimed.
- 2026-09-11 — User approved merging connector settings into main; feature commit 13d403c merged locally. Skeleton already merged upstream through PR #1. Live WordPress verification remains pending.
- 2026-09-11 — Added WordPress Connectors/offline admin selection, Luna/Terra/Sol/Astra model selection, and cached daily-brief adapter with offline fallback. 63 local checks pass; live WordPress verification pending.
- 2026-09-08 — l00prite protocol scaffolded (Planning Mode).
- 2026-09-08 — 0.1.0 skeleton built: engine core + systems, 8 event cards, advisors/countries/outlets, WordPress layer (dbDelta table, REST, shortcode, full-screen page, admin), template AI seam, command-center UI, developer mode, 60-check test suite, README/docs/CI. Verified end-to-end in a browser on a real WordPress.
