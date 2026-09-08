# Engine

`MrPresident\Engine` is the simulation. It owns reality: every indicator, every hidden
variable, every queued consequence and every random draw lives here, and every operation is
a deterministic function of `(GameState, content, RNG state)`.

## The boundary

The engine is plain PHP 7.4 with `declare(strict_types=1)` in every file. Inside `engine/`
there is:

- **no WordPress** — no `wp_*`, no `$wpdb`, no options, no hooks, no translations;
- **no globals or superglobals**, no session state, no output;
- **no clock and no unseeded randomness** — no `time()`, `date()`, `rand()`, `mt_rand()`;
  in-game time is the `date` string carried by `GameState`, and every draw comes from
  `Random`;
- **no file I/O** except `ContentRepository` reading JSON from the directory it was
  constructed with (all of it funnelled through `JsonFile`).

Everything the player will ever see leaves the engine as plain arrays. The WordPress layer
(`includes/`) persists them, the view model strips what must stay hidden, and the AI layer
only ever receives arrays — never a `GameState`.

The practical payoff is that a save is just `GameState::toArray()`: reload it, keep playing,
and the run continues bit-for-bit, because the RNG position travels inside the state.

## Core primitives

These are the pieces the rest of the engine is built on.

| File | Responsibility |
|---|---|
| `Random.php` | Seeded xorshift32 PRNG, masked to 32 bits so results are portable. Serializes to one integer. `nextInt`, `nextFloat`, `range`, `chance`, `weightedPick`. Consumption order is part of the protocol. |
| `Schema.php` | `Schema::VERSION` (the `GAME_STATE_SCHEMA_VERSION`) plus `migrate()`, which walks a persisted document forward through registered steps. |
| `GameState.php` | The state document plus the live RNG. Dot-path `get`/`set`/`has`/`push`/`delete`, `turn()`, `date()`, `term()`, `rng()`, and a lossless `toArray()`/`fromArray()`. Does no clamping and no validation on purpose. |
| `Effects.php` | The only sanctioned way to change gameplay numbers. Applies an effects object (add, set, flag, counter), clamps against `BOUNDS` (wildcards supported), and returns labelled delta rows. `label()` and `isVisible()` decide how a path is presented. |
| `Conditions.php` | The declarative predicate DSL used by event eligibility, weight modifiers and consequences: comparisons, flags, turn windows, seen events, counters, and nested `any`/`all`/`not`. |
| `DelayedConsequenceQueue.php` | Queues a decision's promises and cashes them in on later turns: due and conditional modes, chance rolls, expiry, memory and headline pass-through, and at most one forced event per turn. |
| `DelayedConsequenceItem.php` | Translation between the authoring form (`delay_turns`, `window_turns`) and the stored runtime form (`due_turn`, `expires_turn`), plus defaulting of every optional field. |
| `MemorySystem.php` | The compact structured history: `record()` fills `id`, `turn` and `date`; `recent()` reads the tail; 500 entries maximum. |
| `ContentRepository.php` | The engine's door to `data/`: scenarios, events, advisors, countries and outlets, loaded lazily, validated once, cached. Lists authored with ids come back keyed by id. |
| `ContentValidator.php` | The required-key rules from the spec, applied at load time so a content typo fails in the test run instead of mid-decision. |
| `JsonFile.php` | The three filesystem calls the engine is allowed to make, in one place. |
| `EngineException.php` | The only exception the engine throws, carrying a machine `code()`: `unknown_scenario`, `no_active_event`, `event_already_resolved`, `unknown_choice`, `decision_required`, `unknown_event`, `invalid_content`. |

Built on top of these: `GameEngine` (facade), `TurnEngine`, `EventEngine`, `DecisionEngine`,
the drift systems (`EconomySystem`, `DomesticSystem`, `DiplomacySystem`, `CongressSystem`,
`SecuritySystem`, `ElectionSystem`), `MediaSystem` and `AdvisorSystem`.

## Conventions

- Classes are `PascalCase.php`; `MrPresident\Engine\Foo` maps to `engine/Foo.php`.
- State is addressed by dot path (`public.approval`, `countries.china.trust`,
  `flags.export_controls`, `counters.sanctions_used`).
- Content is data, not code: adding an event means adding `data/events/<id>.json`, never
  editing a `switch`.
- Numbers are stored as floats and rounded only for display.
- Anything the player must not see lives under `hidden.*`, `flags.*`, `counters.*` or
  `delayed_queue`, and `Effects::isVisible()` is the single test for that.

## Checking the core

```
php tests/engine/smoke-core.php
```

A dependency-free self-check of `Random`, `GameState`, `Effects`, `Conditions`,
`MemorySystem` and the delayed queue against a hand-built state. It needs no content files
and no WordPress. The full suite is `php tests/run.php`.
