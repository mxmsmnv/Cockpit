#!/usr/bin/env python3
"""Validate Cockpit release contents, metadata, checksums, and reproducibility."""

from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath


FORBIDDEN_PARTS = {".git", ".github", "tests", "scripts"}
FORBIDDEN_NAMES = {".DS_Store", ".env", ".gitignore", "AGENTS.md"}
FORBIDDEN_SUFFIXES = {".bak", ".local", ".log", ".orig", ".swp", ".temp", ".tmp"}
REQUIRED = {
    "Cockpit/Cockpit.module.php",
    "Cockpit/ProcessCockpit.module.php",
    "Cockpit/README.md",
    "Cockpit/DOCUMENTATION.md",
    "Cockpit/LICENSE",
    "Cockpit/assets/Cockpit.png",
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def fail(message: str) -> None:
    raise RuntimeError(message)


def artifact_paths(dist: Path) -> dict[str, Path]:
    matches = {
        "zip": list(dist.glob("Cockpit-*.zip")),
        "manifest": list(dist.glob("Cockpit-*.manifest.json")),
        "sbom": list(dist.glob("Cockpit-*.sbom.cdx.json")),
        "checksum": list(dist.glob("Cockpit-*.zip.sha256")),
    }
    result: dict[str, Path] = {}
    for kind, paths in matches.items():
        if len(paths) != 1:
            fail(f"Expected exactly one {kind} artifact, found {len(paths)}.")
        result[kind] = paths[0]
    result["checksums"] = dist / "SHA256SUMS"
    if not result["checksums"].is_file():
        fail("SHA256SUMS is missing.")
    return result


def validate(dist: Path) -> dict[str, object]:
    artifacts = artifact_paths(dist)
    with zipfile.ZipFile(artifacts["zip"], "r") as archive:
        infos = archive.infolist()
        names = [info.filename for info in infos]
        if names != sorted(names):
            fail("ZIP entries are not sorted.")
        if len(names) != len(set(names)):
            fail("ZIP contains duplicate entries.")
        if not REQUIRED.issubset(names):
            fail("ZIP is missing required runtime files.")
        for info in infos:
            path = PurePosixPath(info.filename)
            if not path.parts or path.parts[0] != "Cockpit":
                fail(f"ZIP entry is outside the Cockpit root: {info.filename}")
            if info.is_dir():
                fail(f"ZIP contains an unnecessary directory entry: {info.filename}")
            if FORBIDDEN_PARTS.intersection(path.parts):
                fail(f"ZIP contains a development directory: {info.filename}")
            if path.name in FORBIDDEN_NAMES or path.suffix.lower() in FORBIDDEN_SUFFIXES:
                fail(f"ZIP contains a local/development file: {info.filename}")
            if info.date_time != (1980, 1, 1, 0, 0, 0):
                fail(f"ZIP timestamp is not normalized: {info.filename}")
            if (info.external_attr >> 16) & 0o777 != 0o644:
                fail(f"ZIP permissions are not normalized: {info.filename}")

        archive_hashes = {
            info.filename: {
                "path": info.filename,
                "sha256": hashlib.sha256(archive.read(info.filename)).hexdigest(),
                "size": info.file_size,
            }
            for info in infos
        }

    manifest = json.loads(artifacts["manifest"].read_text(encoding="utf-8"))
    manifest_files = {entry["path"]: entry for entry in manifest.get("files", [])}
    if manifest_files != archive_hashes:
        fail("Release manifest does not exactly match ZIP contents.")

    sbom = json.loads(artifacts["sbom"].read_text(encoding="utf-8"))
    if sbom.get("bomFormat") != "CycloneDX" or sbom.get("specVersion") != "1.5":
        fail("SBOM is not CycloneDX 1.5.")
    sbom_hashes = {
        component["name"]: component["hashes"][0]["content"]
        for component in sbom.get("components", [])
    }
    if sbom_hashes != {name: entry["sha256"] for name, entry in archive_hashes.items()}:
        fail("SBOM file components do not exactly match ZIP contents.")

    expected_zip_line = f"{sha256(artifacts['zip'])}  {artifacts['zip'].name}\n"
    if artifacts["checksum"].read_text(encoding="ascii") != expected_zip_line:
        fail("ZIP checksum file is invalid.")
    expected_sums = "".join(
        f"{sha256(artifacts[kind])}  {artifacts[kind].name}\n"
        for kind in ("zip", "manifest", "sbom")
    )
    if artifacts["checksums"].read_text(encoding="ascii") != expected_sums:
        fail("SHA256SUMS is invalid or not deterministic.")

    return {"files": len(names), "version": manifest.get("version"), "zip_sha256": sha256(artifacts["zip"])}


def build(builder: Path, root: Path, dist: Path) -> None:
    subprocess.run(
        [sys.executable, str(builder), "--root", str(root), "--output-dir", str(dist)],
        check=True,
        stdout=subprocess.DEVNULL,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--dist-dir", type=Path)
    args = parser.parse_args()
    root = args.root.resolve()
    builder = root / "scripts" / "build-release.py"
    try:
        with tempfile.TemporaryDirectory(prefix="cockpit-release-test-") as temporary:
            temporary_root = Path(temporary)
            first = temporary_root / "first"
            second = temporary_root / "second"
            build(builder, root, first)
            build(builder, root, second)
            first_result = validate(first)
            second_result = validate(second)
            if first_result["zip_sha256"] != second_result["zip_sha256"]:
                fail("Two clean builds produced different ZIP checksums.")
            for first_path in sorted(first.iterdir()):
                second_path = second / first_path.name
                if not second_path.is_file() or first_path.read_bytes() != second_path.read_bytes():
                    fail(f"Artifact is not reproducible: {first_path.name}")

        provided_result = None
        if args.dist_dir is not None:
            provided_result = validate(args.dist_dir.resolve())
        print(json.dumps({"ok": True, "reproducible": True, "artifact": provided_result or first_result}, indent=2, sort_keys=True))
        return 0
    except (OSError, RuntimeError, subprocess.CalledProcessError, zipfile.BadZipFile, json.JSONDecodeError) as exception:
        print(f"FAIL: {exception}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
