#!/usr/bin/env bash
# Builds build/meilisearch.zip and build/meilisearch/: the exact wordpress.org payload.
# Works from a staging copy, so the checkout's vendor/ and node_modules/ are untouched.
# Requires: git, php + composer, node + npm, wp-cli, tar, zip, unzip.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
build="$root/build"
stage="$build/stage"
dist="$build/meilisearch"

rm -rf "$build"
mkdir -p "$stage"
trap 'rm -rf "$stage"' EXIT

# Tracked files plus new files that are not git-ignored (working-tree content). Ignored paths such as
# vendor/, node_modules/ and build/ are never copied; CI and release checkouts contain no stray files.
( cd "$root" && git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf - ) | tar -xf - -C "$stage"

( cd "$stage" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress )
( cd "$stage" && npm ci --no-audit --no-fund && npm run build )

# dist-archive v3.2+ requires WP-CLI ^2.13; v3.1.0 works with the current WP-CLI 2.12 release.
if ! wp package path wp-cli/dist-archive-command > /dev/null 2>&1; then
php -d memory_limit=-1 "$(command -v wp)" package install wp-cli/dist-archive-command:v3.1.0
fi
wp dist-archive "$stage" "$build/meilisearch.zip" --plugin-dirname=meilisearch --force
unzip -q "$build/meilisearch.zip" -d "$build"
rm -rf "$stage"

status=0
for file in meilisearch.php uninstall.php readme.txt LICENSE composer.json languages/meilisearch.pot src/Plugin.php \
vendor/autoload.php vendor/woocommerce/action-scheduler/action-scheduler.php \
assets/js/autocomplete.js assets/js/autocomplete.min.js; do
if [ ! -e "$dist/$file" ]; then
	echo "ERROR: missing from the zip: $file" >&2
	status=1
fi
done
for path in tests docs bin build wordpress node_modules playwright-report test-results vendor/phpunit vendor/brain composer.lock package.json package-lock.json \
compose.yaml Dockerfile.dev phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist phpunit-integration.xml.dist; do
if [ -e "$dist/$path" ]; then
	echo "ERROR: must not ship: $path" >&2
	status=1
fi
done
hidden="$(find "$dist" -name '.*' -print)"
if [ -n "$hidden" ]; then
echo "ERROR: hidden files must not ship:" >&2
echo "$hidden" >&2
status=1
fi
if [ "$status" -ne 0 ]; then
exit "$status"
fi

echo "Built $build/meilisearch.zip ($(du -h "$build/meilisearch.zip" | cut -f1)) and $dist/"
