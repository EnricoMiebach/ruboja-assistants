#!/usr/bin/env bash
# Sets the plugin version everywhere it appears.
# Usage: bin/bump-version.sh 0.2.0
# Then add a "## [0.2.0] - YYYY-MM-DD" section to CHANGELOG.md, commit, tag v0.2.0 and push the tag.
set -euo pipefail

cd "$(dirname "$0")/.."

NEW="${1:-}"

if [[ ! "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: $0 <major.minor.patch>" >&2
	exit 1
fi

sed -i.bak -E "s/^( \* Version:[[:space:]]*).*/\1$NEW/" ruboja-assistants.php
sed -i.bak -E "s/^const VERSION([[:space:]]*)= '[^']*';/const VERSION\1= '$NEW';/" ruboja-assistants.php
sed -i.bak -E "s/^Stable tag:.*/Stable tag: $NEW/" readme.txt
sed -i.bak -E "s/^([[:space:]]+\"version\": \")[^\"]*(\",)$/\1$NEW\2/" package.json blocks/assistant/block.json
sed -i.bak -E "s/^([[:space:]]+'version'[[:space:]]*=> ')[^']*(',)$/\1$NEW\2/" blocks/assistant/index.asset.php
rm -f ruboja-assistants.php.bak readme.txt.bak package.json.bak blocks/assistant/block.json.bak blocks/assistant/index.asset.php.bak

grep -n "$NEW" ruboja-assistants.php readme.txt package.json blocks/assistant/block.json blocks/assistant/index.asset.php

if ! grep -q "^## \[$NEW\]" CHANGELOG.md; then
	echo "Note: CHANGELOG.md has no section \"## [$NEW]\" yet. The release workflow uses it as release notes." >&2
fi
