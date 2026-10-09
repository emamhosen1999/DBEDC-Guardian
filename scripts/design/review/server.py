#!/usr/bin/env python3
"""Cyber review page server (loopback only).

Serves the review UI, the agents' screenshots and manifest, and stores the owner's suggestions. Runtime data
lives OUTSIDE the repo in $CYBER_REVIEW_DATA (default ~/.local/share/dbedc-cyber-review): shots/ (PNG/JPG +
manifest.json), suggestions.jsonl, kit/ (the Cyber reference kit, extracted from git stash@{0}^3).
"""
import http.server
import json
import os
import socketserver
import time
from urllib.parse import urlparse

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.environ.get("CYBER_REVIEW_DATA", os.path.expanduser("~/.local/share/dbedc-cyber-review"))
SHOTS = os.path.join(DATA, "shots")
SUGGESTIONS = os.path.join(DATA, "suggestions.jsonl")
LISTEN = ("127.0.0.1", 5190)


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=HERE, **kwargs)

    def translate_path(self, path):
        p = urlparse(path).path
        if p.startswith("/shots/"):
            rel = os.path.normpath(p[len("/shots/"):]).lstrip("/")
            if rel.startswith(".."):
                return os.path.join(HERE, "__nope__")
            return os.path.join(SHOTS, rel)
        return super().translate_path(path)

    def _json(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        p = urlparse(self.path).path
        if p == "/api/manifest":
            try:
                with open(os.path.join(SHOTS, "manifest.json"), encoding="utf-8") as fh:
                    return self._json(200, json.load(fh))
            except (OSError, ValueError):
                return self._json(200, [])
        if p == "/api/suggestions":
            items = []
            try:
                with open(SUGGESTIONS, encoding="utf-8") as fh:
                    items = [json.loads(line) for line in fh if line.strip()]
            except OSError:
                pass
            return self._json(200, items)
        return super().do_GET()

    def do_POST(self):
        if urlparse(self.path).path != "/api/suggestions":
            return self._json(404, {"error": "not found"})
        length = min(int(self.headers.get("Content-Length") or 0), 20000)
        try:
            body = json.loads(self.rfile.read(length) or b"{}")
        except ValueError:
            return self._json(422, {"error": "invalid json"})
        text = str(body.get("text", "")).strip()[:4000]
        if not text:
            return self._json(422, {"error": "empty suggestion"})
        item = {
            "at": time.strftime("%Y-%m-%d %H:%M:%S"),
            "pair": str(body.get("pair", ""))[:120],
            "cyber_url": str(body.get("cyber_url", ""))[:500],
            "app_url": str(body.get("app_url", ""))[:500],
            "viewport": str(body.get("viewport", ""))[:20],
            "priority": str(body.get("priority", "normal"))[:20],
            "text": text,
        }
        with open(SUGGESTIONS, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(item, ensure_ascii=False) + "\n")
        return self._json(201, item)

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):  # quiet
        pass


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


if __name__ == "__main__":
    os.makedirs(SHOTS, exist_ok=True)
    Server(LISTEN, Handler).serve_forever()
