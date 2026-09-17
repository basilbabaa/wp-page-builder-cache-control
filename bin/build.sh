#!/usr/bin/env bash
#
# Build an installable plugin zip in dist/.
#
# The version comes from the plugin header so the filename can never disagree
# with what WordPress reports once it is installed.
#
# Usage: ./bin/build.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="wp-page-builder-cache-control"
MAIN="$ROOT/$SLUG.php"

if [[ ! -f "$MAIN" ]]; then
	echo "error: cannot find $MAIN" >&2
	exit 1
fi

VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

if [[ -z "$VERSION" ]]; then
	echo "error: could not read Version from the plugin header" >&2
	exit 1
fi

# Guard against shipping a zip whose header and constant disagree - they are
# edited by hand and the constant is what busts asset caches.
CONST_VERSION="$(grep -m1 "define( 'PBCC_VERSION'" "$MAIN" | sed -E "s/.*'PBCC_VERSION',[[:space:]]*'([^']+)'.*/\1/")"

if [[ "$VERSION" != "$CONST_VERSION" ]]; then
	echo "error: header Version ($VERSION) does not match PBCC_VERSION ($CONST_VERSION)" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"

rsync -a \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude '.DS_Store' \
	--exclude 'dist' \
	--exclude 'bin' \
	"$ROOT/" "$STAGE/$SLUG/"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"

( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" -x '*.DS_Store' )

echo "built $ZIP"
echo "  version: $VERSION"
echo "  size:    $(du -h "$ZIP" | cut -f1)"
echo "  files:   $(unzip -l "$ZIP" | tail -1 | awk '{print $2}')"
