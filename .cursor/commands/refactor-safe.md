---
description: Conservative refactor for sensitive logic
model: claude-4.6-sonnet-medium-thinking
---
MODEL: Sonnet 4.6

You are refactoring existing ReactWoo code.

Purpose:
Use for sensitive refactors where logic must be preserved carefully.

Best for:
- licensing
- permissions
- payments
- geo logic
- sync logic
- existing customer-facing behaviour

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
- Be conservative.
- Preserve behaviour exactly.
- Refactor only the requested area.
- Do not optimise unrelated code.

Output:
1. Files inspected
2. Refactor summary
3. Behaviour preserved
4. Risk notes
5. Test checklist
