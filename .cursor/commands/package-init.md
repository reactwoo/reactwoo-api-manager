---
description: Initialize Python release packager (build_zip.py)
model: gpt-5.3-codex
---
MODEL: Codex 5.3

You are setting up repeatable release packaging for a ReactWoo plugin repository.

Goal:
$ARGUMENTS

Global rules (always apply):
- Do not touch unrelated files.
- Keep changes minimal and controlled.
- Follow existing ReactWoo architecture and naming conventions.
- Preserve compatibility with WordPress plugin zip expectations.

Required setup actions:
1. Inspect repository structure and detect plugin slug/root plugin file.
2. Create a Python build script at `scripts/build_zip.py` (or `build_zip.py` if `scripts/` is not appropriate).
3. Script requirements:
   - Read target version from CLI arg (preferred) or plugin header fallback.
   - Build WordPress-ready zip with root folder prefix `<plugin-slug>/`.
   - Default output name: `<plugin-slug>-<version>.zip`.
   - Exclude git metadata and common dev-only artifacts.
   - Print the final zip path on success.
4. Add lightweight usage notes in `README.md` if suitable (only if README exists and release/build usage is already documented there).
5. Do not run broad refactors.

Validation:
- Run the script once (dry or real) to confirm it executes.
- Confirm generated archive path and top-level folder prefix are correct.

Output:
1. Files created/changed
2. Script path
3. Example command to build zip
4. Validation result
5. Test checklist
