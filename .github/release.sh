#!/usr/bin/env bash

set -euo pipefail
shopt -s nullglob

VERSION="${1:-}"
RELEASE_ROOT="/tmp/release"

echo "Releasing version $VERSION"

rm -rf "$RELEASE_ROOT"
mkdir -p "$RELEASE_ROOT"

mapfile -t PACKAGES < <(jq -c '.extra.whmcs[]' composer.json)

for PACKAGE in "${PACKAGES[@]}"; do
  PACKAGE_PATH="$(jq -r '.path' <<<"$PACKAGE")"
  DESTINATION_PATH="$RELEASE_ROOT/$PACKAGE_PATH"

  echo "Preparing package path: $PACKAGE_PATH"
  mkdir -p "$DESTINATION_PATH"

  mapfile -t FILE_PATTERNS < <(jq -r '.files[]' <<<"$PACKAGE")

  for FILE_PATTERN in "${FILE_PATTERNS[@]}"; do
    MATCHES=( $FILE_PATTERN )

    if [ "${#MATCHES[@]}" -eq 0 ]; then
      echo "No files matched pattern: $FILE_PATTERN" >&2
      exit 1
    fi

    for MATCH in "${MATCHES[@]}"; do
      echo "Copying $MATCH -> $DESTINATION_PATH/"
      cp -R "$MATCH" "$DESTINATION_PATH/"
    done
  done
done

# sed -i "s/0.0.0-dev/$VERSION/g" whmcs.json
