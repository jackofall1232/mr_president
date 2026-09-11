# Mr. President — 0.1.0 Engineering Spec

This document is the contract every module is built against. If code and this document
disagree, fix one of them in the same change. Product intent lives in `l00prite/CLAUDE.md`.

## 0. Non-negotiables

1. **The engine owns reality.** `mr-president-game/engine/` is pure PHP 7.4: no WordPress
   functions, no globals, no superglobals, no `date()`/`time()`/`rand()`/`mt_rand()`, no
   file I/O except `ContentRepository` reading JSON from an injected directory. Every
   engine operation is a deterministic function of (`GameState`, content, RNG state).
2. **AI never mutates state.** `includes/ai/` receives authoritative outcomes and returns
   strings. Nothing under `includes/ai/` may hold a reference to a mutable `GameState`.
3. **The browser sends intent only.** Mutations are `{"choice_id": "..."}` or an empty
   advance. Any state value sent by the client is ignored and logged in developer mode.
4. **Hidden state stays hidden** unless `developer mode` is on **and** the user has
   `manage_options`.
5. **PHP 7.4 syntax only.** No union types, `match`, named arguments, constructor property
   promotion, enums, `readonly`, `str_contains`/`str_starts_with`, nullsafe `?->`,
   attributes, `never`, `mixed`. Typed properties (7.4) are fine. Declare
   `declare(strict_types=1);` in every engine file.
6. **No external dependencies, no build step.** Vanilla JS (ES2017, no modules requiring a
   bundler — use classic scripts or a single IIFE-per-file pattern with a shared
   `window.MRP` namespace), plain CSS with custom properties.
7. **Fictional and neutral.** No real politicians, parties, outlets, or leaders. No
   choice is dominant; every choice trades something.

## 1. Layout, namespaces, autoloading

```
mr-president-game/
├── mr-president-game.php          plugin header, constants, autoloader, boot
├── uninstall.php
├── README.md
├── includes/                      namespace MrPresident\Plugin   (WordPress-style files)
│   ├── class-plugin.php           MrPresident\Plugin\Plugin
│   ├── class-activator.php        MrPresident\Plugin\Activator
│   ├── class-rest-api.php         MrPresident\Plugin\Rest_Api
│   ├── class-game-controller.php  MrPresident\Plugin\Game_Controller
│   ├── class-save-manager.php     MrPresident\Plugin\Save_Manager
│   ├── interface-save-store.php   MrPresident\Plugin\Save_Store_Interface
│   ├── class-database-save-store.php MrPresident\Plugin\Database_Save_Store
│   ├── class-shortcode.php        MrPresident\Plugin\Shortcode
│   ├── class-assets.php           MrPresident\Plugin\Assets
│   ├── class-admin.php            MrPresident\Plugin\Admin
│   ├── class-view-model.php       MrPresident\Plugin\View_Model  (state → client-safe array)
│   └── ai/
│       ├── interface-ai-provider.php    MrPresident\Plugin\AI\AI_Provider_Interface
│       └── class-template-ai-provider.php MrPresident\Plugin\AI\Template_AI_Provider
├── engine/                        namespace MrPresident\Engine   (PSR-4 style: Class.php)
├── data/                          JSON content
├── assets/{css,js,images}/
└── templates/game-shell.php
```

**Autoloader rules** (implemented in `mr-president-game.php`):

- `MrPresident\Engine\Foo` → `engine/Foo.php`. Sub-namespaces map to sub-folders.
- `MrPresident\Plugin\Foo_Bar` → `includes/class-foo-bar.php`.
- `MrPresident\Plugin\Foo_Bar_Interface` → `includes/interface-foo-bar.php`.
- `MrPresident\Plugin\AI\Foo_Bar` → `includes/ai/class-foo-bar.php` (same interface rule).
- Class name → file: lowercase, `_` → `-`.

**Constants** (defined in the main file):

```php
MRP_VERSION                 '0.1.0'
MRP_PLUGIN_FILE             __FILE__
MRP_PLUGIN_DIR              plugin_dir_path( __FILE__ )
MRP_PLUGIN_URL              plugin_dir_url( __FILE__ )
MRP_DATA_DIR                MRP_PLUGIN_DIR . 'data/'
MRP_REST_NAMESPACE          'mr-president/v1'
MRP_OPTION_DEV_MODE         'mrp_developer_mode'
MRP_OPTION_GAME_PAGE_ID     'mrp_game_page_id'
MRP_OPTION_DB_VERSION       'mrp_db_version'
MRP_DB_VERSION              '1'
MRP_MIN_PHP                 '7.4'
```

The engine exposes `MrPresident\Engine\Schema::VERSION` (int, starts at `1`) as the
`GAME_STATE_SCHEMA_VERSION`.

## 2. GameState

`MrPresident\Engine\GameState` is a plain class with typed public-ish accessors backed by
one array, so it serializes trivially and stays portable.

```php
final class GameState {
    public static function fromArray(array $data): GameState;   // runs Schema::migrate() first
    public function toArray(): array;                          // canonical shape below
    public function get(string $path, $default = null);        // dot-path read: 'public.approval', 'countries.china.trust'
    public function set(string $path, $value): void;           // dot-path write (no clamping — Effects clamps)
    public function has(string $path): bool;
    public function turn(): int;  public function date(): string;  // convenience readers
    public function rng(): Random;                             // the live RNG (state is persisted on toArray)
}
```

### 2.1 Canonical array shape (schema version 1)

```jsonc
{
  "schema_version": 1,
  "game_uuid": "uuid-v4",            // assigned by the plugin layer, opaque to the engine
  "president_name": "string",
  "scenario_id": "new-administration",
  "seed": 123456789,                 // int; never changes
  "rng": { "state": 123456789 },     // Random::toArray()
  "turn": 1,                         // 1 = the inauguration month
  "term": 1,
  "date": "2001-01-20",              // ISO date; advancing adds one calendar month, day is kept
  "public": {
    "approval": 52.0,                // 0..100 (%)
    "gdp_growth": 1.8,               // annualized %, -10..10
    "inflation": 2.9,                // %, -2..20
    "unemployment": 5.1,             // %, 2..25
    "deficit": 120.0,                // $bn per year, -500..2000 (negative = surplus)
    "national_debt": 5700.0,         // $bn, 0..
    "global_influence": 60.0,        // 0..100
    "allied_confidence": 58.0,       // 0..100
    "domestic_stability": 62.0,      // 0..100
    "congress_support": 49.0,        // 0..100
    "crisis_level": 15.0             // 0..100
  },
  "hidden": {
    "credibility": 55.0,             // 0..100
    "war_fatigue": 5.0,              // 0..100
    "institutional_trust": 55.0,     // 0..100
    "political_capital": 60.0,       // 0..100
    "rival_risk_tolerance": 45.0,    // 0..100
    "allied_reliability": 60.0,      // 0..100
    "intelligence_confidence": 55.0, // 0..100
    "recession_pressure": 30.0,      // 0..100
    "escalation_pressure": 15.0,     // 0..100
    "trade_retaliation_risk": 10.0,  // 0..100
    "media_goodwill": 50.0           // 0..100
  },
  "countries": {                     // keys come from data/countries/countries.json
    "china":           { "relationship": 10, "trust": 35, "trade_dependency": 55, "military_tension": 30 },
    "russia":          { "relationship": -5, "trust": 30, "trade_dependency": 15, "military_tension": 35 },
    "european_allies": { "relationship": 60, "trust": 65, "trade_dependency": 60, "military_tension": 0, "cooperation": 65 },
    "pacific_allies":  { "relationship": 55, "trust": 60, "trade_dependency": 50, "military_tension": 0, "cooperation": 60 },
    "gulf_states":     { "relationship": 30, "trust": 45, "trade_dependency": 45, "military_tension": 10 },
    "rival_state_a":   { "relationship": -40, "trust": 10, "trade_dependency": 5, "military_tension": 60 }
    // relationship -100..100, everything else 0..100
  },
  "flags": {},                       // string => bool|number|string, set by effects ("flags.export_controls": true)
  "counters": {},                    // string => number, incremented by effects ("counters.sanctions_used": 1)
  "active_event": null,              // see 2.2
  "event_log": [],                   // [{ "event_id", "turn", "date", "choice_id" }]
  "cooldowns": {},                   // event_id => first turn the event is eligible again
  "seen_events": [],                 // event ids ever presented (for max_occurrences / not_seen_event)
  "delayed_queue": [],               // see 5
  "memories": [],                    // see 6
  "media_log": [],                   // last N headlines [{ "turn","date","outlet_id","outlet","title","source" }]
  "last_outcome": null,              // see 4.3 (cleared on advance)
  "turn_report": null,               // see 4.4 (built on advance / new game)
  "indicator_snapshot": {}           // copy of "public" at the start of the current turn (for deltas)
}
```

Number formatting: store floats; the view layer rounds (approval 0 decimals, rates 1
decimal). Countries and public/hidden values are floats internally but may be ints in JSON.

### 2.2 `active_event`

```jsonc
{
  "event_id": "foreign-missile-test",
  "turn_presented": 3,
  "expires_turn": null,              // turn_presented + event.expiry when expiry is set, else null
  "resolved_choice_id": null,        // set by DecisionEngine; advance requires non-null (or null active_event)
  "forced_by": null                  // delayed-consequence id that forced this event, or null
}
```

## 3. Random (seeded, serializable)

`MrPresident\Engine\Random` — a 32-bit **xorshift32** stepped with integer math masked to
32 bits (`& 0xFFFFFFFF`) so results are identical on 64-bit PHP and portable to JS later.

```php
final class Random {
    public function __construct(int $seed);            // seed 0 is remapped to a fixed non-zero constant
    public static function fromArray(array $a): Random; // ['state' => int]
    public function toArray(): array;                   // ['state' => int]
    public function nextInt(): int;                     // 1..2^32-1
    public function nextFloat(): float;                 // [0,1)
    public function range(int $min, int $max): int;     // inclusive
    public function chance(float $p): bool;             // nextFloat() < p
    public function weightedPick(array $weights): ?string; // ['id' => weight>0, ...] → id or null when empty/all-zero
}
```

Every consumer takes the RNG from `$state->rng()`. **Consumption order is part of the
protocol**: TurnEngine steps run in the fixed order listed in §4.2 so reload → same result.

## 4. Engine API

### 4.1 Facade

```php
final class GameEngine {
    public function __construct(ContentRepository $content);
    public function newGame(string $scenarioId, string $presidentName, int $seed): GameState;
    public function applyDecision(GameState $state, string $choiceId): array;  // returns Outcome (4.3); mutates $state
    public function advanceTurn(GameState $state): array;                      // returns TurnReport (4.4); mutates $state
    public function buildBriefing(GameState $state): array;                    // pure read; see 4.5
    public function content(): ContentRepository;
}
```

Errors: engine throws `MrPresident\Engine\EngineException` (extends `RuntimeException`) with
a machine `code()` string: `unknown_scenario`, `no_active_event`, `event_already_resolved`,
`unknown_choice`, `decision_required`, `unknown_event`, `invalid_content`. The plugin maps
these to HTTP 400/404/409.

### 4.2 Turn advance order (TurnEngine::advance) — fixed, do not reorder

1. Guard: if `active_event` is non-null and `resolved_choice_id` is null → throw `decision_required`.
2. `indicator_snapshot = public` (copy), `last_outcome = null`.
3. `turn += 1`; `date` = date + 1 month (same day-of-month; use `DateTimeImmutable::modify('+1 month')` on a `Y-m-20` date, which is always safe).
4. `ElectionSystem::tick` — updates `term` (every 48 turns) and sets `flags.election_year` for turns 45–48 of a term. Nothing else in 0.1.0.
5. Drift systems, each `tick(GameState $s): array $notes` in this order:
   `EconomySystem`, `DomesticSystem`, `DiplomacySystem`, `CongressSystem`, `SecuritySystem`.
   Each applies small, explainable monthly changes driven by hidden state, with tuning
   constants as class constants (no magic numbers inline). Use `Effects::apply` so clamping
   is uniform. Each may consume RNG (`nextFloat`) at most a fixed number of times.
6. `DelayedConsequenceQueue::tick(GameState $s): array $fired` (see §5).
7. `EventEngine::selectNext(GameState $s): ?array` — a forced event from step 6 wins; else
   weighted pick among eligible events; sets `active_event`, records `cooldowns[id] = turn + cooldown`,
   appends to `seen_events`. May return null (quiet month) — allowed but the scenario
   should make it rare.
8. Build `turn_report` (§4.4) and store it on the state.

### 4.3 Outcome (returned by applyDecision and stored as `last_outcome`)

```jsonc
{
  "event_id": "foreign-missile-test",
  "choice_id": "sanctions",
  "choice_label": "Economic Sanctions",
  "outcome_text": "Allied governments welcomed the coordinated response.",
  "visible_deltas": [ { "path": "public.approval", "label": "Approval", "delta": 1.0, "display": "+1" }, ... ],
  "hidden_change_count": 3,          // count only; never the values
  "headlines": [ { "outlet_id": "national-wire", "outlet": "National Wire", "title": "..." } ],
  "memory": { ...the memory record that was appended, see 6 },
  "delayed_count": 2                 // how many consequences were queued
}
```

`visible_deltas` covers `public.*` and `countries.*` only. Labels come from
`Effects::label($path)` (a static map; unknown paths get a humanized fallback).

### 4.4 TurnReport (stored as `turn_report`)

```jsonc
{
  "turn": 4, "date": "2001-04-20", "month_label": "April 2001",
  "indicator_deltas": [ { "path","label","delta","display" } ],   // public.* vs indicator_snapshot, non-zero only
  "system_notes": [ "Consumer demand softened further." ],        // strings from drift systems, ≤ 5
  "fired_consequences": [ { "id", "label", "headline": {...}|null, "visible_deltas": [...] } ],
  "headlines": [ ... ],                                           // fired consequence headlines + 0–1 ambient
  "active_event_id": "energy-price-spike"|null
}
```

### 4.5 Briefing (GET /briefing) — pure read

```jsonc
{
  "date": "2001-04-20", "month_label": "April 2001", "turn": 4, "term": 1,
  "summary": "Presidential Daily Brief for April 2001 ...",   // TemplateAIProvider fills; engine gives structured facts
  "indicators": { ...public with derived labels, see View_Model },
  "turn_report": { ... },
  "event": { ...event card, see 7.2 } | null,
  "cabinet": [ { "advisor_id", "name", "office", "position": "text" } ],   // AdvisorSystem for the active event
  "media": [ last 5 headlines ],
  "memories_recent": [ last 5 memories ]
}
```

## 5. Effects, Conditions, Delayed consequences

### 5.1 Effects DSL

An effects object is `{ "<dot.path>": <op> }`:

- numeric value → add (`"public.approval": -2`), clamped by `Effects::BOUNDS`.
- boolean → set (`"flags.export_controls": true`).
- string beginning with `=` → set (`"flags.posture": "=forward"`, `"public.crisis_level": "=40"` → numeric set).
- `counters.*` numeric → add (unbounded, min 0).

```php
final class Effects {
    const BOUNDS = [ 'public.approval' => [0,100], 'public.gdp_growth' => [-10,10], ... 'countries.*.relationship' => [-100,100], 'countries.*.*' => [0,100], 'hidden.*' => [0,100] ];
    public static function apply(GameState $s, array $effects): array;   // returns [ ['path','label','delta','display'] ] for numeric changes only
    public static function label(string $path): string;                 // "public.approval" → "Approval", "countries.china.trust" → "China · Trust"
    public static function isVisible(string $path): bool;               // public.* or countries.*
}
```

### 5.2 Conditions DSL

`Conditions::evaluate(GameState $s, array $clauses): bool` — clauses are ANDed. Empty array = true.

```jsonc
{ "path": "hidden.recession_pressure", "op": ">=", "value": 40 }   // ops: ==, !=, <, <=, >, >=
{ "flag": "export_controls" }            // truthy flag
{ "not_flag": "export_controls" }
{ "min_turn": 3 }  { "max_turn": 12 }
{ "seen_event": "economic-slowdown" }  { "not_seen_event": "..." }
{ "counter_min": ["sanctions_used", 2] }
{ "any": [ clause, clause ] }            // OR group
{ "not": clause }
```

### 5.3 Delayed consequences

Stored in `delayed_queue`. Created by choices (`choices[].delayed[]`), by events'
`followup_events`, or by other consequences.

```jsonc
{
  "id": "dq-<turn>-<n>",                   // assigned by the queue on enqueue
  "label": "Trade retaliation after export controls",
  "source": "foreign-missile-test:sanctions",
  "created_turn": 3,
  "due_turn": 6,                           // created_turn + delay_turns
  "expires_turn": 10,                      // null = never; for conditional items
  "mode": "due" | "conditional",           // due: evaluate once at due_turn; conditional: re-check every turn from due_turn until expires_turn
  "chance": 0.6,                           // default 1.0; rolled with state RNG only when conditions pass
  "conditions": [ ...Conditions clauses ],
  "effects": {}, "hidden_effects": {},     // both applied via Effects; hidden ones excluded from visible deltas by path
  "trigger_event": "trade-retaliation" | null,   // forces this event next turn (EventEngine honors it even if not otherwise eligible, cooldown ignored)
  "memory": { ...partial memory, see 6 } | null,
  "headline": { "outlet_id": "world-desk", "title": "..." } | null,
  "on_fail": "drop" | "keep"               // conditional mode only: what to do when conditions fail before expiry (default keep)
}
```

Authoring form inside event JSON uses `delay_turns` (int) instead of `due_turn` and
optionally `window_turns` (int) → `expires_turn = due_turn + window_turns`.

`DelayedConsequenceQueue::tick` returns the fired items (with computed `visible_deltas`)
and mutates the queue. Items whose `chance` roll fails in `due` mode are dropped. At most
one `trigger_event` is honored per turn (the first fired); extra triggers are re-queued for
the next turn with `mode: due`.

## 6. Memory records

Appended by `MemorySystem::record(GameState $s, array $partial)` which fills `turn`, `date`
(`YYYY-MM`), and `id`. Max 500 entries (oldest dropped). Never prose paragraphs.

```jsonc
{ "id": "m-3-1", "turn": 3, "date": "2001-03", "type": "foreign_policy", "action": "imposed sanctions after missile test",
  "target": "rival_state_a", "result": "allies supportive; rival defiant", "tags": ["sanctions","credibility","diplomacy"] }
```

`type` ∈ `economy | domestic | foreign_policy | security | congress | crisis | disaster | technology`.

## 7. Content schemas (`data/`)

All JSON. `ContentRepository` validates required keys on load and throws `invalid_content`
naming the file and key. Ids are kebab-case `[a-z0-9-]+`.

### 7.1 `scenarios/<id>.json`

```jsonc
{
  "id": "new-administration", "title": "A New Administration",
  "description": "...", "start_date": "2001-01-20",
  "initial_state": { "public": {...}, "hidden": {...}, "countries": {...}, "flags": {} },   // full values, see 2.1
  "opening_event": "economic-slowdown",     // forced on turn 1 (null = weighted pick)
  "event_pool": ["economic-slowdown", ...],  // ids eligible in this scenario (null = all events)
  "advisor_set": "default",                  // key inside advisors.json
  "quiet_month_weight": 5                    // pseudo-weight for "no event" in weighted selection
}
```

### 7.2 `events/<id>.json`

```jsonc
{
  "id": "foreign-missile-test",
  "title": "Rival State Conducts Missile Test",
  "flash_label": "NATIONAL SECURITY FLASH",          // small caps kicker on the card
  "category": "security",                            // economy|domestic|security|diplomacy|military|congress|disaster|technology|energy|public_health|cyber|intelligence
  "severity": 3,                                      // 1..5, drives card accent + crisis feel
  "summary": "One sentence for lists.",
  "briefing_text": "2–4 sentences for the card body.",
  "location": { "country": "rival_state_a" } | { "region": "domestic" } | null,   // map marker hook
  "start_conditions": [ ...Conditions ],
  "weight": 10,                                       // base weight, >0
  "weight_modifiers": [ { "conditions": [...], "multiply": 2.0 } ],   // optional
  "cooldown": 12,                                     // turns before re-eligible after being presented
  "max_occurrences": 1,                               // null = unlimited
  "exclusive_with": ["other-event-id"],               // never presented while the other is active or was presented this turn
  "expiry": null,                                     // turns the card may sit unresolved (unused by 0.1.0 UI; reserved)
  "tags": ["missiles","deterrence"],
  "cabinet_assessment": { "state": "...", "defense": "...", "treasury": "...", "nsa": "...", "chief_of_staff": "...", "intelligence": "..." },   // any subset
  "choices": [
    {
      "id": "sanctions", "label": "Economic Sanctions",
      "description": "Coordinate targeted sanctions with allies.",
      "advisor_positions": { "treasury": "...", "state": "..." },     // optional per-choice stances
      "effects": { "public.approval": 1, "countries.rival_state_a.relationship": -6, "public.allied_confidence": 3 },
      "hidden_effects": { "hidden.credibility": 3, "hidden.trade_retaliation_risk": 10 },
      "flags": { "sanctions_rival_a": true },
      "counters": { "sanctions_used": 1 },
      "delayed": [ { "label","delay_turns","window_turns","mode","chance","conditions","effects","hidden_effects","trigger_event","memory","headline" } ],
      "memory": { "type": "foreign_policy", "action": "imposed sanctions after missile test", "target": "rival_state_a", "result": "allies supportive", "tags": [...] },
      "outcome_text": "Allied governments welcomed the coordinated response.",
      "headlines": [ { "outlet_id": "national-wire", "title": "..." }, { "outlet_id": "capital-observer", "title": "..." } ]
    }
  ],
  "followup_events": [ { "event_id": "trade-retaliation", "delay_turns": 4, "chance": 0.5, "conditions": [ { "flag": "sanctions_rival_a" } ] } ]   // sugar for delayed trigger_event items, queued when the event is resolved (any choice)
}
```

Eligibility (EventEngine): in scenario pool ∧ `start_conditions` ∧ `cooldowns[id] <= turn`
∧ (`max_occurrences` null or count in `seen_events` < max) ∧ not `exclusive_with` the
current/just-resolved event. Weight = `weight × ∏ matching weight_modifiers`. Selection:
`rng.weightedPick(weights + {"__quiet__": quiet_month_weight})`.

### 7.3 `advisors/advisors.json`

```jsonc
{ "sets": { "default": [
  { "id": "state", "name": "Fictional Name", "office": "Secretary of State", "specialty": "diplomacy",
    "risk_tolerance": 0.3, "policy_tendencies": ["multilateral","engagement"], "confidence_style": "measured",
    "portrait": "state.svg"|null,
    "fallback_positions": { "security": "...", "economy": "...", "default": "..." } }     // used when an event has no line for this advisor
] } }
```

Six advisors, ids fixed: `state`, `defense`, `treasury`, `nsa`, `chief_of_staff`, `intelligence`.

### 7.4 `countries/countries.json`

```jsonc
{ "countries": [ { "id": "china", "name": "People's Republic of China", "kind": "rival_power"|"ally_bloc"|"rival_state"|"partner",
   "map_id": "cn", "leader_title": "President", "leader_name": "fictional", "baseline": { "relationship": 10, "trust": 35, "trade_dependency": 55, "military_tension": 30 } } ] }
```

Real countries/blocs may be named (China, Russia, "European allies") but their leaders are fictional. `rival_state_a` is fully fictional ("the Republic of Kavara" or similar invented name — writers choose one and keep it consistent).

### 7.5 `media/outlets.json`

```jsonc
{ "outlets": [ { "id": "national-wire", "name": "National Wire", "tone": "neutral"|"skeptical"|"establishment"|"international",
   "templates": { "security": ["White House Announces Measures Following {event_short}", ...], "economy": [...], "default": [...] } } ] }
```

`MediaSystem::headlinesFor(state, event, choice)` returns 1–3 headlines: choice-authored
first, then outlet templates by category (placeholders `{event_short}`, `{president}`,
`{choice}`), deduplicated by outlet, chosen with the state RNG.

## 8. Plugin layer contracts

### 8.1 Database (`Activator`)

Table `{$wpdb->prefix}mrp_games` (via `dbDelta`, two spaces after `PRIMARY KEY`, KEY lines,
`$wpdb->get_charset_collate()`):

```
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
user_id BIGINT UNSIGNED NOT NULL,
game_uuid CHAR(36) NOT NULL,
president_name VARCHAR(100) NOT NULL,
scenario_id VARCHAR(64) NOT NULL,
game_date DATE NOT NULL,
turn_number INT UNSIGNED NOT NULL DEFAULT 1,
rng_seed BIGINT NOT NULL,
schema_version SMALLINT UNSIGNED NOT NULL,
state_json LONGTEXT NOT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY game_uuid (game_uuid),
KEY user_id (user_id)
```

(`current_date` is a reserved word — hence `game_date`.) `Activator::activate` creates the
table, stores `MRP_OPTION_DB_VERSION`, and `Activator::maybe_upgrade` (on `plugins_loaded`)
re-runs dbDelta when the stored version differs. `uninstall.php` drops the table and deletes
the three options.

### 8.2 Save store

```php
interface Save_Store_Interface {
    public function create( int $user_id, GameState $state ): string;            // returns game_uuid
    public function load( int $user_id, string $game_uuid ): ?GameState;         // null when missing OR not owned
    public function save( int $user_id, string $game_uuid, GameState $state ): bool;
    public function delete( int $user_id, string $game_uuid ): bool;
    public function list_for_user( int $user_id ): array;                        // [{game_uuid, president_name, scenario_id, game_date, turn_number, updated_at}]
}
```

`Save_Manager` wraps the active store (only `Database_Save_Store` in 0.1.0), generates
uuids with `wp_generate_uuid4()`, JSON-encodes with `wp_json_encode`, and is the only class
that touches `$wpdb` besides `Activator`/`uninstall.php`. All queries use `$wpdb->prepare`.

### 8.3 REST API (`Rest_Api`), namespace `mr-president/v1`

| Method | Route | Body | Response |
|---|---|---|---|
| GET | `/games` | — | `{ games: [...] }` |
| POST | `/game/new` | `{ president_name, scenario_id? }` | `{ game }` |
| GET | `/game/{uuid}` | — | `{ game }` |
| GET | `/game/{uuid}/briefing` | — | `{ briefing }` |
| POST | `/game/{uuid}/decision` | `{ choice_id }` | `{ outcome, game }` |
| POST | `/game/{uuid}/advance` | — | `{ turn_report, game }` |
| POST | `/game/{uuid}/save` | — | `{ saved_at }` |
| DELETE | `/game/{uuid}` | — | `{ deleted: true }` |

- `{uuid}` regex: `(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})`.
- `permission_callback`: `is_user_logged_in()` (nonce is checked by core for cookie auth via
  `X-WP-Nonce`; the client always sends it). Ownership is enforced by `load(user_id, uuid)`
  returning null → 404.
- `args` with `validate_callback`/`sanitize_callback`: `president_name` 2–60 chars,
  `sanitize_text_field`, letters/spaces/'.-; `choice_id` `[a-z0-9-]{1,64}`; `scenario_id` same regex.
- Every mutating route is `POST`/`DELETE` only. No route accepts state.
- Errors: `WP_Error( 'mrp_<code>', message, ['status'=>N] )`; engine codes map:
  `decision_required`→409, `event_already_resolved`→409, `unknown_choice`→400,
  `no_active_event`→409, `unknown_scenario`→400, not found→404.
- Every successful mutation persists via `Save_Manager` before responding (autosave);
  `/save` is an explicit touch that exists for UX and future stores.

### 8.4 View model (`View_Model::game( GameState, bool $debug )`) — the `game` object

```jsonc
{
  "id": "uuid", "president_name", "scenario": { "id","title" },
  "date": "2001-04-20", "month_label": "April 2001", "turn": 4, "term": 1,
  "indicators": {
    "approval": 52, "gdp_growth": 1.8, "inflation": 2.9, "unemployment": 5.1, "deficit": 120, "national_debt": 5700,
    "global_influence": 60, "allied_confidence": 58, "domestic_stability": 62, "congress_support": 49, "crisis_level": 15,
    "economy_status": "Stable",            // derived: Expanding/Stable/Softening/Contracting from gdp_growth+recession label thresholds in View_Model
    "security_status": "Guarded",          // derived from crisis_level: Low/Guarded/Elevated/High/Severe
    "allied_confidence_label": "Stable"    // Shaky/Stable/Strong
  },
  "countries": { "china": { "name","kind","relationship","trust","trade_dependency","military_tension","cooperation"? } ... },
  "active_event": { "id","title","flash_label","category","severity","summary","briefing_text","location","tags",
                    "cabinet": [ {advisor_id,name,office,position} ], "choices": [ {id,label,description,advisor_positions} ],
                    "resolved_choice_id" } | null,
  "last_outcome": {...} | null,
  "turn_report": {...} | null,
  "media": [ last 8 headlines ],
  "memories": [ ...all, newest last ],
  "meta": { "schema_version": 1, "version": "0.1.0", "updated_at": "..." },
  "debug": {                                  // ONLY when $debug (dev mode option AND manage_options)
    "seed", "rng", "hidden", "flags", "counters", "delayed_queue", "cooldowns", "seen_events",
    "eligibility": [ { "event_id", "eligible": bool, "weight": n, "reasons": [...] } ],
    "raw_state": {...}
  }
}
```

`View_Model` strips `hidden`, `rng`, `seed`, `delayed_queue`, `cooldowns`, `flags`, and
`counters` from non-debug output. Choice `effects`/`hidden_effects`/`delayed` are **never**
sent to the client (the player sees consequences after choosing, not before).

### 8.5 Front-end bootstrap

`Assets` enqueues `assets/css/game.css` and `assets/js/{api,store,ui,views/*,app}.js`
(app last) only when the shortcode is present or on the designated page, and calls
`wp_localize_script( 'mrp-app', 'MRP_CONFIG', [...] )`:

```jsonc
{ "restUrl": "https://site/wp-json/mr-president/v1/", "nonce": "…", "devMode": false,
  "isLoggedIn": true, "userName": "Display Name", "loginUrl": "…", "version": "0.1.0", "assetsUrl": "…/assets/" }
```

`Shortcode` renders `templates/game-shell.php` output (an `<div id="mrp-app" class="mrp-app" data-mrp>` root with a
noscript message and a logged-out message when appropriate). If the current page id equals
`MRP_OPTION_GAME_PAGE_ID`, `Plugin` hooks `template_include` to serve
`templates/game-page.php`, a minimal standalone HTML document (`wp_head`/`wp_footer` still
called) so the game is full-screen with no theme chrome. The shortcode alone must also work
inside any theme (the `.mrp-app` root is `position:fixed; inset:0` with an escape hatch
class `mrp-app--inline` reserved for the future).

### 8.6 Admin (`Admin`)

Settings → Mr. President: developer-mode checkbox (`register_setting`, sanitize to bool,
`manage_options`), a "Create game page" button (nonce + capability; creates a published
page titled "Mr. President" containing `[mr_president_game]`, stores its id), and a
read-only panel showing version, schema version, table row count.

### 8.7 AI seam

```php
interface AI_Provider_Interface {
    public function generate_cabinet_response( array $advisor, array $event, array $public_state, array $memories ): string;
    public function generate_news_story( array $headline, array $outcome, array $public_state ): string;
    public function generate_diplomatic_message( array $country, array $outcome, array $memories ): string;
    public function analyze_player_speech( string $speech, array $public_state ): array;   // ['tone'=>..., 'themes'=>[...]] — never effects
    public function generate_crisis_flavor( array $event, array $public_state ): string;
    public function summarize_presidency( array $memories, array $public_state ): string;
    public function id(): string;   // 'template'
}
```

`Template_AI_Provider` implements all methods with deterministic string templates and no
network. `Plugin::ai()` returns the provider chosen by the `mrp_ai_provider` filter
(default WordPress Connectors when available, otherwise template). Admin settings
`mrp_ai_mode` (`wordpress` or `offline`) and `mrp_ai_model` (Luna default, Terra,
Sol, Astra) choose the integration and model separately. The WordPress adapter uses
`wp_ai_client_prompt()->using_model_preference(['openai', model])`; credentials are
managed exclusively in WordPress Settings → Connectors. Only the daily brief currently
uses live generation; other interface methods retain templates. Public template facts
are the only prompt payload. Successes are cached per user/model/facts for 24 hours;
failures use a 60-second fallback cache. AI cannot supply authoritative effects or news.
The briefing `summary` string is produced here from the structured
briefing the engine returns. Nothing in this layer receives a `GameState` object — only
arrays produced by `View_Model`.

## 9. Front-end contract (`assets/js`)

Global namespace `window.MRP`. Files (classic scripts, each an IIFE):

- `api.js` → `MRP.api`: `listGames()`, `newGame(name)`, `getGame(id)`, `getBriefing(id)`,
  `decide(id, choiceId)`, `advance(id)`, `save(id)`, `deleteGame(id)`; `fetch` with
  `X-WP-Nonce`, JSON errors surfaced as `{code, message}`.
- `store.js` → `MRP.store`: `get()`, `set(patch)`, `subscribe(fn)`; state:
  `{ screen: 'title'|'new'|'game', game, briefing, view: 'situation'|'economy'|'congress'|'diplomacy'|'security'|'history', panel: 'brief'|'cabinet'|'intel'|'congress', busy, toast, error, devOpen, currentGameId }`.
  Persists `currentGameId` in `localStorage['mrp.currentGameId']` so reload resumes.
- `ui.js` → `MRP.ui`: `el(tag, attrs, children)`, `fmt.pct/num/delta/date`, `toast()`, focus helpers.
- `views/title.js`, `views/new-game.js`, `views/dashboard.js` (top bar, map, event feed, right panels),
  `views/event-card.js`, `views/outcome.js`, `views/panels/*.js` (economy, congress, diplomacy, security, history),
  `views/dev-panel.js`.
- `app.js`: boots, reads config, resumes `currentGameId` via `getGame`, renders on store change (full re-render of the changed region is fine; keep DOM building in `MRP.ui.el`, never `innerHTML` with server strings — text nodes only).

Accessibility: buttons are `<button>`, panels are `role="tabpanel"`, event choices are a
`role="group"` with labelled buttons, focus moves to the outcome after a decision, `prefers-reduced-motion` respected.

## 10. Verification

- `php -l` on every PHP file; `php tests/run.php` (dependency-free runner in `tests/`).
- Static guards in `tests/run.php`: grep `engine/` for `\b(get_option|wp_|add_action|add_filter|\$wpdb|\$_GET|\$_POST|mt_rand|rand\(|time\(|date\()` → fail; grep all PHP for PHP-8-only tokens listed in §0.5 → fail.
- Engine tests: RNG determinism + serialization; Effects clamping/labels; Conditions matrix;
  event eligibility/cooldown/exclusivity/max_occurrences; delayed queue due/conditional/chance/trigger;
  full game: new → decision → advance × 6 with seed S, then serialize at each step, reload,
  continue → identical `toArray()`; content load validates all six events + scenario + advisors.

## 11. Campaign expansion (0.2.0)

Save schema 2 adds `president_profile` and `campaign`. Optional creation profiles contain
age (35–100), a US state abbreviation, a supported alignment, and exactly three distinct
policy priorities. These describe the president without granting stat bonuses. The server
validates the profile and discards extra fields; the old name-only creation call still works.

`CampaignSystem` runs after calendar/term bookkeeping, before drift. It consumes no RNG.
For the January 2001 scenario, congressional elections occur on turns 23, 47, 71 and 95.
All 435 House seats and one rotating Senate class (33/33/34 seats) are contested. Approval
below 50 causes losses; approval at or above 50 produces nonnegative swings. Seat counts
are bounded, and chamber shares influence the congressional-support target. These are
transparent game rules, not a forecast of real elections.

The first presidential election is turn 47: raw approval strictly greater than 50 wins;
50 or below loses. A defeated administration continues until January 2005 (turn 49).
A reelected administration ends January 2009 (turn 97). Endings clear the event, skip
drift/event selection, and persist a public-only legacy summary. Further decisions and
advances return `campaign_finished` (HTTP 409). Congress results are recorded once per
election turn. Schema-1 saves retain their RNG and progress; saves already beyond the
first election are grandfathered into reelection rather than retroactively defeated.

The public view model includes campaign/profile blocks. `views/campaign.js` supplies the
milestone banner, separate chamber cards, election ledger and archive ending. The legacy
report is deterministic template text; optional AI is not required for either ending.
`tests/engine/CampaignTest.php` exercises both full campaign paths with reload after every
step, fractional reelection boundaries, migration, profiles, seat limits and terminal guards.
`tests/browser-preview.php` provides a mocked transport for checking the real UI scripts;
it is a development fixture, not an installation file or live WordPress integration test.
