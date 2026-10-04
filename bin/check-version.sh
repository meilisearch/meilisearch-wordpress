#!/usr/bin/env bash
# Usage: bin/check-version.sh <version>   (e.g. 1.0.0 or v1.0.0, typically the git tag)
# Fails unless the version equals the plugin header "Version:", the MEILISEARCH_VERSION constant, the unit bootstrap
# constant, the package.json version and the readme "Stable tag:", the readme has a changelog entry for it, and the readme name matches "Plugin Name:".
set -euo pipefail

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
echo "Usage: $0 <version>   (e.g. 1.0.0 or v1.0.0)" >&2
exit 2
fi

root="$(cd "$(dirname "$0")/.." && pwd)"
main="$root/meilisearch.php"
readme="$root/readme.txt"
bootstrap="$root/tests/unit/bootstrap.php"
package="$root/package.json"
expected="${1#v}"

first_match() { # <sed expression> <file>
sed -n "$1" "$2" | head -n 1 | tr -d '\r'
}

header="$(first_match 's/^[[:space:]]*\**[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$main")"
constant="$(first_match "s/^.*define([[:space:]]*'MEILISEARCH_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*$/\1/p" "$main")"
if [ -z "$constant" ]; then
constant="$(first_match "s/^.*const[[:space:]]*MEILISEARCH_VERSION[[:space:]]*=[[:space:]]*'\([^']*\)'.*$/\1/p" "$main")"
fi
boot_constant="$(first_match "s/^.*define([[:space:]]*'MEILISEARCH_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*$/\1/p" "$bootstrap")"
package_version="$(first_match 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*$/\1/p' "$package")"
stable="$(first_match 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$readme")"
plugin_name="$(first_match 's/^[[:space:]]*\**[[:space:]]*Plugin Name:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' "$main")"
readme_name="$(first_match 's/^===[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*===[[:space:]]*$/\1/p' "$readme")"

status=0
compare() { # <label> <actual>
if [ "$2" != "$expected" ]; then
	echo "ERROR: $1 is '${2:-<missing>}', expected '$expected'." >&2
	status=1
fi
}
compare "meilisearch.php 'Version:' header" "$header"
compare "MEILISEARCH_VERSION constant" "$constant"
compare "tests/unit/bootstrap.php MEILISEARCH_VERSION" "$boot_constant"
compare "package.json version" "$package_version"
compare "readme.txt 'Stable tag:'" "$stable"
if ! tr -d '\r' < "$readme" | grep -qxF "= ${expected} ="; then
echo "ERROR: readme.txt has no '= ${expected} =' changelog entry." >&2
status=1
fi
if [ -z "$plugin_name" ] || [ "$plugin_name" != "$readme_name" ]; then
echo "ERROR: readme.txt name '${readme_name}' does not match 'Plugin Name: ${plugin_name}'." >&2
status=1
fi

if [ "$status" -eq 0 ]; then
echo "OK: version $expected is consistent (header, constants, package.json, Stable tag, changelog)."
fi
exit "$status"
