#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="pat-product-emailer"
DIST_DIR="${REPO_DIR}/dist"
BUILD_ROOT="$(mktemp -d)"
STAGING_DIR="${BUILD_ROOT}/${PLUGIN_SLUG}"

cleanup() {
  rm -rf "${BUILD_ROOT}"
}
trap cleanup EXIT

mkdir -p "${DIST_DIR}" "${STAGING_DIR}"

cp "${REPO_DIR}/pat-product-emailer.php" "${STAGING_DIR}/"
cp "${REPO_DIR}/README.md" "${STAGING_DIR}/"

(
  cd "${BUILD_ROOT}"
  rm -f "${DIST_DIR}/${PLUGIN_SLUG}.zip"
  zip -rq "${DIST_DIR}/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

echo "Built ${DIST_DIR}/${PLUGIN_SLUG}.zip"
