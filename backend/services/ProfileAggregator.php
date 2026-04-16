<?php
/**
 * ProfileAggregator — the core SaaS intelligence.
 *
 * Collects fingerprints from multiple sites with the same environment,
 * merges statistics, filters noise, and triggers profile compilation
 * when enough quality data has accumulated.
 *
 * This is the key differentiator: a new WooCommerce site doesn't need
 * to learn for a week — it gets instant intelligence from every other
 * WooCommerce site that has already contributed data.
 *
 * In Laravel: app/Services/ProfileAggregator.php
 *
 * Usage (in a controller or job):
 *   $aggregator = new ProfileAggregator(new ProfileCompiler(), $fingerprintRepo);
 *   $result     = $aggregator->ingest($siteId, $envHash, $fingerprints);
 *   if ($result['should_compile']) {
 *       $profile = $aggregator->compileForEnvironment($envHash);
 *   }
 */

class ProfileAggregator
{
    /**
     * Minimum number of unique templates before a profile can be compiled.
     */
    private const MIN_TEMPLATES = 20;

    /**
     * Minimum number of contributing sites before cross-site aggregation
     * adds extra confidence filtering.
     */
    private const CROSS_SITE_THRESHOLD = 3;

    private ProfileCompiler $compiler;

    public function __construct(ProfileCompiler $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * Ingest a batch of fingerprints from a single site.
     *
     * For each fingerprint:
     * 1. Upsert into the global `fingerprints` table (keyed by fp + env_hash)
     * 2. Update the `fingerprint_sources` table (per-site stats)
     * 3. Re-aggregate total_hits and sites_seen
     *
     * @param string $siteId         The site sending data.
     * @param string $envHash        Environment hash.
     * @param array  $fingerprints   Array of {fingerprint, template, tables, hit_count, avg_rows}.
     * @return array{received: int, new_count: int, updated_count: int, should_compile: bool}
     */
    public function ingest(string $siteId, string $envHash, array $fingerprints): array
    {
        $newCount     = 0;
        $updatedCount = 0;

        foreach ($fingerprints as $fp) {
            $fingerprint = $fp['fingerprint'];
            $template    = $fp['template'];
            $tables      = $fp['tables'];
            $hitCount    = (int) ($fp['hit_count'] ?? 1);
            $avgRows     = (float) ($fp['avg_rows'] ?? 0);

            if (is_array($tables)) {
                $tablesJson = json_encode(array_values($tables));
            } else {
                $tablesJson = $tables;
            }

            // In Laravel — upsert into fingerprints table:
            //
            // $existing = Fingerprint::where('fingerprint', $fingerprint)
            //     ->where('env_hash', $envHash)
            //     ->first();
            //
            // if ($existing) {
            //     $existing->update([
            //         'total_hits' => DB::raw("total_hits + {$hitCount}"),
            //         'last_seen'  => now(),
            //     ]);
            //     $updatedCount++;
            // } else {
            //     Fingerprint::create([
            //         'fingerprint' => $fingerprint,
            //         'template'    => $template,
            //         'tables_json' => $tablesJson,
            //         'env_hash'    => $envHash,
            //         'total_hits'  => $hitCount,
            //         'avg_rows'    => $avgRows,
            //         'sites_seen'  => 1,
            //     ]);
            //     $newCount++;
            // }

            // In Laravel — upsert into fingerprint_sources:
            //
            // FingerprintSource::updateOrCreate(
            //     ['fingerprint_id' => $fpRecord->id, 'site_id' => $siteId],
            //     ['hit_count' => $hitCount, 'avg_rows' => $avgRows, 'last_synced_at' => now()]
            // );

            // Placeholder for actual implementation
            if (true /* new record */) {
                $newCount++;
            } else {
                $updatedCount++;
            }
        }

        // Recalculate sites_seen for affected fingerprints
        $this->recalculateSitesSeen($envHash);

        // Determine if we should trigger compilation
        $shouldCompile = $this->shouldCompile($envHash);

        return [
            'received'       => count($fingerprints),
            'new_count'      => $newCount,
            'updated_count'  => $updatedCount,
            'should_compile' => $shouldCompile,
        ];
    }

    /**
     * Determine whether enough data has accumulated to compile/recompile
     * the profile for an environment.
     *
     * Criteria:
     * - At least MIN_TEMPLATES unique fingerprints
     * - OR: existing profile exists but new data has > 10% more templates
     *
     * @param string $envHash
     * @return bool
     */
    public function shouldCompile(string $envHash): bool
    {
        // In Laravel:
        // $totalTemplates = Fingerprint::where('env_hash', $envHash)->count();
        //
        // if ($totalTemplates < self::MIN_TEMPLATES) {
        //     return false;
        // }
        //
        // $existingProfile = Profile::where('env_hash', $envHash)
        //     ->orderByDesc('version')
        //     ->first();
        //
        // if (!$existingProfile) {
        //     return true; // first compilation
        // }
        //
        // $growth = ($totalTemplates - $existingProfile->unique_templates)
        //         / max(1, $existingProfile->unique_templates);
        //
        // return $growth > 0.10; // recompile if 10%+ new templates

        return false; // placeholder
    }

    /**
     * Compile the profile for an environment using all aggregated data.
     *
     * @param string $envHash
     * @return array The compiled profile.
     */
    public function compileForEnvironment(string $envHash): array
    {
        // In Laravel:
        // $fingerprints = Fingerprint::where('env_hash', $envHash)
        //     ->orderByDesc('total_hits')
        //     ->get()
        //     ->toArray();

        $fingerprints = []; // placeholder

        $profile = $this->compiler->compile($fingerprints);

        // In Laravel — store the compiled profile:
        //
        // $version = Profile::where('env_hash', $envHash)->max('version') ?? 0;
        //
        // $sitesContributing = FingerprintSource::whereHas('fingerprint', function ($q) use ($envHash) {
        //     $q->where('env_hash', $envHash);
        // })->distinct('site_id')->count();
        //
        // Profile::create([
        //     'env_hash'           => $envHash,
        //     'profile_hash'       => $this->compiler->hash($profile),
        //     'version'            => $version + 1,
        //     'compiled_data'      => json_encode($profile),
        //     'unique_templates'   => $profile['unique_templates'],
        //     'total_queries'      => $profile['query_count'],
        //     'sites_contributing' => $sitesContributing,
        // ]);

        return $profile;
    }

    /**
     * Recalculate the sites_seen counter for fingerprints in an environment.
     *
     * @param string $envHash
     */
    private function recalculateSitesSeen(string $envHash): void
    {
        // In Laravel:
        //
        // DB::statement("
        //     UPDATE fingerprints f
        //     SET f.sites_seen = (
        //         SELECT COUNT(DISTINCT fs.site_id)
        //         FROM fingerprint_sources fs
        //         WHERE fs.fingerprint_id = f.id
        //     )
        //     WHERE f.env_hash = ?
        // ", [$envHash]);
    }

    /**
     * Get aggregation stats for an environment.
     *
     * @param string $envHash
     * @return array{total_templates: int, total_hits: int, sites_contributing: int, has_profile: bool}
     */
    public function getStats(string $envHash): array
    {
        // In Laravel:
        //
        // $templates = Fingerprint::where('env_hash', $envHash)->count();
        // $hits      = Fingerprint::where('env_hash', $envHash)->sum('total_hits');
        // $sites     = FingerprintSource::whereHas('fingerprint', fn($q) =>
        //     $q->where('env_hash', $envHash)
        // )->distinct('site_id')->count();
        // $profile   = Profile::where('env_hash', $envHash)->exists();

        return [
            'total_templates'    => 0,
            'total_hits'         => 0,
            'sites_contributing' => 0,
            'has_profile'        => false,
        ];
    }

    /**
     * Prune old fingerprints that haven't been seen in a long time.
     *
     * Keeps the database healthy as sites come and go.
     *
     * @param string $envHash
     * @param int    $daysOld  Remove fingerprints not seen in this many days.
     * @return int Number of rows deleted.
     */
    public function prune(string $envHash, int $daysOld = 90): int
    {
        // In Laravel:
        //
        // return Fingerprint::where('env_hash', $envHash)
        //     ->where('last_seen', '<', now()->subDays($daysOld))
        //     ->where('sites_seen', '<', 2)
        //     ->delete();

        return 0; // placeholder
    }
}
