# l00prite protocol — conventions for Aider (and any --read-style agent)

This project uses the l00prite protocol. Full agent instructions, loop prompts, and
persistent memory live in `l00prite/`.

Start here: l00prite/AGENTS.md

Do not duplicate instructions into this file. If your tool only reads repo root, open the
linked file next — it is not optional context, it is the actual protocol.

> Aider does **not** auto-load this file. Start with
> `aider --read CONVENTIONS.md --read l00prite/AGENTS.md`, or add
> `read: [CONVENTIONS.md, l00prite/AGENTS.md]` to your own `.aider.conf.yml`. l00prite
> deliberately ships no `.aider.conf.yml` — a repo-root config silently overrides
> same-named keys from your home config, and common `.aider*` gitignore patterns would
> hide it anyway.
