#!/usr/bin/env python3
"""Writes checksums.json for a built plugin folder.

Usage: bin/make-checksums.py <plugin-folder> <version> <output-file>

The file lists the SHA-256 of every file in the release, with paths relative
to the plugin folder. The release workflow attaches it to the GitHub release,
and the plugin compares its installed files against it (includes/class-integrity.php).
"""

import hashlib
import json
import os
import sys


def main() -> int:
    if len(sys.argv) != 4:
        print(__doc__, file=sys.stderr)
        return 1

    root, version, output = sys.argv[1], sys.argv[2], sys.argv[3]
    files = {}

    for directory, _, names in os.walk(root):
        for name in names:
            path = os.path.join(directory, name)
            relative = os.path.relpath(path, root).replace(os.sep, "/")
            with open(path, "rb") as handle:
                files[relative] = hashlib.sha256(handle.read()).hexdigest()

    with open(output, "w", encoding="utf-8") as handle:
        json.dump({"version": version, "algorithm": "sha256", "files": dict(sorted(files.items()))}, handle, indent=1)
        handle.write("\n")

    print(f"Wrote {output}: {len(files)} files, version {version}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
