-- PigCache Cloud — Backend MySQL Schema
-- Use this as the basis for Laravel migrations.

-- =========================================================================
-- 1. LICENSES
-- =========================================================================
CREATE TABLE licenses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key         VARCHAR(64)     NOT NULL,
    email           VARCHAR(255)    NOT NULL,
    plan            VARCHAR(32)     NOT NULL DEFAULT 'pro'  COMMENT 'pro, agency, enterprise',
    sites_max       SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at      DATETIME        NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_api_key (api_key),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 2. SITES
-- =========================================================================
CREATE TABLE sites (
    id                  VARCHAR(32)     NOT NULL  COMMENT 'site_id sent to plugin',
    license_id          BIGINT UNSIGNED NOT NULL,
    site_url            VARCHAR(512)    NOT NULL,
    site_name           VARCHAR(255)    NOT NULL DEFAULT '',
    environment_hash    VARCHAR(32)     DEFAULT NULL  COMMENT 'md5 of canonical env signature',
    last_sync_at        DATETIME        DEFAULT NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_license (license_id),
    KEY idx_env_hash (environment_hash),
    CONSTRAINT fk_sites_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 3. SITE ENVIRONMENTS
-- Snapshot of the plugin/theme stack for each site. Updated on each sync.
-- =========================================================================
CREATE TABLE site_environments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         VARCHAR(32)     NOT NULL,
    plugins_json    JSON            NOT NULL  COMMENT '["woocommerce","jetpack",...]',
    theme           VARCHAR(128)    NOT NULL,
    wp_version      VARCHAR(16)     NOT NULL,
    wp_major        VARCHAR(8)      NOT NULL  COMMENT '6.7',
    php_version     VARCHAR(8)      NOT NULL DEFAULT '',
    env_hash        VARCHAR(32)     NOT NULL  COMMENT 'canonical hash',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_site (site_id),
    KEY idx_env_hash (env_hash),
    CONSTRAINT fk_site_env_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 4. FINGERPRINTS
-- Aggregated SQL fingerprints across all sites. This is the learning data.
-- =========================================================================
CREATE TABLE fingerprints (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint     VARCHAR(32)     NOT NULL  COMMENT 'md5 of normalized query',
    template        TEXT            NOT NULL  COMMENT 'Normalized SQL template',
    tables_json     VARCHAR(512)    NOT NULL  COMMENT '["wp_posts","wp_postmeta"]',
    env_hash        VARCHAR(32)     NOT NULL  COMMENT 'environment that produced this',

    -- Aggregation counters (merged from all sites with this env)
    total_hits      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    avg_rows        FLOAT           NOT NULL DEFAULT 0,
    sites_seen      INT UNSIGNED    NOT NULL DEFAULT 1  COMMENT 'number of distinct sites reporting this',

    first_seen      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_fp_env (fingerprint, env_hash),
    KEY idx_env_hash (env_hash),
    KEY idx_hits (total_hits DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 5. FINGERPRINT SOURCES
-- Track which site contributed each fingerprint (for dedup and weighting).
-- =========================================================================
CREATE TABLE fingerprint_sources (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint_id  BIGINT UNSIGNED NOT NULL,
    site_id         VARCHAR(32)     NOT NULL,
    hit_count       INT UNSIGNED    NOT NULL DEFAULT 1,
    avg_rows        FLOAT           NOT NULL DEFAULT 0,
    last_synced_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_fp_site (fingerprint_id, site_id),
    KEY idx_site (site_id),
    CONSTRAINT fk_fps_fingerprint FOREIGN KEY (fingerprint_id) REFERENCES fingerprints(id) ON DELETE CASCADE,
    CONSTRAINT fk_fps_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 6. PROFILES
-- Compiled profiles stored per environment hash. Multiple versions kept.
-- =========================================================================
CREATE TABLE profiles (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    env_hash            VARCHAR(32)     NOT NULL,
    profile_hash        VARCHAR(32)     NOT NULL  COMMENT 'md5 of compiled data for cache busting',
    version             INT UNSIGNED    NOT NULL DEFAULT 1,
    compiled_data       LONGTEXT        NOT NULL  COMMENT 'JSON: {map, tables, stats, ...}',
    unique_templates    INT UNSIGNED    NOT NULL DEFAULT 0,
    total_queries       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sites_contributing  INT UNSIGNED    NOT NULL DEFAULT 0,
    compiled_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_env_hash (env_hash),
    KEY idx_profile_hash (profile_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 7. SYNC LOG
-- Audit trail for every sync event from a site.
-- =========================================================================
CREATE TABLE sync_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         VARCHAR(32)     NOT NULL,
    event_type      VARCHAR(32)     NOT NULL  COMMENT 'fingerprints, environment, profile_download',
    fingerprints_received   INT UNSIGNED NOT NULL DEFAULT 0,
    fingerprints_new        INT UNSIGNED NOT NULL DEFAULT 0,
    profile_hash    VARCHAR(32)     DEFAULT NULL,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_site (site_id),
    KEY idx_created (created_at),
    CONSTRAINT fk_sync_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- 8. KNOWN PLUGIN TABLES
-- Mapping of popular plugins to their expected database tables.
-- Populated from config/known-plugins.php and enriched from real data.
-- =========================================================================
CREATE TABLE known_plugin_tables (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plugin_slug     VARCHAR(128)    NOT NULL,
    table_name      VARCHAR(128)    NOT NULL  COMMENT 'without prefix, e.g. wc_orders',
    source          VARCHAR(16)     NOT NULL DEFAULT 'seed'  COMMENT 'seed or learned',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plugin_table (plugin_slug, table_name),
    KEY idx_plugin (plugin_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
