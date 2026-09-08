# `l00prite/` — this project's agent protocol folder

This folder is the home of the [l00prite protocol](https://github.com/jackofall1232/l00prite)
in this repository: durable, file-based project memory plus the operating procedures that
let any AI coding agent — Claude, Codex, GPT, Gemini, Copilot, Cursor, Windsurf, Aider, or
one that doesn't exist yet — pick up where another left off.

## What's in here

| Path | What it is |
|------|------------|
| `AGENTS.md` | The full agent operating rules (vendor-neutral). Every root-level pointer file leads here. |
| `CLAUDE.md` | The project blueprint — mission, architecture, requirements, definition of done — plus the fixed protocol section. |
| `.l00prite/` | The memory folder: `blueprint.md`, `ledger.md`, `memory.md`, `constraints.md`, `failures.md`, `todos.md`, `heartbeat.json`, `state.json`, `lock.json`, `LOCKING.md`, `events/`, `reviews/`, `sessions/` — see `.l00prite/README.md`. |
| `.l00prite/prompts/` | The canonical loop prompts: resume, heartbeat, event, review, handoff, execute — see `.l00prite/prompts/README.md` for the agent quickstart. |

The thin files at the repo root (`AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `QWEN.md`,
`CONVENTIONS.md`) exist only so each tool's native discovery finds the protocol; they all
point here. The dot-folder adapters (`.github/copilot-instructions.md`,
`.cursor/rules/l00prite.mdc`, `.windsurf/rules/l00prite.md`, `.grok/GROK.md`) carry the six
load-bearing rules inline because their tools require self-contained files at those exact
paths.

## For humans

- Read `CLAUDE.md` here for what this project is and when it's done.
- Read `.l00prite/ledger.md` for what has actually been built and verified so far.
- Don't hand-edit `.l00prite/` memory files while an agent run is active — check
  `.l00prite/lock.json` first (rules in `.l00prite/LOCKING.md`).
- `.l00prite/prompts/` files are protocol files: agents never modify them during a loop,
  and neither should casual edits.

## For agents

Start with `AGENTS.md` in this folder, then `.l00prite/prompts/README.md` (the quickstart).
Treat `.l00prite/` — not your session history — as the source of truth, and update it
before stopping.
