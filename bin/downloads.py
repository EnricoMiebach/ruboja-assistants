#!/usr/bin/env python3
"""Shows how often the plugin was downloaded, per release and in total.

Usage: bin/downloads.py [owner/repo]        (npm run downloads)

"Zip" counts downloads of ruboja-assistants.zip: manual downloads, installs
and every update WordPress installs from GitHub. "Prüfungen" counts downloads
of checksums.json, which every installation fetches for the daily file check
(from 0.1.9 on, at most about twice a day per site), so it hints at the number
of active installations. Set GITHUB_TOKEN for a higher API rate limit.
"""

import json
import os
import sys
import urllib.request

ZIP = "ruboja-assistants.zip"
CHECKSUMS = "checksums.json"


def releases(repo):
    page = 1
    while True:
        request = urllib.request.Request(
            f"https://api.github.com/repos/{repo}/releases?per_page=100&page={page}",
            headers={"Accept": "application/vnd.github+json", "User-Agent": "ruboja-assistants-downloads"},
        )
        token = os.environ.get("GITHUB_TOKEN")
        if token:
            request.add_header("Authorization", f"Bearer {token}")
        with urllib.request.urlopen(request, timeout=15) as response:
            batch = json.load(response)
        if not batch:
            return
        yield from batch
        page += 1


def count(release, name):
    return sum(asset.get("download_count", 0) for asset in release.get("assets", []) if asset.get("name") == name)


def main():
    repo = sys.argv[1] if len(sys.argv) > 1 else "EnricoMiebach/ruboja-assistants"
    rows = [(r["tag_name"], (r.get("published_at") or "")[:10], count(r, ZIP), count(r, CHECKSUMS)) for r in releases(repo)]

    print(f"{'Version':<9}{'Datum':<12}{'Zip':>7}{'Prüfungen':>11}")
    for tag, date, zips, checks in rows:
        print(f"{tag:<9}{date:<12}{zips:>7}{checks:>11}")
    print(f"{'Gesamt':<21}{sum(r[2] for r in rows):>7}{sum(r[3] for r in rows):>11}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
