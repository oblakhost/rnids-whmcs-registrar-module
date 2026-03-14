#!/usr/bin/env bash

set -euo pipefail
shopt -s nullglob

COMPOSER_FILE="composer.json"
RELEASE_ROOT="/tmp/release"

SLUG=""
TYPE=""
VERSION=""

log_info() {
  echo "[release] $*"
}

log_error() {
  echo "[release] ERROR: $*" >&2
}

usage() {
  cat <<USAGE
Usage: $0 <version>

Builds WHMCS release packages under: $RELEASE_ROOT
USAGE
}

require_version_arg() {
  VERSION="${1:-}"

  if [[ -z "$VERSION" ]]; then
    usage
    exit 1
  fi
}

load_release_metadata() {
  SLUG="$(jq -r '.extra.whmcs.slug // empty' "$COMPOSER_FILE")"
  TYPE="$(jq -r '.extra.whmcs.type // empty' "$COMPOSER_FILE")"

  if [[ -z "$SLUG" || -z "$TYPE" ]]; then
    log_error "Missing '.extra.whmcs.slug' or '.extra.whmcs.type' in $COMPOSER_FILE"
    exit 1
  fi
}

prepare_release_root() {
  rm -rf "$RELEASE_ROOT"
  mkdir -p "$RELEASE_ROOT"
}

render_destination_path() {
  local dst_template="$1"

  sed \
    "s|{slug}|$SLUG|g; s|{type}|$TYPE|g; s|{ver}|$VERSION|g" \
    <<<"$dst_template"
}

update_whmcs_version_if_present() {
  local copied_source="$1"
  local destination="$2"

  if [[ "$copied_source" == "whmcs.json" ]]; then
    log_info "Updating version in $destination/whmcs.json"
    sed -i "s/0.0.0-dev/$VERSION/g" "$destination/whmcs.json"
  fi
}

copy_sources_for_pattern() {
  local file_pattern="$1"
  local destination="$2"
  local source_files=()

  source_files=( $file_pattern )

  if [[ "${#source_files[@]}" -eq 0 ]]; then
    log_error "No files matched pattern: $file_pattern"
    exit 1
  fi

  for source_file in "${source_files[@]}"; do
    log_info "Copying $source_file -> $destination/"
    cp -R "$source_file" "$destination/"
    update_whmcs_version_if_present "$source_file" "$destination"
  done
}

process_package() {
  local package_json="$1"
  local dst_template=""
  local rendered_dst=""
  local full_destination=""
  local file_patterns=()

  dst_template="$(jq -r '.dst // empty' <<<"$package_json")"
  if [[ -z "$dst_template" ]]; then
    log_error "Package is missing 'dst' value"
    exit 1
  fi

  rendered_dst="$(render_destination_path "$dst_template")"
  full_destination="$RELEASE_ROOT/$rendered_dst"

  log_info "Processing package with destination: $full_destination"
  mkdir -p "$full_destination"

  mapfile -t file_patterns < <(jq -r '.src[]?' <<<"$package_json")
  if [[ "${#file_patterns[@]}" -eq 0 ]]; then
    log_error "Package is missing 'src' file patterns"
    exit 1
  fi

  local file_pattern
  for file_pattern in "${file_patterns[@]}"; do
    copy_sources_for_pattern "$file_pattern" "$full_destination"
  done
}

main() {
  require_version_arg "${1:-}"
  load_release_metadata

  log_info "Releasing version $VERSION"
  prepare_release_root

  local packages=()
  mapfile -t packages < <(jq -c '.extra.whmcs.data[]?' "$COMPOSER_FILE")

  if [[ "${#packages[@]}" -eq 0 ]]; then
    log_error "No '.extra.whmcs.data' entries found in $COMPOSER_FILE"
    exit 1
  fi

  local package
  for package in "${packages[@]}"; do
    process_package "$package"
  done

  pushd "$RELEASE_ROOT" > /dev/null
  zip -qr /tmp/package.zip ./
  mv "/tmp/package.zip" "$RELEASE_ROOT/"
  log_info "Release package created at: $RELEASE_ROOT/package.zip"
  popd > /dev/null
}

main "$@"
