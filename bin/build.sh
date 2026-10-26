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

rm -f "$ROOT/$SLUG.zip"
( cd "$STAGE" && zip -rq "$ROOT/$SLUG.zip" "$SLUG" )

printf 'Built %s/%s.zip (%s files)\n' "$ROOT" "$SLUG" "$(unzip -l "$ROOT/$SLUG.zip" | tail -1 | awk '{print $2}')"
