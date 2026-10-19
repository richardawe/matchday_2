#!/usr/bin/env python3
"""Read-only HTTP acceptance checks for the release that was just deployed."""
import json
from html.parser import HTMLParser
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def smoke(base_url, expected_sha):
    if not re.fullmatch(r"[0-9a-f]{40}", expected_sha):
        raise ValueError("Expected a full release commit SHA")
    parsed = urllib.parse.urlsplit(base_url)
    if parsed.scheme != "https" or not parsed.netloc or parsed.query or parsed.fragment:
        raise ValueError("Use the production HTTPS base URL")

    def get(path, expected_type=None):
        request = urllib.request.Request(
            base_url.rstrip("/") + path,
            headers={"Cache-Control": "no-cache", "User-Agent": "Matchday-deploy-smoke"},
        )
        for attempt in range(3):
            try:
                with urllib.request.urlopen(request, timeout=20) as response:
                    if response.status != 200:
                        raise RuntimeError(f"{path}: expected HTTP 200")
                    # Redirects to sign-in or another host cannot establish readiness.
                    if urllib.parse.urlsplit(response.url).netloc != parsed.netloc:
                        raise RuntimeError(f"{path}: unexpected redirect host")
                    if urllib.parse.urlsplit(response.url).path != urllib.parse.urlsplit(path).path:
                        raise RuntimeError(f"{path}: unexpected redirect path")
                    if expected_type and response.headers.get_content_type() not in expected_type:
                        raise RuntimeError(f"{path}: incorrect asset content type")
                    result = response.read().decode("utf-8")
                    if not result.strip():
                        raise RuntimeError(f"{path}: empty response")
                    if expected_type and re.search(r'<(?:!doctype\s+html|html)(?:\s|>)', result, re.I):
                        raise RuntimeError(f"{path}: HTML instead of a compiled asset")
                    print(f"HTTP 200: {urllib.parse.urlsplit(path).path}")
                    return result
            except (urllib.error.URLError, TimeoutError):
                if attempt == 2:
                    raise
                time.sleep(2)

    if get("/deploy-version.txt?release=" + expected_sha).strip() != expected_sha:
        raise RuntimeError("The public document root is not serving the uploaded release")
    get("/up")
    if '<html' not in get('/war').lower():
        raise RuntimeError('War landing page did not render')
    home = get("/")
    if "<html" not in home.lower() or "/build/assets/" not in home:
        raise RuntimeError("Home page did not render the compiled frontend")
    class Assets(HTMLParser):
        def __init__(self):
            super().__init__()
            self.urls = set()

        def handle_starttag(self, tag, attrs):
            attrs = dict(attrs)
            url = attrs.get('href') if tag == 'link' else attrs.get('src') if tag == 'script' else None
            if url and '/build/assets/' in url:
                self.urls.add(url)

    assets = Assets()
    assets.feed(home)
    extensions = set()
    for url in sorted(assets.urls):
        asset = urllib.parse.urlsplit(urllib.parse.urljoin(base_url, url))
        if asset.netloc != parsed.netloc or asset.scheme != 'https':
            raise RuntimeError('Compiled assets must use the production HTTPS origin')
        extension = os.path.splitext(asset.path)[1]
        types = {'.css': ['text/css'], '.js': ['application/javascript', 'text/javascript']}
        if extension in types:
            get(asset.path, types[extension])
            extensions.add(extension)
    if extensions != {'.css', '.js'}:
        raise RuntimeError('Home page must load compiled CSS and JavaScript')
    listing = get("/matches")
    if "<html" not in listing.lower() or "Match" not in listing:
        raise RuntimeError("Match listing did not render")
    pulse = json.loads(get("/api/matchday-pulse"))
    if not isinstance(pulse.get("html"), str) or not isinstance(pulse.get("has_live"), bool):
        raise RuntimeError("Pulse API returned an unexpected payload")
    match_id = os.environ.get("SMOKE_MATCH_ID", "")
    if not match_id:
        link = re.search(r"/matches/(\d+)(?:[?\"'/])", listing + pulse["html"] + home)
        match_id = link[1] if link else ""
    if not re.fullmatch(r"\d+", match_id):
        raise RuntimeError("No fixture found. Set production variable CPANEL_SMOKE_MATCH_ID to an existing fixture ID")
    detail = get(f"/matches/{match_id}")
    if "<html" not in detail.lower() or "Match" not in detail:
        raise RuntimeError("Match detail did not render")
    live = json.loads(get(f"/matches/{match_id}/live"))
    if not isinstance(live.get("score"), dict) or not isinstance(live.get("html"), str):
        raise RuntimeError("Live match API returned an unexpected payload")
    print("Release, pages, and live JSON checks passed.")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit("Usage: smoke-production.py https://matchday.africa FULL_COMMIT_SHA")
    smoke(*sys.argv[1:])
