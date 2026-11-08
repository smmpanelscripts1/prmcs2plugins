#!/usr/bin/env python3
"""Root FS helper for PRM CS2 Plugin Installer (panel PHP runs as nginx)."""

from __future__ import annotations

import json
import os
import shutil
import tarfile
import tempfile
import urllib.request
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


DATA = Path("/data")
WINGS_UID = 988
WINGS_GID = 988


def _safe_uuid(value: str) -> str:
    value = (value or "").strip()
    if not value or "/" in value or "\\" in value or ".." in value:
        raise ValueError("invalid uuid")
    return value


def _safe_rel(value: str) -> Path:
    raw = (value or "").replace("\\", "/").strip().lstrip("/")
    if not raw or ".." in raw.split("/"):
        raise ValueError("invalid relative path")
    return Path(raw)


def _chown_tree(path: Path) -> None:
    for root, dirs, files in os.walk(path):
        try:
            os.chown(root, WINGS_UID, WINGS_GID)
        except OSError:
            pass
        for name in dirs + files:
            try:
                os.chown(os.path.join(root, name), WINGS_UID, WINGS_GID)
            except OSError:
                pass


def _merge_tree(src: Path, dst: Path) -> int:
    count = 0
    dst.mkdir(parents=True, exist_ok=True)
    for item in src.iterdir():
        target = dst / item.name
        if item.is_dir():
            count += _merge_tree(item, target)
        else:
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(item, target)
            count += 1
    return count


def _find_payload_root(extracted: Path, hint: str | None) -> Path:
    """Pick the folder that actually contains addons/ or plugins/ content."""
    if hint:
        candidate = extracted / hint
        if candidate.is_dir():
            return candidate

    # Prefer a directory that already looks like a CSS/addons tree.
    for marker in (
        "addons/counterstrikesharp",
        "addons/metamod",
        "counterstrikesharp",
        "metamod",
        "plugins",
    ):
        hit = extracted / marker
        if hit.exists():
            # return parent that should be merged into dest
            if marker.startswith("addons/"):
                return extracted
            if marker in ("counterstrikesharp", "metamod"):
                return extracted
            if marker == "plugins":
                return extracted

    children = [p for p in extracted.iterdir() if p.name not in (".", "..")]
    if len(children) == 1 and children[0].is_dir():
        return children[0]
    return extracted


def download_extract(uuid: str, url: str, dest_rel: str, mode: str, strip_hint: str | None) -> dict:
    server = DATA / _safe_uuid(uuid)
    if not server.is_dir():
        raise FileNotFoundError(f"volume missing: {uuid}")

    dest = server / _safe_rel(dest_rel)
    dest.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="prmcs2-") as tmp:
        tmp_path = Path(tmp)
        lower = url.lower().split("?", 1)[0]
        if lower.endswith(".tar.gz") or lower.endswith(".tgz"):
            archive = tmp_path / "pkg.tar.gz"
            kind = "tar.gz"
        else:
            archive = tmp_path / "pkg.zip"
            kind = "zip"

        req = urllib.request.Request(
            url,
            headers={"User-Agent": "PRM-CS2-Plugin-Installer/1.0"},
        )
        with urllib.request.urlopen(req, timeout=180) as resp, open(archive, "wb") as out:
            shutil.copyfileobj(resp, out)

        extract_dir = tmp_path / "out"
        extract_dir.mkdir()
        if kind == "tar.gz":
            with tarfile.open(archive, "r:gz") as tf:
                tf.extractall(extract_dir)
        else:
            with zipfile.ZipFile(archive, "r") as zf:
                zf.extractall(extract_dir)

        payload = _find_payload_root(extract_dir, strip_hint)

        if mode == "replace_dir":
            if dest.exists():
                shutil.rmtree(dest)
            shutil.copytree(payload, dest)
            files = sum(1 for _ in dest.rglob("*") if _.is_file())
        else:
            files = _merge_tree(payload, dest)

        _chown_tree(dest)
        return {"ok": True, "files": files, "dest": str(dest_rel)}


def remove_rel(uuid: str, rel: str) -> dict:
    server = DATA / _safe_uuid(uuid)
    target = server / _safe_rel(rel)
    if not str(target).startswith(str(server)):
        raise ValueError("path escape")
    if not target.exists():
        return {"ok": True, "removed": False}
    if target.is_dir():
        shutil.rmtree(target)
    else:
        target.unlink()
    return {"ok": True, "removed": True}


def list_rel(uuid: str, rel: str) -> dict:
    server = DATA / _safe_uuid(uuid)
    target = server / _safe_rel(rel) if rel else server
    if not target.exists():
        return {"ok": True, "entries": []}
    entries = []
    for item in sorted(target.iterdir(), key=lambda p: p.name.lower()):
        entries.append(
            {
                "name": item.name,
                "dir": item.is_dir(),
                "size": item.stat().st_size if item.is_file() else 0,
            }
        )
    return {"ok": True, "entries": entries}


def exists_rel(uuid: str, rel: str) -> dict:
    server = DATA / _safe_uuid(uuid)
    target = server / _safe_rel(rel)
    return {"ok": True, "exists": target.exists(), "dir": target.is_dir() if target.exists() else False}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        pass

    def _json(self, code: int, payload: dict) -> None:
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _read_json(self) -> dict:
        length = int(self.headers.get("Content-Length", "0"))
        return json.loads(self.rfile.read(length).decode("utf-8") or "{}")

    def do_GET(self) -> None:
        if self.path == "/health":
            self._json(200, {"ok": True, "service": "prmcs2plugins"})
            return
        if self.path.startswith("/list?"):
            from urllib.parse import parse_qs, urlparse

            qs = parse_qs(urlparse(self.path).query)
            try:
                self._json(
                    200,
                    list_rel(qs.get("uuid", [""])[0], qs.get("rel", [""])[0]),
                )
            except Exception as exc:  # noqa: BLE001
                self._json(400, {"ok": False, "error": str(exc)})
            return
        if self.path.startswith("/exists?"):
            from urllib.parse import parse_qs, urlparse

            qs = parse_qs(urlparse(self.path).query)
            try:
                self._json(
                    200,
                    exists_rel(qs.get("uuid", [""])[0], qs.get("rel", [""])[0]),
                )
            except Exception as exc:  # noqa: BLE001
                self._json(400, {"ok": False, "error": str(exc)})
            return
        self._json(404, {"error": "not found"})

    def do_POST(self) -> None:
        try:
            body = self._read_json()
            if self.path == "/install":
                result = download_extract(
                    str(body.get("uuid", "")),
                    str(body.get("url", "")),
                    str(body.get("dest_rel", "")),
                    str(body.get("mode", "merge") or "merge"),
                    body.get("strip_hint"),
                )
                self._json(200, result)
                return
            if self.path == "/remove":
                self._json(
                    200,
                    remove_rel(str(body.get("uuid", "")), str(body.get("rel", ""))),
                )
                return
            self._json(404, {"error": "not found"})
        except Exception as exc:  # noqa: BLE001
            self._json(500, {"ok": False, "error": str(exc)})


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8100), Handler).serve_forever()
