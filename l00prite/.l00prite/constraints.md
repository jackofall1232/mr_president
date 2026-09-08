# Constraints

Hard rules, user preferences, security boundaries, and architecture constraints.

## Hard Rules
- Scaffolding generates files only; it does not execute implementation.
- Existing files must not be silently overwritten.
- Every implementation loop must update `.l00prite/` memory before stopping.

## User Preferences
- Production-quality, readable, modular code; no giant files; no duplicated logic; constants instead of magic numbers.
- Complete files or complete functions when changing code; preserve existing behavior unless a redesign is requested.
- Explain root causes before fixes; state assumptions instead of guessing.
- WordPress coding standards; plugin may eventually be submitted to WordPress.org.
- No third-party frameworks or large dependencies; vanilla JS/CSS, no build step required to play.

## Security Boundaries
- Server authority: the browser sends intent (`decision_id`, `advance`) only; it can never submit state values.
- Every REST route has a permission callback (logged-in user + `X-WP-Nonce`), and every game route checks the save belongs to the current user.
- Hidden state is never returned to the client unless developer mode is on AND the user has `manage_options`.
- Sanitize all input, escape all output, validate every action id against loaded content.
- No AI provider that makes network calls, and no API keys, in 0.1.0; `TemplateAIProvider` only.
- Database schema changes go through `Activator` migrations keyed on the stored plugin/schema version.

## Architecture Constraints
- PHP 7.4+ compatible syntax only (no union types, `match`, named args, constructor promotion, enums, `readonly`, `str_contains`, nullsafe operator). Current WordPress releases.
- `mr-president-game/engine/` is pure PHP: no WordPress functions, no globals, no `$_POST`, deterministic given state + seed. It must be reusable in a standalone web app, WebView shell, or native client.
- AI must never mutate game state; the AI seam receives authoritative outcomes and returns text.
- Events, advisors, countries, outlets, and scenarios are JSON content under `data/`; no hard-coded event switch statements.
- Content is fictional and politically neutral: no real politicians, no ideology encoded as correct.
- Tests are plain PHP (`php tests/run.php`) with no dependencies so they run anywhere PHP runs.

## Autonomous-Edit Denylist

Machine-readable glob list of paths an Execution Mode run must **never** auto-edit. A file
about to be edited that matches any glob below is treated as the
`destructive_operation_required` run boundary: the loop stops and asks for explicit per-action
human permission. This block is **protocol-adjacent and loop-immutable** — a run may never
remove or loosen an entry to get past a stop (doing so is itself the `human_review_gate`
boundary). Edit it yourself, before you arm a run. `scripts/l00prite-doctor.js` warns if this
block is missing.

```gitignore
# Secrets & credentials
.env
.env.*
**/secrets/**
**/credentials/**
**/*_key*
**/*_secret*
# Auth, money, and data safety
auth/**
payments/**
billing/**
**/migrations/**
# Infrastructure & deploy
.terraform/**
k8s/production/**
# Mr. President review-gated files (see l00prite/CLAUDE.md §6)
mr-president-game/uninstall.php
mr-president-game/includes/class-activator.php
mr-president-game/includes/class-rest-api.php
mr-president-game/includes/class-save-manager.php
# Protocol files (never agent-edited during a loop) — both layouts:
# memory at l00prite/.l00prite/ (standard scaffold) or .l00prite/ at repo root
l00prite/.l00prite/prompts/**
l00prite/.l00prite/LOCKING.md
l00prite/AGENTS.md
.l00prite/prompts/**
.l00prite/LOCKING.md
```

### Auto-merge allowlist (default: none)

Nothing is auto-merged by default — push/merge/deploy always need per-action human permission.
If you ever allow auto-merge for trivial changes, list the exact safe paths here (e.g. docs or
comment-only edits). Behavior changes, dependency bumps, lockfile edits, and any denylisted
path are never eligible.
