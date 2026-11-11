#!/usr/bin/env python3
"""Root FS helper for PRM CS2 Plugin Installer (panel PHP runs as nginx)."""

from __future__ import annotations

import json
import os
import re
import shutil
import socket
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

_orig_getaddrinfo = socket.getaddrinfo


def _ipv4_getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
    return _orig_getaddrinfo(host, port, socket.AF_INET, type, proto, flags)


socket.getaddrinfo = _ipv4_getaddrinfo


WINGS_UID = 988
WINGS_GID = 988
DEFAULT_ROOTS = (
    "/data",
    "/var/lib/pterodactyl/volumes",
    "/peto-volumes",
    "/srv/daemon-data",
    "/srv/pterodactyl/volumes",
)


def volume_roots() -> list[Path]:
    ordered: list[Path] = []
    raw = os.environ.get("PRM_VOLUMES", "")
    for chunk in raw.replace(";", ",").split(","):
        item = chunk.strip()
        if item:
            ordered.append(Path(item))
    ordered.extend(Path(p) for p in DEFAULT_ROOTS)
    seen: set[str] = set()
    out: list[Path] = []
    for path in ordered:
        key = str(path)
        if key in seen:
            continue
        seen.add(key)
        if path.is_dir():
            out.append(path)
    return out


def server_dir(uuid: str) -> Path:
    uid = _safe_uuid(uuid)
    for root in volume_roots():
        candidate = root / uid
        if candidate.is_dir():
            return candidate
    roots = volume_roots()
    return (roots[0] if roots else Path("/data")) / uid


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


def _tokens(name: str) -> set[str]:
    s = re.sub(r"\[[^\]]*\]", "", name or "")
    s = re.sub(r"([a-z])([A-Z])", r"\1 \2", s)
    s = re.sub(r"[/_\-]+", " ", s).lower()
    s = re.sub(r"^(cs2|css)\s+", "", s)
    drop = {"cs2", "css", "plugin", "plugins", "mod", "for", "the", "and", "with"}
    return {p for p in re.split(r"[^a-z0-9]+", s) if len(p) >= 3 and p not in drop}


def _keep_matching_plugins(payload: Path, dest_rel: str, only_names: list[str] | None) -> None:
    dest = (dest_rel or "").replace("\\", "/").strip("/")
    plugins = payload / "plugins"
    if not dest.endswith("counterstrikesharp") or not plugins.is_dir() or not only_names:
        return
    dirs = [p for p in plugins.iterdir() if p.is_dir()]
    if len(dirs) <= 1:
        return
    want: set[str] = set()
    for name in only_names:
        want |= _tokens(str(name))
    if not want:
        return

    def score(path: Path) -> int:
        got = _tokens(path.name)
        overlap = got & want
        extra = overlap - {"bot"}
        if len(overlap) >= 2 or extra:
            return 10 + len(overlap) * 5 + len(extra) * 8
        return len(overlap)

    ranked = sorted(((score(d), d) for d in dirs), key=lambda x: x[0], reverse=True)
    keep = {d.name for sc, d in ranked if sc >= 10}
    if not keep and ranked:
        keep = {ranked[0][1].name}
    bases = {re.sub(r"(api|impl)$", "", n, flags=re.I) for n in keep}
    for d in dirs:
        base = re.sub(r"(api|impl)$", "", d.name, flags=re.I)
        if base in bases:
            keep.add(d.name)
    for d in dirs:
        if d.name not in keep:
            shutil.rmtree(d, ignore_errors=True)


def _find_payload_root(extracted: Path, hint: str | None, dest_rel: str = "") -> Path:
    """Pick the folder that should be merged into dest_rel."""
    dest = (dest_rel or "").replace("\\", "/").strip("/")
    if hint:
        candidate = extracted / hint
        if candidate.is_dir():
            return candidate

    css_inside = extracted / "addons" / "counterstrikesharp"
    mm_inside = extracted / "addons" / "metamod"
    if dest.endswith("counterstrikesharp") and css_inside.is_dir():
        return css_inside
    if dest.endswith("csgo") and (css_inside.is_dir() or mm_inside.is_dir() or (extracted / "addons").is_dir()):
        return extracted
    if dest.endswith("counterstrikesharp") and (extracted / "plugins").is_dir():
        return extracted

    children = [p for p in extracted.iterdir() if p.name not in (".", "..")]
    if len(children) == 1 and children[0].is_dir():
        return _find_payload_root(children[0], None, dest_rel)
    return extracted


def _normalize_css_layout(server: Path) -> None:
    """Hoist nested addons/counterstrikesharp trees into the real CSS folder."""
    css = server / "game/csgo/addons/counterstrikesharp"
    if not css.is_dir():
        return
    nested = css / "addons" / "counterstrikesharp"
    try:
        if nested.is_dir() and nested.resolve() != css.resolve():
            _merge_tree(nested, css)
            shutil.rmtree(nested, ignore_errors=True)
            leftover = css / "addons"
            if leftover.is_dir() and not any(leftover.iterdir()):
                leftover.rmdir()
    except OSError:
        pass


def download_extract(uuid: str, url: str, dest_rel: str, mode: str, strip_hint: str | None, only_names: list | None = None) -> dict:
    server = server_dir(uuid)
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
            headers={
                "User-Agent": "PRM-CS2-Plugin-Installer/1.0",
                "Accept": "application/octet-stream,*/*",
            },
        )
        last_err: Exception | None = None
        for attempt in range(4):
            try:
                with urllib.request.urlopen(req, timeout=45) as resp, open(archive, "wb") as out:
                    ctype = (resp.headers.get("Content-Type") or "").lower()
                    length = int(resp.headers.get("Content-Length") or "0")
                    if "text/html" in ctype:
                        raise RuntimeError("GitHub zip yerine HTML döndü (release yok veya rate limit)")
                    if length > 80 * 1024 * 1024:
                        raise RuntimeError(f"Paket çok büyük ({length} byte). with-cssharp/full pack indirme.")
                    shutil.copyfileobj(resp, out, length=256 * 1024)
                last_err = None
                break
            except (TimeoutError, socket.gaierror, urllib.error.URLError, OSError) as exc:
                last_err = exc
                sys.stderr.write(f"download retry {attempt + 1}/4 {url} {exc}\n")
                time.sleep(1.5 * (attempt + 1))
        if last_err is not None:
            raise RuntimeError(f"GitHub indirme başarısız: {last_err}") from last_err

        magic = archive.read_bytes()[:4]
        if kind != "tar.gz" and magic.startswith(b"PK"):
            kind = "zip"
        elif magic.startswith(b"\x1f\x8b"):
            kind = "tar.gz"
        else:
            preview = archive.read_bytes()[:80]
            raise RuntimeError("İndirilen dosya zip/tar değil: " + preview[:80].decode("utf-8", "replace"))

        extract_dir = tmp_path / "out"
        extract_dir.mkdir()
        if kind == "tar.gz":
            with tarfile.open(archive, "r:gz") as tf:
                tf.extractall(extract_dir)
        else:
            with zipfile.ZipFile(archive, "r") as zf:
                zf.extractall(extract_dir)

        payload = _find_payload_root(extract_dir, strip_hint, dest_rel)
        _keep_matching_plugins(payload, dest_rel, [str(n) for n in (only_names or []) if n])

        if mode == "replace_dir":
            if dest.exists():
                shutil.rmtree(dest)
            shutil.copytree(payload, dest)
            files = sum(1 for _ in dest.rglob("*") if _.is_file())
        else:
            files = _merge_tree(payload, dest)

        _normalize_css_layout(server)
        _chown_tree(dest)
        plugins_path = server / "game/csgo/addons/counterstrikesharp/plugins"
        plugin_dirs = []
        if plugins_path.is_dir():
            plugin_dirs = [p.name for p in plugins_path.iterdir() if p.is_dir()]
        return {
            "ok": True,
            "files": files,
            "dest": str(dest_rel),
            "plugin_dirs": plugin_dirs,
        }


def remove_rel(uuid: str, rel: str) -> dict:
    server = server_dir(uuid)
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
    server = server_dir(uuid)
    _normalize_css_layout(server)
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
    server = server_dir(uuid)
    _normalize_css_layout(server)
    target = server / _safe_rel(rel)
    return {"ok": True, "exists": target.exists(), "dir": target.is_dir() if target.exists() else False}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

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
                only_names = body.get("only_names") or []
                if not isinstance(only_names, list):
                    only_names = []
                result = download_extract(
                    str(body.get("uuid", "")),
                    str(body.get("url", "")),
                    str(body.get("dest_rel", "")),
                    str(body.get("mode", "merge") or "merge"),
                    body.get("strip_hint"),
                    only_names,
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
