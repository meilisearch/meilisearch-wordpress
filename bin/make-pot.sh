#!/usr/bin/env bash
# Usage: bin/make-pot.sh           regenerates languages/meilisearch.pot from the source
#        bin/make-pot.sh --check   fails when the committed template no longer matches the source (CI)
# Requires WP-CLI ("wp") with its i18n command.
#
# The check compares what a translator works from: the header, every msgid, context, plural and translator comment.
# It ignores the creation date, the generator version and the "#: file:line" references, so moving code around
# without touching a translatable string does not require a regeneration.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
pot="$root/languages/meilisearch.pot"
mode="${1:-}"

if [ "$#" -gt 1 ] || { [ -n "$mode" ] && [ "$mode" != "--check" ]; }; then
echo "Usage: $0 [--check]" >&2
exit 2
fi
if ! command -v wp >/dev/null 2>&1; then
echo "ERROR: WP-CLI (wp) is required: https://wp-cli.org/#installing" >&2
exit 2
fi

make_pot() { # <destination>
# Only the plugin's own code: Composer packages (Action Scheduler has its own text domain), WordPress core,
# the test suites and build output are never scanned.
wp i18n make-pot "$root" "$1" --slug=meilisearch --domain=meilisearch \
	--exclude=vendor,node_modules,wordpress,tests,build,docs,bin,test-results,playwright-report >&2
}

if [ "$mode" != "--check" ]; then
make_pot "$pot"
exit 0
fi

comparable() { # <pot file>
grep -v -e '^"POT-Creation-Date: ' -e '^"X-Generator: ' -e '^#: ' "$1"
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
make_pot "$work/meilisearch.pot"
comparable "$pot" > "$work/committed"
comparable "$work/meilisearch.pot" > "$work/generated"
if ! diff -u "$work/committed" "$work/generated" >&2; then
echo "ERROR: languages/meilisearch.pot is out of date with the translatable strings in the source." >&2
echo "Run 'bash bin/make-pot.sh' and commit the result." >&2
exit 1
fi
echo "OK: languages/meilisearch.pot matches the source."
