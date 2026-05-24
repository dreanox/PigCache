#!/usr/bin/env bash
# PigCache Docker test-environment setup.
#
# Run once after 'docker compose up -d' to install WordPress, activate the
# plugin, and install the PigCache drop-ins (object cache, advanced-cache, db).
#
# Usage:
#   cd PigCache/tests/docker
#   docker compose up -d
#   bash setup.sh
#
# Re-running is safe (WP-CLI is idempotent for most operations).

set -euo pipefail

WP_URL="http://localhost:8088"
WP_TITLE="PigCache E2E Tests"
WP_ADMIN_USER="admin"
WP_ADMIN_PASS="admin"
WP_ADMIN_EMAIL="test@pigcache.test"

COMPOSE="docker compose -f $(dirname "$0")/docker-compose.yml"
WPCLI="$COMPOSE run --rm wpcli"

echo "[setup] Waiting for WordPress to be ready..."
until curl -sf "$WP_URL/wp-login.php" > /dev/null 2>&1; do
  sleep 3
done

echo "[setup] Installing WordPress core..."
$WPCLI core install \
  --url="$WP_URL" \
  --title="$WP_TITLE" \
  --admin_user="$WP_ADMIN_USER" \
  --admin_password="$WP_ADMIN_PASS" \
  --admin_email="$WP_ADMIN_EMAIL" \
  --skip-email || echo "[setup] Core already installed, continuing..."

echo "[setup] Activating PigCache plugin..."
$WPCLI plugin activate pigcache || echo "[setup] Already active, continuing..."

echo "[setup] Installing drop-ins (object cache, advanced-cache, db)..."
$WPCLI eval '
  if ( class_exists("PigCache_Dropin_Object_Cache") ) {
    PigCache_Dropin_Object_Cache::install();
  }
  if ( class_exists("PigCache_Dropin_Html_Cache") ) {
    PigCache_Dropin_Html_Cache::install();
  }
  if ( class_exists("PigCache_Dropin_Db") ) {
    PigCache_Dropin_Db::install();
  }
  echo "drop-ins installed\n";
'

echo "[setup] Creating sample posts for cache tests..."
# Create a standard post (plain slug — will use EARLY cache path).
$WPCLI post create \
  --post_type=post \
  --post_status=publish \
  --post_title="PigCache Test Article" \
  --post_content="$(cat <<'EOF'
<p>This is a test article used by the PigCache E2E suite. It contains enough
body text to satisfy the minimum article character threshold used in the HTML
cache completeness tests.</p>
<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod
tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam,
quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo
consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse
cillum dolore eu fugat nulla pariatur.</p>
<h2>Section Two</h2>
<p>Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia
deserunt mollit anim id est laborum. PigCache stores this page in Redis and
serves subsequent requests at sub-millisecond latency.</p>
EOF
)" \
  --post_name="pigcache-test-article" \
  --porcelain || echo "[setup] Post may already exist, continuing..."

# Create a post with an emoji in the slug (mirrors the nuevoleonhoy.com bug URL).
$WPCLI post create \
  --post_type=post \
  --post_status=publish \
  --post_title="PigCache Emoji URL Test 🏆" \
  --post_content="<p>This post has an emoji in its slug to test the EARLY/LATE cache key symmetry fix (Bug #2). The slug is percent-encoded by WordPress.</p><p>$(python3 -c "print('x ' * 100)")</p>" \
  --post_name="pigcache-test-emoji-url" \
  --porcelain || echo "[setup] Emoji post may already exist, continuing..."

echo "[setup] Flushing object cache to start fresh..."
$WPCLI cache flush || true

echo "[setup] Done. WordPress is ready at $WP_URL"
echo ""
echo "  Admin: $WP_URL/wp-admin  (user: $WP_ADMIN_USER  pass: $WP_ADMIN_PASS)"
echo "  Run tests: cd PigCache/tests/e2e && npx playwright test --project=chromium"
