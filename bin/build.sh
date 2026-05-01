#!/usr/bin/env bash
# -----------------------------------------------------------------------
# PigCache build script — generates Free and Pro distribution ZIPs.
#
# Usage:
#   ./bin/build.sh          # builds both
#   ./bin/build.sh free     # Free ZIP only
#   ./bin/build.sh pro      # Pro ZIP only
#
# Output: dist/pigcache-free-<version>.zip
#         dist/pigcache-pro-<version>.zip
#
# WordPress.org: upload ONLY the ZIP from dist/ (built with this script). Do not zip the
# repository root — .git/ and dist/*.zip must never appear inside the uploaded plugin.
# class-pigcache-updates.php is Pro-only: .org forbids filtering site_transient_update_plugins.
#
# Requirements: bash, rsync, zip
# On Windows: run from Git Bash or WSL
# -----------------------------------------------------------------------

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${PLUGIN_DIR}/dist"

# Read version from plugin header
VERSION=$(grep -m1 "Version:" "${PLUGIN_DIR}/pigcache.php" \
  | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
  echo "ERROR: could not read Version from pigcache.php" >&2
  exit 1
fi

echo ""
echo "PigCache ${VERSION} — pre-build checklist"
echo ""
echo "  Before exporting, confirm that the version has been updated in:"
echo "    1. pigcache.php      → Plugin header  (Version: x.x.x)"
echo "    2. pigcache.php      → PIGCACHE_VERSION constant"
echo "    3. readme.txt        → Stable tag"
echo "    4. readme.txt        → Changelog entry"
echo ""
read -r -p "  Have you updated the version in all of the above? [Y/n] " _confirm
case "${_confirm}" in
  [Yy]|"") ;;
  *)
    echo ""
    echo "  Build cancelled. Update the version and run again."
    exit 1
    ;;
esac
echo ""
echo "PigCache ${VERSION} — building..."

mkdir -p "${DIST_DIR}"

# -----------------------------------------------------------------------
# PHP class files present ONLY in the Pro build.
# Paths relative to the plugin root.
# -----------------------------------------------------------------------
PRO_ONLY_FILES=(
  "includes/class-pigcache-environment.php"
  "includes/class-pigcache-cloud-client.php"
  "includes/class-pigcache-cloud-sync.php"
  "includes/class-pigcache-sql-profile-store.php"
  "includes/class-pigcache-sql-profiler.php"
  "includes/class-pigcache-updates.php"
  "includes/class-pigcache-query-buffer.php"
  "includes/class-pigcache-query-stats.php"
  "includes/class-pigcache-continuous-learner.php"
  "includes/class-pigcache-admin-pro.php"
  "includes/class-pigcache-tag-index.php"
  "includes/class-pigcache-tag-collector.php"
)

# -----------------------------------------------------------------------
# Helper: build one ZIP into dist/
#   $1 = label ("free" or "pro")
# -----------------------------------------------------------------------
build_zip() {
  local label="$1"
  local zip_name="pigcache-${VERSION}.zip"
  [[ "${label}" == "pro" ]] && zip_name="pigcache-pro-${VERSION}.zip"
  local staging_root="${DIST_DIR}/.staging-${label}"
  local staging="${staging_root}/pigcache"

  echo ""
  echo "  → Building ${zip_name} ..."

  # Clean & recreate staging dir
  rm -rf "${staging_root}"
  mkdir -p "${staging}"

  # ---- Copy plugin, honouring .distignore --------------------------------
  # .distignore already excludes: CHANGELOG, CACHE-FLOW, MANUAL, CLI,
  # composer manifests, IDE files, and all README* variants.
  rsync -rl --no-perms --no-owner --no-group \
    --exclude-from="${PLUGIN_DIR}/.distignore" \
    "${PLUGIN_DIR}/" "${staging}/"

  # ---- Strip vendor dev metadata (keep only what PHP needs at runtime) ---
  # composer/ autoload scripts ship; autoload_*.php files are fine.
  # Only remove files no WordPress site ever needs.
  rm -f  "${staging}/vendor/composer/installed.json"
  rm -f  "${staging}/vendor/composer/installed.php"
  rm -rf "${staging}/vendor/composer/installers" 2>/dev/null || true

  # ---- Inject the correct README as README.md ----------------------------
  if [[ "${label}" == "free" ]]; then
    cp "${PLUGIN_DIR}/README-free.md" "${staging}/README.md"
  else
    cp "${PLUGIN_DIR}/README-pro.md" "${staging}/README.md"
  fi

  # ---- Strip Pro-only PHP files for the Free build -----------------------
  if [[ "${label}" == "free" ]]; then
    for f in "${PRO_ONLY_FILES[@]}"; do
      rm -f "${staging}/${f}"
    done
  fi

  # ---- Inject Pro-only bin files (bin/ is excluded from rsync via .distignore) ---
  # pigcache-cron.php is a standalone CLI script shipped only in the Pro build.
  # build.sh itself never ships.
  if [[ "${label}" == "pro" ]]; then
    mkdir -p "${staging}/bin"
    cp "${PLUGIN_DIR}/bin/pigcache-cron.php" "${staging}/bin/pigcache-cron.php"
  fi

  # ---- Create ZIP (pigcache/ wrapper folder required by wordpress.org) ---
  # Remove any previous ZIP so we never merge stale entries from an old build.
  rm -f "${DIST_DIR}/${zip_name}"
  (cd "${staging_root}" && zip -rq "${DIST_DIR}/${zip_name}" pigcache/)

  # Cleanup
  rm -rf "${staging_root}"

  echo "     Done → dist/${zip_name}"
}

# -----------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------
TARGET="${1:-both}"

case "${TARGET}" in
  free) build_zip free ;;
  pro)  build_zip pro  ;;
  both) build_zip free; build_zip pro ;;
  *)
    echo "Usage: $0 [free|pro|both]" >&2
    exit 1
    ;;
esac

echo ""
echo "Build complete."
