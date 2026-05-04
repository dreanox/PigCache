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

# Read current version from plugin header
VERSION=$(grep -m1 "Version:" "${PLUGIN_DIR}/pigcache.php" \
  | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
  echo "ERROR: could not read Version from pigcache.php" >&2
  exit 1
fi

# -----------------------------------------------------------------------
# Version bump
# -----------------------------------------------------------------------
IFS='.' read -r _V_MAJOR _V_MINOR _V_PATCH <<< "${VERSION}"

echo ""
echo "PigCache — current version: ${VERSION}"
echo ""
echo "  Release type:"
echo "    1) patch  → ${_V_MAJOR}.${_V_MINOR}.$((_V_PATCH + 1))   (bug fix / maintenance)"
echo "    2) minor  → ${_V_MAJOR}.$((_V_MINOR + 1)).0              (new feature)"
echo "    3) major  → $((_V_MAJOR + 1)).0.0                        (breaking change)"
echo "    4) manual → enter version yourself"
echo "    5) skip   → keep ${VERSION} as-is (files already updated)"
echo ""
read -r -p "  Choose [1-5]: " _bump_type

case "${_bump_type}" in
  1|"") NEW_VERSION="${_V_MAJOR}.${_V_MINOR}.$((_V_PATCH + 1))" ;;
  2)    NEW_VERSION="${_V_MAJOR}.$((_V_MINOR + 1)).0" ;;
  3)    NEW_VERSION="$((_V_MAJOR + 1)).0.0" ;;
  4)
    read -r -p "  Enter new version (e.g. 1.2.0): " NEW_VERSION
    NEW_VERSION="${NEW_VERSION//[[:space:]]/}"
    if [[ -z "${NEW_VERSION}" ]]; then
      echo "  No version entered. Aborting." >&2; exit 1
    fi
    ;;
  5)    NEW_VERSION="${VERSION}" ;;
  *)    echo "  Invalid choice. Aborting." >&2; exit 1 ;;
esac

if [[ "${NEW_VERSION}" != "${VERSION}" ]]; then
  echo ""
  read -r -p "  Changelog summary for ${NEW_VERSION} (one line): " _changelog_summary

  echo ""
  echo "  About to update:"
  echo "    pigcache.php  →  Version: ${NEW_VERSION}"
  echo "    pigcache.php  →  PIGCACHE_VERSION '${NEW_VERSION}'"
  echo "    readme.txt    →  Stable tag: ${NEW_VERSION}"
  echo "    readme.txt    →  Changelog entry"
  echo ""
  read -r -p "  Apply? [Y/n] " _confirm_bump
  case "${_confirm_bump}" in
    [Yy]|"") ;;
    *) echo "  Aborted."; exit 1 ;;
  esac

  # 1. Plugin header  (` * Version: x.x.x`)
  sed -i "s|^ \* Version: .*| \* Version: ${NEW_VERSION}|" "${PLUGIN_DIR}/pigcache.php"

  # 2. PIGCACHE_VERSION constant
  sed -i "s|define( 'PIGCACHE_VERSION', '[^']*' )|define( 'PIGCACHE_VERSION', '${NEW_VERSION}' )|" \
    "${PLUGIN_DIR}/pigcache.php"

  # 3. Stable tag in readme.txt
  sed -i "s|^Stable tag: .*|Stable tag: ${NEW_VERSION}|" "${PLUGIN_DIR}/readme.txt"

  # 4. Changelog entry — inserted right after == Changelog == heading
  sed -i "s|^== Changelog ==\$|== Changelog ==\n\n= ${NEW_VERSION} =\n* ${_changelog_summary}|" \
    "${PLUGIN_DIR}/readme.txt"

  VERSION="${NEW_VERSION}"
  echo "  Version bumped to ${VERSION}."
fi

echo ""
echo "PigCache ${VERSION} — building..."

mkdir -p "${DIST_DIR}"

# -----------------------------------------------------------------------
# PHP class files present ONLY in the Pro build.
# Paths relative to the plugin root.
# -----------------------------------------------------------------------
PRO_ONLY_FILES=(
  "includes/pro"
  # The entire includes/pro/ subtree is Pro-only; listing the folder is enough
  # because the strip loop below removes it with rm -rf for the Free build.
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

  # ---- Strip Pro-only files for the Free build ------------------------------
  if [[ "${label}" == "free" ]]; then
    for f in "${PRO_ONLY_FILES[@]}"; do
      rm -rf "${staging}/${f}"
    done

    # Strip PRO_START…PRO_END blocks from dual-source files.
    sed -i '/\/\/ ── PRO_START/,/\/\/ ── PRO_END/d' \
      "${staging}/includes/class-pigcache-admin.php"
  fi

  # ---- Inject cron script (bin/ is excluded from rsync via .distignore) ------
  # Pro: ship as-is. Free: strip Pro-only sections first.
  # build.sh itself never ships.
  mkdir -p "${staging}/bin"
  if [[ "${label}" == "pro" ]]; then
    cp "${PLUGIN_DIR}/bin/pigcache-cron.php" "${staging}/bin/pigcache-cron.php"
  else
    sed '/\/\/ ── PRO_START/,/\/\/ ── PRO_END/d' \
      "${PLUGIN_DIR}/bin/pigcache-cron.php" > "${staging}/bin/pigcache-cron.php"
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
