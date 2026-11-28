"""Build a deterministic installer and the hash manifest used by delta updates."""
import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path


def build(root: Path, output: Path) -> tuple[Path, Path]:
    header = (root / "ai-comment-audit.php").read_text(encoding="utf-8")
    version = re.search(r"Version:\s*(\d+\.\d+\.\d+)", header).group(1)
    php = re.search(r"Requires PHP:\s*([\d.]+)", header).group(1)
    wp = re.search(r"Requires at least:\s*([\d.]+)", header).group(1)
    sources = [root / p for p in ["ai-comment-audit.php", "index.php", "uninstall.php", "README.md", "readme.txt"]]
    for directory in ["assets", "includes", "migrations"]:
        sources.extend(p for p in (root / directory).rglob("*") if p.is_file())
    files = {}
    for source in sorted(sources):
        if source.is_symlink():
            raise ValueError(f"Symlink is not a release file: {source}")
        # GitHub raw files and release ZIPs must have identical canonical bytes.
        data = source.read_bytes()
        if source.suffix.lower() in {".php", ".css", ".js", ".json", ".txt", ".md", ".svg", ".html"}:
            data = data.replace(b"\r\n", b"\n")
        files[source.relative_to(root).as_posix()] = data
    migrations = []
    for path, data in files.items():
        if path.startswith("migrations/") and path.endswith(".json"):
            item = json.loads(data)
            if path != f"migrations/{item['version']}.json" or not isinstance(item["steps"], list):
                raise ValueError(f"Invalid migration: {path}")
            migrations.append({"version": item["version"], "from": item["from"], "path": path})
    migrations.sort(key=lambda item: tuple(int(v) for v in item["version"].split(".")))
    if not migrations or migrations[-1]["version"] != version:
        raise ValueError("Every release must add its versioned migration file, even with empty steps")
    previous = "1.4.0"
    for item in migrations:
        if item["from"] != previous:
            raise ValueError(f"Broken migration chain before {item['version']}")
        previous = item["version"]
    manifest = {
        "format": 1, "repository": "RainReiki/AICommentAudit", "version": version,
        "requires_php": php, "requires_wp": wp, "migration_baseline": "1.4.0",
        "files": {path: {"sha256": hashlib.sha256(data).hexdigest(), "size": len(data)} for path, data in files.items()},
        "migrations": migrations,
    }
    manifest_bytes = (json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    output.mkdir(parents=True, exist_ok=True)
    manifest_path = output / "update-manifest.json"
    manifest_path.write_bytes(manifest_bytes)
    files["update-manifest.json"] = manifest_bytes
    archive = output / f"AICommentAudit-{version}.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as package:
        for path, data in sorted(files.items()):
            info = zipfile.ZipInfo("ai-comment-audit/" + path, (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            package.writestr(info, data)
    return archive, manifest_path


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, default=Path("dist"))
    args = parser.parse_args()
    for artifact in build(args.root.resolve(), args.output.resolve()):
        print(artifact)
