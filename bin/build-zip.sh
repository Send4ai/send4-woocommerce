#!/usr/bin/env bash
# Build the zip that gets uploaded to a site or to wordpress.org.
set -euo pipefail

cd "$(dirname "$0")/.."

# Refuses a zip whose three version strings disagree; see the script.
VERSION="$(bin/check-version.sh)"

BUILD_DIR="build"
STAGE="${BUILD_DIR}/send4-for-woocommerce"

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE}"

# rsync honours .distignore the way the wordpress.org deploy actions do.
rsync -a --exclude-from=.distignore ./ "${STAGE}/"

# A copy of each of our own assets with the version in its name, which
# Send4WC_Assets::url() prefers. `?ver=` does not reach a CDN that ignores the
# query string; a new file name does.
for asset in admin/css/*.css; do
	[ -f "${STAGE}/${asset}" ] || continue
	cp "${STAGE}/${asset}" "${STAGE}/${asset%.*}.${VERSION}.${asset##*.}"
done

( cd "${BUILD_DIR}" && zip -qr "send4-for-woocommerce.zip" "send4-for-woocommerce" )

echo "Built ${BUILD_DIR}/send4-for-woocommerce.zip (version ${VERSION})"
