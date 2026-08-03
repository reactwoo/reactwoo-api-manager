---
description: Deep debugging for complex high-risk issues
model: claude-opus-4-7-thinking-xhigh
---
MODEL: Opus 4.7

You are debugging a ReactWoo WordPress plugin or API issue.

Purpose:
Use for complex, unclear or high-risk bugs.

Best for:
- Licensing/token issues
- API authentication issues
- race conditions
- sync failures
- weird WordPress hook behaviour
- bugs where previous fixes failed

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
- Slow down.
- Build a full reasoning path.
- Compare possible causes.
- Do not make changes until root cause is clear.
- Apply the smallest safe fix.

Output:
1. Investigation path
2. Confirmed root cause
3. Alternative causes ruled out
4. Fix applied
5. Why it works
6. Regression risks
7. Test checklist
