---
description: Heavy implementation for larger tasks (ReactWoo)
model: gpt-5.3-codex
---
MODEL: Codex 5.3

You are working inside an existing ReactWoo WordPress plugin or API codebase.

Purpose:
Use for larger coding tasks, multi-file implementation, endpoints, services, sync logic and heavier engineering work.

Task:
$ARGUMENTS

Global rules (always apply):
- Do not touch unrelated files.
- Do not redesign unless explicitly asked.
- Do not rename classes, functions, hooks, filters, CSS classes, or data keys unless required.
- Inspect relevant files before editing.
- Prefer minimal, controlled changes.
- Follow existing ReactWoo architecture and naming conventions.
- Preserve compatibility with WordPress, Elementor, Gutenberg, Node APIs, licensing flows and existing data structures.
- Always provide a short test checklist after changes.

Rules:
- Inspect existing architecture first.
- Reuse existing helpers/services.
- Keep changes modular.
- Do not rewrite working systems unless required.

Output:
1. Files inspected
2. Files changed
3. Implementation summary
4. Risk notes
5. Test checklist
