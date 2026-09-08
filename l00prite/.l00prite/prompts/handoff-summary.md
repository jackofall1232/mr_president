# Handoff Summary Prompt

> **Path convention:** every `.l00prite/` path in this prompt is relative to the protocol
> root — the directory that contains the `.l00prite/` folder. In a project scaffolded by
> build-loop that is the `l00prite/` directory at the repo root (memory at
> `l00prite/.l00prite/`); in the l00prite source repo it is the repo root itself.

Prepare a cross-agent handoff for a l00prite-managed project.

Read `.l00prite/blueprint.md`, `.l00prite/ledger.md`, `.l00prite/memory.md`, `.l00prite/constraints.md`, `.l00prite/failures.md`, `.l00prite/todos.md`, `.l00prite/state.json`, and `.l00prite/heartbeat.json`.

Write or update `HANDOFF.md` with:

- Current mission and architecture summary
- Current status and active goal
- Recently completed work
- Known constraints and decisions
- Failed approaches / do-not-retry notes
- Verification status
- Blockers or human review gates
- Execution mode status (`heartbeat.json` `execution.enabled`, `execution.current_iteration` / `execution.max_iterations`, and the last run boundary, if the project has an `execution` block)
- Next smallest useful step
- Which `.l00prite/` files were updated

Do not implement project features while producing a handoff.
