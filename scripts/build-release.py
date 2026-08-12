#!/usr/bin/env python3
"""Build a deterministic, installable Cockpit ZIP and release metadata."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath


PACKAGE_NAME = "Cockpit"
FIXED_ZIP_TIME = (1980, 1, 1, 0, 0, 0)
ROOT_FILES = {
    "API.md",
    "CHANGELOG.md",
    "Cockpit.module.php",
    "DOCUMENTATION.md",
    "EXAMPLES.md",
    "LICENSE",
    "ProcessCockpit.module.php",
    "README.md",
    "SECURITY.md",
}
TREE_SUFFIXES = {
    "assets": {".css", ".gif", ".jpg", ".jpeg", ".js", ".png", ".svg", ".webp"},
    "docs": {".md"},
    "src": {".php"},
}


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def module_version(root: Path) -> str:
    source = (root / "Cockpit.module.php").read_text(encoding="utf-8")
    match = re.search(r"['\"]version['\"]\s*=>\s*(\d+)", source)
    if not match:
        raise RuntimeError("Unable to read the numeric module version.")
    encoded = match.group(1)
    if len(encoded) < 3:
        encoded = encoded.zfill(3)
    return f"{int(encoded[:-2])}.{int(encoded[-2])}.{int(encoded[-1])}"


def release_files(root: Path) -> list[Path]:
    files: list[Path] = []
    for name in sorted(ROOT_FILES):
        path = root / name
        if path.is_file():
            files.append(path)
    for directory, suffixes in sorted(TREE_SUFFIXES.items()):
        base = root / directory
        if not base.is_dir():
            continue
        for path in sorted(base.rglob("*")):
            if path.is_symlink():
                raise RuntimeError(f"Release input must not contain symlinks: {path}")
            if path.is_file() and path.suffix.lower() in suffixes:
                files.append(path)
    required = {"Cockpit.module.php", "ProcessCockpit.module.php", "LICENSE"}
    present = {path.relative_to(root).as_posix() for path in files}
    missing = required - present
    if missing:
        raise RuntimeError("Missing required release files: " + ", ".join(sorted(missing)))
    return sorted(files, key=lambda path: path.relative_to(root).as_posix())


def file_manifest(root: Path, files: list[Path]) -> list[dict[str, object]]:
    manifest: list[dict[str, object]] = []
    for path in files:
        data = path.read_bytes()
        relative = path.relative_to(root).as_posix()
        manifest.append(
            {
                "path": f"{PACKAGE_NAME}/{relative}",
                "sha256": sha256_bytes(data),
                "size": len(data),
            }
        )
    return manifest


def write_zip(destination: Path, root: Path, files: list[Path]) -> None:
    # ZIP_STORED avoids platform/zlib-dependent compressed output. The package
    # is small, and byte-for-byte reproducibility is more valuable here.
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_STORED) as archive:
        for path in files:
            relative = path.relative_to(root).as_posix()
            archive_name = f"{PACKAGE_NAME}/{relative}"
            info = zipfile.ZipInfo(archive_name, FIXED_ZIP_TIME)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_STORED
            archive.writestr(info, path.read_bytes())


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def build(root: Path, output_dir: Path) -> dict[str, str]:
    root = root.resolve()
    output_dir = output_dir.resolve()
    version = module_version(root)
    files = release_files(root)
    manifest = file_manifest(root, files)

    output_dir.mkdir(parents=True, exist_ok=True)
    # Remove only artifacts owned by this builder. Never clear an arbitrary
    # caller-provided directory such as a shared CI workspace.
    owned_patterns = [
        f"{PACKAGE_NAME}-*.zip",
        f"{PACKAGE_NAME}-*.manifest.json",
        f"{PACKAGE_NAME}-*.sbom.cdx.json",
        f"{PACKAGE_NAME}-*.zip.sha256",
        "SHA256SUMS",
    ]
    for pattern in owned_patterns:
        for child in output_dir.glob(pattern):
            if child.is_file() or child.is_symlink():
                child.unlink()

    stem = f"{PACKAGE_NAME}-{version}"
    zip_path = output_dir / f"{stem}.zip"
    manifest_path = output_dir / f"{stem}.manifest.json"
    sbom_path = output_dir / f"{stem}.sbom.cdx.json"
    checksum_path = output_dir / f"{stem}.zip.sha256"
    checksums_path = output_dir / "SHA256SUMS"

    write_zip(zip_path, root, files)
    write_json(
        manifest_path,
        {
            "files": manifest,
            "format": 1,
            "name": PACKAGE_NAME,
            "version": version,
        },
    )
    write_json(
        sbom_path,
        {
            "bomFormat": "CycloneDX",
            "components": [
                {
                    "hashes": [{"alg": "SHA-256", "content": entry["sha256"]}],
                    "name": entry["path"],
                    "type": "file",
                }
                for entry in manifest
            ],
            "metadata": {
                "component": {
                    "name": PACKAGE_NAME,
                    "type": "application",
                    "version": version,
                }
            },
            "specVersion": "1.5",
            "version": 1,
        },
    )

    zip_hash = sha256_file(zip_path)
    checksum_path.write_text(f"{zip_hash}  {zip_path.name}\n", encoding="ascii", newline="\n")
    checksum_entries = [zip_path, manifest_path, sbom_path]
    checksums_path.write_text(
        "".join(f"{sha256_file(path)}  {path.name}\n" for path in checksum_entries),
        encoding="ascii",
        newline="\n",
    )

    return {
        "checksum": str(checksum_path),
        "checksums": str(checksums_path),
        "manifest": str(manifest_path),
        "sbom": str(sbom_path),
        "version": version,
        "zip": str(zip_path),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output-dir", type=Path, default=Path("dist"))
    args = parser.parse_args()
    try:
        result = build(args.root, args.output_dir)
    except (OSError, RuntimeError, ValueError, zipfile.BadZipFile) as exception:
        print(f"FAIL: {exception}", file=sys.stderr)
        return 1
    print(json.dumps({"ok": True, **result}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
