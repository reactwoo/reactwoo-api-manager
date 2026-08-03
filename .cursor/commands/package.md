---
description: Release package workflow (tag, build zip, push)
model: gpt-5.3-codex
---
MODEL: Codex 5.3

You are preparing a ReactWoo plugin release package.

Release scope:
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

Release rules:
- Bump version in required plugin version locations before tagging.
- Create an annotated git tag that matches the bumped version.
- If a Python zip build script exists, use it as the default packaging path.
- If no Python zip build script exists, clearly recommend creating one and use a safe fallback archive command.
- Do not include unrelated files in the release commit.
- Do not commit generated zip files unless the repo already tracks them by convention.

Required process:
1. Inspect `git status`, current branch, and version locations.
2. Identify the next release version and update version constants/headers.
3. Commit release changes with a concise release message.
4. Create annotated tag `vX.Y.Z`.
5. Detect and run Python build script if available (for example, `scripts/build_zip.py`, `build_zip.py`, or similar).
6. If no Python build script is found:
   - State that none exists.
   - Recommend creating one for repeatable packaging.
   - Build zip via fallback `git archive --format=zip --prefix=<plugin-slug>/ -o <plugin-slug>-<version>.zip vX.Y.Z`.
7. Push branch and tag to origin.

Output:
1. Version bump applied
2. Commit created
3. Tag created
4. Build method used (Python script or fallback archive)
5. Zip artifact path
6. Push result
7. Recommendation (only if Python build script was missing)
8. Test checklist
