<?php
/**
 * QueryNormalizer — shared normalization and table extraction logic.
 *
 * This is the backend counterpart of PigCache_Sql_Profiler::normalize()
 * and extract_tables(). The backend re-normalizes incoming templates to
 * ensure consistency, since different WP versions or plugin updates may
 * produce slightly different whitespace or quoting.
 *
 * In Laravel: app/Services/QueryNormalizer.php
 *
 * Usage:
 *   $normalizer = new QueryNormalizer();
 *   $template   = $normalizer->normalize($raw_sql);
 *   $tables     = $normalizer->extractTables($raw_sql);
 *   $fp         = $normalizer->fingerprint($raw_sql);
 */

class QueryNormalizer
{
    /**
     * SQL keywords that should NOT be treated as table names.
     */
    private const RESERVED = [
        'select', 'from', 'where', 'join', 'on', 'as', 'set',
        'values', 'into', 'null', 'true', 'false', 'and', 'or',
        'not', 'in', 'like', 'between', 'exists', 'having',
        'group', 'order', 'limit', 'offset', 'union', 'all',
        'distinct', 'case', 'when', 'then', 'else', 'end',
    ];

    /**
     * Normalize a SQL query into a stable template.
     *
     * - Collapses whitespace
     * - Replaces string literals with ?
     * - Replaces numeric literals with ?
     * - Collapses IN(?, ?, ?) into IN(?)
     */
    public function normalize(string $query): string
    {
        $norm = preg_replace('/\s+/', ' ', trim($query));

        // String literals (handles escaped quotes)
        $norm = preg_replace("/('[^'\\\\]*(?:\\\\.[^'\\\\]*)*')/", '?', $norm);

        // Numeric literals
        $norm = preg_replace('/\b\d+\b/', '?', $norm);

        // Collapse IN lists
        $norm = preg_replace('/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'IN (?)', $norm);

        return $norm;
    }

    /**
     * Compute the fingerprint (md5) of a normalized query.
     */
    public function fingerprint(string $query): string
    {
        return md5($this->normalize($query));
    }

    /**
     * Extract table names from a SQL query.
     *
     * Looks for FROM and JOIN clauses. Returns unique, lowercased names.
     *
     * @return string[]
     */
    public function extractTables(string $query): array
    {
        $tables = [];

        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?/i', $query, $matches)) {
            foreach ($matches[1] as $t) {
                $t = strtolower($t);
                if ($this->isValidTable($t)) {
                    $tables[$t] = true;
                }
            }
        }

        return array_keys($tables);
    }

    /**
     * Extract the target table from a mutation (INSERT/UPDATE/DELETE).
     */
    public function extractMutationTable(string $query): string
    {
        $query = ltrim($query);

        if (preg_match('/^\s*(?:INSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?INTO|REPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?(?:INTO)?)\s+`?(\w+)`?/i', $query, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)?`?(\w+)`?/i', $query, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^\s*DELETE\s+.*?\bFROM\s+`?(\w+)`?/i', $query, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $query, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    /**
     * Validate that a name is likely a real table, not a SQL keyword.
     */
    private function isValidTable(string $name): bool
    {
        if (strlen($name) < 2 || strlen($name) > 128) {
            return false;
        }

        return !in_array($name, self::RESERVED, true);
    }

    /**
     * Strip the WP table prefix from a table name.
     *
     * Useful for cross-site normalization: "wp_posts" and "mysite_posts"
     * both become "posts", making fingerprints portable.
     *
     * @param string $table  Full table name.
     * @param string $prefix WP prefix (e.g. "wp_").
     * @return string Table name without prefix.
     */
    public function stripPrefix(string $table, string $prefix = 'wp_'): string
    {
        if ($prefix && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }

    /**
     * Normalize table names across sites by stripping the WP prefix.
     *
     * This is essential for aggregation: site A uses "wp_posts" and
     * site B uses "myblog_posts" — both should map to "posts" in the
     * shared profile.
     *
     * @param string[] $tables Original table names.
     * @param string   $prefix The site's WP table prefix.
     * @return string[] Normalized table names.
     */
    public function normalizeTables(array $tables, string $prefix = 'wp_'): array
    {
        return array_map(fn($t) => $this->stripPrefix($t, $prefix), $tables);
    }
}
