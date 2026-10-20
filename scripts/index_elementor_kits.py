#!/usr/bin/env python3
"""Read-only Elementor kit ZIP inventory. Standard library only."""
import argparse
import collections
import hashlib
import json
from pathlib import Path
import zipfile

def index_zip(path):
    widgets = collections.Counter()
    json_files = 0
    templates = 0
    containers = 0
    metadata = []
    warnings = []
    with zipfile.ZipFile(path) as z:
        for item in z.infolist():
            if item.is_dir() or not item.filename.lower().endswith(".json"):
                continue
            if item.file_size > 12_000_000:
                warnings.append("Skipped oversized JSON: " + item.filename)
                continue
            try:
                obj = json.loads(z.read(item))
            except (ValueError, UnicodeDecodeError):
                warnings.append("Invalid JSON: " + item.filename)
                continue
            json_files += 1
            if isinstance(obj, dict) and ("content" in obj or "elements" in obj):
                templates += 1
            if isinstance(obj, dict):
                for key in ("title", "name", "version", "type"):
                    if key in obj and isinstance(obj[key], (str, int, float)):
                        metadata.append({key: str(obj[key])[:160]})
            def walk(node):
                nonlocal containers
                if isinstance(node, dict):
                    typ = node.get("elType")
                    if typ == "container":
                        containers += 1
                    if typ == "widget" and isinstance(node.get("widgetType"), str):
                        widgets[node["widgetType"]] += 1
                    for val in node.values():
                        walk(val)
                elif isinstance(node, list):
                    for val in node:
                        walk(val)
            walk(obj)
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1024 * 1024), b""):
            h.update(block)
    return {"archive": path.name, "sha256": h.hexdigest(), "json_files": json_files,
            "candidate_templates": templates, "containers": containers,
            "widget_counts": dict(widgets.most_common()),
            "metadata_sample": metadata[:12], "warnings": warnings,
            "compatibility": "untested"}

def main():
    p = argparse.ArgumentParser()
    p.add_argument("directory", type=Path)
    p.add_argument("--output", type=Path, default=Path("kits-catalog.json"))
    args = p.parse_args()
    if not args.directory.is_dir():
        p.error("ZIP directory does not exist")
    result = []
    for path in sorted(args.directory.rglob("*.zip")):
        try:
            result.append(index_zip(path))
        except (zipfile.BadZipFile, OSError, RuntimeError) as exc:
            result.append({"archive": path.name, "error": str(exc)})
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print("Indexed", len(result), "ZIP archives into", args.output)

if __name__ == "__main__":
    main()
