<?php
/**
 * Extra wp-config constants injected into the Docker test WordPress instance.
 * Loaded by docker-compose.yml before WordPress boots.
 *
 * DO NOT commit real credentials here — this file is for local/CI test use only.
 */

// Redis — points to the 'redis' Docker Compose service.
define( 'PIGCACHE_REDIS_HOST', 'redis' );
define( 'PIGCACHE_REDIS_PORT', 6379 );

// Emit X-PigCache-* debug response headers so Playwright can inspect cache state
// without needing a REST endpoint.
define( 'PIGCACHE_TESTING', true );

// Use a short HTML cache TTL in tests so we can verify re-caching quickly.
define( 'PIGCACHE_HTML_TTL', 30 );

// Disable adaptive TTL in tests — we want deterministic static TTL behaviour.
define( 'PIGCACHE_ADAPTIVE_TTL_ENABLED', false );

// Verbose cron output for CI logs.
define( 'PIGCACHE_CRON_VERBOSE', true );
