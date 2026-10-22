#!/usr/bin/env bash
# Builds dist/ruboja-assistants.zip with only the files the plugin needs at runtime.
# The zip contains a top-level folder "ruboja-assistants/", as WordPress expects.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="ruboja-assistants"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | tr -d '[:space:]')"
BUILD_DIR="dist/build"

rm -rf dist
mkdir -p "$BUILD_DIR/$SLUG"

rsync -a --exclude-from=.distignore ./ "$BUILD_DIR/$SLUG/"

( cd "$BUILD_DIR" && zip -qr "../$SLUG.zip" "$SLUG" )
rm -rf "$BUILD_DIR"

echo "Built dist/$SLUG.zip (version $VERSION)"
