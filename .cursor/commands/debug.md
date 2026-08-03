---
description: Default debugging command (ReactWoo)
model: claude-4.6-sonnet-medium-thinking
---
MODEL: Sonnet 4.6

You are debugging a ReactWoo WordPress plugin or API issue.

Purpose:
Default debugging command.

Problem:
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
- Do not guess.
- Trace the issue step-by-step.
- Identify root cause before changing code.
- Apply the smallest safe fix.

Output:
1. Root cause
2. Affected files/functions
3. Fix applied
4. Why it works
5. Test checklist
