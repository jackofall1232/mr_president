# Mr. President

A fictional presidential decision simulator that runs as a full-screen web application
inside WordPress. You take the oath on January 20, 2001 and govern one month at a time:
read the Presidential Daily Brief, weigh the cabinet's advice on the crisis of the month,
decide, watch the numbers move, and live with consequences that surface turns later.

Version **0.1.0** is the first playable skeleton: a clean, extensible plugin architecture plus
one vertical slice that proves the core loop end to end. It is not the whole game.

- Requires WordPress 6.0+ and PHP 7.4+.
- No build step, no third-party frameworks, no external services, no API keys.
- All content is fictional and politically neutral. No real politicians appear.

## Contents

1. [Architecture](#architecture)
2. [Installation](#installation)
3. [Shortcode and the dedicated page](#shortcode-and-the-dedicated-page)
4. [Save system](#save-system)
5. [REST API](#rest-api)
6. [Game engine and the main loop](#game-engine-and-the-main-loop)
7. [Event schema](#event-schema)
8. [AI abstraction](#ai-abstraction)
9. [Adding a new event](#adding-a-new-event)
10. [Developer mode](#developer-mode)
11. [Security model](#security-model)
12. [Versioning and state migrations](#versioning-and-state-migrations)
13. [Tests](#tests)
14. [Roadmap](#roadmap)

## Architecture

The plugin is five layers with hard boundaries. The rule that shapes everything: **the
simulation engine owns reality; AI (future) only presents and interprets, and the browser
only ever sends intent.**

```
mr-president-game/
├── mr-president-game.php        plugin header, constants, autoloader, activation hooks, boot
├── uninstall.php                drops the saves table and options when the plugin is deleted
├── includes/                    LAYER 1 · WordPress integration      (MrPresident\Plugin)
│   ├── class-plugin.php         singleton bootstrap; wires everything; template_include
│   ├── class-activator.php      dbDelta table creation + version-gated upgrades
│   ├── class-rest-api.php       /wp-json/mr-president/v1 routes, permission + validation
│   ├── class-game-controller.php  one method per REST action: load → engine → persist → view
│   ├── interface-save-store.php Save_Store_Interface (future guest / local stores)
│   ├── class-database-save-store.php  custom-table store, ownership enforced in every query
│   ├── class-save-manager.php   wraps the active store, uuids, JSON encode/decode
│   ├── class-view-model.php     GameState → client-safe payload (hidden data stripped)
│   ├── class-view-model-filters.php / -labels.php   payload helpers, derived status words
│   ├── class-shortcode.php      [mr_president_game]
│   ├── class-assets.php         conditional enqueue + MRP_CONFIG
│   ├── class-admin.php          Settings → Mr. President (developer mode, create page)
│   └── ai/                      LAYER 5 · AI seam
│       ├── interface-ai-provider.php    AI_Provider_Interface
│       └── class-template-ai-provider.php  deterministic templates, no network
├── engine/                      LAYER 2 · simulation engine            (MrPresident\Engine)
│   ├── GameEngine.php           facade: newGame / applyDecision / advanceTurn / buildBriefing
│   ├── GameState.php            the authoritative state document + live RNG
│   ├── TurnEngine.php           the month advance (fixed step order, see below)
│   ├── EventEngine.php          eligibility, weights, cooldowns, exclusivity, forced events
│   ├── DecisionEngine.php       validates a choice and applies everything it promises
│   ├── DelayedConsequenceQueue.php / DelayedConsequenceItem.php   consequences that land later
│   ├── Effects.php              path-based effect application with clamping and labels
│   ├── Conditions.php           the declarative condition DSL
│   ├── EconomySystem / DomesticSystem / DiplomacySystem / CongressSystem / SecuritySystem
│   │                            monthly drift driven by hidden state (DriftSystem base)
│   ├── ElectionSystem.php       term bookkeeping only in 0.1.0
│   ├── MemorySystem.php         compact structured memories (feeds History and future AI)
│   ├── MediaSystem.php          templated headlines from four fictional outlets
│   ├── AdvisorSystem.php        six fictional advisors and their positions
│   ├── ContentRepository.php / ContentValidator.php / JsonFile.php   loads and validates data/
│   ├── Random.php               seeded, serializable xorshift32
│   ├── Schema.php               GAME_STATE_SCHEMA_VERSION + migrate()
│   └── README.md                the engine boundary in detail
├── data/                        LAYER 3 · content (JSON, fictional)
│   ├── scenarios/new-administration.json
│   ├── events/*.json            one event card per file
│   ├── advisors/advisors.json
│   ├── countries/countries.json
│   └── media/outlets.json
├── assets/                      LAYER 4 · presentation (vanilla JS + CSS)
│   ├── css/game.css             design system: charcoal / navy / presidential blue / parchment / gold
│   ├── js/api.js, store.js, ui.js, app.js, views/*.js, views/panels/*.js
│   └── images/world-map.svg     stylized placeholder map with a marker layer
└── templates/
    ├── game-shell.php           the shortcode markup (root element, noscript, login gate)
    └── game-page.php            standalone full-screen document for the designated page
```

The engine is **pure PHP 7.4**: no WordPress functions, no globals, no clock, no unseeded
randomness, no file I/O except reading `data/`. It can be lifted into a standalone web app,
a WebView shell, or a native client unchanged. The test runner enforces this mechanically.

Request flow:

```
browser  ──intent only (choice_id / advance)──▶  Rest_Api  ──▶  Game_Controller
                                                                  │  load save (ownership enforced)
                                                                  │  GameEngine mutates GameState
                                                                  │  Save_Manager persists
                                                                  ▼
browser  ◀──client-safe payload (hidden stripped)──  View_Model  ◀──  Template_AI_Provider (prose only)
```

## Installation

1. Copy the `mr-president-game/` folder into `wp-content/plugins/` (or zip it and upload it
   through Plugins → Add New).
2. Activate **Mr. President**. Activation creates the `{prefix}mrp_games` table via `dbDelta`.
3. Go to **Settings → Mr. President** and click **Create game page**. This publishes a page
   containing the shortcode and serves it as a full-screen application.
4. Visit the page while logged in and click **New Administration**.

Deactivating the plugin never deletes saves. Deleting the plugin runs `uninstall.php`, which
drops the table and removes the plugin's three options.

## Shortcode and the dedicated page

`[mr_president_game]` renders the game wherever it is placed. The root element is
`position: fixed; inset: 0`, so the game covers the viewport inside any theme (and sits
below the admin bar for logged-in users). Assets are enqueued only when the shortcode is
present or the request is for the designated page; nothing loads site-wide.

The **designated page** (created from the settings screen, or any page whose id is stored in
the `mrp_game_page_id` option) is additionally served through `template_include` with
`templates/game-page.php`, a minimal standalone document with no theme chrome.
`wp_head()` and `wp_footer()` still run, so other plugins and the admin bar behave normally.

Logged-out visitors see a login gate with a link back to the page. Saved games belong to a
WordPress user, so 0.1.0 requires an account.

## Save system

Each game is one row in `{prefix}mrp_games`:

| column | purpose |
|---|---|
| `id` | primary key |
| `user_id` | owner (every query filters on it) |
| `game_uuid` | public identifier used in URLs (`wp_generate_uuid4()`) |
| `president_name`, `scenario_id` | list-view columns |
| `game_date`, `turn_number` | list-view columns (`current_date` is a reserved word) |
| `rng_seed`, `schema_version` | diagnostics and migrations |
| `state_json` | the full `GameState::toArray()` document |
| `created_at`, `updated_at` | UTC |

The server autosaves after every mutation (new game, decision, advance). The explicit
**Save** button calls `POST /game/{uuid}/save`, which touches the row; it exists for player
reassurance and for future stores that are not automatically durable.

`Save_Store_Interface` (`create`, `load`, `save`, `delete`, `list_for_user`) is the seam
for other backends. A guest/local-storage store would implement the same interface; the
controller would not change.

Because the RNG state, the delayed-consequence queue, cooldowns and the media log all live
inside the state document, reloading a save continues the exact run. The test suite proves
this by playing ten months straight and ten months with a JSON round trip after every step,
and asserting the serialized results are identical.

## REST API

Namespace: `/wp-json/mr-president/v1/`. Every route requires a logged-in user; the browser
sends the `wp_rest` nonce in `X-WP-Nonce` (it is in `MRP_CONFIG`). Ownership is enforced
inside every database query, so another user's uuid is a 404, never a leak.

| Method | Route | Body | Returns |
|---|---|---|---|
| GET | `/games` | — | `{ games: [ { game_uuid, president_name, scenario_id, game_date, turn_number, updated_at } ] }` |
| POST | `/game/new` | `{ president_name, scenario_id? }` | `{ game }` |
| GET | `/game/{uuid}` | — | `{ game }` |
| GET | `/game/{uuid}/briefing` | — | `{ briefing }` |
| POST | `/game/{uuid}/decision` | `{ choice_id }` | `{ outcome, game }` |
| POST | `/game/{uuid}/advance` | — | `{ turn_report, game }` |
| POST | `/game/{uuid}/save` | — | `{ saved_at }` |
| DELETE | `/game/{uuid}` | — | `{ deleted: true }` |

Validation: `president_name` is 2–60 characters of letters, spaces, apostrophes, periods
and hyphens; `choice_id` and `scenario_id` are kebab-case ids; `{uuid}` must be a v4-shaped
uuid. There is no route that accepts state values. A body like `{ "approval": 100 }` is
ignored (and logged in developer mode when `WP_DEBUG` is on).

Engine errors map to HTTP status codes: `decision_required` and `event_already_resolved`
→ 409, `unknown_choice` and `unknown_scenario` → 400, missing or foreign game → 404.

The `game` object contains: `id`, `president_name`, `scenario`, `date`, `month_label`,
`turn`, `term`, `indicators` (the public numbers plus derived `economy_status`,
`security_status`, `allied_confidence_label`), `countries`, `active_event` (card, cabinet
positions and choices — **never** the choices' effects), `last_outcome`, `turn_report`,
`media`, `memories`, `meta`, and, for administrators with developer mode on, `debug`.
See `docs/api.md` in the repository for the field-level reference.

## Game engine and the main loop

`GameEngine` is the facade: `newGame()`, `applyDecision()`, `advanceTurn()`,
`buildBriefing()`. Everything the player experiences is one of these four calls.

**A decision** (`DecisionEngine::apply`): validate that the choice belongs to the active
event and that the event is unresolved → apply `effects` and `hidden_effects` through
`Effects` (clamped, labelled) → set `flags` and `counters` → enqueue the choice's `delayed`
consequences and the event's `followup_events` → record a compact memory → generate 1–3
headlines via `MediaSystem` → mark the event resolved → store `last_outcome`.

**A month** (`TurnEngine::advance`), in a fixed order that is part of the save format:

1. Refuse if the active event has no decision (`decision_required`).
2. Snapshot the public indicators (for "movement since last month").
3. `turn += 1`, `date += 1 month`.
4. `ElectionSystem` — term bookkeeping (48 turns per term), `flags.election_year`.
5. Drift systems in order: Economy, Domestic, Diplomacy, Congress, Security. Each applies
   small explainable changes driven by hidden state (recession pressure drags growth,
   approval decays toward a base modulated by the economy and crisis level, relations decay
   toward each country's baseline, congressional support drifts toward approval, escalation
   pressure cools). Tuning numbers are class constants.
6. `DelayedConsequenceQueue::tick` — due items fire (or drop); conditional items are
   re-checked until they expire; chance rolls use the state RNG; at most one item may force
   an event this turn.
7. `EventEngine::selectNext` — a forced event wins; otherwise a weighted pick among eligible
   events plus a "quiet month" pseudo-weight from the scenario.
8. Build the `turn_report` (indicator deltas, system notes, fired consequences, headlines).

Everything is deterministic given the seed. RNG consumption order is therefore a protocol:
do not reorder these steps without bumping the schema version.

## Event schema

Events are JSON files in `data/events/`. Nothing is hard-coded per event; the engine reads
the schema and the content writers fill it. A trimmed real example:

```jsonc
{
  "id": "trade-retaliation",
  "title": "Retaliation Targets American Exporters",
  "flash_label": "TRADE COUNTERMEASURES",           // kicker on the card
  "category": "economy",                             // economy|domestic|security|diplomacy|military|congress|disaster|technology|energy|public_health|cyber|intelligence
  "severity": 3,                                     // 1..5, drives card accent
  "summary": "One sentence for lists.",
  "briefing_text": "Two to four sentences for the card body.",
  "location": { "country": "rival_state_a" },        // map-marker hook; or { "region": "domestic" } or null
  "start_conditions": [ { "any": [ { "flag": "sanctions_rival_a" }, { "flag": "export_controls" } ] } ],
  "weight": 7,
  "weight_modifiers": [ { "conditions": [ { "path": "hidden.trade_retaliation_risk", "op": ">=", "value": 30 } ], "multiply": 2.0 } ],
  "cooldown": 12,                                    // turns before it may be presented again
  "max_occurrences": 2,                              // null = unlimited
  "exclusive_with": [],                              // never presented right after these
  "expiry": null,                                    // reserved for emergency events
  "tags": ["trade", "retaliation", "kavara", "economy"],
  "cabinet_assessment": { "state": "…", "defense": "…", "treasury": "…", "nsa": "…", "chief_of_staff": "…", "intelligence": "…" },
  "choices": [
    {
      "id": "escalate-tariffs",
      "label": "Match With Tariffs",
      "description": "Impose reciprocal tariffs of equivalent scope.",
      "advisor_positions": { "treasury": "…", "chief_of_staff": "…" },
      "effects": { "public.approval": 2, "public.inflation": 0.4, "countries.rival_state_a.relationship": -6 },
      "hidden_effects": { "hidden.credibility": 4, "hidden.trade_retaliation_risk": 8 },
      "flags": { "reciprocal_tariffs": true },
      "counters": { "tariff_actions": 1 },
      "delayed": [
        {
          "label": "Tariff pass-through reaches consumers",
          "delay_turns": 3, "mode": "due", "chance": 1.0, "conditions": [],
          "effects": { "public.inflation": 0.2 }, "hidden_effects": { "hidden.recession_pressure": 4 },
          "trigger_event": null,
          "memory": { "type": "economy", "action": "tariff costs passed through to consumers", "target": "domestic", "result": "prices rose", "tags": ["tariffs"] },
          "headline": { "outlet_id": "american-ledger", "title": "Import Prices Climb as Reciprocal Tariffs Take Effect" }
        }
      ],
      "memory": { "type": "economy", "action": "imposed reciprocal tariffs", "target": "rival_state_a", "result": "firmness demonstrated; prices rose", "tags": ["tariffs"] },
      "outcome_text": "The reciprocal list published within a week…",
      "headlines": [ { "outlet_id": "national-wire", "title": "United States Imposes Reciprocal Tariffs" } ]
    }
  ],
  "followup_events": [ { "event_id": "…", "delay_turns": 4, "chance": 0.5, "conditions": [] } ]
}
```

**Effects** are `{ "dot.path": op }`: a number adds (clamped by `Effects::BOUNDS`), a boolean
sets a flag, a string starting with `=` sets a value. Paths: `public.*`, `hidden.*`,
`countries.<id>.<field>`, `flags.*`, `counters.*`.

**Conditions** are ANDed clauses: `{ "path", "op", "value" }` (`== != < <= > >=`),
`{ "flag" }`, `{ "not_flag" }`, `{ "min_turn" }`, `{ "max_turn" }`, `{ "seen_event" }`,
`{ "not_seen_event" }`, `{ "counter_min": [name, n] }`, `{ "any": [...] }`, `{ "all": [...] }`,
`{ "not": clause }`. A typo in a clause key fails loudly at load time, never silently.

**Delayed consequences** use `delay_turns` (and optionally `window_turns` for a conditional
window). `mode: "due"` evaluates once at the due turn; `mode: "conditional"` re-checks every
turn until it expires (`on_fail: keep|drop`). `chance` is rolled with the state RNG after
conditions pass. `trigger_event` forces that event next turn even if it is not otherwise
eligible.

`ContentValidator` checks required keys on load and names the file and key when something
is missing. The test suite additionally checks that every path, outlet, advisor and event id
referenced anywhere actually exists, and that no choice is free of cost.

## AI abstraction

The game is fully playable with no AI provider and no API key. The seam is
`MrPresident\Plugin\AI\AI_Provider_Interface`:

```php
generate_cabinet_response( array $advisor, array $event, array $public_state, array $memories ): string
generate_news_story( array $headline, array $outcome, array $public_state ): string
generate_diplomatic_message( array $country, array $outcome, array $memories ): string
analyze_player_speech( string $speech, array $public_state ): array   // tone/themes — never effects
generate_crisis_flavor( array $event, array $public_state ): string
summarize_presidency( array $memories, array $public_state ): string
generate_briefing_summary( array $briefing ): string
id(): string
```

`Template_AI_Provider` implements every method with deterministic string templates.
A real provider is swapped in with the `mrp_ai_provider` filter; anything that does not
implement the interface is discarded in favour of the templates.

Two invariants keep AI from ever owning reality: the provider receives **only arrays**
produced by `View_Model` (never a `GameState`), and every method returns text or an
analysis — none returns effects. When a future provider interprets a speech, the engine
decides what, if anything, that means.

Cabinet positions today come from `AdvisorSystem`: the event's `cabinet_assessment`, then
the chosen choice's `advisor_positions`, then the advisor's `fallback_positions` by category.
The structured `memories` list is the record a future provider will read so advisors and
foreign governments can reference what the player has done.

## Adding a new event

1. Create `data/events/<kebab-id>.json` following the schema above. Use only paths that
   exist in the state (`public.*`, `hidden.*`, `countries.<id>.*`, `flags.*`, `counters.*`),
   advisor ids `state|defense|treasury|nsa|chief_of_staff|intelligence`, and outlet ids
   `national-wire|capital-observer|american-ledger|world-desk`.
2. Give every choice a real cost. The content test fails a choice whose numeric effects are
   all favourable.
3. Add the id to `event_pool` in `data/scenarios/new-administration.json` (or leave the pool
   `null` to include every event).
4. If the event should only follow another decision, gate it with `start_conditions` on a
   flag that decision sets, or have the earlier choice queue it with `trigger_event`.
5. Run `php tests/run.php` from the repository root. It validates the JSON, every reference,
   and that each choice applies from the start state.
6. Open the game with developer mode on: the **Eligibility** block in the developer drawer
   shows the event's weight and the reasons it is or is not eligible right now.

No PHP changes are needed for new events, advisors, countries, outlets or scenarios.

## Developer mode

Settings → Mr. President → **Show hidden state to administrators**. When the option is on
**and** the current user has `manage_options`, the REST payload gains a `debug` block and the
top bar shows a **Dev** button that opens a drawer with: seed and RNG state, hidden
variables, flags, counters, the delayed-consequence queue, cooldowns, seen events, the
per-event eligibility report (weight and reasons), and the raw state JSON. Ordinary players
never receive this data, regardless of the option.

## Security model

- **Server authority.** The browser sends `president_name`, `choice_id`, and `advance`.
  The engine resolves every effect. No route reads state values from a request.
- **Authentication.** Every route has a `permission_callback` requiring a logged-in user;
  cookie auth is validated by core through the `wp_rest` nonce the client sends.
- **Ownership.** Every save query includes `user_id = %d`; a uuid the user does not own is a
  404. There is no enumeration route.
- **Validation and sanitization.** Every REST argument declares `validate_callback` and
  `sanitize_callback`; ids are regex-checked; names are length- and character-checked.
- **Escaping.** Templates escape all output; the front end builds DOM with text nodes and
  never injects server strings as HTML.
- **Hidden data.** `View_Model` strips `hidden`, `rng`, `seed`, `delayed_queue`, `cooldowns`,
  `flags`, `counters` and every choice's `effects` unless developer mode applies. A test
  walks the payload and fails on any of those keys.
- **Admin actions.** Settings use the Settings API with a sanitize callback; the
  create-page button is an `admin-post` action behind a nonce and `manage_options`.
- **Database.** `dbDelta` on activation, `$wpdb->prepare` everywhere, table dropped only
  by `uninstall.php` under `WP_UNINSTALL_PLUGIN`.

## Versioning and state migrations

- Plugin version: `MRP_VERSION` (`0.1.0`).
- Database version: `MRP_DB_VERSION`; `Activator::maybe_upgrade()` re-runs `dbDelta` when
  the stored version differs.
- State schema: `MrPresident\Engine\Schema::VERSION` (`1`). Every save records it.
  `Schema::migrate()` walks a loaded document forward through registered steps before
  `GameState` is built; adding a step is how a future version reshapes old saves. A save
  from a newer schema than the running code is refused rather than guessed at.

## Tests

From the repository root:

```
php tests/run.php          # static guards + unit tests + smoke scripts
php tests/run.php --quick  # skip the two smoke scripts
```

No PHPUnit or Composer required. The runner lints every file, greps the engine for
WordPress/global/clock/random calls, greps the whole plugin for PHP-8-only syntax, checks
every JSON file, and runs the engine, content and view-model tests. CI runs it on PHP 7.4
and 8.3.

## Roadmap

Intentionally deferred from 0.1.0:

- A real AI provider (OpenAI, Anthropic, or a local model) behind `AI_Provider_Interface`,
  configured from the settings screen — cabinet dialogue, news stories, diplomatic
  messages, speech interpretation, presidency summaries.
- Guest / local-storage saves behind `Save_Store_Interface`.
- An interactive world map: country selection, crisis markers, alliance colouring, trade and
  conflict overlays. The SVG already carries region ids and a marker layer.
- Emergency events that interrupt a month rather than waiting for the next one (the
  `expiry` field and `active_event.expires_turn` are reserved for this).
- Elections, a deeper economic model, and congressional negotiation.
- Historical scenario packs (2000 onward) with sourced research, alongside the fictional
  and procedural crises.
- Android / WebView shell reusing the engine and REST contract.
