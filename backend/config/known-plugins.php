<?php
/**
 * Known plugin table signatures.
 *
 * Maps popular WordPress plugin slugs to the custom database tables they
 * create (without the WP prefix). This seed data helps the backend
 * attribute fingerprints to the correct plugin and build smarter profiles.
 *
 * The list grows automatically as the aggregator learns from real sites.
 *
 * Format: 'plugin_slug' => ['table_without_prefix', ...]
 */

return [

    // -------------------------------------------------------------------
    // WooCommerce (80M+ installs)
    // -------------------------------------------------------------------
    'woocommerce' => [
        'wc_orders',
        'wc_orders_meta',
        'wc_order_addresses',
        'wc_order_operational_data',
        'wc_order_product_lookup',
        'wc_order_stats',
        'wc_order_tax_lookup',
        'wc_order_coupon_lookup',
        'wc_product_meta_lookup',
        'wc_category_lookup',
        'wc_customer_lookup',
        'wc_download_log',
        'wc_webhooks',
        'woocommerce_sessions',
        'woocommerce_api_keys',
        'woocommerce_attribute_taxonomies',
        'woocommerce_downloadable_product_permissions',
        'woocommerce_order_items',
        'woocommerce_order_itemmeta',
        'woocommerce_tax_rates',
        'woocommerce_tax_rate_locations',
        'woocommerce_shipping_zones',
        'woocommerce_shipping_zone_locations',
        'woocommerce_shipping_zone_methods',
        'woocommerce_payment_tokens',
        'woocommerce_payment_tokenmeta',
        'woocommerce_log',
    ],

    // -------------------------------------------------------------------
    // Jetpack (60M+ installs)
    // -------------------------------------------------------------------
    'jetpack' => [
        'jetpack_sync_queue',
    ],

    // -------------------------------------------------------------------
    // Yoast SEO (30M+ installs)
    // -------------------------------------------------------------------
    'wordpress-seo' => [
        'yoast_seo_links',
        'yoast_indexable',
        'yoast_indexable_hierarchy',
        'yoast_primary_term',
        'yoast_migrations',
    ],

    // -------------------------------------------------------------------
    // WPForms (20M+ installs)
    // -------------------------------------------------------------------
    'wpforms-lite' => [
        'wpforms_entries',
        'wpforms_entry_meta',
        'wpforms_entry_fields',
        'wpforms_tasks_meta',
        'wpforms_payments',
        'wpforms_payment_meta',
    ],

    // -------------------------------------------------------------------
    // Contact Form 7 (15M+ installs)
    // Note: CF7 doesn't create tables by default, but CFDB7 does.
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // Elementor (10M+ installs)
    // -------------------------------------------------------------------
    'elementor' => [
        'e_submissions',
        'e_submissions_values',
        'e_submissions_actions_log',
        'e_events',
    ],

    // -------------------------------------------------------------------
    // Wordfence (5M+ installs)
    // -------------------------------------------------------------------
    'wordfence' => [
        'wfblockediplog',
        'wfblocks7',
        'wfconfig',
        'wfcrawlers',
        'wffilechanges',
        'wffilemods',
        'wfhits',
        'wfhoover',
        'wfissues',
        'wfknownfilelist',
        'wflivetraffichuman',
        'wflocs',
        'wflogins',
        'wfls_2fa_secrets',
        'wfls_settings',
        'wfnotifications',
        'wfpendingissues',
        'wfreversecache',
        'wfsnipcache',
        'wfstatus',
        'wftrafficrates',
    ],

    // -------------------------------------------------------------------
    // WP Mail SMTP (4M+ installs)
    // -------------------------------------------------------------------
    'wp-mail-smtp' => [
        'wpmailsmtp_tasks_meta',
        'wpmailsmtp_emails_log',
    ],

    // -------------------------------------------------------------------
    // Redirection (3M+ installs)
    // -------------------------------------------------------------------
    'redirection' => [
        'redirection_items',
        'redirection_groups',
        'redirection_logs',
        'redirection_404',
    ],

    // -------------------------------------------------------------------
    // TablePress (800K+ installs)
    // -------------------------------------------------------------------
    'tablepress' => [
        'tablepress_tables',
    ],

    // -------------------------------------------------------------------
    // Advanced Custom Fields (ACF) — uses core postmeta/options tables
    // but also creates its own in Pro.
    // -------------------------------------------------------------------
    'advanced-custom-fields' => [],

    // -------------------------------------------------------------------
    // bbPress (forum plugin)
    // -------------------------------------------------------------------
    'bbpress' => [],

    // -------------------------------------------------------------------
    // BuddyPress (social networking)
    // -------------------------------------------------------------------
    'buddypress' => [
        'bp_activity',
        'bp_activity_meta',
        'bp_friends',
        'bp_groups',
        'bp_groups_members',
        'bp_groups_groupmeta',
        'bp_messages_messages',
        'bp_messages_meta',
        'bp_messages_notices',
        'bp_messages_recipients',
        'bp_notifications',
        'bp_notifications_meta',
        'bp_xprofile_data',
        'bp_xprofile_fields',
        'bp_xprofile_groups',
        'bp_xprofile_meta',
    ],

    // -------------------------------------------------------------------
    // Easy Digital Downloads (EDD)
    // -------------------------------------------------------------------
    'easy-digital-downloads' => [
        'edd_orders',
        'edd_ordermeta',
        'edd_order_items',
        'edd_order_itemmeta',
        'edd_order_adjustments',
        'edd_order_adjustmentmeta',
        'edd_order_addresses',
        'edd_order_transactions',
        'edd_customers',
        'edd_customermeta',
        'edd_customer_addresses',
        'edd_customer_email_addresses',
        'edd_notes',
        'edd_notemeta',
        'edd_logs',
        'edd_logmeta',
        'edd_logs_api_requests',
        'edd_logs_api_requestmeta',
    ],

    // -------------------------------------------------------------------
    // GravityForms
    // -------------------------------------------------------------------
    'gravityforms' => [
        'gf_entry',
        'gf_entry_meta',
        'gf_entry_notes',
        'gf_form',
        'gf_form_meta',
        'gf_form_view',
        'gf_form_revisions',
        'gf_draft_submissions',
        'gf_addon_feed',
    ],
];
