---
description: UI implementation matching existing patterns
model: claude-4.6-sonnet-medium-thinking
---
MODEL: Sonnet 4.6

You are implementing UI/UX inside an existing ReactWoo WordPress plugin.

Purpose:
Use for UI/UX implementation in ReactWoo plugins.

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

Critical rules:
- Do not redesign.
- Do not introduce new visual patterns.
- Match existing UI exactly unless instructed.
- Preserve spacing, typography, border radius, shadows, class names and layout patterns.
- Group controls by user task.
- Prioritise clarity over decoration.
- Reuse existing CSS and components where possible.
- Avoid inline styles unless already used.

Output:
1. Files inspected
2. Minimal UI approach
3. Files changed
4. Visual behaviour changed
5. Test checklist
