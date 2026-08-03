#!/usr/bin/env python3
"""Release zip for ReactWoo API Manager. Paths: package.json → reactwooBuild."""

from __future__ import annotations

import json
import os
import re
import zipfile
from pathlib import Path

_DEFAULT_FOLDER = "reactwoo-api-manager"

INCLUDE_DIRS = ["admin", "assets", "includes", "templates", "languages"]

INCLUDE_FILES = [
    "woocommerce-api-subscription-bridge.php",
    "readme.txt",
]


def _is_ci_environment() -> bool:
    return os.environ.get("CI", "").lower() in ("1", "true", "yes")


def _read_plugin_version(base: Path, cfg: dict, folder: str) -> str | None:
    main_php = cfg.get("mainPhp") or f"{folder}.php"
    php_path = base / str(main_php)
    if not php_path.is_file():
        return None
    try:
        text = php_path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return None
    match = re.search(r"^\s*\*\s*Version:\s*([^\s\r\n]+)", text, re.MULTILINE)
    return match.group(1).strip() if match else None


def _zip_paths(base: Path) -> tuple[str, str]:
    pkg_path = base / "package.json"
    zip_name = f"{_DEFAULT_FOLDER}.zip"
    if not pkg_path.is_file():
        return _DEFAULT_FOLDER, zip_name
    try:
        data = json.loads(pkg_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return _DEFAULT_FOLDER, zip_name
    cfg = data.get("reactwooBuild")
    if not isinstance(cfg, dict):
        return _DEFAULT_FOLDER, zip_name
    folder = cfg.get("pluginFolder") or _DEFAULT_FOLDER
    zfile = cfg.get("zipFile") or f"{folder}.zip"
    version_in_zip = cfg.get("versionInZipFile", True)
    if version_in_zip and not _is_ci_environment():
        version = _read_plugin_version(base, cfg, folder)
        if version:
            stem = Path(zfile).stem
            suffix = Path(zfile).suffix or ".zip"
            zfile = f"{stem}-{version}{suffix}"
    return str(folder), str(zfile)


def main() -> None:
    base = Path(__file__).resolve().parent.parent
    root_folder, zip_name = _zip_paths(base)
    out = base / zip_name
    if out.exists():
        out.unlink()

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for dirname in INCLUDE_DIRS:
            dirpath = base / dirname
            if not dirpath.is_dir():
                continue
            for root, _dirs, files in os.walk(dirpath):
                for filename in files:
                    filepath = Path(root) / filename
                    rel = filepath.relative_to(base).as_posix()
                    zf.write(filepath, arcname=f"{root_folder}/{rel}")

        for filename in INCLUDE_FILES:
            filepath = base / filename
            if filepath.is_file():
                zf.write(filepath, arcname=f"{root_folder}/{filename}")

    with zipfile.ZipFile(out, "r") as zf:
        names = zf.namelist()
        if any("\\" in n for n in names) or any(
            n.startswith(f"{root_folder}/{root_folder}/") for n in names
        ):
            raise RuntimeError("Invalid zip structure")
        # Never ship committed secrets / local tooling
        forbidden = (".env", "node_modules/", ".git/", "tests/", "scripts/")
        for n in names:
            lower = n.lower()
            if any(part in lower for part in forbidden):
                raise RuntimeError(f"Forbidden path in zip: {n}")

    print(f"Created: {out}")


if __name__ == "__main__":
    main()
