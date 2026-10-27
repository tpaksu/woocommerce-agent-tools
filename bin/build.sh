#!/usr/bin/env bash
# Builds an installable plugin zip: composer decides what ships, this wraps it in the
# directory name WordPress expects.
set -euo pipefail

SLUG="woocommerce-agent-tools"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

cd "$ROOT"
composer archive --quiet --format=zip --dir="$STAGE" --file=payload
mkdir -p "$STAGE/$SLUG"
unzip -q "$STAGE/payload.zip" -d "$STAGE/$SLUG"

# src/ is PSR-4, so the zip has to carry an autoloader. Built in the staging copy, never in
# the repo, or a build would delete the dev dependencies phpcs and phpstan run from.
cp composer.json composer.lock "$STAGE/$SLUG/"
composer install --working-dir="$STAGE/$SLUG" --no-dev --classmap-authoritative --no-interaction --quiet
rm -f "$STAGE/$SLUG/composer.json" "$STAGE/$SLUG/composer.lock"

rm -f "$ROOT/$SLUG.zip"
( cd "$STAGE" && zip -rq "$ROOT/$SLUG.zip" "$SLUG" )

printf 'Built %s/%s.zip (%s files)\n' "$ROOT" "$SLUG" "$(unzip -l "$ROOT/$SLUG.zip" | tail -1 | awk '{print $2}')"
