#!/bin/sh
# Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License,
# or (at your option) any later version.

set -eu

module_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
module_parent=$(dirname -- "$module_root")
version=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" "$module_root/core/modules/modKreaProducts.class.php" | head -n 1)

if [ -z "$version" ]; then
	echo "Unable to resolve the KreaProducts module version." >&2
	exit 1
fi

destination="$module_root/bin/module_kreaproducts-$version.zip"
temporary=$(mktemp "${TMPDIR:-/tmp}/module_kreaproducts-${version}.XXXXXX.zip")
allowlist="$module_root/build/makepack-kreaproducts.conf"
trap 'rm -f "$temporary"' EXIT HUP INT TERM
rm -f "$temporary"

if [ ! -r "$allowlist" ]; then
	echo "Release allowlist is not readable: $allowlist" >&2
	exit 1
fi

cd "$module_parent"
set --
while IFS= read -r entry || [ -n "$entry" ]; do
	case "$entry" in
		''|'#'*)
			continue
			;;
		*'*'*|*'?'*|*'['*)
			matched=0
			for expanded in $entry; do
				if [ ! -e "$expanded" ]; then
					continue
				fi
				set -- "$@" "$expanded"
				matched=1
			done
			if [ "$matched" -ne 1 ]; then
				echo "Release allowlist pattern matched no files: $entry" >&2
				exit 1
			fi
			;;
		*)
			if [ ! -e "$entry" ]; then
				echo "Release allowlist entry does not exist: $entry" >&2
				exit 1
			fi
			set -- "$@" "$entry"
			;;
	esac
done < "$allowlist"

if [ "$#" -eq 0 ]; then
	echo "Release allowlist is empty." >&2
	exit 1
fi

/usr/bin/zip -q -r "$temporary" "$@" -x '*/.DS_Store'

mkdir -p "$module_root/bin"
mv "$temporary" "$destination"
trap - EXIT HUP INT TERM

echo "$destination"
