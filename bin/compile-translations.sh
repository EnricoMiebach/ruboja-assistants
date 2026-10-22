#!/usr/bin/env bash
# Compiles languages/*.po into .mo (PHP) and .json (block editor script).
# Runs inside the wp-env CLI container: npm run i18n:compile
set -euo pipefail

cd "$(dirname "$0")/.."

for po in languages/*.po; do
	wp i18n make-mo "$po" languages/
done

wp i18n make-json languages/ --no-purge --pretty-print
