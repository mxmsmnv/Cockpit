#!/usr/bin/env python3
"""Small dependency-free static checks for high-confidence security mistakes."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SECRET_PATTERNS = {
    "AWS access key": re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    "GitHub token": re.compile(r"\bgh[pousr]_[A-Za-z0-9]{36,}\b"),
    "OpenAI-style secret": re.compile(r"\bsk-(?:proj-)?[A-Za-z0-9_-]{32,}\b"),
    "private key": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
}
DYNAMIC_EXECUTION = re.compile(r"\b(?:eval|shell_exec|system|passthru|proc_open|popen)\s*\(")
TEXT_SUFFIXES = {".css", ".json", ".js", ".md", ".php", ".py", ".txt", ".yml", ".yaml"}


def source_files() -> list[Path]:
    files = []
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        if ".git" in path.parts or "dist" in path.parts:
            continue
        files.append(path)
    return sorted(files)


def main() -> int:
    findings: list[str] = []
    for path in source_files():
        text = path.read_text(encoding="utf-8", errors="replace")
        relative = path.relative_to(ROOT).as_posix()
        for label, pattern in SECRET_PATTERNS.items():
            if pattern.search(text):
                findings.append(f"{relative}: possible {label}")
        if (relative.endswith(".php") and not relative.startswith("tests/")
                and DYNAMIC_EXECUTION.search(text)):
            findings.append(f"{relative}: packaged PHP uses a dynamic process/code execution function")

    if findings:
        for finding in findings:
            print("FAIL: " + finding, file=sys.stderr)
        return 1
    print(json.dumps({"ok": True, "checks": ["high-confidence-secrets", "runtime-dynamic-execution"], "files": len(source_files())}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
