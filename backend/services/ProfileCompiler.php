<?php
/**
 * ProfileCompiler — builds a compiled profile from aggregated fingerprints.
 *
 * Extracted and adapted from PigCache_Sql_Profiler::compile().
 * The compiled profile has the same structure the plugin expects, so
 * downloading a cloud profile is a drop-in replacement for local compilation.
 *
 * In Laravel: app/Services/ProfileCompiler.php
 *
 * Usage:
 *   $compiler = new ProfileCompiler();
 *   $profile  = $compiler->compile($fingerprints);
 *   // $profile is the array the plugin writes to pigcache-sql-profile.php
 */

class ProfileCompiler
{
    /**
     * Minimum number of sites that must report a fingerprint for it to
     * be included in the compiled profile. Filters noise from single-site
     * anomalies.
     */
    private int $minSites;

    /**
     * Minimum total hits across all sites for a fingerprint to be included.
     */
    private int $minHits;

    public function __construct(int $minSites = 1, int $minHits = 5)
    {
        $this->minSites = $minSites;
        $this->minHits  = $minHits;
    }

    /**
     * Compile an array of aggregated fingerprints into a profile.
     *
     * @param array $fingerprints Each element: {
     *   fingerprint: string,
     *   template:    string,
     *   tables_json: string|array,  // JSON string or decoded array
     *   total_hits:  int,
     *   avg_rows:    float,
     *   sites_seen:  int
     * }
     * @return array The compiled profile with keys: compiled_at, source,
     *               unique_templates, query_count, map, tables, stats.
     */
    public function compile(array $fingerprints): array
    {
        $map          = [];
        $tablesIndex  = [];
        $stats        = [];
        $totalQueries = 0;

        foreach ($fingerprints as $row) {
            $fp    = $row['fingerprint'] ?? ($row->fingerprint ?? '');
            $tbls  = $row['tables_json'] ?? ($row->tables_json ?? '[]');
            $hits  = (int) ($row['total_hits'] ?? ($row->total_hits ?? 0));
            $sites = (int) ($row['sites_seen'] ?? ($row->sites_seen ?? 1));

            if (is_string($tbls)) {
                $tbls = json_decode($tbls, true);
            }

            if (!is_array($tbls) || empty($tbls) || empty($fp)) {
                continue;
            }

            if ($sites < $this->minSites || $hits < $this->minHits) {
                continue;
            }

            $map[$fp] = $tbls;
            $totalQueries += $hits;

            foreach ($tbls as $t) {
                $tablesIndex[$t][] = $fp;
            }

            $template = $row['template'] ?? ($row->template ?? '');
            $avgRows  = (float) ($row['avg_rows'] ?? ($row->avg_rows ?? 0));

            $stats[$fp] = [
                'hits'       => $hits,
                'avg_rows'   => round($avgRows, 2),
                'template'   => $template,
                'sites_seen' => $sites,
            ];
        }

        // Deduplicate the tables index
        foreach ($tablesIndex as $table => $fps) {
            $tablesIndex[$table] = array_values(array_unique($fps));
        }

        return [
            'compiled_at'      => time(),
            'source'           => 'cloud',
            'unique_templates' => count($map),
            'query_count'      => $totalQueries,
            'map'              => $map,
            'tables'           => $tablesIndex,
            'stats'            => $stats,
        ];
    }

    /**
     * Merge a new profile with an existing one, preferring higher-confidence data.
     *
     * Useful when a profile is incrementally updated as more sites contribute.
     *
     * @param array $existing Previous compiled profile.
     * @param array $incoming New compiled profile.
     * @return array Merged profile.
     */
    public function merge(array $existing, array $incoming): array
    {
        $merged = $existing;

        foreach ($incoming['map'] ?? [] as $fp => $tables) {
            if (!isset($merged['map'][$fp])) {
                $merged['map'][$fp] = $tables;
            }
        }

        foreach ($incoming['stats'] ?? [] as $fp => $stat) {
            if (!isset($merged['stats'][$fp])) {
                $merged['stats'][$fp] = $stat;
            } else {
                $merged['stats'][$fp]['hits'] = max(
                    $merged['stats'][$fp]['hits'],
                    $stat['hits']
                );
                $merged['stats'][$fp]['sites_seen'] = max(
                    $merged['stats'][$fp]['sites_seen'] ?? 1,
                    $stat['sites_seen'] ?? 1
                );
            }
        }

        // Rebuild tables index from merged map
        $tablesIndex = [];
        foreach ($merged['map'] as $fp => $tables) {
            foreach ($tables as $t) {
                $tablesIndex[$t][] = $fp;
            }
        }
        foreach ($tablesIndex as $table => $fps) {
            $tablesIndex[$table] = array_values(array_unique($fps));
        }

        $merged['tables']           = $tablesIndex;
        $merged['unique_templates'] = count($merged['map']);
        $merged['compiled_at']      = time();

        return $merged;
    }

    /**
     * Generate a hash for cache-busting the profile.
     */
    public function hash(array $profile): string
    {
        $keys = array_keys($profile['map'] ?? []);
        sort($keys);

        return md5(implode('|', $keys));
    }
}
