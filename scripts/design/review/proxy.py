#!/usr/bin/env python3
"""Local-only Cyber review proxy: 127.0.0.1:8002 -> the local Guardian app on 127.0.0.1:8001.

The app correctly refuses to be framed (X-Frame-Options: DENY, CSP frame-ancestors 'none'). For the owner's
side-by-side Cyber review this proxy strips those two framing headers on LOCAL responses only, so the app can
sit next to the live Cyber reference in one page. Bound to loopback; never deployed.
"""
import http.client
import http.server
import socketserver

UPSTREAM = ("127.0.0.1", 8001)
LISTEN = ("127.0.0.1", 8002)
HOP_BY_HOP = {"connection", "keep-alive", "proxy-authenticate", "proxy-authorization", "te", "trailers",
              "transfer-encoding", "upgrade", "content-length"}
STRIP = {"x-frame-options", "content-security-policy"}


class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _forward(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else None
        headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP_BY_HOP}
        headers["Host"] = f"{LISTEN[0]}:{LISTEN[1]}"
        try:
            conn = http.client.HTTPConnection(*UPSTREAM, timeout=60)
            conn.request(self.command, self.path, body=body, headers=headers)
            resp = conn.getresponse()
            data = resp.read()
        except OSError as exc:
            msg = f"Local Guardian app is not running on {UPSTREAM[0]}:{UPSTREAM[1]} ({exc}).".encode()
            self.send_response(502)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", str(len(msg)))
            self.end_headers()
            self.wfile.write(msg)
            return
        self.send_response(resp.status, resp.reason)
        for key, value in resp.getheaders():
            low = key.lower()
            if low in HOP_BY_HOP or low in STRIP:
                continue
            if low == "location":
                value = value.replace("127.0.0.1:8001", "127.0.0.1:8002").replace("localhost:8001", "127.0.0.1:8002")
            self.send_header(key, value)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = do_OPTIONS = _forward

    def log_message(self, fmt, *args):  # quiet
        pass


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


if __name__ == "__main__":
    Server(LISTEN, Proxy).serve_forever()
