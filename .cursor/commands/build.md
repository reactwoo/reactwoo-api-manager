---
description: Default implementation command (ReactWoo)
model: claude-4.6-sonnet-medium-thinking
---
MODEL: Sonnet 4.6

You are working inside an existing ReactWoo WordPress plugin or API codebase.

Purpose:
Default implementation command for controlled feature work.

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
- Inspect relevant files first.
- Explain planned changes briefly.
- Implement only what is requested.
- Avoid broad rewrites.

Output:
1. Files inspected
2. Files changed
3. Summary of implementation
4. Test checklist
