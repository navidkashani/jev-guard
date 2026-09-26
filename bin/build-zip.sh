#!/usr/bin/env bash
#
# Builds the installable plugin zip: dist/spamlens-<version>.zip containing a spamlens/ folder.
#
#   1. composer install (dev) so WP Scoper writes packages/autoload.php,
#   2. npm ci + npm run build for public/,
#   3. copies everything not listed in .distignore and checks the files the plugin cannot run without.
#
# Usage: bin/build-zip.sh [--skip-install]

set -euo pipefail

cd "$(dirname "$0")/.."

SLUG=spamlens
VERSION=$(grep -oE 'Version:[[:space:]]+[0-9.]+' "$SLUG.php" | awk '{print $2}')

if [ "${1:-}" != "--skip-install" ]; then
	composer install --no-interaction --prefer-dist --no-progress
	npm ci --no-audit --no-fund
	npm run build
fi

rm -rf "dist/$SLUG" "dist/$SLUG-$VERSION.zip"
mkdir -p "dist/$SLUG"
rsync -a --exclude-from=.distignore ./ "dist/$SLUG/"

for f in "$SLUG.php" uninstall.php readme.txt LICENSE packages/autoload.php public/app/settings.js public/app/settings.css public/js/comments.js public/css/comments.css resources/assets/veronalabs.svg resources/assets/logo-dark.png; do
	test -s "dist/$SLUG/$f" || { echo "The zip would miss $f" >&2; exit 1; }
done

(cd dist && zip -rq "$SLUG-$VERSION.zip" "$SLUG")
echo "dist/$SLUG-$VERSION.zip"
