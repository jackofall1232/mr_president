# REST API reference

Namespace `mr-president/v1`, served at `/wp-json/mr-president/v1/` (or
`/?rest_route=/mr-president/v1/...` without pretty permalinks).

## Authentication

Every route requires a logged-in WordPress user. The front end sends the `wp_rest` nonce
in the `X-WP-Nonce` header; both values are provided to the page in `MRP_CONFIG`. Without a
valid nonce WordPress treats the request as anonymous and the permission callback returns
401. A game that exists but belongs to another user is a 404.

## Routes

| Method | Route | Body | Success |
|---|---|---|---|
| GET | `/games` | — | `{ games: Summary[] }` |
| POST | `/game/new` | `{ president_name: string, scenario_id?: string }` | `{ game: Game }` |
| GET | `/game/{uuid}` | — | `{ game: Game }` |
| GET | `/game/{uuid}/briefing` | — | `{ briefing: Briefing }` |
| POST | `/game/{uuid}/decision` | `{ choice_id: string }` | `{ outcome: Outcome, game: Game }` |
| POST | `/game/{uuid}/advance` | — | `{ turn_report: TurnReport, game: Game }` |
| POST | `/game/{uuid}/save` | — | `{ saved_at: string }` |
| DELETE | `/game/{uuid}` | — | `{ deleted: true }` |

Argument rules:

- `president_name`: 2–60 characters; letters (any script), spaces, `'`, `.`, `-`.
- `scenario_id`, `choice_id`: `^[a-z0-9-]{1,64}$`. Default scenario is `new-administration`.
- `{uuid}`: `[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}`.

No route accepts state values. Unknown body fields are ignored.

## Errors

`WP_Error` JSON: `{ code, message, data: { status } }`.

| code | status | when |
|---|---|---|
| `mrp_not_logged_in` | 401 | no authenticated user |
| `mrp_invalid_president_name`, `mrp_invalid_id`, `mrp_invalid_uuid` | 400 | argument validation |
| `mrp_unknown_scenario`, `mrp_unknown_choice` | 400 | engine rejected the id |
| `mrp_not_found` | 404 | no such game for this user |
| `mrp_decision_required` | 409 | advance called while the active event is unresolved |
| `mrp_event_already_resolved`, `mrp_no_active_event` | 409 | decision called at the wrong time |
| `mrp_save_failed` | 500 | database write failed |

## Shapes

### Summary (from `/games`)

```jsonc
{ "game_uuid": "…", "president_name": "…", "scenario_id": "new-administration",
  "game_date": "2001-02-20", "turn_number": 2, "updated_at": "2026-09-08 14:57:00" }
```

### Game

```jsonc
{
  "id": "uuid", "president_name": "Alexandra Reyes",
  "scenario": { "id": "new-administration", "title": "A New Administration" },
  "date": "2001-02-20", "month_label": "February 2001", "turn": 2, "term": 1,
  "indicators": {
    "approval": 54, "gdp_growth": 1.8, "inflation": 2.8, "unemployment": 5.1, "deficit": 119,
    "national_debt": 5714, "global_influence": 60, "allied_confidence": 58, "domestic_stability": 62,
    "congress_support": 46, "crisis_level": 15,
    "economy_status": "Stable", "security_status": "Guarded", "allied_confidence_label": "Stable"
  },
  "countries": { "china": { "name": "People's Republic of China", "kind": "rival_power", "relationship": 10, "trust": 35, "trade_dependency": 55, "military_tension": 30 }, "…": {} },
  "active_event": {
    "id": "allied-security-request", "title": "…", "flash_label": "ALLIANCE CONSULTATION", "category": "diplomacy",
    "severity": 3, "summary": "…", "briefing_text": "…", "location": { "country": "european_allies" }, "tags": [],
    "cabinet": [ { "advisor_id": "state", "name": "…", "office": "Secretary of State", "position": "…" } ],
    "choices": [ { "id": "full-support", "label": "…", "description": "…", "advisor_positions": { "defense": "…" } } ],
    "resolved_choice_id": null
  },
  "last_outcome": null,            // see Outcome; cleared when a month advances
  "turn_report": { },              // see TurnReport
  "media": [ { "turn": 1, "date": "2001-01-20", "outlet_id": "national-wire", "outlet": "National Wire", "title": "…", "source": "…" } ],
  "memories": [ { "id": "m-1-1", "turn": 1, "date": "2001-01", "type": "economy", "action": "…", "target": "domestic", "result": "…", "tags": [] } ],
  "meta": { "schema_version": 1, "version": "0.1.0", "updated_at": "…" },
  "debug": { }                     // only for administrators with developer mode on
}
```

Choice `effects`, `hidden_effects`, `flags`, `counters`, `delayed`, `memory`, `headlines`
and `outcome_text` are never included: the player learns consequences after choosing.

### Outcome

```jsonc
{
  "event_id": "economic-slowdown", "choice_id": "targeted-tax-relief", "choice_label": "…",
  "outcome_text": "…",
  "visible_deltas": [ { "path": "public.approval", "label": "Approval", "delta": 2, "display": "+2" } ],
  "hidden_change_count": 3,
  "headlines": [ { "outlet_id": "national-wire", "outlet": "National Wire", "title": "…" } ],
  "memory": { "id": "m-1-1", "…": "…" },
  "delayed_count": 2
}
```

### TurnReport

```jsonc
{
  "turn": 2, "date": "2001-02-20", "month_label": "February 2001",
  "indicator_deltas": [ { "path": "public.approval", "label": "Approval", "delta": -0.4, "display": "-0.4" } ],
  "system_notes": [ "Price pressures eased as demand cooled." ],
  "fired_consequences": [ { "id": "dq-1-1", "label": "…", "headline": { }, "visible_deltas": [] } ],
  "headlines": [ ],
  "active_event_id": "allied-security-request"
}
```

### Briefing

```jsonc
{
  "date": "2001-02-20", "month_label": "February 2001", "turn": 2, "term": 1,
  "summary": "Presidential Daily Brief for February 2001. Approval stands at 54 percent…",   // prose from the AI provider
  "summary_facts": { "month_label": "…", "headline_count": 2, "active_event_title": "…", "top_deltas": [] },
  "indicators": { },               // same as Game.indicators
  "turn_report": { },
  "event": { },                    // same as Game.active_event
  "cabinet": [ ],                  // six advisor positions for the active event
  "media": [ ],                    // last five headlines
  "memories_recent": [ ]           // last five memories
}
```

### Debug block (developer mode only)

`seed`, `rng`, `hidden`, `flags`, `counters`, `delayed_queue`, `cooldowns`, `seen_events`,
`eligibility` (`[ { event_id, eligible, weight, reasons[] } ]`, including the `__quiet__`
pseudo-entry), and `raw_state`.
