#!/usr/bin/env bash
#
# PigCache test runner.
#
# Works the same on macOS, Linux and WSL. The only hard requirement for the unit
# suite is PHP; everything else runs inside Docker so no local MySQL, Redis or
# WordPress is needed.
#
# Usage:
#   bash tests/run.sh              # unit + integration + resilience
#   bash tests/run.sh unit         # fast, no Docker
#   bash tests/run.sh integration  # WordPress + MySQL + Redis in Docker
#   bash tests/run.sh resilience   # the site must survive Redis going away
#   bash tests/run.sh e2e          # Playwright against the Docker site
#   bash tests/run.sh free         # same suites against the built Free ZIP
#   bash tests/run.sh pro          # same suites against the built Pro ZIP
#   bash tests/run.sh packages     # both built ZIPs
#   bash tests/run.sh up           # just bring the stack up
#   bash tests/run.sh down         # tear the stack down and wipe volumes

set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${TESTS_DIR}/.." && pwd)"
COMPOSE_FILE="${TESTS_DIR}/docker/docker-compose.yml"
# Port 9520 by default. Export PIGCACHE_TEST_PORT to move it if it collides.
export PIGCACHE_TEST_PORT="${PIGCACHE_TEST_PORT:-9520}"
SITE_URL="http://localhost:${PIGCACHE_TEST_PORT}"

# Plugin path as seen from inside the container.
CONTAINER_PLUGIN="/var/www/html/wp-content/plugins/pigcache"

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ! %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[31m  ✗ %s\033[0m\n' "$*" >&2; exit 1; }

compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose -f "${COMPOSE_FILE}" "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose -f "${COMPOSE_FILE}" "$@"
  else
    die "Docker Compose not found. Install Docker Desktop, or Docker Engine with the compose plugin."
  fi
}

need_docker() {
  command -v docker >/dev/null 2>&1 || die "docker not found on PATH."
  docker info >/dev/null 2>&1 || die "The Docker daemon is not running."
}

# ── Unit suite ────────────────────────────────────────────────────────────────

run_unit() {
  say "Unit suite (no WordPress, no Redis, no MySQL)"

  command -v php >/dev/null 2>&1 || die "php not found on PATH. The unit suite needs a PHP CLI."

  if [[ ! -x "${TESTS_DIR}/vendor/bin/phpunit" ]]; then
    command -v composer >/dev/null 2>&1 \
      || die "PHPUnit is not installed and composer is not on PATH. Run: cd tests && composer install"
    say "Installing PHPUnit into tests/vendor (kept out of the plugin's own vendor/)"
    ( cd "${TESTS_DIR}" && composer install --no-interaction )
  fi

  ( cd "${TESTS_DIR}" && ./vendor/bin/phpunit --configuration phpunit-unit.xml "$@" )
  ok "Unit suite passed"
}

# ── Docker stack ──────────────────────────────────────────────────────────────

stack_up() {
  need_docker
  say "Starting WordPress + MySQL + Redis"
  compose up -d --wait 2>/dev/null || compose up -d

  printf '  waiting for WordPress'
  for _ in $(seq 1 60); do
    if curl -sf "${SITE_URL}/wp-login.php" >/dev/null 2>&1; then
      printf '\n'
      ok "WordPress is up at ${SITE_URL}"
      return 0
    fi
    printf '.'
    sleep 3
  done
  printf '\n'
  die "WordPress did not come up. Try: bash tests/run.sh down && bash tests/run.sh up"
}

stack_down() {
  need_docker
  say "Tearing down the stack"
  compose down -v
  ok "Volumes removed"
}

# Installs WordPress and the drop-ins if this is a fresh stack.
ensure_installed() {
  if compose run --rm wpcli core is-installed >/dev/null 2>&1; then
    return 0
  fi
  say "First run — installing WordPress and the PigCache drop-ins"
  bash "${TESTS_DIR}/docker/setup.sh"
}

# ── Integration suite ─────────────────────────────────────────────────────────

run_integration() {
  need_docker
  stack_up
  ensure_installed

  say "Integration suite (live WordPress, MySQL and Redis)"

  # PHPUnit runs from tests/vendor, which is bind-mounted with the plugin, so
  # there is nothing to install inside the container.
  if [[ ! -x "${TESTS_DIR}/vendor/bin/phpunit" ]]; then
    ( cd "${TESTS_DIR}" && composer install --no-interaction )
  fi

  compose exec -T -u www-data wordpress \
    php "${CONTAINER_PLUGIN}/tests/vendor/bin/phpunit" \
      --configuration "${CONTAINER_PLUGIN}/tests/phpunit-integration.xml" \
      "$@"

  ok "Integration suite passed"
}

# ── Resilience ────────────────────────────────────────────────────────────────

# The failure mode that actually takes sites down: Redis goes away and the
# cache layer, which sits in front of everything, takes WordPress with it.
# The site must degrade to uncached-but-working, not to a 500.
run_resilience() {
  need_docker
  stack_up
  ensure_installed

  say "Resilience: the site must survive Redis going away"

  # The home page, following redirects: with pretty permalinks a ?p=N URL
  # answers 301 and would fail the check for the wrong reason.
  local url="${SITE_URL}/"

  local before
  before="$(curl -sL -o /dev/null -w '%{http_code}' "${url}")"
  [[ "${before}" == "200" ]] || die "Baseline request returned ${before}, expected 200"
  ok "Baseline request is 200"

  compose stop redis >/dev/null
  # The circuit breaker needs a request to trip; the first one may be slow.
  local degraded
  degraded="$(curl -sL -o /dev/null -w '%{http_code}' --max-time 30 "${url}")"
  local degraded_again
  degraded_again="$(curl -sL -o /dev/null -w '%{http_code}' --max-time 30 "${url}")"

  compose start redis >/dev/null
  sleep 3

  [[ "${degraded}" == "200" ]] \
    || die "With Redis down the site returned ${degraded}. It must fail open, not fail closed."
  [[ "${degraded_again}" == "200" ]] \
    || die "The second request with Redis down returned ${degraded_again}."
  ok "The site stays up with Redis down"

  local recovered
  recovered="$(curl -sL -o /dev/null -w '%{http_code}' --max-time 30 "${url}")"
  [[ "${recovered}" == "200" ]] || die "After Redis came back the site returned ${recovered}"
  ok "The site recovers when Redis comes back"
}

# ── Free package ──────────────────────────────────────────────────────────────

# Runs the suite against a built ZIP rather than the working tree.
#
# The working tree is neither package: it is the Pro source with the PRO markers
# still in place. build.sh strips whole blocks out of the Free build and rewrites
# headers in both, so passing tests on the tree say nothing about what actually
# ships unless the artefacts themselves are exercised.
#
# $1 — "free" or "pro"
run_package() {
  need_docker

  local edition="${1}"
  local stage="${PLUGIN_DIR}/dist/${edition}-tree"
  local zip
  zip="$(ls -t "${PLUGIN_DIR}"/dist/pigcache-"${edition}"-*.zip 2>/dev/null | head -1 || true)"

  if [[ -z "${zip}" ]]; then
    die "No ${edition} ZIP in dist/. Build one first: bash bin/build.sh"
  fi

  say "Unpacking $(basename "${zip}")"
  rm -rf "${stage}"
  mkdir -p "${stage}"
  unzip -q "${zip}" -d "${stage}"
  [[ -d "${stage}/pigcache" ]] || die "Unexpected ZIP layout: expected a top-level pigcache/ directory"

  # tests/ is excluded from the package by .distignore, so overlay it. This is
  # the only thing added to the extracted tree — the plugin code stays exactly
  # as it shipped.
  cp -R "${TESTS_DIR}" "${stage}/pigcache/tests"

  # Lets the suite tell a built package apart from the working tree. Some
  # assertions — no leftover PRO markers, no includes/pro in Free — only make
  # sense against an artefact.
  echo "${edition}" > "${stage}/pigcache/tests/.packaged-edition"

  ok "${edition} tree ready at dist/${edition}-tree/pigcache"

  # A different plugin tree means a different bind-mount, so the stack has to be
  # recreated rather than reused.
  say "Recreating the stack against the ${edition} package"
  compose down -v >/dev/null 2>&1 || true

  local plugin_root="${stage}/pigcache"
  (
    export PIGCACHE_SOURCE="${plugin_root}"
    stack_up
    ensure_installed

    say "Unit suite against the ${edition} package"
    ( cd "${plugin_root}/tests" && "${TESTS_DIR}/vendor/bin/phpunit" --configuration phpunit-unit.xml )
    ok "Unit suite passed on ${edition}"

    say "Integration suite against the ${edition} package"
    compose exec -T -u www-data wordpress \
      php "${CONTAINER_PLUGIN}/tests/vendor/bin/phpunit" \
        --configuration "${CONTAINER_PLUGIN}/tests/phpunit-integration.xml"
    ok "Integration suite passed on ${edition}"

    run_lifecycle "${edition}" "${plugin_root}"
  )

  say "Restoring the stack to the working tree"
  compose down -v >/dev/null 2>&1 || true
  ok "${edition} package verified"
}

# Exercises deactivate → reactivate → deactivate → uninstall through WP-CLI,
# with WP_DEBUG/WP_DEBUG_LOG on, and fails loudly on any warning/notice/error
# or any byte of unexpected output. This is exactly the class of bug
# WordPress.org's review flags as "unexpected output during activation".
#
# The copy under test is `docker cp`-ed into the container's own filesystem
# (the wp_html named volume) rather than exercised through the host bind
# mount at $2: a real WordPress install's plugin folder is a plain directory
# on a plain disk, never a bind-mount point, and `rm -rf` against the actual
# mount point fails with EBUSY ("device or resource busy") purely as a Docker
# artefact — that would fail this check for a reason no real site ever hits.
run_lifecycle() {
  local edition="${1}"
  local plugin_root="${2}"
  local slug="pigcache-lifecycle-${edition}"
  local container_path="/var/www/html/wp-content/plugins/${slug}"

  say "Activation lifecycle against the ${edition} package"

  local cid
  cid="$(compose ps -q wordpress)"
  [[ -n "${cid}" ]] || die "wordpress container is not running."

  # The bind-mounted copy at wp-content/plugins/pigcache is active from
  # ensure_installed(); both declare the same global functions
  # (pigcache_fragment() etc.), so it must be off before the second copy
  # under test can be activated.
  compose run --rm wpcli plugin deactivate pigcache >/dev/null 2>&1 || true

  compose exec -T -u www-data wordpress rm -rf "${container_path}"
  docker cp "${plugin_root}" "${cid}:${container_path}"
  compose exec -T -u root wordpress chown -R 33:33 "${container_path}"
  compose exec -T -u www-data wordpress rm -f /var/www/html/wp-content/debug.log

  local step
  for step in "deactivate" "activate" "deactivate" "activate" "deactivate"; do
    compose run --rm wpcli plugin "${step}" "${slug}"
  done

  local log
  log="$(compose exec -T -u www-data wordpress cat /var/www/html/wp-content/debug.log 2>/dev/null || true)"
  if [[ -n "${log}" ]]; then
    printf '%s\n' "${log}"
    die "debug.log is not empty after activate/deactivate cycles on ${edition} — see output above."
  fi
  ok "No warnings/notices/errors during activate/deactivate on ${edition}"

  compose run --rm wpcli plugin uninstall "${slug}"

  log="$(compose exec -T -u www-data wordpress cat /var/www/html/wp-content/debug.log 2>/dev/null || true)"
  if [[ -n "${log}" ]]; then
    printf '%s\n' "${log}"
    die "debug.log is not empty after uninstall on ${edition} — see output above."
  fi

  if compose exec -T -u www-data wordpress test -d "${container_path}"; then
    die "${container_path} still exists after uninstall on ${edition} — the plugin directory was not removed."
  fi
  ok "Uninstall removed the plugin directory cleanly on ${edition}, no warnings/notices/errors"
}

# ── End-to-end ────────────────────────────────────────────────────────────────

run_e2e() {
  need_docker
  stack_up
  ensure_installed

  say "End-to-end suite (Playwright against the Docker site)"

  command -v npm >/dev/null 2>&1 || die "npm not found on PATH. The e2e suite needs Node."

  ( cd "${TESTS_DIR}/e2e" \
    && npm install --no-audit --no-fund \
    && npx playwright install --with-deps chromium \
    && npx playwright test --project=chromium "$@" )

  ok "E2E suite passed"
}

# ── Entry point ───────────────────────────────────────────────────────────────

target="${1:-all}"
shift || true

case "${target}" in
  unit)        run_unit "$@" ;;
  integration) run_integration "$@" ;;
  resilience)  run_resilience ;;
  e2e)         run_e2e "$@" ;;
  free)        run_package free ;;
  pro)         run_package pro ;;
  packages)    run_package free; run_package pro ;;
  up)          stack_up; ensure_installed ;;
  down)        stack_down ;;
  all)
    run_unit
    run_integration
    run_resilience
    run_package free
    run_package pro
    say "All suites passed"
    ;;
  *)
    die "Unknown target '${target}'. Use: unit | integration | resilience | e2e | free | pro | packages | up | down | all"
    ;;
esac
