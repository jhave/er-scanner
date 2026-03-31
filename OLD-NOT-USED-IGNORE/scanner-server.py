#!/usr/bin/env python3
"""
ER Scanner Server
─────────────────
Static file serving + lightweight API for the vanilla scanner.

Endpoints:
  GET  /                              → static files (index.html, etc.)
  GET  /api/scanner/species           → returns scanner-species.json
  POST /api/scanner/species           → upsert a species entry
  POST /api/scanner/download-image    → download image from URL → scanned/

Usage:
  cd vanilla/
  python3 scanner-server.py           → http://localhost:8000/index.html
"""

import json, os, re, sys, time, traceback
import urllib.request, urllib.error, urllib.parse
from http.server import HTTPServer, SimpleHTTPRequestHandler
from pathlib import Path

PORT = int(os.environ.get("PORT", 8000))
ROOT = Path(__file__).resolve().parent
SCANNER_DB = ROOT / "scanner-species.json"
SCANNED_DIR = ROOT / "scanned"
CONFIG_FILE = ROOT / "config.local.json"


# ── Helpers ────────────────────────────────────────────────────────────────

def load_scanner_db():
    """Read scanner-species.json, return list."""
    if SCANNER_DB.exists():
        try:
            return json.loads(SCANNER_DB.read_text("utf-8"))
        except json.JSONDecodeError:
            return []
    return []


def save_scanner_db(db):
    """Write scanner-species.json atomically-ish."""
    tmp = SCANNER_DB.with_suffix(".tmp")
    tmp.write_text(json.dumps(db, indent=2, ensure_ascii=False), "utf-8")
    tmp.replace(SCANNER_DB)


def upsert_species(species):
    """Add or update a species entry in the scanner DB."""
    db = load_scanner_db()
    key = species.get("scientific_name", "").strip().lower()
    idx = next(
        (i for i, s in enumerate(db)
         if s.get("scientific_name", "").strip().lower() == key),
        -1
    )
    if idx >= 0:
        db[idx] = {**db[idx], **species}
    else:
        species.setdefault("scanned_at", time.strftime("%Y-%m-%dT%H:%M:%S"))
        db.append(species)
    save_scanner_db(db)
    return species


def download_image(url, filename):
    """Download an image to scanned/ and return the relative path."""
    SCANNED_DIR.mkdir(exist_ok=True)
    safe = re.sub(r"[^\w\-.]", "_", filename)
    dest = SCANNED_DIR / safe
    if dest.exists():
        return f"scanned/{safe}"
    try:
        req = urllib.request.Request(url, headers={
            "User-Agent": "ERScanner/1.0 (museum kiosk; contact jhave2@gmail.com)"
        })
        with urllib.request.urlopen(req, timeout=15) as resp:
            dest.write_bytes(resp.read())
        print(f"  ✓ Downloaded {safe}")
        return f"scanned/{safe}"
    except Exception as exc:
        print(f"  ✗ Image download failed for {filename}: {exc}")
        return None


# ── HTTP Handler ───────────────────────────────────────────────────────────

class ScannerHandler(SimpleHTTPRequestHandler):
    """Extend SimpleHTTPRequestHandler with a few JSON API routes."""

    def do_OPTIONS(self):
        """CORS preflight."""
        self.send_response(204)
        self._cors()
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()

    def do_GET(self):
        if self.path == "/api/scanner/species":
            self._json_response(load_scanner_db())
        elif self.path == "/api/config":
            cfg = {}
            # 1. Read .env (same source api.php uses in production)
            env_file = ROOT / ".env"
            if env_file.exists():
                for line in env_file.read_text("utf-8").splitlines():
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        cfg[k.strip().lower()] = v.strip()
            # 2. config.local.json overrides (optional)
            if CONFIG_FILE.exists():
                try:
                    cfg.update(json.loads(CONFIG_FILE.read_text("utf-8")))
                except Exception:
                    pass
            # 3. Environment variable overrides all
            env_key = os.environ.get("GEMINI_API_KEY")
            if env_key:
                cfg["gemini_api_key"] = env_key
            self._json_response(cfg)
        else:
            super().do_GET()

    def do_POST(self):
        try:
            body = self._read_json_body()
        except Exception:
            self._json_response({"error": "Invalid JSON"}, 400)
            return

        if self.path == "/api/scanner/species":
            result = upsert_species(body)
            self._json_response({"ok": True, "species": result})

        elif self.path == "/api/scanner/download-image":
            url = body.get("url", "")
            fname = body.get("filename", "image.jpg")
            if not url:
                self._json_response({"error": "No URL"}, 400)
                return
            path = download_image(url, fname)
            self._json_response({"path": path})

        else:
            self._json_response({"error": "Not found"}, 404)

    # ── internal ──

    def _read_json_body(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length)
        return json.loads(raw)

    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")

    def _json_response(self, data, code=200):
        payload = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", len(payload))
        self._cors()
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt, *args):
        # Quieter logging: skip 200 GETs for static assets
        if len(args) >= 2 and str(args[1]) == "200" and "api" not in str(args[0]):
            return
        super().log_message(fmt, *args)


# ── Main ───────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    SCANNED_DIR.mkdir(exist_ok=True)
    if not SCANNER_DB.exists():
        SCANNER_DB.write_text("[]", "utf-8")

    os.chdir(ROOT)
    server = HTTPServer(("0.0.0.0", PORT), ScannerHandler)
    print(f"─── ER Scanner Server ──────────────────────────")
    print(f"  http://localhost:{PORT}/index.html")
    print(f"  Scanner DB : {SCANNER_DB}")
    print(f"  Image cache: {SCANNED_DIR}/")
    print(f"────────────────────────────────────────────────")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down.")
        server.server_close()
