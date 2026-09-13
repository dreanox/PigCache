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
# The Free build runs a compliance gate before zipping: it fails if any Pro or
# licensing identifier survived the PRO_START/PRO_END strip, or if a shipped PHP
# file does not parse. Upload only after that gate passes.
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

# In-place sed. GNU sed wants `-i`, BSD/macOS sed wants `-i ''`.
if sed --version >/dev/null 2>&1; then
  sed_i() { sed -i "$@"; }
else
  sed_i() { sed -i '' "$@"; }
fi

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
  sed_i "s|^ \* Version: .*| \* Version: ${NEW_VERSION}|" "${PLUGIN_DIR}/pigcache.php"

  # 2. PIGCACHE_VERSION constant
  sed_i "s|define( 'PIGCACHE_VERSION', '[^']*' )|define( 'PIGCACHE_VERSION', '${NEW_VERSION}' )|" \
    "${PLUGIN_DIR}/pigcache.php"

  # 3. Stable tag in readme.txt
  sed_i "s|^Stable tag: .*|Stable tag: ${NEW_VERSION}|" "${PLUGIN_DIR}/readme.txt"

  # 4. Changelog entry — inserted right after == Changelog == heading.
  #    awk instead of sed: multi-line replacements are not portable across
  #    GNU and BSD sed.
  awk -v ver="${NEW_VERSION}" -v summary="${_changelog_summary}" '
    { print }
    /^== Changelog ==$/ && !done { print ""; print "= " ver " ="; print "* " summary; done = 1 }
  ' "${PLUGIN_DIR}/readme.txt" > "${PLUGIN_DIR}/readme.txt.tmp" \
    && mv "${PLUGIN_DIR}/readme.txt.tmp" "${PLUGIN_DIR}/readme.txt"

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
  local zip_name="pigcache-free-${VERSION}.zip"
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
    for _pro_file in \
      "${staging}/pigcache.php" \
      "${staging}/includes/class-pigcache-admin.php" \
      "${staging}/includes/class-pigcache-html-cache.php" \
      "${staging}/includes/class-pigcache-invalidation.php" \
      "${staging}/includes/class-pigcache-wpdb.php" \
      "${staging}/includes/class-pigcache-fragments.php" \
      "${staging}/includes/class-pigcache-sql-cache.php" \
      "${staging}/includes/class-pigcache-plugin.php" \
    ; do
      sed_i '/\/\/ ── PRO_START/,/\/\/ ── PRO_END/d' "${_pro_file}"
    done
    unset _pro_file
  fi

  # ---- Inject cron script (bin/ is excluded from rsync via .distignore) ------
  # Pro only: the standalone cron uses direct mysqli (no WordPress bootstrap) and
  # handles Pro-only pipelines (query stats, cloud sync, fingerprints, adaptive TTL).
  # The Free build has none of those features so the script is not included.
  if [[ "${label}" == "pro" ]]; then
    mkdir -p "${staging}/bin"
    cp "${PLUGIN_DIR}/bin/pigcache-cron.php" "${staging}/bin/pigcache-cron.php"
  fi

  # ---- Inject Python CLI tooling (cli/ is excluded from rsync via .distignore)
  # Pro-only: pigcache-monitor.py is an ops-grade Redis+MySQL probe targeted
  # at high-traffic deployments (the Pro persona). It has no Pro PHP-side
  # dependencies — it reads Redis INFO/SCAN and MySQL status directly — so it
  # ships verbatim with no PRO_START/END stripping. Falls back to a stdlib
  # RESP client when redis-py is not installed on the host.
  if [[ "${label}" == "pro" ]]; then
    mkdir -p "${staging}/cli"
    cp "${PLUGIN_DIR}/cli/pigcache-monitor.py" "${staging}/cli/pigcache-monitor.py"
    chmod +x "${staging}/cli/pigcache-monitor.py"
  fi

  # ---- Compliance gate for the Free build -------------------------------
  # WordPress.org Guideline 5 forbids shipping any mechanism that unlocks
  # built-in features. Fail the build rather than upload a rejectable ZIP.
  if [[ "${label}" == "free" ]]; then
    echo "     Checking WordPress.org compliance ..."

    local violations=0

    if [[ -d "${staging}/includes/pro" ]]; then
      echo "     ✗ includes/pro/ is present in the Free staging tree" >&2
      violations=1
    fi

    # Pro class and licensing identifiers must leave no trace.
    for _pattern in \
      'PigCache_License' \
      'pigcache_license' \
      'PigCache_Cloud_' \
      'PigCache_Sql_Profiler' \
      'PigCache_Continuous_Learner' \
      'PigCache_Adaptive_Ttl' \
      'PigCache_Updates' \
      'PRO_START' \
      'sys_get_temp_dir' \
    ; do
      if grep -rqI --exclude-dir=vendor -- "${_pattern}" "${staging}"; then
        echo "     ✗ Free build still references '${_pattern}':" >&2
        grep -rnI --exclude-dir=vendor -- "${_pattern}" "${staging}" | sed 's|^|       |' >&2
        violations=1
      fi
    done
    unset _pattern

    # Every shipped PHP file must parse. Skipped when no PHP binary is on PATH
    # so the build still works on machines that only package the plugin.
    if command -v php >/dev/null 2>&1; then
      find "${staging}" -name '*.php' -not -path '*/vendor/*' -print > "${staging_root}/php-files.txt"
      while IFS= read -r _php_file; do
        [[ -n "${_php_file}" ]] || continue
        if ! php -l "${_php_file}" >/dev/null 2>&1; then
          echo "     ✗ PHP syntax error in ${_php_file#"${staging}/"}" >&2
          violations=1
        fi
      done < "${staging_root}/php-files.txt"
      rm -f "${staging_root}/php-files.txt"
      unset _php_file
    else
      echo "     ! php not found on PATH — skipping syntax check" >&2
    fi

    if [[ "${violations}" -ne 0 ]]; then
      echo "" >&2
      echo "     Build aborted: the Free package is not WordPress.org compliant." >&2
      exit 1
    fi

    echo "     ✓ Free package is clean"
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
