#!/usr/bin/env bash
# Check that a release is consistent, and print its version.
#
#   bin/check-version.sh           the three version strings agree
#   bin/check-version.sh v0.2.0    ... and the tag is that version
#
# Three places carry the version and nothing else keeps them in step. The
# header is what WordPress offers as an update, SEND4WC_VERSION busts asset
# caches, and the readme's Stable tag is what WordPress.org serves — a zip
# where they disagree installs as one version while behaving like another.
# The release workflow runs this against the pushed tag, because a tag cannot
# be moved once WordPress.org has it.
#
# Prints the version on stdout and nothing else; every complaint goes to stderr.
set -euo pipefail

cd "$(dirname "$0")/.."

fail() {
	echo "$1" >&2
	exit 1
}

VERSION="$(grep -m1 '^ \* Version:' send4-for-woocommerce.php | awk '{print $3}')"
CONSTANT="$(sed -n "s/^define( 'SEND4WC_VERSION', '\([^']*\)' );$/\1/p" send4-for-woocommerce.php)"
STABLE="$(grep -m1 '^Stable tag:' readme.txt | awk '{print $3}')"

if [ -z "${VERSION}" ] || [ "${VERSION}" != "${CONSTANT}" ] || [ "${VERSION}" != "${STABLE}" ]; then
	fail "Version mismatch: header '${VERSION}', SEND4WC_VERSION '${CONSTANT}', readme Stable tag '${STABLE}'."
fi

# WordPress.org compares versions with version_compare(), and a tag is cut from
# this string, so it stays plain X.Y.Z.
if ! [[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	fail "Version '${VERSION}' is not X.Y.Z."
fi

# The changelog entry is what the GitHub release and the directory's Changelog
# tab show for this version.
if ! grep -qxF "= ${VERSION} =" readme.txt; then
	fail "readme.txt has no changelog entry '= ${VERSION} ='."
fi

if [ "$#" -ge 1 ]; then
	TAG="$1"

	if [ "${TAG}" != "v${VERSION}" ]; then
		fail "Tag '${TAG}' does not match the version in the code, '${VERSION}' (expected 'v${VERSION}')."
	fi
fi

echo "${VERSION}"
