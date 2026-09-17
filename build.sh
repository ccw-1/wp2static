#!/bin/sh
set -e

PLUGIN="wp2static"
DIST="build"
VERSION="${1:-$(grep -m1 'Stable tag:' "${PLUGIN}/readme.txt" | sed 's/.*Stable tag:[[:space:]]*//')}"
ZIP="${DIST}/${PLUGIN}-${VERSION}.zip"

rm -rf "${DIST}" "${PLUGIN}.build"
mkdir -p "${DIST}" "${PLUGIN}.build"

if git ls-tree HEAD --name-only -- "${PLUGIN}" | grep -q .; then
  # Fresh copy of the plugin from the last commit (no build artifacts)
  git archive HEAD -- "${PLUGIN}" | tar -x -C "${PLUGIN}.build"
else
  # Plugin not committed yet: copy from the working tree
  cp -R "${PLUGIN}" "${PLUGIN}.build/${PLUGIN}"
fi

# WordPress expects the plugin files inside a folder named after the plugin
cd "${PLUGIN}.build"
zip -rq "../${ZIP}" "${PLUGIN}"
cd ..
rm -rf "${PLUGIN}.build"

echo "Created ${ZIP}"