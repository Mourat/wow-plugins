#!/usr/bin/env bash
#
# Raises the version of every plugin that has changed since the last tag.
#
# The version in a plugin's header is the only thing a site compares against
# what it has installed — the tag on the release is just a name. A plugin
# that changed and kept its version reaches nobody, and that shows up days
# later as "the site did not update", looking like a fault in the updater.
#
# Too easy to forget across eleven plugins, so it is not left to memory:
# what changed is a question git can answer.
#
# Both places are written — the header WordPress reads and the constant the
# plugin reads about itself — because they drift apart otherwise, and the one
# that drifts is always the one nobody looks at.
#
# Usage:
#   bin/bump.sh                  what would change, and nothing else
#   bin/bump.sh --apply          write it
#   bin/bump.sh --minor --apply  0.1.3 -> 0.2.0 rather than 0.1.4
#   bin/bump.sh --major --apply  0.1.3 -> 1.0.0
#   bin/bump.sh --all --apply    every plugin, changed or not

set -eu

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

apply=0
all=0
part=patch

for flag in "$@"; do
	case "$flag" in
		--apply) apply=1 ;;
		--all)   all=1 ;;
		--minor) part=minor ;;
		--major) part=major ;;
		*) echo "unknown flag: $flag" >&2; exit 1 ;;
	esac
done

tag="$(git describe --tags --abbrev=0 2>/dev/null || true)"

if [ -z "$tag" ]; then
	echo "no tag to compare against yet — tag the first release before using this" >&2
	exit 1
fi

echo "since $tag"
echo

changed=0

for folder in plugins/*/; do
	slug="${folder%/}"
	slug="${slug#plugins/}"
	main="plugins/$slug/$slug.php"

	[ -f "$main" ] || continue

	# Committed since the tag, and anything not committed yet: a release is
	# usually tagged in the same breath as the work that goes into it. Files
	# that are new count too — git diff says nothing about a file it has
	# never been told of, and a plugin whose only change is a new file would
	# otherwise go out at its old version.
	touched="$( {
		git diff --name-only "$tag" -- "plugins/$slug"
		git ls-files --others --exclude-standard -- "plugins/$slug"
	} | sort -u | wc -l | tr -d ' ' )"

	if [ "$touched" = "0" ] && [ "$all" = "0" ]; then
		continue
	fi

	now="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\)[[:space:]]*$/\1/p' "$main" | head -1)"

	if [ -z "$now" ]; then
		echo "$slug: no Version header" >&2
		continue
	fi

	# Already raised since the tag — running this twice before tagging must
	# not carry the version two steps away from the work it stands for.
	was="$(git show "$tag:$main" 2>/dev/null \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\)[[:space:]]*$/\1/p' \
		| head -1)"

	if [ -n "$was" ] && [ "$was" != "$now" ]; then
		printf '%-20s %-8s already raised from %s\n' "$slug" "$now" "$was"
		continue
	fi

	major="${now%%.*}"
	rest="${now#*.}"
	minor="${rest%%.*}"
	patch="${rest#*.}"
	[ "$patch" = "$rest" ] && patch=0

	case "$part" in
		major) next="$((major + 1)).0.0" ;;
		minor) next="$major.$((minor + 1)).0" ;;
		patch) next="$major.$minor.$((patch + 1))" ;;
	esac

	if [ "$touched" = "0" ]; then
		printf '%-20s %-8s -> %-8s (unchanged, --all)\n' "$slug" "$now" "$next"
	else
		printf '%-20s %-8s -> %-8s %s files\n' "$slug" "$now" "$next" "$touched"
	fi

	changed=$((changed + 1))

	[ "$apply" = "1" ] || continue

	# The header WordPress reads...
	sed -i "s/^\([[:space:]]*\*[[:space:]]*Version:[[:space:]]*\)$now[[:space:]]*$/\1$next/" "$main"
	# ...and the constant the plugin reads about itself.
	sed -i "s/\(define([[:space:]]*'[A-Z_]*_VERSION',[[:space:]]*'\)$now\('[[:space:]]*)\)/\1$next\2/" "$main"
done

if [ "$changed" = "0" ]; then
	echo "nothing to raise since $tag"
	exit 0
fi

if [ "$apply" = "1" ]; then
	echo
	echo "written. commit, then tag the release"
else
	echo
	echo "nothing written — add --apply"
fi
