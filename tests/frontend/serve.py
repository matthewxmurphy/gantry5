#!/usr/bin/env python3
"""Serve only the local Gantry browser fixture and its built assets.
Copyright 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
"""
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
FILES = {
    "/": (ROOT / "tests/frontend/fixture.html", "text/html; charset=utf-8"),
    "/nucleus.css": (ROOT / "engines/common/nucleus/css-compiled/nucleus.css", "text/css"),
    "/main.js": (ROOT / "assets/common/js/main.js", "text/javascript"),
}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        entry = FILES.get(urlsplit(self.path).path)
        if entry is None:
            self.send_error(404)
            return
        path, content_type = entry
        if not path.is_file():
            self.send_error(404, "Build the Gantry frontend assets first")
            return
        data = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=0)
    args = parser.parse_args()
    server = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    print(f"Gantry browser fixture: http://127.0.0.1:{server.server_port}/", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
