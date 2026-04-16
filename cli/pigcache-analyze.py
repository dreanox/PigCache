#!/usr/bin/env python3
"""
PigCache SQL Profiler — Deep Analyzer

Reads the learning data from the wp_pigcache_sql_fingerprints table and
produces a JSON report with frequency analysis, table dependency mapping,
cache efficiency estimates, and optimization recommendations.

Usage:
    python3 pigcache-analyze.py --host 127.0.0.1 --user root --password "" --database wordpress
    python3 pigcache-analyze.py --wp-config /path/to/wp-config.php
    python3 pigcache-analyze.py --wp-config /path/to/wp-config.php --output report.json

Requires: Python 3.6+, mysql-connector-python (pip3 install mysql-connector-python)
          or PyMySQL (pip3 install pymysql)
"""

import argparse
import json
import re
import sys
from collections import defaultdict
from datetime import datetime

DB_MODULE = None

try:
    import mysql.connector as mysql_mod
    DB_MODULE = "mysql-connector"
except ImportError:
    try:
        import pymysql as mysql_mod
        DB_MODULE = "pymysql"
    except ImportError:
        mysql_mod = None


def parse_wp_config(path):
    """Extract DB credentials from wp-config.php."""
    creds = {}
    mapping = {
        "DB_NAME": "database",
        "DB_USER": "user",
        "DB_PASSWORD": "password",
        "DB_HOST": "host",
    }

    try:
        with open(path, "r", encoding="utf-8") as f:
            content = f.read()
    except FileNotFoundError:
        print(f"Error: wp-config.php not found at {path}", file=sys.stderr)
        sys.exit(1)

    for const, key in mapping.items():
        pattern = rf"define\s*\(\s*['\"]({const})['\"]\s*,\s*['\"]([^'\"]*)"
        match = re.search(pattern, content)
        if match:
            creds[key] = match.group(2)

    prefix_match = re.search(
        r"\$table_prefix\s*=\s*['\"]([^'\"]*)['\"]", content
    )
    creds["prefix"] = prefix_match.group(1) if prefix_match else "wp_"

    return creds


def get_connection(args, creds):
    """Create a database connection."""
    if mysql_mod is None:
        print(
            "Error: No MySQL driver found. Install one of:\n"
            "  pip3 install mysql-connector-python\n"
            "  pip3 install pymysql",
            file=sys.stderr,
        )
        sys.exit(1)

    host = args.host or creds.get("host", "127.0.0.1")
    port = 3306
    if ":" in host:
        host, port_str = host.rsplit(":", 1)
        try:
            port = int(port_str)
        except ValueError:
            pass

    params = {
        "host": host,
        "port": port,
        "user": args.user or creds.get("user", "root"),
        "password": args.password if args.password is not None else creds.get("password", ""),
        "database": args.database or creds.get("database", "wordpress"),
    }

    if DB_MODULE == "pymysql":
        params["charset"] = "utf8mb4"
        params["cursorclass"] = mysql_mod.cursors.DictCursor
    else:
        params["charset"] = "utf8mb4"

    return mysql_mod.connect(**params)


def fetch_fingerprints(conn, prefix):
    """Fetch all fingerprint rows."""
    table = f"{prefix}pigcache_sql_fingerprints"
    cursor = conn.cursor()

    if DB_MODULE == "pymysql":
        cursor.execute(f"SELECT * FROM `{table}` ORDER BY hit_count DESC")
        rows = cursor.fetchall()
    else:
        cursor.execute(f"SELECT * FROM `{table}` ORDER BY hit_count DESC")
        columns = [desc[0] for desc in cursor.description]
        rows = [dict(zip(columns, row)) for row in cursor.fetchall()]

    cursor.close()
    return rows


def analyze(rows):
    """Produce the analysis report."""
    total_queries = sum(r["hit_count"] for r in rows)
    unique_templates = len(rows)

    table_query_count = defaultdict(int)
    table_hit_count = defaultdict(int)
    table_co_occurrence = defaultdict(lambda: defaultdict(int))
    top_queries = []

    for row in rows:
        tables = json.loads(row["tables_json"]) if isinstance(row["tables_json"], str) else row["tables_json"]
        hits = row["hit_count"]

        for t in tables:
            table_query_count[t] += 1
            table_hit_count[t] += hits

        for i, t1 in enumerate(tables):
            for t2 in tables[i + 1:]:
                table_co_occurrence[t1][t2] += hits
                table_co_occurrence[t2][t1] += hits

        top_queries.append({
            "fingerprint": row["fingerprint"],
            "template": row["template"][:200],
            "tables": tables,
            "hits": hits,
            "avg_rows": round(float(row["avg_rows"]), 2),
            "first_seen": str(row["first_seen"]),
            "last_seen": str(row["last_seen"]),
        })

    sorted_tables = sorted(
        table_hit_count.items(), key=lambda x: x[1], reverse=True
    )

    posts_table_names = [t for t in table_hit_count if "posts" in t and "meta" not in t]
    posts_hits = sum(table_hit_count[t] for t in posts_table_names)
    posts_pct = (posts_hits / total_queries * 100) if total_queries else 0

    options_table_names = [t for t in table_hit_count if "options" in t]
    options_hits = sum(table_hit_count[t] for t in options_table_names)
    options_pct = (options_hits / total_queries * 100) if total_queries else 0

    surviving_pct = 100 - posts_pct if total_queries else 0

    recommendations = []

    if posts_pct > 80:
        recommendations.append(
            f"{posts_pct:.1f}% of queries touch posts tables. "
            f"Per-table epochs will still invalidate most queries on post save. "
            f"Consider shorter TTL for posts-related queries."
        )

    if surviving_pct > 20:
        recommendations.append(
            f"{surviving_pct:.1f}% of queries would survive a wp_posts mutation "
            f"with per-table epochs (vs 0% with global epoch). "
            f"This is the direct cache efficiency gain."
        )

    if options_pct > 30:
        recommendations.append(
            f"{options_pct:.1f}% of queries touch options tables. "
            f"Options are rarely mutated on the frontend, so these queries "
            f"benefit significantly from per-table epochs."
        )

    if unique_templates > 200:
        recommendations.append(
            f"Found {unique_templates} unique query templates. This is high — "
            f"check if plugins are generating excessive dynamic queries."
        )

    co_occurrence_flat = []
    seen = set()
    for t1, partners in table_co_occurrence.items():
        for t2, count in partners.items():
            pair = tuple(sorted([t1, t2]))
            if pair not in seen:
                seen.add(pair)
                co_occurrence_flat.append({
                    "tables": list(pair),
                    "co_occurrences": count,
                })

    co_occurrence_flat.sort(key=lambda x: x["co_occurrences"], reverse=True)

    return {
        "generated_at": datetime.utcnow().isoformat() + "Z",
        "summary": {
            "total_queries_observed": total_queries,
            "unique_templates": unique_templates,
            "tables_found": len(table_hit_count),
            "posts_query_pct": round(posts_pct, 1),
            "options_query_pct": round(options_pct, 1),
            "cache_survival_on_post_save_pct": round(surviving_pct, 1),
        },
        "tables_by_frequency": [
            {"table": t, "query_templates": table_query_count[t], "total_hits": h}
            for t, h in sorted_tables
        ],
        "table_co_occurrence": co_occurrence_flat[:20],
        "top_queries": top_queries[:50],
        "recommendations": recommendations,
    }


def main():
    parser = argparse.ArgumentParser(
        description="PigCache SQL Profiler — Deep Analyzer"
    )
    parser.add_argument("--wp-config", help="Path to wp-config.php")
    parser.add_argument("--host", help="MySQL host (default from wp-config)")
    parser.add_argument("--user", help="MySQL user (default from wp-config)")
    parser.add_argument("--password", default=None, help="MySQL password")
    parser.add_argument("--database", help="MySQL database name")
    parser.add_argument("--prefix", default=None, help="Table prefix (default: wp_)")
    parser.add_argument("--output", "-o", help="Output JSON file (default: stdout)")
    args = parser.parse_args()

    creds = {}
    if args.wp_config:
        creds = parse_wp_config(args.wp_config)

    prefix = args.prefix or creds.get("prefix", "wp_")

    print(f"Connecting to MySQL ({DB_MODULE})...", file=sys.stderr)
    conn = get_connection(args, creds)

    print(f"Reading {prefix}pigcache_sql_fingerprints...", file=sys.stderr)
    rows = fetch_fingerprints(conn, prefix)
    conn.close()

    if not rows:
        print("No fingerprints found. Run the profiler learning mode first.", file=sys.stderr)
        sys.exit(1)

    print(f"Analyzing {len(rows)} fingerprints...", file=sys.stderr)
    report = analyze(rows)

    output = json.dumps(report, indent=2, ensure_ascii=False)

    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(output)
        print(f"Report written to {args.output}", file=sys.stderr)
    else:
        print(output)

    print(f"\nSummary:", file=sys.stderr)
    s = report["summary"]
    print(f"  Queries observed:  {s['total_queries_observed']:,}", file=sys.stderr)
    print(f"  Unique templates:  {s['unique_templates']}", file=sys.stderr)
    print(f"  Tables found:      {s['tables_found']}", file=sys.stderr)
    print(f"  Posts queries:     {s['posts_query_pct']}%", file=sys.stderr)
    print(f"  Cache survival:    {s['cache_survival_on_post_save_pct']}% on post save", file=sys.stderr)

    if report["recommendations"]:
        print(f"\nRecommendations:", file=sys.stderr)
        for i, rec in enumerate(report["recommendations"], 1):
            print(f"  {i}. {rec}", file=sys.stderr)


if __name__ == "__main__":
    main()
