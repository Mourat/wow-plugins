#!/usr/bin/env bash
#
# Brings the plugins back from a site into this repository.
#
# The site is where they are worked on and the repository is where they are
# released from, so code travels one way and versions travel the other. A
# plain copy gets the first half right and the second half backwards: a site
# that has not taken the last release yet is still carrying an older version
# number, and copying it in takes the repository's version down with it.
#
# What follows from that is quiet and nasty. The next release goes out at a
# number below one already published, every site compares it against what it
# has installed, finds it no newer, and does nothing — and the work sits in a
# release nobody can see.
#
# So the code is copied and the version is kept: whatever the repository has
# committed wins over whatever arrives, unless what arrives is actually
# higher, which means somebody raised it on purpose and is left alone.
#
# Usage:
#   bin/sync.sh ~/projects/ArleaMH/wp-content/plugins
#   bin/sync.sh ~/projects/ArleaMH/wp-content/plugins wow-blocks wow-guard
#   bin/sync.sh ~/projects/ArleaMH/wp-content/themes wow-theme

set -eu

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if [ "$#" -lt 1 ]; then
	echo "usage: bin/sync.sh <site plugins directory> [slug ...]" >&2
	exit 1
fi

from="${1%/}"
shift

if [ ! -d "$from" ]; then
	echo "no such directory: $from" >&2
	exit 1
fi

# Which half of the repository this directory belongs to. A site keeps its
# plugins and its themes in folders of those names, and so does this.
case "$( basename "$from" )" in
	themes) area="themes" ;;
	*)      area="plugins" ;;
esac

# Named folders, or every one this repository already knows about.
if [ "$#" -gt 0 ]; then
	slugs=( "$@" )
else
	slugs=()
	for folder in "$area"/*/; do
		[ -d "$folder" ] || continue
		slugs+=( "$( basename "${folder%/}" )" )
	done
fi

# A plugin writes its version inside a comment block, a theme writes it at
# the start of a line in style.css; the star is therefore optional.
version_in() {
	sed -n 's/^[[:space:]]*\*\{0,1\}[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\)[[:space:]]*$/\1/p' | head -1
}

copied=0
kept=0

for slug in "${slugs[@]}"; do
	if [ "$area" = "themes" ]; then
		main="themes/$slug/style.css"
	else
		main="plugins/$slug/$slug.php"
	fi

	if [ ! -d "$from/$slug" ]; then
		printf '%-20s not on the site — skipped\n' "$slug"
		continue
	fi

	# What the repository has committed, before anything is copied over it.
	mine="$( git show "HEAD:$main" 2>/dev/null | version_in || true )"

	rsync -a --delete \
		--exclude node_modules --exclude .git --exclude build/ \
		"$from/$slug/" "$area/$slug/"

	theirs="$( version_in < "$main" 2>/dev/null || true )"
	note=""

	if [ -n "$mine" ] && [ -n "$theirs" ] && [ "$mine" != "$theirs" ]; then
		lower="$( printf '%s\n%s\n' "$mine" "$theirs" | sort -V | head -1 )"

		if [ "$lower" = "$theirs" ]; then
			# The site is behind. Put the repository's version back, in the
			# header and in the plugin's own constant alike.
			sed -i "s/^\([[:space:]]*\*\{0,1\}[[:space:]]*Version:[[:space:]]*\)$theirs[[:space:]]*$/\1$mine/" "$main"
			sed -i "s/\(define([[:space:]]*'[A-Z_]*_VERSION',[[:space:]]*'\)$theirs\('[[:space:]]*)\)/\1$mine\2/" "$main"
			note="version kept at $mine (site had $theirs)"
			kept=$(( kept + 1 ))
		else
			note="version raised on the site to $theirs — left alone"
		fi
	fi

	printf '%-20s copied%s\n' "$slug" "${note:+ — $note}"
	copied=$(( copied + 1 ))
done

# Anything on the site this repository has never heard of is worth saying
# out loud: adding a plugin is a decision, not a side effect of copying.
for folder in "$from"/wow-*/; do
	slug="$( basename "${folder%/}" )"

	if [ ! -d "$area/$slug" ]; then
		printf '%-20s on the site but not in this repository\n' "$slug"
	fi
done

printf '\n%d copied, %d with the version kept\n' "$copied" "$kept"

if ! git diff --quiet || [ -n "$( git ls-files --others --exclude-standard )" ]; then
	echo
	git diff --stat | tail -12
fi
