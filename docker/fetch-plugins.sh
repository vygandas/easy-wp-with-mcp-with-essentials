#!/bin/sh
# Download the plugins listed in docker/external-plugins.txt from GitHub.
#
#   sh docker/fetch-plugins.sh <list file> <destination plugins directory>
#
# Set SKIP_EXISTING=1 to leave plugins that are already present alone; the
# local start script does that so reruns do not download everything again.
# Repositories must be public: no credentials are sent.
set -eu

list="${1:?usage: fetch-plugins.sh <list file> <plugins directory>}"
dest="${2:?usage: fetch-plugins.sh <list file> <plugins directory>}"
mkdir -p "$dest"

grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$list" | tr -d '\r' | while read -r dir repo ref extra; do
	if [ -z "$dir" ] || [ -z "$repo" ] || [ -z "$ref" ] || [ -n "${extra:-}" ]; then
		echo "fetch-plugins: expected '<directory> <owner/repo> <ref>' in $list, got: $dir $repo $ref ${extra:-}" >&2
		exit 1
	fi
	case "$dir" in
		*/* | .* ) echo "fetch-plugins: '$dir' must be a plain directory name" >&2; exit 1 ;;
	esac
	if [ "${SKIP_EXISTING:-0}" = "1" ] && [ -d "$dest/$dir" ]; then
		echo "fetch-plugins: $dir already present, skipping"
		continue
	fi

	echo "fetch-plugins: $repo@$ref -> $dest/$dir"
	archive="$dest/.$dir.tar.gz"
	# Download first, so a failed request never deletes a working copy.
	curl -fsSL --retry 3 -o "$archive" "https://codeload.github.com/$repo/tar.gz/$ref"
	rm -rf "$dest/$dir"
	mkdir -p "$dest/$dir"
	tar -xzf "$archive" -C "$dest/$dir" --strip-components=1
	rm -f "$archive"
	# Development-only folders never belong on a running site.
	rm -rf "$dest/$dir/tests" "$dest/$dir/scripts" "$dest/$dir/dist" "$dest/$dir/.github"
done
