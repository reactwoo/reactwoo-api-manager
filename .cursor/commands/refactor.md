---
description: Default refactoring command (ReactWoo)
model: gpt-5.3-codex
---
MODEL: Codex 5.3

You are refactoring existing ReactWoo code.

Purpose:
Use for general refactoring where code structure needs improvement.

Goal:
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
- Do not change behaviour.
- Do not change public APIs.
- Do not break backwards compatibility.
- Preserve existing hooks, filters and data structures.
- Keep same inputs and outputs.

Output:
1. Refactor summary
2. Files changed
3. Behaviour preserved
4. Risk notes
5. Test checklist
