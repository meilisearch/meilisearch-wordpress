#!/usr/bin/env bash
# Usage: bin/check-assets.sh                  checks the wordpress.org listing assets in .wordpress-org/
#        bin/check-assets.sh --export <dir>   checks them, then copies the images (and nothing else) to <dir>
# Fails unless both banners, both icons and one screenshot per numbered caption under "== Screenshots ==" in
# readme.txt are present (.png or .jpg), and nothing but those images and README.md is in the directory.
# The release publishes the exported directory to the plugin's SVN assets/, so README.md never reaches it.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
assets="${MEILISEARCH_ASSETS_DIR:-$root/.wordpress-org}"
readme="${MEILISEARCH_README:-$root/readme.txt}"
export_dir=""

if [ "$#" -eq 2 ] && [ "$1" = "--export" ] && [ -n "$2" ]; then
export_dir="$2"
elif [ "$#" -ne 0 ]; then
echo "Usage: $0 [--export <dir>]" >&2
exit 2
fi

status=0
fail() { # <message>
echo "ERROR: $1" >&2
status=1
}

# Number of captions: the numbered lines between "== Screenshots ==" and the next "== Section ==".
captions="$(tr -d '\r' < "$readme" | awk '
	/^==[^=]/ { inside = ( $0 ~ /^==[[:space:]]*Screenshots[[:space:]]*==[[:space:]]*$/ ); next }
	inside && /^[0-9]+\./ { count++ }
	END { print count + 0 }
')"
if [ "$captions" -eq 0 ]; then
fail "readme.txt has no numbered caption under '== Screenshots =='."
fi

required=(banner-772x250 banner-1544x500 icon-128x128 icon-256x256)
for ((i = 1; i <= captions; i++)); do
required+=("screenshot-$i")
done

images=()
for name in "${required[@]}"; do
found=""
for ext in png jpg; do
	if [ -s "$assets/$name.$ext" ]; then
		if [ -n "$found" ]; then
			fail "$name exists as both $found and $name.$ext in .wordpress-org/: keep one."
		fi
		found="$name.$ext"
		images+=("$found")
	fi
done
if [ -z "$found" ]; then
	fail "missing wordpress.org asset: .wordpress-org/$name.png (or .jpg)."
fi
done

# Anything else would be published to SVN assets/ (or, for a screenshot, shown without a caption).
if [ -d "$assets" ]; then
while IFS= read -r -d '' path; do
	file="${path#"$assets"/}"
	known=0
	if [ "$file" = "README.md" ]; then
		known=1
	fi
	for image in ${images[@]+"${images[@]}"}; do
		if [ "$file" = "$image" ]; then
			known=1
		fi
	done
	if [ "$known" -eq 0 ]; then
		case "$file" in
			screenshot-*) fail "unexpected file in .wordpress-org/: $file (readme.txt has $captions screenshot captions; names are screenshot-<n>.png or .jpg)." ;;
			*) fail "unexpected file in .wordpress-org/: $file (only the images listed in .wordpress-org/README.md belong there)." ;;
		esac
	fi
done < <(find "$assets" -mindepth 1 -print0)
else
fail "the assets directory .wordpress-org/ does not exist."
fi

if [ "$status" -ne 0 ]; then
exit "$status"
fi

if [ -n "$export_dir" ]; then
rm -rf "$export_dir"
mkdir -p "$export_dir"
for image in "${images[@]}"; do
	cp "$assets/$image" "$export_dir/$image"
done
echo "OK: ${#images[@]} wordpress.org assets exported to $export_dir."
else
echo "OK: ${#images[@]} wordpress.org assets present (2 banners, 2 icons, $captions screenshots)."
fi
