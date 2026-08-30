#!/usr/bin/env bash
# Builds a clean, WP.org-submission-ready ZIP of the plugin — excludes
# everything dev-only (tests/, dev-tests/, composer.*, vendor/,
# phpunit.xml.dist) per stripe-order-reconciler/.distignore, so what you
# see in build/ is exactly what would ship to end users, not just an
# intention documented in a file nobody runs.
set -euo pipefail

PLUGIN_SLUG="stripe-order-reconciler"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/$PLUGIN_SLUG"
BUILD_DIR="$REPO_ROOT/build"
STAGE_DIR="$BUILD_DIR/$PLUGIN_SLUG"

VERSION="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_DIR/$PLUGIN_SLUG.php" | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
ZIP_PATH="$BUILD_DIR/$PLUGIN_SLUG-$VERSION.zip"

echo "==> Building $PLUGIN_SLUG v$VERSION"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

# rsync --exclude-from expects glob-ish patterns, not the regex .distignore
# format some WP.org deploy Actions use — translate the small, fixed list
# here rather than depending on rsync understanding regex.
rsync -a \
	--exclude='.git' \
	--exclude='.gitignore' \
	--exclude='.distignore' \
	--exclude='.phpunit.result.cache' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit.xml.dist' \
	--exclude='tests' \
	--exclude='dev-tests' \
	--exclude='vendor' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
	"$PLUGIN_DIR/" "$STAGE_DIR/"

echo "==> Staged files:"
find "$STAGE_DIR" -type f | sed "s|$BUILD_DIR/||" | sort

echo "==> Verifying no dev-only files leaked in"
if find "$STAGE_DIR" -regex '.*/\(tests\|dev-tests\|vendor\)\(/.*\)?' -o -name 'composer.*' -o -name 'phpunit.xml.dist' | grep -q .; then
	echo "FAIL: dev-only files present in the staged build — see above."
	exit 1
fi
echo "OK — no dev-only files present."

( cd "$BUILD_DIR" && zip -rq "$(basename "$ZIP_PATH")" "$PLUGIN_SLUG" )

echo "==> Built: $ZIP_PATH"
echo "==> Unzipped size: $(du -sh "$STAGE_DIR" | cut -f1)"
