#!/usr/bin/env python3
"""Mock Azure Front Door purge endpoint (LOCAL TESTING ONLY).

Stands in for the Azure hosts drupal/azure_cdn_purge calls, commercial
(login.microsoftonline.com, management.azure.com) and Azure Government
(login.microsoftonline.us, management.usgovcloudapi.net). The host is taken
from the CONNECT target and logged, so tests can assert which cloud was used:

  POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
  POST https://management.azure.com/subscriptions/{sub}/resourceGroups/{rg}/
       providers/Microsoft.Cdn/profiles/{profile}/afdEndpoints/{name}/purge
       ?api-version=...

azure_cdn_purge 1.0.4 hard-codes both hosts (src/Plugin/Purge/Purger/
AzurePurger.php lines 145 and 165), so Drupal cannot be pointed at another URL
through the module's config. Instead the lab sets Drupal core's
`http_client_config` proxy setting to this service. Guzzle then sends
`CONNECT host:443` here. For the four Azure hosts above this service answers
the CONNECT itself, wraps the socket in TLS with a self-signed certificate and
serves the request locally, so real Azure is never contacted. A CONNECT to any
other host is tunnelled untouched (stage_file_proxy fetching production files).

Plain HTTP requests to the same paths also work (for curl tests).

Control endpoints (plain HTTP):
  GET  /__mock/health             -> 200 "ok"
  GET  /__mock/requests           -> the JSON lines log
  POST /__mock/reset              -> clears the log and the failure plan
  POST /__mock/fail?count=N&status=S
                                  -> the next N purge requests answer status S
                                     (defaults 1 and 500)
  POST /__mock/edge-purge?enabled=0|1
                                  -> turn forwarding to the local edge off/on
                                     (reset turns it back on)

Local edge cache: when $EDGE_PURGE_URL is set (for example
http://edge:8099/__edge/purge), every successful purge is also forwarded to
the lab edge (edge/, the Front Door cache stand-in) so the local cache is
really purged. The edge answer is logged as kind "edge_purge". This is a
container-to-container call on the lab network, never to Azure.

Every request is appended as one JSON object per line to $MOCK_LOG
(default /data/requests.jsonl). Client secrets are never logged.
"""

from __future__ import annotations

import contextlib
import json
import os
import socket
import ssl
import sys
import threading
import time
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

LOG_PATH = os.environ.get("MOCK_LOG", "/data/requests.jsonl")
CERT = os.environ.get("MOCK_CERT", "/opt/mock/cert.pem")
KEY = os.environ.get("MOCK_KEY", "/opt/mock/key.pem")
PORT = int(os.environ.get("MOCK_PORT", "8080"))
# The Azure hosts faked here (the certificate's names); other CONNECTs tunnel.
FAKE_HOSTS = {"login.microsoftonline.com", "login.microsoftonline.us",
              "management.azure.com", "management.usgovcloudapi.net"}

_lock = threading.Lock()
_fail_plan = {"remaining": 0, "status": 500}
EDGE_PURGE_URL = os.environ.get("EDGE_PURGE_URL", "")
_edge = {"enabled": True}


def _forward_to_edge(paths: object) -> None:
    """Purge the same content paths from the lab edge cache (lab network only)."""
    if not EDGE_PURGE_URL or not _edge["enabled"] or not isinstance(paths, list):
        return
    target = urlsplit(EDGE_PURGE_URL)
    data = json.dumps({"contentPaths": paths})
    try:
        conn = HTTPConnection(target.hostname or "edge", target.port or 80, timeout=10)
        conn.request("POST", target.path or "/", body=data, headers={"Content-Type": "application/json"})
        resp = conn.getresponse()
        raw = resp.read().decode() or "{}"
        conn.close()
        body = json.loads(raw) if resp.status == 200 else {"raw": raw[:200]}
        _log({"kind": "edge_purge", "status": resp.status,
              "purged": body.get("purged"), "scanned": body.get("scanned")})
    except (OSError, ValueError) as exc:
        _log({"kind": "edge_purge", "status": 0, "error": str(exc)})


def _log(entry: dict) -> None:
    entry["ts"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    line = json.dumps(entry, sort_keys=True)
    with _lock:
        os.makedirs(os.path.dirname(LOG_PATH) or ".", exist_ok=True)
        with open(LOG_PATH, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    print(line, flush=True)


def _tls_context() -> ssl.SSLContext:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(CERT, KEY)
    return ctx


def _pipe(src: socket.socket, dst: socket.socket) -> None:
    """Copy bytes one way until either side closes, then shut both down."""
    with contextlib.suppress(OSError):
        while data := src.recv(65536):
            dst.sendall(data)
    for sock in (src, dst):
        with contextlib.suppress(OSError):
            sock.shutdown(socket.SHUT_RDWR)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    tunnel_host = ""  # set on the handler that serves a CONNECT tunnel

    def log_message(self, fmt: str, *args: object) -> None:
        return

    def _send(self, status: int, body: object, ctype: str = "application/json") -> None:
        if isinstance(body, bytes):
            data = body
        elif isinstance(body, str):
            data = body.encode()
        else:
            data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _body(self) -> str:
        length = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(length).decode("utf-8", "replace") if length else ""

    def _host(self) -> str:
        return self.tunnel_host or (self.headers.get("Host") or "")

    def _tunnel(self, host: str, port: int) -> None:
        """Pass a CONNECT to a non-Azure host through untouched (no TLS interception)."""
        try:
            upstream = socket.create_connection((host, port), timeout=10)
        except OSError as exc:
            _log({"kind": "tunnel_error", "target": self.path, "error": str(exc)})
            self.send_error(502)
            return
        _log({"kind": "tunnel", "target": self.path})
        upstream.settimeout(None)
        self.send_response(200, "Connection Established")
        self.end_headers()
        back = threading.Thread(target=_pipe, args=(upstream, self.connection), daemon=True)
        back.start()
        _pipe(self.connection, upstream)
        back.join()
        upstream.close()
        self.close_connection = True

    def do_CONNECT(self) -> None:  # noqa: N802
        target = self.path
        host, _, port = target.rpartition(":")
        if host.lower() not in FAKE_HOSTS:
            self._tunnel(host, int(port or 443))
            return
        _log({"kind": "connect", "target": target})
        self.send_response(200, "Connection Established")
        self.end_headers()
        self.wfile.flush()
        try:
            tls_sock = _tls_context().wrap_socket(self.connection, server_side=True)
        except (ssl.SSLError, OSError) as exc:
            _log({"kind": "tls_error", "target": target, "error": str(exc)})
            self.close_connection = True
            return
        inner = Handler.__new__(Handler)
        inner.tunnel_host = target.split(":")[0]
        try:
            BaseHTTPRequestHandler.__init__(inner, tls_sock, self.client_address, self.server)
        except (ssl.SSLError, OSError) as exc:
            _log({"kind": "tunnel_error", "target": target, "error": str(exc)})
        self.close_connection = True

    def do_GET(self) -> None:  # noqa: N802
        path = urlsplit(self.path).path
        if path == "/__mock/health":
            self._send(200, "ok\n", "text/plain")
            return
        if path == "/__mock/requests":
            try:
                with open(LOG_PATH, "rb") as fh:
                    self._send(200, fh.read(), "application/x-ndjson")
            except FileNotFoundError:
                self._send(200, b"", "application/x-ndjson")
            return
        _log({"kind": "unhandled", "method": "GET", "host": self._host(), "path": self.path})
        self._send(404, {"error": "mock: not found"})

    def do_POST(self) -> None:  # noqa: N802
        url = urlsplit(self.path)
        body = self._body()
        host = self._host()

        if url.path == "/__mock/reset":
            with _lock:
                _fail_plan.update(remaining=0, status=500)
                _edge["enabled"] = True
                open(LOG_PATH, "w", encoding="utf-8").close()
            self._send(200, {"reset": True})
            return

        if url.path == "/__mock/fail":
            q = parse_qs(url.query)
            with _lock:
                _fail_plan["remaining"] = int(q.get("count", ["1"])[0])
                _fail_plan["status"] = int(q.get("status", ["500"])[0])
                plan = dict(_fail_plan)
            _log({"kind": "control", "fail_plan": plan})
            self._send(200, plan)
            return

        if url.path == "/__mock/edge-purge":
            q = parse_qs(url.query)
            _edge["enabled"] = q.get("enabled", ["1"])[0] != "0"
            _log({"kind": "control", "edge_purge_enabled": _edge["enabled"]})
            self._send(200, {"edge_purge_enabled": _edge["enabled"], "url": EDGE_PURGE_URL})
            return

        if url.path.endswith("/oauth2/v2.0/token"):
            form = parse_qs(body)
            _log({
                "kind": "token",
                "host": host,
                "path": url.path,
                "client_id": form.get("client_id", [""])[0],
                "grant_type": form.get("grant_type", [""])[0],
                "scope": form.get("scope", [""])[0],
                "client_secret_sent": bool(form.get("client_secret", [""])[0]),
            })
            self._send(200, {
                "token_type": "Bearer",
                "expires_in": 3599,
                "access_token": "mock-access-token",
            })
            return

        if url.path.endswith("/purge") and ("/afdEndpoints/" in url.path or "/endpoints/" in url.path):
            try:
                payload = json.loads(body) if body else {}
            except json.JSONDecodeError:
                payload = {"_unparsed": body}
            with _lock:
                fail = _fail_plan["remaining"] > 0
                status = _fail_plan["status"] if fail else 202
                if fail:
                    _fail_plan["remaining"] -= 1
            _log({
                "kind": "purge",
                "host": host,
                "path": url.path,
                "query": url.query,
                "auth_scheme": (self.headers.get("Authorization") or "").split(" ")[0],
                "content_paths": payload.get("contentPaths") if isinstance(payload, dict) else None,
                "status": status,
            })
            if fail:
                self._send(status, {"error": {"code": "MockFailure", "message": "mock told to fail"}})
            else:
                if isinstance(payload, dict):
                    _forward_to_edge(payload.get("contentPaths"))
                self._send(202, b"")
            return

        _log({"kind": "unhandled", "method": "POST", "host": host, "path": self.path, "body": body[:500]})
        self._send(404, {"error": "mock: not found"})


def main() -> int:
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"mock-afd listening on :{PORT}, log {LOG_PATH}", flush=True)
    server.serve_forever()
    return 0


if __name__ == "__main__":
    sys.exit(main())
