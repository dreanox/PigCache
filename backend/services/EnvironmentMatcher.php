<?php
/**
 * EnvironmentMatcher — matches site environments to existing profiles.
 *
 * The canonical environment is defined by:
 *   sorted(active_plugin_slugs) + theme_slug + wp_major_version
 *
 * An exact match means two sites run the exact same stack. Fuzzy matching
 * relaxes constraints (e.g. ignoring WP minor version or excluding small
 * plugins) to maximize profile reuse.
 *
 * In Laravel: app/Services/EnvironmentMatcher.php
 *
 * Usage:
 *   $matcher = new EnvironmentMatcher($profileRepository);
 *   $result  = $matcher->match($plugins, $theme, $wpMajor);
 */

class EnvironmentMatcher
{
    /**
     * Plugins that are cosmetic or unlikely to produce unique SQL patterns.
     * These can be ignored for fuzzy matching.
     */
    private const IGNORABLE_PLUGINS = [
        'akismet',
        'hello-dolly',
        'classic-editor',
        'disable-comments',
        'wp-mail-smtp',
        'redirection',
        'duplicate-post',
    ];

    /**
     * Compute the canonical environment hash.
     *
     * This must produce the same output as PigCache_Environment::get_signature()
     * in the WordPress plugin, so profile matching works correctly.
     *
     * @param string[] $plugins Sorted array of plugin directory slugs.
     * @param string   $theme   Stylesheet slug.
     * @param string   $wpMajor WP major.minor version (e.g. "6.7").
     * @return string 32-char md5 hex.
     */
    public function computeHash(array $plugins, string $theme, string $wpMajor): string
    {
        sort($plugins);

        $canonical = implode('|', $plugins)
            . '||' . $theme
            . '||' . $wpMajor;

        return md5($canonical);
    }

    /**
     * Try to find an exact match for the environment.
     *
     * In your Laravel app, this would query the `profiles` table:
     *   SELECT * FROM profiles WHERE env_hash = ? ORDER BY version DESC LIMIT 1
     *
     * @param string[] $plugins
     * @param string   $theme
     * @param string   $wpMajor
     * @return array|null Profile row or null.
     */
    public function findExact(array $plugins, string $theme, string $wpMajor): ?array
    {
        $hash = $this->computeHash($plugins, $theme, $wpMajor);

        // In Laravel:
        // return Profile::where('env_hash', $hash)
        //     ->orderByDesc('version')
        //     ->first()
        //     ?->toArray();

        // Placeholder — replace with actual DB query
        return null;
    }

    /**
     * Try fuzzy matching strategies when no exact match exists.
     *
     * Strategy 1: Same plugins + theme, any WP version
     * Strategy 2: Same "big" plugins (remove ignorable), same theme
     * Strategy 3: Same core plugins only (WooCommerce, Jetpack, etc.)
     *
     * @param string[] $plugins
     * @param string   $theme
     * @param string   $wpMajor
     * @return array{strategy: string, hash: string, profile: array}|null
     */
    public function findFuzzy(array $plugins, string $theme, string $wpMajor): ?array
    {
        // Strategy 1: ignore WP minor differences
        // Try all known WP major versions with the same plugin set
        $knownWpVersions = ['6.5', '6.6', '6.7', '6.8'];

        foreach ($knownWpVersions as $ver) {
            if ($ver === $wpMajor) {
                continue;
            }
            $hash = $this->computeHash($plugins, $theme, $ver);
            // $profile = Profile::where('env_hash', $hash)->orderByDesc('version')->first();
            // if ($profile) return ['strategy' => 'wp_version_flex', 'hash' => $hash, 'profile' => $profile->toArray()];
        }

        // Strategy 2: remove ignorable plugins
        $significant = array_values(array_diff($plugins, self::IGNORABLE_PLUGINS));
        if (count($significant) !== count($plugins)) {
            $hash = $this->computeHash($significant, $theme, $wpMajor);
            // $profile = Profile::where('env_hash', $hash)->orderByDesc('version')->first();
            // if ($profile) return ['strategy' => 'ignorable_removed', 'hash' => $hash, 'profile' => $profile->toArray()];
        }

        // Strategy 3: core-only plugins (major ones that define the SQL patterns)
        $corePlugins = array_values(array_intersect($plugins, [
            'woocommerce', 'jetpack', 'elementor', 'yoast-seo',
            'wpforms-lite', 'wordfence', 'litespeed-cache',
        ]));
        if (!empty($corePlugins)) {
            $hash = $this->computeHash($corePlugins, $theme, $wpMajor);
            // $profile = Profile::where('env_hash', $hash)->orderByDesc('version')->first();
            // if ($profile) return ['strategy' => 'core_plugins_only', 'hash' => $hash, 'profile' => $profile->toArray()];
        }

        return null;
    }

    /**
     * Compute a similarity score between two plugin lists.
     *
     * Uses Jaccard index: |intersection| / |union|
     * Returns 0.0 (no overlap) to 1.0 (identical).
     *
     * @param string[] $a
     * @param string[] $b
     * @return float
     */
    public function similarity(array $a, array $b): float
    {
        $setA  = array_flip($a);
        $setB  = array_flip($b);
        $inter = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }
}
