#!/usr/bin/env python3
# API TODO — agregar nuevas implementaciones pendientes aquí cuando se necesiten.
# Ver MANUAL.md §13 para el wire format completo, portabilidad y notas de implementación.

"""
PigCache Monitor — Redis + MySQL health/efficiency probe for cPanel cron.

Designed to answer the operational questions you actually have on a high-traffic
WordPress install behind PigCache:

  * Is Redis up, reachable, and within latency budget?
  * Is the cache *actually* absorbing load (real hit ratio, not the in-request one)?
  * Is Redis memory being used efficiently, or is it bloated with no-TTL keys?
  * Are evictions happening (= maxmemory too low or bad keys)?
  * Which PigCache group (html / sql / fragments / options / posts / …) is
    consuming memory?
  * Are stampede locks piling up (= regenerations queueing)?
  * Why does MySQL throw "Error establishing a database connection" — connection
    saturation, aborted clients, slow queries piling up?

Output: plain-text dashboard (default), single-line log (for cron tailing),
strict JSON (for ingestion / jq / Prometheus), or non-zero exit codes for
alerting via cPanel email-on-failure.

Quick start
-----------

The `report` subcommand is the swiss-army-knife: it gathers EVERYTHING
(Redis snapshot + per-group memory breakdown + stampede locks + MySQL
saturation + pre-computed alerts) into a single JSON payload, and is the
exact same invocation whether you run it by hand or from cron.

Run it from inside `wp-content/plugins/pigcache/cli/` — `wp-config.php` is
auto-discovered by walking up the directory tree (same algorithm as
`bin/pigcache-cron.php`), so no flag is needed:

    cd /home/USER/public_html/wp-content/plugins/pigcache/cli

    # 1) MANUAL — prints the full JSON report to the terminal (dry-run)
    python3 pigcache-monitor.py report

    # 1b) MANUAL human-readable variant (still includes everything):
    python3 pigcache-monitor.py report | python3 -m json.tool | less

    # 2) CRON — same command, pushes the same payload to the PigCache API
    #    every 5 min, silent in stdout, errors emailed by cPanel on non-zero exit
    */5 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py report --push --quiet >> /home/USER/logs/pigcache-monitor.err 2>&1

The same `report` payload can also be kept on disk for offline analysis:

    python3 pigcache-monitor.py report --save /home/USER/logs/pigcache-$(date +%Y%m%d-%H%M).json --push --quiet

Sizing the SCAN sample
----------------------

`report` and `breakdown` walk Redis via SCAN to compute the per-group memory
breakdown. By default they scan up to 20,000 keys (`--sample-cap 20000`),
which on a 700k-key database means ~2.8 % coverage — fine for trends, but
inaccurate per-group totals get extrapolated from a tiny sample.

For accurate numbers, point `--sample-cap` at your real DBSIZE. Three ways
(all work via either the redis-py driver OR the stdlib-bare fallback):

    # ── 0) Quick check: how many keys does this Redis hold right now? ─
    #     (DBSIZE is global to the Redis DB you connect to; PigCache uses
    #      whatever PIGCACHE_REDIS_DATABASE points at in wp-config.php.)
    python3 pigcache-monitor.py snapshot --skip-mysql --json | python3 -c "import json,sys;d=json.load(sys.stdin);print('dbsize:',d['redis']['dbsize'])"

    # ── 1) Easy mode: scan EVERYTHING (cap auto-resolves to DBSIZE) ────
    #     For a 1M-key Redis this typically runs in ~2-4 s and gives you
    #     100% coverage. Use this when you want trustworthy per-group bytes.
    python3 pigcache-monitor.py report --sample-cap auto --quiet --save /home/USER/logs/pigcache-$(date +%Y%m%d-%H%M).json

    # ── 2) Percentage of DBSIZE (sample 25% of the keyspace) ──────────
    python3 pigcache-monitor.py report --sample-cap pct:25 --quiet --save /home/USER/logs/pigcache-$(date +%Y%m%d-%H%M).json

    # ── 3) Explicit number, with k/m shorthand ────────────────────────
    python3 pigcache-monitor.py report --sample-cap 200k --quiet --save /home/USER/logs/pigcache-$(date +%Y%m%d-%H%M).json
    python3 pigcache-monitor.py report --sample-cap 1m   --quiet --save /home/USER/logs/pigcache-$(date +%Y%m%d-%H%M).json

The resulting JSON always reports the actual coverage so you can tell what
you got:

    "breakdown": {
      "dbsize_total":         705679,
      "scanned_keys":         705679,
      "coverage_pct":         100.0,
      "sample_cap_requested": "auto",
      "sample_cap_resolved":  705679,
      ...
    }

Rule of thumb: for ad-hoc analysis use `--sample-cap auto`; for high-frequency
cron use a fixed integer (e.g. `--sample-cap 50000`) so each run takes a
predictable amount of time even as the keyspace grows.

Lighter cron probes (use these in *addition* to `report` if you want
sub-minute granularity for tail/grep/alerting):

    # Per-minute single-line metric log (tailable, awk-friendly)
    * * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py log-line >> /home/USER/logs/pigcache-monitor.log 2>&1

    # Pass/fail alert (cPanel emails any non-zero exit)
    */5 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py health

If you need to point at a wp-config in a non-standard location, every
subcommand accepts `--wp-config /path/to/wp-config.php`.

Install (recommended, but optional — see the Requirements block below):

    pip3 install --user redis PyMySQL    # or: mysql-connector-python

Requirements
------------
  * Python 3.6+ — the ONLY hard requirement. The script falls back to:
        - a stdlib-only Redis RESP client if `redis-py` is missing,
        - the `mariadb` / `mysql` CLI binary (via subprocess) if no Python
          MySQL driver is installed.
    So on locked-down cPanel hosts with no pip you still get a fully
    working monitor.
  * Recommended (faster, less subprocess overhead, but optional):
        pip3 install --user redis PyMySQL
"""

import argparse
import json
import os
import re
import socket
import sys
import time
from collections import defaultdict
from datetime import datetime

# ─── Optional deps ───────────────────────────────────────────────────────────
#
# We try redis-py first (richer + faster), but if it isn't installed we fall
# back to a minimal stdlib-only RESP client further down (_BareRedisClient).
# That makes the script work on locked-down cPanel hosts where you cannot
# install Python packages at all.

try:
    import redis as redis_lib
except ImportError:
    redis_lib = None

DB_MODULE = None
try:
    import mysql.connector as _mysql_mod
    DB_MODULE = "mysql-connector"
except ImportError:
    try:
        import pymysql as _mysql_mod
        DB_MODULE = "pymysql"
    except ImportError:
        _mysql_mod = None


# ─── Stdlib-only Redis fallback (used when redis-py is not installed) ────────

class _BareRedisError(Exception):
    """Raised by _BareRedisClient when the server returns -ERR or the socket
    misbehaves. Caught by the existing snapshot/scan loops the same way the
    redis-py exceptions are."""


class _BareRedisClient:
    """Minimal Redis client implemented on top of stdlib `socket` + RESP2.

    Implements only the subset that pigcache-monitor.py needs:
        ping, info, config_get, dbsize, scan, ttl, memory_usage,
        slowlog_get, pipeline(transaction=False).{ttl,memory_usage}.execute()

    This is the zero-dependency fallback for cPanel hosts where you cannot
    install redis-py. Performance is intentionally simple (no connection pool,
    no SSL, no cluster) — perfect for a cron-driven monitor probe.
    """

    def __init__(self, host="127.0.0.1", port=6379, password=None, db=0,
                 socket_timeout=3.0, socket_connect_timeout=3.0,
                 decode_responses=True):
        self.host = host
        self.port = port
        self.password = password
        self.db = db
        self.socket_timeout = socket_timeout
        self.socket_connect_timeout = socket_connect_timeout
        self.decode_responses = decode_responses
        self._sock = None
        self._buf = b""

    def _connect(self):
        if self._sock is not None:
            return
        s = socket.create_connection((self.host, self.port),
                                     timeout=self.socket_connect_timeout)
        s.settimeout(self.socket_timeout)
        self._sock = s
        self._buf = b""
        if self.password:
            self._call("AUTH", self.password)
        if self.db:
            self._call("SELECT", str(self.db))

    def _close(self):
        if self._sock is not None:
            try:
                self._sock.close()
            except OSError:
                pass
            self._sock = None
            self._buf = b""

    @staticmethod
    def _encode_cmd(args):
        parts = [b"*", str(len(args)).encode(), b"\r\n"]
        for a in args:
            if isinstance(a, str):
                a = a.encode("utf-8")
            elif isinstance(a, (int, float)):
                a = str(a).encode()
            elif not isinstance(a, (bytes, bytearray)):
                a = str(a).encode("utf-8")
            parts.extend([b"$", str(len(a)).encode(), b"\r\n", a, b"\r\n"])
        return b"".join(parts)

    def _read_line(self):
        while b"\r\n" not in self._buf:
            chunk = self._sock.recv(65536)
            if not chunk:
                raise _BareRedisError("connection closed by server")
            self._buf += chunk
        idx = self._buf.index(b"\r\n")
        line = self._buf[:idx]
        self._buf = self._buf[idx + 2:]
        return line

    def _read_n(self, n):
        needed = n + 2
        while len(self._buf) < needed:
            chunk = self._sock.recv(max(65536, needed - len(self._buf)))
            if not chunk:
                raise _BareRedisError("connection closed by server")
            self._buf += chunk
        out = self._buf[:n]
        self._buf = self._buf[needed:]
        return out

    def _read_reply(self):
        line = self._read_line()
        if not line:
            raise _BareRedisError("empty reply")
        prefix = chr(line[0])
        rest = line[1:]

        if prefix == "+":
            return rest.decode("utf-8", "replace")
        if prefix == "-":
            raise _BareRedisError(rest.decode("utf-8", "replace"))
        if prefix == ":":
            return int(rest)
        if prefix == "$":
            n = int(rest)
            if n == -1:
                return None
            data = self._read_n(n)
            if self.decode_responses:
                try:
                    return data.decode("utf-8")
                except UnicodeDecodeError:
                    return data
            return data
        if prefix == "*":
            n = int(rest)
            if n == -1:
                return None
            return [self._read_reply() for _ in range(n)]
        raise _BareRedisError(f"unknown RESP type byte: {prefix!r}")

    def _call(self, *args):
        self._connect()
        try:
            self._sock.sendall(self._encode_cmd(args))
            return self._read_reply()
        except (socket.timeout, OSError) as exc:
            self._close()
            raise _BareRedisError(str(exc))

    # ── Public surface (matches the subset of redis-py we use) ─────────

    def ping(self):
        return self._call("PING") == "PONG"

    def info(self, section=None):
        args = ["INFO"]
        if section:
            args.append(section)
        raw = self._call(*args) or ""
        result = {}
        for line in raw.splitlines():
            if not line or line.startswith("#") or ":" not in line:
                continue
            k, v = line.split(":", 1)
            try:
                if "." in v:
                    result[k] = float(v)
                else:
                    result[k] = int(v)
            except (TypeError, ValueError):
                result[k] = v
        return result

    def config_get(self, pattern):
        flat = self._call("CONFIG", "GET", pattern)
        if not isinstance(flat, list):
            return {}
        return {flat[i]: flat[i + 1] for i in range(0, len(flat) - 1, 2)}

    def dbsize(self):
        return int(self._call("DBSIZE"))

    def scan(self, cursor=0, match=None, count=500):
        args = ["SCAN", str(cursor)]
        if match:
            args.extend(["MATCH", match])
        if count:
            args.extend(["COUNT", str(count)])
        result = self._call(*args)
        if not isinstance(result, list) or len(result) < 2:
            return (0, [])
        return (int(result[0]), result[1] or [])

    def ttl(self, key):
        return int(self._call("TTL", key))

    def memory_usage(self, key):
        r = self._call("MEMORY", "USAGE", key)
        return int(r) if r is not None else None

    def slowlog_get(self, n=10):
        result = self._call("SLOWLOG", "GET", str(n))
        if not isinstance(result, list):
            return []
        out = []
        for entry in result:
            # entry: [id, start_time, duration_us, [cmd...], (client_ip?, client_name?)]
            if not isinstance(entry, list) or len(entry) < 4:
                continue
            out.append({
                "id": entry[0],
                "start_time": entry[1],
                "duration": entry[2],
                "command": entry[3] if isinstance(entry[3], list) else [entry[3]],
            })
        return out

    def pipeline(self, transaction=False):
        return _BarePipeline(self)

    def close(self):
        self._close()


class _BarePipeline:
    """Buffers commands and flushes them in one sendall(), then reads N
    replies. Matches the tiny redis-py pipeline subset we call."""

    def __init__(self, client):
        self._client = client
        self._commands = []

    def ttl(self, key):
        self._commands.append(("TTL", key))
        return self

    def memory_usage(self, key):
        self._commands.append(("MEMORY", "USAGE", key))
        return self

    def execute(self):
        if not self._commands:
            return []
        self._client._connect()
        encode = self._client._encode_cmd
        payload = b"".join(encode(c) for c in self._commands)
        try:
            self._client._sock.sendall(payload)
        except (socket.timeout, OSError) as exc:
            self._client._close()
            raise _BareRedisError(str(exc))

        results = []
        for _ in self._commands:
            try:
                results.append(self._client._read_reply())
            except _BareRedisError:
                results.append(None)
        self._commands = []
        return results


# ─── wp-config.php parser (re-used pattern from pigcache-analyze.py) ─────────

WP_CONFIG_CONSTANTS = (
    "DB_NAME", "DB_USER", "DB_PASSWORD", "DB_HOST",
    "PIGCACHE_REDIS_HOST", "PIGCACHE_REDIS_PORT",
    "PIGCACHE_REDIS_PASSWORD", "PIGCACHE_REDIS_DATABASE",
    "PIGCACHE_REDIS_PREFIX", "PIGCACHE_REDIS_TIMEOUT",
    "WP_CACHE_KEY_SALT",
    # API / cloud credentials — match bin/pigcache-cron.php exactly.
    "PIGCACHE_CLOUD_API_URL", "PIGCACHE_API_KEY",
    "WP_HOME", "WP_SITEURL",
)

DEFAULT_API_URL = "https://bluecache.pigworlds.com/api/v1"


def parse_wp_config(path):
    """Extract relevant defines from wp-config.php without executing PHP."""
    out = {}
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            content = f.read()
    except OSError as exc:
        print(f"warn: could not read wp-config.php at {path}: {exc}",
              file=sys.stderr)
        return out

    for const in WP_CONFIG_CONSTANTS:
        m = re.search(
            rf"define\s*\(\s*['\"]({const})['\"]\s*,\s*"
            r"(?:'([^']*)'|\"([^\"]*)\"|(true|false|[0-9]+))\s*\)",
            content,
        )
        if not m:
            continue
        val = m.group(2) or m.group(3) or m.group(4) or ""
        if val == "true":
            val = True
        elif val == "false":
            val = False
        out[const] = val

    m = re.search(r"\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]", content)
    if m:
        out["table_prefix"] = m.group(1)

    return out


def find_wp_config(start_dir=None, max_levels=6):
    """Auto-discover wp-config.php by walking up from the script directory.

    Mirrors the strategy of `_pigcache_cron_find_wp_config()` in
    bin/pigcache-cron.php so both tools behave the same way when invoked
    without `--wp-config`. The script is typically at
        wp-content/plugins/pigcache/cli/pigcache-monitor.py
    so wp-config.php is ~4 levels up. We walk up to `max_levels` (default 6)
    to give margin for non-standard installs.
    """
    if start_dir is None:
        start_dir = os.path.dirname(os.path.abspath(__file__))

    current = start_dir
    for _ in range(max_levels):
        candidate = os.path.join(current, "wp-config.php")
        if os.path.isfile(candidate):
            return candidate
        parent = os.path.dirname(current)
        if parent == current:
            break
        current = parent

    return None


def fetch_wp_options(conn, table_prefix, names):
    """Fetch one or more rows from wp_options as a dict of name -> raw value.

    Used to retrieve api_key / site_id from the database the same way the PHP
    cron does it (`pigcache_license_key`, `pigcache_cloud_site_id`).
    """
    table = (table_prefix or "wp_") + "options"
    cur = conn.cursor()
    placeholders = ",".join(["%s"] * len(names))
    out = {}
    try:
        cur.execute(
            f"SELECT option_name, option_value FROM `{table}` "
            f"WHERE option_name IN ({placeholders})",
            tuple(names),
        )
        for row in cur.fetchall():
            out[row[0]] = row[1]
    except Exception:
        pass
    finally:
        try:
            cur.close()
        except Exception:
            pass
    return out


def resolve_api_credentials(args, cfg, mysql_conn=None):
    """Resolve (api_url, api_key, site_id, site_url) the same way as the PHP cron.

    Precedence (highest first):
      1. Explicit CLI flags (--api-url, --api-key, --site-id).
      2. Constants in wp-config.php (PIGCACHE_CLOUD_API_URL, PIGCACHE_API_KEY).
      3. wp_options rows (pigcache_license_key, pigcache_cloud_site_id, siteurl).
    """
    api_url = (
        getattr(args, "api_url", None)
        or cfg.get("PIGCACHE_CLOUD_API_URL")
        or DEFAULT_API_URL
    )
    api_url = api_url.rstrip("/")

    api_key = getattr(args, "api_key", None) or cfg.get("PIGCACHE_API_KEY") or ""
    site_id = getattr(args, "site_id", None) or ""
    site_url = cfg.get("WP_HOME") or cfg.get("WP_SITEURL") or ""

    if mysql_conn is not None and (not api_key or not site_id or not site_url):
        opts = fetch_wp_options(
            mysql_conn,
            cfg.get("table_prefix") or "wp_",
            ("pigcache_license_key", "pigcache_cloud_site_id", "siteurl", "home"),
        )
        if not api_key:
            api_key = opts.get("pigcache_license_key", "") or ""
        if not site_id:
            site_id = opts.get("pigcache_cloud_site_id", "") or ""
        if not site_url:
            site_url = opts.get("home") or opts.get("siteurl") or ""

    return api_url, str(api_key), str(site_id), str(site_url)


def post_json_to_api(url, body, api_key, site_id, timeout=20):
    """POST a JSON payload to the PigCache API using stdlib `urllib` only.

    Mirrors `_pigcache_cron_api_request()` from bin/pigcache-cron.php:
    same Authorization / X-Site-Id headers, same JSON content type, same
    20s default timeout. Returns (status_code, response_text).
    """
    from urllib import request as urllib_request
    from urllib import error as urllib_error

    payload = json.dumps(body, default=str).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "pigcache-monitor/1.0",
    }
    if api_key:
        headers["Authorization"] = "Bearer " + api_key
    if site_id:
        headers["X-Site-Id"] = str(site_id)

    req = urllib_request.Request(url, data=payload, headers=headers, method="POST")
    try:
        with urllib_request.urlopen(req, timeout=timeout) as resp:
            return resp.getcode(), resp.read().decode("utf-8", "replace")
    except urllib_error.HTTPError as exc:
        try:
            body_text = exc.read().decode("utf-8", "replace")
        except Exception:
            body_text = ""
        return exc.code, body_text
    except urllib_error.URLError as exc:
        return 0, "URLError: " + str(exc.reason)
    except (socket.timeout, OSError) as exc:
        return 0, "socket/OS error: " + str(exc)


# ─── Redis connection ────────────────────────────────────────────────────────

def connect_redis(args, cfg):
    """Return a connected Redis client (redis-py if available, otherwise the
    stdlib-only _BareRedisClient). Raises SystemExit(2) if neither can talk
    to the Redis server."""
    host = args.redis_host or cfg.get("PIGCACHE_REDIS_HOST") or "127.0.0.1"
    port_raw = args.redis_port or cfg.get("PIGCACHE_REDIS_PORT") or 6379
    try:
        port = int(port_raw)
    except (TypeError, ValueError):
        port = 6379

    # Allow host:port shorthand.
    if isinstance(host, str) and ":" in host and args.redis_port is None:
        host, p = host.rsplit(":", 1)
        try:
            port = int(p)
        except ValueError:
            pass

    password = args.redis_password
    if password is None:
        password = cfg.get("PIGCACHE_REDIS_PASSWORD") or None

    db = args.redis_db
    if db is None:
        db_raw = cfg.get("PIGCACHE_REDIS_DATABASE")
        if db_raw not in (None, "", False):
            try:
                db = int(db_raw)
            except (TypeError, ValueError):
                db = 0
        else:
            db = 0

    timeout = float(args.timeout)

    if redis_lib is not None:
        client = redis_lib.Redis(
            host=host,
            port=port,
            password=password,
            db=db,
            socket_timeout=timeout,
            socket_connect_timeout=timeout,
            decode_responses=True,
        )
        driver = "redis-py"
    else:
        client = _BareRedisClient(
            host=host,
            port=port,
            password=password,
            db=db,
            socket_timeout=timeout,
            socket_connect_timeout=timeout,
            decode_responses=True,
        )
        driver = "stdlib-bare"
        if not getattr(args, "json", False):
            print("info: redis-py not installed, using stdlib RESP fallback "
                  "(pip3 install --user redis for richer behaviour)",
                  file=sys.stderr)

    try:
        client.ping()
    except Exception as exc:
        print(f"error: cannot reach Redis at {host}:{port} (db {db}) "
              f"via {driver}: {exc}", file=sys.stderr)
        sys.exit(2)

    return client, {"host": host, "port": port, "db": db, "driver": driver}


# ─── MySQL connection ────────────────────────────────────────────────────────

def _resolve_mysql_endpoint(args, cfg):
    """Pick the (host, port) tuple to connect to, accepting `host:port` in
    either --mysql-host or wp-config's DB_HOST."""
    raw = args.mysql_host or cfg.get("DB_HOST") or "127.0.0.1"
    host = raw
    port = 3306
    if isinstance(raw, str) and ":" in raw:
        host, p = raw.rsplit(":", 1)
        try:
            port = int(p)
        except ValueError:
            host, port = raw, 3306
    return host, port


def connect_mysql(args, cfg):
    """Return a MySQL connection, or None on failure (with stderr message)."""
    if _mysql_mod is None:
        return None, "no MySQL Python driver — using mariadb/mysql CLI fallback"

    host, port = _resolve_mysql_endpoint(args, cfg)
    user = args.mysql_user or cfg.get("DB_USER") or "root"
    password = args.mysql_password
    if password is None:
        password = cfg.get("DB_PASSWORD", "")
    database = args.mysql_db or cfg.get("DB_NAME") or ""

    try:
        if DB_MODULE == "pymysql":
            conn = _mysql_mod.connect(
                host=host, port=port, user=user, password=password,
                database=database, charset="utf8mb4",
                connect_timeout=int(args.timeout),
                read_timeout=int(args.timeout),
            )
        else:
            conn = _mysql_mod.connect(
                host=host, port=port, user=user, password=password,
                database=database, charset="utf8mb4",
                connection_timeout=int(args.timeout),
            )
    except Exception as exc:
        return None, f"connect failed: {exc}"

    return conn, None


# ─── Redis circuit-breaker state ─────────────────────────────────────────────

def circuit_breaker_state(host, port):
    """Report the drop-in's circuit-breaker state.

    The drop-in keeps this state in APCu (see WP_Object_Cache::pigcache_circuit_key),
    which lives inside the PHP-FPM workers and is not reachable from a separate
    process. There is nothing for this script to inspect, so the state is reported
    as unknown rather than guessed.
    """
    import hashlib
    key = "pigcache_cb_" + hashlib.md5(f"{host}:{port}".encode()).hexdigest()
    return {"open": None, "key": key, "age_seconds": None}


# ─── Redis snapshot / metrics gathering ──────────────────────────────────────

def redis_snapshot(client, endpoint):
    """Take a comprehensive one-shot picture of the Redis server."""
    t0 = time.time()
    info = client.info()
    info_latency_ms = (time.time() - t0) * 1000

    try:
        config = client.config_get("maxmemory*")
    except Exception:
        config = {}

    try:
        dbsize = int(client.dbsize())
    except Exception:
        dbsize = None

    # PING latency (separate sample so it's not skewed by INFO size).
    pings = []
    for _ in range(5):
        t = time.time()
        try:
            client.ping()
            pings.append((time.time() - t) * 1000)
        except Exception:
            pings.append(float("inf"))
    pings.sort()
    ping_p50 = pings[len(pings) // 2]
    ping_max = pings[-1]

    hits = int(info.get("keyspace_hits", 0))
    misses = int(info.get("keyspace_misses", 0))
    total_ops = hits + misses
    hit_ratio = (hits / total_ops * 100.0) if total_ops else None

    used_memory = int(info.get("used_memory", 0))
    maxmemory_raw = config.get("maxmemory", "0")
    try:
        maxmemory = int(maxmemory_raw)
    except (TypeError, ValueError):
        maxmemory = 0
    mem_fill_pct = (used_memory / maxmemory * 100.0) if maxmemory > 0 else None

    # Slowlog top 5.
    slow_top = []
    try:
        for entry in client.slowlog_get(5):
            slow_top.append({
                "id": entry.get("id"),
                "start_time": entry.get("start_time"),
                "duration_us": entry.get("duration"),
                "command": " ".join(
                    str(a) for a in (entry.get("command") or [])
                )[:200],
            })
    except Exception:
        pass

    snapshot = {
        "endpoint": endpoint,
        "captured_at": datetime.utcnow().isoformat() + "Z",
        "info_latency_ms": round(info_latency_ms, 2),
        "ping_p50_ms": round(ping_p50, 3),
        "ping_max_ms": round(ping_max, 3),
        "redis_version": info.get("redis_version"),
        "uptime_seconds": int(info.get("uptime_in_seconds", 0)),
        "connected_clients": int(info.get("connected_clients", 0)),
        "blocked_clients": int(info.get("blocked_clients", 0)),
        "maxclients": int(info.get("maxclients", 0)),
        "instantaneous_ops_per_sec": int(info.get("instantaneous_ops_per_sec", 0)),
        "total_commands_processed": int(info.get("total_commands_processed", 0)),
        "total_connections_received": int(info.get("total_connections_received", 0)),
        "rejected_connections": int(info.get("rejected_connections", 0)),
        "keyspace_hits": hits,
        "keyspace_misses": misses,
        "hit_ratio_pct": round(hit_ratio, 2) if hit_ratio is not None else None,
        "evicted_keys": int(info.get("evicted_keys", 0)),
        "expired_keys": int(info.get("expired_keys", 0)),
        "used_memory_bytes": used_memory,
        "used_memory_human": info.get("used_memory_human"),
        "used_memory_peak_bytes": int(info.get("used_memory_peak", 0)),
        "used_memory_peak_human": info.get("used_memory_peak_human"),
        "used_memory_rss_bytes": int(info.get("used_memory_rss", 0)),
        "mem_fragmentation_ratio": float(info.get("mem_fragmentation_ratio", 0) or 0),
        "maxmemory_bytes": maxmemory,
        "maxmemory_policy": config.get("maxmemory-policy", "?"),
        "maxmemory_fill_pct": round(mem_fill_pct, 2) if mem_fill_pct is not None else None,
        "dbsize": dbsize,
        "slowlog_top5": slow_top,
    }
    return snapshot


# ─── SCAN-based group breakdown ──────────────────────────────────────────────

# Recognised group names whose keys we want to bucket nicely. Anything not in
# this list is grouped under the literal group token found in the key.
KNOWN_GROUPS = {
    # PigCache native
    "pigcache_html", "pigcache_sql", "pigcache_fragments", "pigcache",
    # WordPress core / very common plugins (the ones that dominate volume).
    "options", "site-options", "transient", "site-transient",
    "posts", "post_meta", "post-queries",
    "terms", "term_meta", "term-queries", "term_relationships",
    "category_relationships", "post_tag_relationships",
    "users", "user_meta",
    "comments", "comment_meta", "comment-queries",
    "themes", "plugins", "translation_files",
}


def _classify_key(key):
    """Return the cache group of a PigCache key like
    'PREFIX:blogprefix:GROUP:userkey' or 'PREFIX:GROUP:userkey'.
    Falls back to '__other__' when the shape doesn't match.
    """
    parts = key.split(":", 4)
    # Try each part from the right side; the group is the segment that matches
    # KNOWN_GROUPS or starts with 'pigcache'.
    for p in parts:
        if p in KNOWN_GROUPS:
            return p
        if p.startswith("pigcache"):
            return p
    if len(parts) >= 2:
        return parts[-2] or "__other__"
    return "__other__"


def scan_breakdown(client, pattern, cap, sample_per_group, count):
    """Walk the keyspace via SCAN and bucket keys per group.

    For each group: counts the keys, samples up to `sample_per_group` for
    MEMORY USAGE + TTL stats, and computes averages.
    """
    groups = defaultdict(lambda: {
        "key_count": 0,
        "no_ttl_count": 0,
        "ttls": [],
        "sampled_bytes": [],
        "sample_keys": [],
        "sample_size": 0,
    })

    scanned = 0
    cursor = 0
    pipe_chunk = 200

    while True:
        cursor, batch = client.scan(cursor=cursor, match=pattern, count=count)
        if batch:
            # Pre-classify and pipeline TTL for the whole batch.
            classified = [(_classify_key(k), k) for k in batch]
            pipe = client.pipeline(transaction=False)
            for _, k in classified:
                pipe.ttl(k)
            try:
                ttls = pipe.execute()
            except Exception:
                ttls = [None] * len(classified)

            for (group, k), ttl in zip(classified, ttls):
                g = groups[group]
                g["key_count"] += 1
                if ttl is None or ttl == -1:
                    g["no_ttl_count"] += 1
                elif ttl > 0:
                    g["ttls"].append(int(ttl))

                # Sample MEMORY USAGE only up to sample_per_group per group.
                if g["sample_size"] < sample_per_group:
                    g["sample_keys"].append(k)
                    g["sample_size"] += 1

            scanned += len(batch)

        if cursor == 0 or scanned >= cap:
            break

    # Now MEMORY USAGE for the sampled keys, pipelined per group.
    for group, g in groups.items():
        if not g["sample_keys"]:
            continue
        for i in range(0, len(g["sample_keys"]), pipe_chunk):
            chunk = g["sample_keys"][i:i + pipe_chunk]
            pipe = client.pipeline(transaction=False)
            for k in chunk:
                pipe.memory_usage(k)
            try:
                sizes = pipe.execute()
            except Exception:
                sizes = [None] * len(chunk)
            for sz in sizes:
                if isinstance(sz, int) and sz > 0:
                    g["sampled_bytes"].append(sz)

    # Build summary rows.
    rows = []
    for group, g in groups.items():
        sb = g["sampled_bytes"]
        ttls = g["ttls"]
        avg_bytes = (sum(sb) / len(sb)) if sb else None
        est_total_bytes = int(avg_bytes * g["key_count"]) if avg_bytes else None
        rows.append({
            "group": group,
            "key_count": g["key_count"],
            "no_ttl_count": g["no_ttl_count"],
            "no_ttl_pct": round(g["no_ttl_count"] / g["key_count"] * 100, 1) if g["key_count"] else 0,
            "avg_ttl_s": int(sum(ttls) / len(ttls)) if ttls else None,
            "min_ttl_s": min(ttls) if ttls else None,
            "max_ttl_s": max(ttls) if ttls else None,
            "sample_size": len(sb),
            "avg_bytes_sampled": int(avg_bytes) if avg_bytes else None,
            "est_total_bytes": est_total_bytes,
        })

    rows.sort(key=lambda r: r["est_total_bytes"] or 0, reverse=True)
    return {
        "scanned_keys": scanned,
        "scan_capped_at": cap,
        "pattern": pattern,
        "groups": rows,
    }


# ─── Hot keys (largest by MEMORY USAGE) ──────────────────────────────────────

def scan_hot_keys(client, pattern, cap, top_n, count):
    sampled = []
    cursor = 0
    scanned = 0
    pipe_chunk = 200

    while True:
        cursor, batch = client.scan(cursor=cursor, match=pattern, count=count)
        if batch:
            for i in range(0, len(batch), pipe_chunk):
                chunk = batch[i:i + pipe_chunk]
                pipe = client.pipeline(transaction=False)
                for k in chunk:
                    pipe.memory_usage(k)
                try:
                    sizes = pipe.execute()
                except Exception:
                    sizes = [None] * len(chunk)
                for k, sz in zip(chunk, sizes):
                    if isinstance(sz, int) and sz > 0:
                        sampled.append((sz, k))

            scanned += len(batch)
        if cursor == 0 or scanned >= cap:
            break

    sampled.sort(reverse=True)
    return {
        "scanned_keys": scanned,
        "scan_capped_at": cap,
        "pattern": pattern,
        "top": [{"bytes": sz, "key": k} for sz, k in sampled[:top_n]],
    }


# ─── Stampede locks ──────────────────────────────────────────────────────────

def scan_stampede_locks(client, pattern, cap, count):
    locks = []
    cursor = 0
    scanned = 0
    while True:
        cursor, batch = client.scan(cursor=cursor, match=pattern, count=count)
        for k in batch:
            try:
                ttl = client.ttl(k)
            except Exception:
                ttl = None
            locks.append({"key": k, "ttl": ttl})
            scanned += 1
            if scanned >= cap:
                break
        if cursor == 0 or scanned >= cap:
            break
    return {"active_lock_count": len(locks), "locks": locks[:50]}


# ─── MySQL probe — Python driver path + mariadb/mysql CLI fallback ──────────
#
# On shared cPanel hosts you often can't install PyMySQL or mysql-connector
# (no pip). But the `mariadb` (or `mysql`) CLI binary is universally present.
# When the Python driver is missing we shell out to it via `subprocess`,
# parse the tab-separated output, and produce the same result dict — so the
# rest of the script doesn't care which path was taken.

# Status keys we want from SHOW GLOBAL STATUS (everything else is filtered out
# to keep the payload small even though SHOW STATUS returns ~500 rows).
_MYSQL_STATUS_KEYS = frozenset((
    "Threads_connected", "Threads_running", "Threads_cached", "Threads_created",
    "Max_used_connections", "Aborted_connects", "Aborted_clients",
    "Connection_errors_max_connections", "Connection_errors_internal",
    "Slow_queries", "Uptime", "Questions", "Com_select", "Com_insert",
    "Com_update", "Com_delete",
))

_MYSQL_VAR_KEYS = frozenset((
    "max_connections", "wait_timeout", "interactive_timeout",
    "max_user_connections", "table_open_cache", "innodb_buffer_pool_size",
    "version", "version_comment",
))


def _build_mysql_summary(status, variables, processlist_count, proc_states,
                         probe_via):
    """Shared dict-builder used by both the Python-driver and CLI paths.
    Produces the exact same shape so consumers (snapshot, report, alerts)
    don't need to know which path produced the data."""

    def _i(d, k, default=0):
        try:
            return int(d.get(k, default))
        except (TypeError, ValueError):
            return default

    threads_conn = _i(status, "Threads_connected")
    threads_run = _i(status, "Threads_running")
    max_used = _i(status, "Max_used_connections")
    max_conn = _i(variables, "max_connections")
    aborted_c = _i(status, "Aborted_clients")
    aborted_s = _i(status, "Aborted_connects")
    uptime = _i(status, "Uptime")
    questions = _i(status, "Questions")

    return {
        "captured_at": datetime.utcnow().isoformat() + "Z",
        "probe_via": probe_via,
        "server_version": variables.get("version") or "?",
        "threads_connected": threads_conn,
        "threads_running": threads_run,
        "max_used_connections": max_used,
        "max_connections": max_conn,
        "conn_saturation_pct": round(threads_conn / max_conn * 100, 2) if max_conn else None,
        "peak_saturation_pct": round(max_used / max_conn * 100, 2) if max_conn else None,
        "aborted_clients": aborted_c,
        "aborted_connects": aborted_s,
        "connection_errors_max_connections": _i(status, "Connection_errors_max_connections"),
        "connection_errors_internal": _i(status, "Connection_errors_internal"),
        "slow_queries": _i(status, "Slow_queries"),
        "uptime_s": uptime,
        "questions": questions,
        "qps_avg_since_boot": round(questions / uptime, 2) if uptime else None,
        "wait_timeout_s": _i(variables, "wait_timeout"),
        "innodb_buffer_pool_bytes": _i(variables, "innodb_buffer_pool_size"),
        "processlist_total": processlist_count,
        "processlist_by_command": dict(proc_states),
    }


# ── Python-driver path ─────────────────────────────────────────────────

def mysql_probe(conn):
    """Probe MySQL via the open Python-driver connection."""
    cur = conn.cursor()

    status = {}
    try:
        cur.execute("SHOW GLOBAL STATUS")
        for row in cur.fetchall():
            if row and row[0] in _MYSQL_STATUS_KEYS:
                status[row[0]] = row[1]
    except Exception:
        pass

    variables = {}
    try:
        cur.execute("SHOW VARIABLES")
        for row in cur.fetchall():
            if row and row[0] in _MYSQL_VAR_KEYS:
                variables[row[0]] = row[1]
    except Exception:
        pass

    processlist_count = None
    proc_states = defaultdict(int)
    try:
        cur.execute("SELECT COMMAND FROM information_schema.PROCESSLIST")
        for (cmd,) in cur.fetchall():
            processlist_count = (processlist_count or 0) + 1
            proc_states[cmd or "?"] += 1
    except Exception:
        pass

    cur.close()
    return _build_mysql_summary(
        status, variables, processlist_count, proc_states,
        probe_via="python-driver:" + (DB_MODULE or "?"),
    )


# ── CLI-binary path (mariadb / mysql) ──────────────────────────────────

# Common cPanel install locations checked when shutil.which() comes up empty
# (cron jobs usually run with a very minimal PATH like "/usr/bin:/bin").
_MYSQL_CLI_FALLBACK_PATHS = (
    "/usr/bin/mariadb", "/usr/local/bin/mariadb",
    "/usr/bin/mysql", "/usr/local/bin/mysql",
    # cPanel EasyApache / MariaDB packages:
    "/opt/cpanel/ea-mariadb*/bin/mariadb",
    "/opt/cpanel/ea-mysql*/bin/mysql",
)


def find_mysql_cli(override=None):
    """Return absolute path to `mariadb` or `mysql` CLI binary, or None.
    Used as the no-dependency fallback for the MySQL probe when neither
    PyMySQL nor mysql-connector-python is installed (typical cPanel)."""
    import shutil
    import glob as _glob

    if override:
        if os.path.isfile(override) and os.access(override, os.X_OK):
            return override
        return None

    for name in ("mariadb", "mysql"):
        path = shutil.which(name)
        if path:
            return path

    for pattern in _MYSQL_CLI_FALLBACK_PATHS:
        if "*" in pattern:
            for p in _glob.glob(pattern):
                if os.access(p, os.X_OK):
                    return p
        elif os.path.isfile(pattern) and os.access(pattern, os.X_OK):
            return pattern

    return None


def _mysql_cli_invoke(binary, host, port, user, password, db, sql, timeout):
    """Run a single SQL statement through the mariadb/mysql CLI in batch mode.
    Returns the raw tab-separated text on success, raises RuntimeError on
    non-zero exit. Password is passed via MYSQL_PWD env (the documented
    way to avoid leaking it in `ps`)."""
    import subprocess

    env = os.environ.copy()
    if password:
        env["MYSQL_PWD"] = password
    # Strip any inherited credentials we don't want the CLI to pick up.
    env.pop("MYSQL_HOST", None)
    env.pop("MYSQL_USER", None)

    cmd = [binary,
           "-h", str(host),
           "-P", str(port),
           "-u", str(user),
           "--batch",                # tab-separated, machine-readable
           "--skip-column-names",    # no header row
           "--silent",               # suppress connect-time chatter
           "-e", sql]
    if db:
        cmd.extend(["-D", str(db)])

    try:
        result = subprocess.run(
            cmd, env=env,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=timeout, check=False,
        )
    except subprocess.TimeoutExpired:
        raise RuntimeError("CLI timeout after %ss" % timeout)
    except (FileNotFoundError, OSError) as exc:
        raise RuntimeError("CLI exec failed: %s" % exc)

    if result.returncode != 0:
        err = result.stderr.decode("utf-8", "replace").strip()
        # Trim very long error bodies so they fit in a single log line.
        raise RuntimeError("exit %d: %s" % (result.returncode, err[:300]))

    return result.stdout.decode("utf-8", "replace")


def _parse_kv_tab_lines(text):
    """Parse `--batch --skip-column-names` output of SHOW STATUS / VARIABLES.
    Each line is `key\\tvalue`. Empty lines and malformed rows are skipped."""
    out = {}
    for line in text.splitlines():
        if "\t" not in line:
            continue
        k, v = line.split("\t", 1)
        out[k] = v
    return out


def mysql_probe_via_cli(args, cfg, binary=None):
    """Probe MySQL by shelling out to `mariadb` / `mysql`. No Python deps.

    Returns the same shape as mysql_probe(conn) on success, or
    {"error": "..."} on failure.
    """
    if binary is None:
        binary = find_mysql_cli(getattr(args, "mysql_cli", None))
    if not binary:
        return {"error": "no MySQL Python driver and no mariadb/mysql CLI "
                         "binary found in PATH. Pass --mysql-cli /path/to/mariadb"}

    host, port = _resolve_mysql_endpoint(args, cfg)
    user = args.mysql_user or cfg.get("DB_USER") or "root"
    password = args.mysql_password
    if password is None:
        password = cfg.get("DB_PASSWORD", "") or ""
    database = args.mysql_db or cfg.get("DB_NAME") or ""

    timeout = max(2, int(args.timeout))

    try:
        status_text = _mysql_cli_invoke(
            binary, host, port, user, password, database,
            "SHOW GLOBAL STATUS", timeout,
        )
        vars_text = _mysql_cli_invoke(
            binary, host, port, user, password, database,
            "SHOW VARIABLES", timeout,
        )
        proc_text = _mysql_cli_invoke(
            binary, host, port, user, password, database,
            "SELECT COMMAND, COUNT(*) FROM information_schema.PROCESSLIST "
            "GROUP BY COMMAND", timeout,
        )
    except RuntimeError as exc:
        return {"error": "mariadb/mysql CLI probe failed: %s" % exc,
                "binary": binary}

    status = _parse_kv_tab_lines(status_text)
    status = {k: v for k, v in status.items() if k in _MYSQL_STATUS_KEYS}
    variables = _parse_kv_tab_lines(vars_text)
    variables = {k: v for k, v in variables.items() if k in _MYSQL_VAR_KEYS}

    processlist_count = 0
    proc_states = defaultdict(int)
    for line in proc_text.splitlines():
        if "\t" not in line:
            continue
        cmd, n = line.split("\t", 1)
        try:
            n_int = int(n)
        except ValueError:
            continue
        proc_states[cmd or "?"] += n_int
        processlist_count += n_int

    summary = _build_mysql_summary(
        status, variables, processlist_count, proc_states,
        probe_via="cli:" + os.path.basename(binary),
    )
    summary["cli_binary"] = binary
    return summary


# ── Orchestrator used by every subcommand that needs MySQL data ────────

def gather_mysql(args, cfg):
    """Return the MySQL probe dict, transparently choosing the best path.

    Order:
      1. Python driver (PyMySQL / mysql-connector-python) — if installed.
      2. `mariadb` / `mysql` CLI binary via subprocess — for cPanel hosts
         where no pip / no Python MySQL driver is available.
    """
    if _mysql_mod is not None:
        conn, err = connect_mysql(args, cfg)
        if conn is not None:
            try:
                return mysql_probe(conn)
            except Exception as exc:
                return {"error": "Python driver probe failed: %s" % exc}
            finally:
                try:
                    conn.close()
                except Exception:
                    pass
        # Python driver present but couldn't connect — try CLI as fallback.
        result = mysql_probe_via_cli(args, cfg)
        if "error" not in result:
            return result
        return {"error": "python-driver connect failed (%s); cli fallback: %s"
                         % (err, result.get("error"))}

    # No Python driver — straight to CLI fallback.
    return mysql_probe_via_cli(args, cfg)


def _open_mysql_for_options(args, cfg):
    """Open either a real Python driver connection OR a tiny shim that exposes
    a `query_options(table, names) -> dict` method backed by the CLI binary.

    Used by resolve_api_credentials() to look up pigcache_license_key and
    pigcache_cloud_site_id from wp_options without depending on PyMySQL.
    Returns (driver_conn_or_shim, kind) where kind is "python" | "cli" | None.
    """
    if _mysql_mod is not None:
        conn, _err = connect_mysql(args, cfg)
        if conn is not None:
            return conn, "python"

    binary = find_mysql_cli(getattr(args, "mysql_cli", None))
    if binary is None:
        return None, None

    return _MysqlCliOptionsShim(binary, args, cfg), "cli"


class _MysqlCliOptionsShim:
    """Minimal duck-type that lets fetch_wp_options() work over the CLI binary
    without changing its call signature."""

    def __init__(self, binary, args, cfg):
        self.binary = binary
        self.args = args
        self.cfg = cfg

    def cursor(self):
        return _MysqlCliCursor(self.binary, self.args, self.cfg)

    def close(self):
        pass


class _MysqlCliCursor:
    """Just enough of a DB-API cursor to satisfy fetch_wp_options(): supports
    `execute(sql, params)` for the SELECT used to read wp_options, then
    `fetchall()` returns a list of (name, value) tuples."""

    def __init__(self, binary, args, cfg):
        self.binary = binary
        self.args = args
        self.cfg = cfg
        self._rows = []

    def execute(self, sql, params=()):
        host, port = _resolve_mysql_endpoint(self.args, self.cfg)
        user = self.args.mysql_user or self.cfg.get("DB_USER") or "root"
        password = self.args.mysql_password
        if password is None:
            password = self.cfg.get("DB_PASSWORD", "") or ""
        database = self.args.mysql_db or self.cfg.get("DB_NAME") or ""

        # Inline params as quoted strings. fetch_wp_options() is the only
        # caller and always passes static option names — but we still
        # escape with backslashes to be safe.
        def _q(v):
            if v is None:
                return "NULL"
            s = str(v).replace("\\", "\\\\").replace("'", "\\'")
            return "'" + s + "'"

        rendered = sql.replace("%s", "{}").format(*[_q(p) for p in params])

        try:
            text = _mysql_cli_invoke(
                self.binary, host, port, user, password, database,
                rendered, max(2, int(self.args.timeout)),
            )
        except RuntimeError:
            self._rows = []
            return

        rows = []
        for line in text.splitlines():
            if "\t" in line:
                parts = line.split("\t")
                rows.append(tuple(parts))
            elif line:
                rows.append((line,))
        self._rows = rows

    def fetchall(self):
        return self._rows

    def close(self):
        pass


# ─── Memcached probe ─────────────────────────────────────────────────────────

# Well-known port → service name (used by service detector + monitor)
_PORT_SERVICES = {
    21: "ftp", 22: "ssh", 25: "smtp", 53: "dns",
    80: "http", 110: "pop3", 111: "rpcbind", 143: "imap",
    443: "https", 465: "smtps", 587: "smtp-submission",
    783: "spamd", 953: "rndc-dns", 993: "imaps", 995: "pop3s",
    2077: "cpanel-webdav", 2078: "cpanel-webdav-ssl",
    2079: "cpanel-caldav", 2080: "cpanel-caldav-ssl",
    2082: "cpanel", 2083: "cpanel-ssl", 2086: "whm", 2087: "whm-ssl",
    2091: "cpanel-leechprotect", 2095: "cpanel-webmail", 2096: "cpanel-webmail-ssl",
    3306: "mysql", 4190: "managesieve",
    6379: "redis", 6380: "redis-alt",
    7080: "litespeed-admin", 7443: "litespeed-admin-ssl",
    8080: "http-alt", 8443: "https-alt",
    8888: "webshell-or-stats", 8889: "webshell-or-stats-alt",
    9000: "php-fpm", 9098: "litespeed-internal",
    11211: "memcached", 11212: "memcached-alt",
    11234: "imunify360-or-custom", 12131: "imunify360",
    27217: "imunify360-agent",
}


def memcached_probe(host="127.0.0.1", port=11211, timeout=2.0):
    """Connect to Memcached, send `stats`, return key metrics.

    Uses raw socket — no deps. Returns {"available": False} when Memcached
    is unreachable or the response can't be parsed.
    """
    try:
        s = socket.create_connection((host, port), timeout=timeout)
        s.settimeout(timeout)
        s.sendall(b"stats\r\n")
        buf = b""
        while b"END\r\n" not in buf and len(buf) < 65536:
            chunk = s.recv(4096)
            if not chunk:
                break
            buf += chunk
        s.close()
    except (socket.timeout, OSError) as exc:
        return {"available": False, "error": str(exc), "host": host, "port": port}

    raw = {}
    for line in buf.decode("utf-8", "replace").splitlines():
        if line.startswith("STAT "):
            parts = line.split(None, 2)
            if len(parts) == 3:
                raw[parts[1]] = parts[2]

    def _i(k, default=0):
        try:
            return int(raw.get(k, default))
        except (ValueError, TypeError):
            return default

    hits   = _i("get_hits")
    misses = _i("get_misses")
    total  = hits + misses
    limit_bytes = _i("limit_maxbytes")
    used_bytes  = _i("bytes")

    return {
        "available":        True,
        "host":             host,
        "port":             port,
        "version":          raw.get("version", "?"),
        "uptime_s":         _i("uptime"),
        "curr_connections": _i("curr_connections"),
        "total_connections":_i("total_connections"),
        "curr_items":       _i("curr_items"),
        "total_items":      _i("total_items"),
        "get_hits":         hits,
        "get_misses":       misses,
        "hit_ratio_pct":    round(hits / total * 100, 2) if total else None,
        "evictions":        _i("evictions"),
        "limit_bytes":      limit_bytes,
        "used_bytes":       used_bytes,
        "mem_used_pct":     round(used_bytes / limit_bytes * 100, 1) if limit_bytes else None,
        "bytes_read":       _i("bytes_read"),
        "bytes_written":    _i("bytes_written"),
        "cmd_get":          _i("cmd_get"),
        "cmd_set":          _i("cmd_set"),
        "cas_hits":         _i("cas_hits"),
    }


# ─── Environment scan ────────────────────────────────────────────────────────
#
# Every probe is independent.  A failure in one never affects the others.
# Each sub-function returns a dict with at minimum {"available": bool}.
# The scan is intentionally exhaustive: it tries many possible paths because
# cPanel, LiteSpeed, Plesk, and plain LAMP installs all use different layouts.

def environment_scan(wp_root=None):
    """Probe the server for services, files, and tools that affect PigCache.

    Designed to be run once per `report` invocation.  All checks are best-
    effort: missing permissions, missing files, or unsupported kernel features
    produce {"available": False, "reason": "..."} rather than exceptions.

    Covers:
      - Web server / PHP handler (LiteSpeed vs Apache vs nginx)
      - LiteSpeed page cache (LSCache) — potential HTML cache conflict
      - CageFS isolation (CloudLinux) — explains /proc restrictions
      - Imunify360 presence
      - WP-CLI availability
      - Redis RDB dump (location + size — hints at user-space Redis)
      - AWStats data files (traffic source for Adaptive TTL v2)
      - Raw access logs (alternative traffic source)
      - Competing WordPress cache plugins active on disk
    """
    home = os.path.expanduser("~")
    out  = {"home": home}

    out["web_server"]            = _probe_web_server()
    out["lscache"]               = _probe_lscache(home, wp_root)
    out["cagefs"]                = _probe_cagefs(home)
    out["imunify360"]            = _probe_imunify(home)
    out["wpcli"]                 = _probe_wpcli(home)
    out["redis_rdb"]             = _probe_redis_rdb(home, wp_root)
    out["awstats"]               = _probe_awstats(home, wp_root)
    out["access_logs"]           = _probe_access_logs(home, wp_root)
    # Inspect the WP drop-in BEFORE competing-plugin scan so the latter can
    # use the drop-in owner to demote false positives (e.g. having Till Krüss
    # redis-cache on disk but not active is harmless when PigCache owns the
    # drop-in).
    out["object_cache_dropin"]   = _probe_object_cache_dropin(wp_root)
    out["competing_plugins"]     = _probe_competing_plugins(
        wp_root, dropin_info=out["object_cache_dropin"],
    )

    return out


# ── Web server ────────────────────────────────────────────────────────────────

def _probe_web_server():
    """Detect the web server by looking at listening ports and known binaries."""
    result = {"available": True}

    # Port fingerprint (from /proc/net/tcp — already done in detect_services,
    # but we keep this self-contained so it works standalone).
    listening = set()
    for proto in ("tcp", "tcp6"):
        try:
            for line in open("/proc/net/" + proto).readlines()[1:]:
                parts = line.split()
                if len(parts) >= 4 and parts[3] == "0A":
                    listening.add(int(parts[1].split(":")[1], 16))
        except OSError:
            pass

    server = "unknown"
    if 7080 in listening or 7443 in listening:
        server = "litespeed"
    elif 80 in listening or 443 in listening:
        # Could still be LiteSpeed — try binary detection
        import shutil
        if shutil.which("lsws") or shutil.which("litespeed") or \
                any(os.path.isfile(p) for p in (
                    "/usr/local/lsws/bin/lshttpd",
                    "/opt/lsws/bin/lshttpd",
                )):
            server = "litespeed"
        elif shutil.which("nginx") or any(os.path.isfile(p) for p in (
                "/usr/sbin/nginx", "/usr/local/nginx/sbin/nginx")):
            server = "nginx"
        elif shutil.which("apache2") or shutil.which("httpd") or \
                any(os.path.isfile(p) for p in (
                    "/usr/sbin/apache2", "/usr/sbin/httpd")):
            server = "apache"

    result["server"] = server
    result["litespeed_admin_port"] = 7080 if 7080 in listening else (
        7443 if 7443 in listening else None
    )

    # PHP handler hint from our own process names. Two outputs:
    #   php_handler        : list of binary names seen (e.g. ["lsphp"])
    #   handler_kind       : one of {"lsphp","php-fpm","mod_php","cgi","unknown"}
    #                        — used by the cloud to decide whether per-domain
    #                        attribution from /proc is reliable for this host.
    try:
        pids = [d for d in os.listdir("/proc") if d.isdigit()]
        php_handlers = set()
        for pid in pids:
            try:
                name = open("/proc/%s/comm" % pid).read().strip()
                if name in ("lsphp", "php-fpm", "php", "php-cgi",
                            "php8.1", "php8.2", "php8.3", "php7.4"):
                    php_handlers.add(name)
            except OSError:
                pass
        result["php_handler"] = sorted(php_handlers) or ["unknown"]
    except OSError:
        result["php_handler"] = ["unknown"]

    handlers = set(result["php_handler"])
    if "lsphp" in handlers:
        result["handler_kind"]            = "lsphp"
        result["per_domain_attribution"]  = "reliable"     # paths in cmdline
    elif "php-fpm" in handlers:
        result["handler_kind"]            = "php-fpm"
        result["per_domain_attribution"]  = "pool_name"    # only pool granularity
    elif "php-cgi" in handlers:
        result["handler_kind"]            = "cgi"
        result["per_domain_attribution"]  = "cwd_only"
    elif server == "apache" and not handlers - {"unknown"}:
        result["handler_kind"]            = "mod_php"
        result["per_domain_attribution"]  = "unavailable"
    else:
        result["handler_kind"]            = "unknown"
        result["per_domain_attribution"]  = "unknown"

    return result


# ── LiteSpeed Cache (LSCache) ─────────────────────────────────────────────────

def _probe_lscache(home, wp_root):
    """Detect LiteSpeed's built-in page cache (the web-server module, not the
    WP plugin).

    Three independent signals decide whether LSCache is actually SERVING
    pages (= real conflict with PigCache HTML cache) versus just having a
    few leftover files from when it was last enabled:

      1. cache_dir       → ~/lscache/ exists at all (necessary, not sufficient)
      2. cache_active    → has files AND they have been touched recently
                           (mtime within last 24 h)
      3. htaccess_lookup → wp_root/.htaccess contains `CacheLookup on|public`
                           (= LiteSpeed is configured to actually use the cache)

    Conflict is reported ONLY when BOTH (2) and (3) are true. A single stale
    file from months ago no longer trips the alert (this was a false positive
    on accounts that had LSCache enabled in the past but later disabled it).
    """
    result = {"available": False}

    # 1. Cache directory in home
    lscache_dir = os.path.join(home, "lscache")
    if os.path.isdir(lscache_dir):
        result["available"]  = True
        result["cache_dir"]  = lscache_dir
        now = time.time()
        recent_threshold = now - 24 * 3600   # files touched in the last 24h

        # LiteSpeed shards its cache into 16 hex subdirs (0..f). These dirs
        # are typically owned by the LS daemon user (`nobody`/`lsadm`), not
        # by the cPanel user, so walking them yields permission-denied for
        # everything except `.cm.log`. We need to distinguish:
        #   - "no files"     = directory empty, no cache activity
        #   - "permission denied" = LS owns the subdirs, files DO exist but
        #     we can't see them; ABSENCE of files is not evidence of inactivity
        denied_subdirs = 0
        try:
            for entry in os.listdir(lscache_dir):
                full = os.path.join(lscache_dir, entry)
                if os.path.isdir(full):
                    try:
                        os.listdir(full)
                    except PermissionError:
                        denied_subdirs += 1
                    except OSError:
                        pass
        except OSError:
            pass
        result["shard_subdirs_denied"] = denied_subdirs
        # 16 hex shards (0..9, a..f) all permission-denied = clear LS fingerprint
        result["server_module_installed"] = denied_subdirs >= 8

        try:
            total_files  = 0
            total_bytes  = 0
            recent_files = 0
            newest_mtime = 0
            for root, _dirs, files in os.walk(lscache_dir):
                for f in files:
                    total_files += 1
                    full = os.path.join(root, f)
                    try:
                        st = os.stat(full)
                    except OSError:
                        continue
                    total_bytes += st.st_size
                    if st.st_mtime > recent_threshold:
                        recent_files += 1
                    if st.st_mtime > newest_mtime:
                        newest_mtime = st.st_mtime
                if total_files > 5000:
                    result["cached_files_approx"] = ">5000"
                    result["cached_bytes_approx"] = ">estimate"
                    break
            else:
                result["cached_files"]   = total_files
                result["cached_bytes"]   = total_bytes
                result["recent_files_24h"] = recent_files
                result["newest_file_age_h"] = (
                    round((now - newest_mtime) / 3600, 1) if newest_mtime else None
                )
            # Activity heuristic — "files we CAN see" is a lower bound when
            # LS owns the subdirs. Bump it up if our user actually owns some
            # of the shards (rare on cPanel, but signals user-mode caching).
            result["cache_active"] = recent_files >= 10 or total_files >= 50
        except OSError as exc:
            result["cache_dir_err"] = str(exc)

    # 2. LSCache manager data
    lscm_dir = os.path.join(home, "lscmData")
    result["lscm_data_dir"] = lscm_dir if os.path.isdir(lscm_dir) else None

    # 3. WordPress plugin on disk (the optional WP companion plugin)
    if wp_root:
        plugin_path = os.path.join(
            wp_root, "wp-content", "plugins", "litespeed-cache", "litespeed-cache.php"
        )
        result["wp_plugin_installed"] = os.path.isfile(plugin_path)

    # 4. .htaccess directive lookup — this is the authoritative "is it on?"
    result["htaccess_cachelookup"] = False
    if wp_root:
        htaccess = os.path.join(wp_root, ".htaccess")
        if os.path.isfile(htaccess):
            try:
                with open(htaccess, "r", encoding="utf-8", errors="ignore") as f:
                    content = f.read(50000)  # cap read; .htaccess is small
                # Match `CacheLookup on` or `CacheLookup public` not inside a
                # commented line.
                for line in content.splitlines():
                    stripped = line.strip()
                    if stripped.startswith("#"):
                        continue
                    if re.search(r"\bCacheLookup\s+(on|public)\b", stripped, re.I):
                        result["htaccess_cachelookup"] = True
                        break
            except OSError:
                pass

    # 5. Conflict assessment — strict: needs cache_active AND htaccess directive
    cache_active     = bool(result.get("cache_active"))
    htaccess_enabled = bool(result.get("htaccess_cachelookup"))
    server_module    = bool(result.get("server_module_installed"))
    cached_files     = result.get("cached_files", 0)

    if result["available"] and cache_active and htaccess_enabled:
        result["conflict"] = (
            "LSCache is actively serving pages (.htaccess CacheLookup on + "
            "recent cache files). LiteSpeed serves these pages before "
            "advanced-cache.php runs, so PigCache HTML cache entries in Redis "
            "are never populated or served for those URLs. "
            "Disable LSCache (CacheLookup off in .htaccess OR cPanel → "
            "LiteSpeed Web Cache Manager → Disable) OR disable PigCache "
            "HTML cache — pick one."
        )
    elif result["available"] and server_module and not htaccess_enabled:
        # LS module installed at server-level (shard subdirs exist), but THIS
        # site's .htaccess doesn't ask for cache → safe (per-site only).
        result["note"] = (
            "LSCache module is installed at server-level (shard subdirs "
            f"{denied_subdirs}/16 are owned by the LS daemon and not "
            "readable from user-space), but this site's .htaccess has no "
            "`CacheLookup on/public` directive, so LSCache is NOT serving "
            "pages for this site. No conflict."
        )
    elif result["available"] and cache_active and not htaccess_enabled:
        result["note"] = (
            "Found cached files in ~/lscache/ but no `CacheLookup on` directive "
            "in .htaccess — LSCache is likely disabled and those files are "
            "leftovers. Safe to ignore (or `rm -rf ~/lscache/*` to clean)."
        )
    elif result["available"] and not cache_active and cached_files > 0:
        result["note"] = (
            f"Stale ~/lscache/ — only {cached_files} file(s) and none "
            "touched in the last 24h. Not actively serving."
        )

    return result


# ── WP object cache drop-in (wp-content/object-cache.php) detection ──────────

def _probe_object_cache_dropin(wp_root):
    """Identify which plugin owns wp-content/object-cache.php.

    The drop-in is what WordPress actually loads — whichever plugin's name is
    in this file is the ONE serving object cache, regardless of how many
    object-cache plugins are installed on disk. This is the single most
    reliable signal for "is there a real conflict between cache plugins?".

    Returns dict with:
        present       (bool)
        path          (str|None)
        owner         ("pigcache"|"redis-cache-till-kruss"|"w3-total-cache"|
                       "memcached"|"other"|"unknown"|None)
        plugin_name   (str|None) — first 'Plugin Name:' / heading line found
        size_bytes    (int|None)
        mtime_iso     (str|None)
    """
    out = {"present": False, "path": None, "owner": None,
           "plugin_name": None, "size_bytes": None, "mtime_iso": None}

    if not wp_root:
        return out

    dropin = os.path.join(wp_root, "wp-content", "object-cache.php")
    if not os.path.isfile(dropin):
        return out

    out["present"] = True
    out["path"]    = dropin
    try:
        st = os.stat(dropin)
        out["size_bytes"] = st.st_size
        out["mtime_iso"]  = (datetime.utcfromtimestamp(st.st_mtime).isoformat() + "Z")
    except OSError:
        pass

    try:
        with open(dropin, "r", encoding="utf-8", errors="ignore") as f:
            head = f.read(8000)
    except OSError:
        return out

    head_l = head.lower()
    # Fingerprints, ordered most→least specific.
    if "pigcache" in head_l:
        out["owner"] = "pigcache"
    elif "till krüss" in head_l or "till kruss" in head_l or \
         "rhubarbgroup/redis-cache" in head_l or "tillkruss/redis-cache" in head_l:
        out["owner"] = "redis-cache-till-kruss"
    elif "w3 total cache" in head_l or "w3tc" in head_l:
        out["owner"] = "w3-total-cache"
    elif "memcached" in head_l and "redis" not in head_l:
        out["owner"] = "memcached"
    elif "redis" in head_l:
        out["owner"] = "redis-other"   # could be Rocket / WordPress.com etc.
    else:
        out["owner"] = "unknown"

    # Best-effort plugin name from the PHP doc header
    m = re.search(r"Plugin\s*Name\s*:\s*([^\n\r*]+)", head, re.I)
    if m:
        out["plugin_name"] = m.group(1).strip()[:120]

    return out


# ── CageFS ────────────────────────────────────────────────────────────────────

def _probe_cagefs(home):
    """Detect CloudLinux CageFS.

    When CageFS is active each user gets a virtualized /proc, /etc, etc.
    This explains why /proc/lve/list and /sys/fs/cgroup are inaccessible
    even though the process runs under a CloudLinux LVE.
    """
    cagefs_dir = os.path.join(home, ".cagefs")
    active = os.path.isdir(cagefs_dir)
    return {
        "available": active,
        "dir": cagefs_dir if active else None,
        "note": (
            "CageFS virtualizes /proc and blocks /proc/lve/list + cgroup fs access. "
            "Per-account resource limits are not readable from user-space."
        ) if active else None,
    }


# ── Imunify360 ────────────────────────────────────────────────────────────────

def _probe_imunify(home):
    """Detect Imunify360 security suite."""
    markers = [
        os.path.join(home, ".imunify_patch_id"),
        os.path.join(home, ".myimunify_id"),
    ]
    found = [m for m in markers if os.path.isfile(m)]
    active = bool(found)
    patch_id = None
    if active:
        try:
            patch_id = open(found[0]).read().strip()
        except OSError:
            pass
    return {
        "available": active,
        "patch_id":  patch_id,
        # Imunify360 owns ports 27217 and 12131 on this server
        "known_ports": [12131, 27217] if active else [],
    }


# ── WP-CLI ────────────────────────────────────────────────────────────────────

def _probe_wpcli(home):
    """Detect WP-CLI installation."""
    import shutil
    candidates = [
        shutil.which("wp"),
        os.path.join(home, ".wp-cli", "packages", "vendor", "bin", "wp"),
        "/usr/local/bin/wp",
        "/usr/bin/wp",
    ]
    for path in candidates:
        if path and os.path.isfile(path) and os.access(path, os.X_OK):
            return {"available": True, "path": path}
    wpcli_dir = os.path.join(home, ".wp-cli")
    return {
        "available": False,
        "config_dir_exists": os.path.isdir(wpcli_dir),
    }


# ── Redis RDB dump ────────────────────────────────────────────────────────────

def _probe_redis_rdb(home, wp_root):
    """Locate Redis RDB/AOF persistence files.

    On user-space Redis (common on cPanel shared hosting) the dump.rdb is
    often written to the user's home directory.  Its size indicates the
    approximate dataset size when Redis INFO is unavailable.
    """
    candidates = [home]
    if wp_root:
        candidates.append(wp_root)
    candidates += [
        "/var/lib/redis",
        "/var/redis",
        "/tmp",
    ]

    found = []
    for directory in candidates:
        for fname in ("dump.rdb", "appendonly.aof", "redis.rdb"):
            fpath = os.path.join(directory, fname)
            if os.path.isfile(fpath):
                try:
                    stat = os.stat(fpath)
                    found.append({
                        "path":     fpath,
                        "size_mb":  round(stat.st_size / (1024 * 1024), 1),
                        "mtime":    datetime.utcfromtimestamp(stat.st_mtime).isoformat() + "Z",
                    })
                except OSError:
                    found.append({"path": fpath})

    return {"available": bool(found), "files": found}


# ── AWStats ───────────────────────────────────────────────────────────────────

def _probe_awstats(home, wp_root):
    """Detect AWStats data files for URL traffic analysis (Adaptive TTL v2).

    AWStats writes monthly data files to ~/tmp/awstats/ on cPanel.
    Multiple domains may have separate files.
    """
    import glob as _glob

    candidates = [os.path.join(home, "tmp", "awstats")]
    if wp_root:
        candidates.append(os.path.join(os.path.dirname(wp_root), "tmp", "awstats"))

    for directory in candidates:
        if not os.path.isdir(directory):
            continue
        files = sorted(_glob.glob(os.path.join(directory, "awstats*.txt")), reverse=True)
        if not files:
            continue
        latest = files[0]
        size   = 0
        try:
            size = os.path.getsize(latest)
        except OSError:
            pass
        return {
            "available":    True,
            "dir":          directory,
            "file_count":   len(files),
            "latest_file":  latest,
            "latest_size_kb": round(size / 1024, 1),
        }

    return {"available": False, "searched": candidates}


# ── Raw access logs ───────────────────────────────────────────────────────────

def _probe_access_logs(home, wp_root):
    """Detect raw HTTP access log files (alternative to AWStats).

    On cPanel the user's access logs live in ~/access-logs/ or ~/logs/.
    These can be parsed for per-URL hit counts when AWStats is absent.
    """
    candidates = [
        os.path.join(home, "access-logs"),
        os.path.join(home, "logs"),
        "/var/log/apache2",
        "/var/log/nginx",
        "/usr/local/apache/logs",
    ]
    if wp_root:
        candidates.insert(0, os.path.join(os.path.dirname(wp_root), "logs"))

    for directory in candidates:
        if not os.path.isdir(directory):
            continue
        try:
            entries = os.listdir(directory)
        except OSError:
            continue
        log_files = [
            e for e in entries
            if "access" in e.lower() or e.endswith((".log", ".gz"))
        ]
        if not log_files:
            continue
        # Find largest (most complete) log
        biggest = None
        biggest_size = 0
        for lf in log_files:
            try:
                sz = os.path.getsize(os.path.join(directory, lf))
                if sz > biggest_size:
                    biggest_size, biggest = sz, lf
            except OSError:
                pass
        return {
            "available":     True,
            "dir":           directory,
            "file_count":    len(log_files),
            "largest_file":  biggest,
            "largest_size_mb": round(biggest_size / (1024 * 1024), 1),
        }

    return {"available": False, "searched": candidates}


# ── Competing WordPress cache plugins ─────────────────────────────────────────

# Plugins that implement full-page HTML caching and/or object cache replacement.
# If active alongside PigCache they conflict at the same cache layer.
# Cache plugins that PigCache is known to conflict with. Each entry says
# which cache LAYER the plugin owns (page = full-HTML, object = WP object
# cache, both = does both). The on-disk presence of any of these only
# matters when the WP object-cache.php / advanced-cache.php drop-ins are
# also pointing at them — see `_probe_competing_plugins` below.
_COMPETING_CACHE_PLUGINS = {
    "w3-total-cache":          {"name": "W3 Total Cache",                  "layers": ["page", "object"]},
    "wp-super-cache":          {"name": "WP Super Cache",                  "layers": ["page"]},
    "wp-rocket":               {"name": "WP Rocket",                       "layers": ["page"]},
    "litespeed-cache":         {"name": "LiteSpeed Cache (LSCache)",       "layers": ["page", "object"]},
    "swift-performance-lite":  {"name": "Swift Performance Lite",          "layers": ["page"]},
    "swift-performance":       {"name": "Swift Performance",               "layers": ["page"]},
    "cache-enabler":           {"name": "Cache Enabler",                   "layers": ["page"]},
    "comet-cache":             {"name": "Comet Cache",                     "layers": ["page"]},
    "hyper-cache":             {"name": "Hyper Cache",                     "layers": ["page"]},
    "sg-cachepress":           {"name": "SiteGround Optimizer",            "layers": ["page"]},
    "hummingbird-performance": {"name": "Hummingbird",                     "layers": ["page"]},
    "wp-fastest-cache":        {"name": "WP Fastest Cache",                "layers": ["page"]},
    "breeze":                  {"name": "Breeze (Cloudways)",              "layers": ["page", "object"]},
    "redis-cache":             {"name": "Redis Object Cache (Till Krüss)", "layers": ["object"],
                                "note": "PigCache is a fork of this plugin. Having both on disk is "
                                        "fine; the conflict only matters when the object-cache.php "
                                        "drop-in is owned by Till Krüss's version (see "
                                        "environment.object_cache_dropin.owner)."},
}


def _probe_competing_plugins(wp_root, dropin_info=None):
    """Scan wp-content/plugins/ for cache plugins and classify the result
    against the actual object-cache.php drop-in owner.

    Behaviour change vs. the prior version:

      * On-disk presence is now INFORMATIONAL, not a conflict in itself.
      * The drop-in owner (from _probe_object_cache_dropin) decides who's
        actually serving the object cache. If the owner is pigcache, having
        Till Krüss's redis-cache plugin folder on disk too is harmless.
      * A real conflict is raised only when:
          - drop-in owner is NOT pigcache, OR
          - 2+ "page" layer plugins are installed AND no drop-in clarifies
            who owns the HTML cache.
    """
    result = {
        "available":           False,
        "found":               [],
        "plugins_dir_readable": False,
        "object_cache_owner":  (dropin_info or {}).get("owner"),
    }

    if not wp_root:
        return result

    plugins_dir = os.path.join(wp_root, "wp-content", "plugins")
    if not os.path.isdir(plugins_dir):
        return result

    result["plugins_dir_readable"] = True

    try:
        installed = set(os.listdir(plugins_dir))
    except OSError as exc:
        result["error"] = str(exc)
        return result

    # Also detect PigCache itself so the report shows what's actually present.
    pigcache_present = "pigcache" in installed and os.path.isfile(
        os.path.join(plugins_dir, "pigcache", "pigcache.php")
    )
    result["pigcache_installed"] = pigcache_present

    for slug, meta in _COMPETING_CACHE_PLUGINS.items():
        if slug in installed:
            plugin_file = os.path.join(plugins_dir, slug, slug + ".php")
            row = {
                "slug":       slug,
                "name":       meta["name"],
                "layers":     meta["layers"],
                "php_exists": os.path.isfile(plugin_file),
            }
            if "note" in meta:
                row["note"] = meta["note"]

            # Heuristic: is this plugin REALLY serving (vs. just sitting in
            # the folder)?  We don't have DB access here, so use file mtime
            # of the plugin's main PHP file vs. the drop-in mtime.
            row["likely_active"] = False
            if slug == "redis-cache":
                # The original Till Krüss plugin is "in use" only if it owns
                # the drop-in. Otherwise the folder is just legacy artifacts.
                row["likely_active"] = (
                    (dropin_info or {}).get("owner") == "redis-cache-till-kruss"
                )
            elif slug == "litespeed-cache":
                row["likely_active"] = (
                    (dropin_info or {}).get("owner") == "litespeed-cache"
                )
            elif slug == "w3-total-cache":
                row["likely_active"] = (
                    (dropin_info or {}).get("owner") == "w3-total-cache"
                )
            # For page-only plugins we can't infer activity from drop-ins.

            result["found"].append(row)

    result["available"] = bool(result["found"])

    # Conflict assessment
    conflicts = []
    drop_owner = (dropin_info or {}).get("owner")
    obj_competitors = [
        p for p in result["found"]
        if "object" in p["layers"] and p["likely_active"]
    ]
    page_competitors = [p for p in result["found"] if "page" in p["layers"]]

    if drop_owner and drop_owner != "pigcache" and pigcache_present:
        conflicts.append(
            f"PigCache is installed but the active object-cache.php drop-in "
            f"is owned by '{drop_owner}', so PigCache's object cache is NOT "
            f"in use. Replace the drop-in (PigCache settings → re-enable "
            f"object cache) or uninstall the other plugin."
        )
    if len(obj_competitors) > 1:
        names = ", ".join(p["name"] for p in obj_competitors)
        conflicts.append(
            f"Multiple object-cache plugins active simultaneously: {names}. "
            f"Only one can own the drop-in at a time — keep one."
        )
    if len(page_competitors) > 1:
        names = ", ".join(p["name"] for p in page_competitors)
        conflicts.append(
            f"{len(page_competitors)} HTML page-cache plugins installed: "
            f"{names}. They may compete with each other (and with PigCache "
            f"HTML cache, if enabled)."
        )

    result["conflicts"] = conflicts
    if conflicts:
        result["warning"] = " | ".join(conflicts)
    elif result["found"]:
        result["info"] = (
            f"{len(result['found'])} cache plugin folder(s) present on disk "
            "but no active conflict detected (drop-in owner verified)."
        )

    return result


# ─── Service + process detection ─────────────────────────────────────────────

def detect_services():
    """Scan /proc/net/tcp and tcp6 for listening ports and identify services.

    Returns a dict of port → {service, proto} for all listening sockets,
    keyed by port number.
    """
    listening = {}
    for proto in ("tcp", "tcp6"):
        try:
            for line in open("/proc/net/" + proto).readlines()[1:]:
                parts = line.split()
                if len(parts) >= 4 and parts[3] == "0A":   # TCP_LISTEN
                    port = int(parts[1].split(":")[1], 16)
                    if port not in listening:
                        listening[port] = proto
        except OSError:
            pass

    result = {}
    for port, proto in sorted(listening.items()):
        result[port] = {
            "proto":   proto,
            "service": _PORT_SERVICES.get(port, "unknown"),
        }
    return result


def scan_own_processes():
    """Return resource stats for all PIDs visible to the current user.

    On cPanel with hidepid=2 this is limited to the account's own processes.
    Includes name, state, RSS memory, cumulative CPU ticks, and I/O byte counts.
    """
    procs = []
    try:
        pids = [d for d in os.listdir("/proc") if d.isdigit()]
    except OSError:
        return []

    sc_clk = 100  # assume 100 Hz (sysconf(_SC_CLK_TCK)) — correct on virtually all Linux

    for pid in pids:
        p = {"pid": int(pid)}
        try:
            raw = open("/proc/%s/cmdline" % pid).read()
            p["cmdline"] = raw.replace("\x00", " ").strip()[:120]
        except OSError:
            p["cmdline"] = ""
        try:
            status = {}
            for line in open("/proc/%s/status" % pid):
                if ":" in line:
                    k, v = line.split(":", 1)
                    status[k.strip()] = v.strip()
            p["name"]    = status.get("Name", "?")
            p["state"]   = status.get("State", "?").split()[0]
            rss = status.get("VmRSS", "0 kB").split()[0]
            p["rss_mb"]  = round(int(rss) / 1024, 1)
            p["threads"] = int(status.get("Threads", 1))
        except (OSError, ValueError):
            pass
        try:
            fields = open("/proc/%s/stat" % pid).read().split()
            utime  = int(fields[13])
            stime  = int(fields[14])
            p["cpu_seconds"] = round((utime + stime) / sc_clk, 2)
        except (OSError, IndexError, ValueError):
            pass
        try:
            io = {}
            for line in open("/proc/%s/io" % pid):
                if ":" in line:
                    k, v = line.split(":", 1)
                    io[k.strip()] = v.strip()
            p["io_read_mb"]  = round(int(io.get("read_bytes",  "0")) / (1024 * 1024), 1)
            p["io_write_mb"] = round(int(io.get("write_bytes", "0")) / (1024 * 1024), 1)
        except (OSError, ValueError):
            pass
        procs.append(p)

    procs.sort(key=lambda x: x.get("rss_mb", 0), reverse=True)
    return procs


# ─── System metrics (OS-level, /proc-based) ──────────────────────────────────

def system_snapshot(wp_root=None):
    """Collect OS-level server metrics from /proc/*.

    /proc/diskstats is intentionally omitted — it is blocked (EPERM) on most
    cPanel shared hosts. proc_visible reflects only the calling user's PIDs
    when hidepid=2 is active (typical on shared cPanel).
    """
    snap = {"captured_at": datetime.utcnow().isoformat() + "Z"}

    # CPU cores
    try:
        snap["cpu_cores"] = os.cpu_count()
    except Exception as exc:
        snap["cpu_cores"] = None
        snap["cpu_error"] = str(exc)

    # Load avg + process counters from /proc/loadavg
    try:
        parts = open("/proc/loadavg").read().split()
        snap["load_1m"] = float(parts[0])
        snap["load_5m"] = float(parts[1])
        snap["load_15m"] = float(parts[2])
        run, total = parts[3].split("/")
        snap["proc_running"] = int(run)
        snap["proc_total"] = int(total)
    except Exception as exc:
        snap["load_1m"] = snap["load_5m"] = snap["load_15m"] = None
        snap["load_error"] = str(exc)

    # RAM + swap from /proc/meminfo
    try:
        meminfo = {}
        for line in open("/proc/meminfo"):
            if ":" in line:
                k, v = line.split(":", 1)
                try:
                    meminfo[k.strip()] = int(v.split()[0])
                except (ValueError, IndexError):
                    pass
        total_kb = meminfo.get("MemTotal", 0)
        avail_kb = meminfo.get("MemAvailable", meminfo.get("MemFree", 0))
        used_kb = total_kb - avail_kb
        snap["mem_total_mb"] = total_kb // 1024
        snap["mem_available_mb"] = avail_kb // 1024
        snap["mem_used_mb"] = used_kb // 1024
        snap["mem_used_pct"] = round(used_kb / total_kb * 100, 1) if total_kb else None
        swap_total_kb = meminfo.get("SwapTotal", 0)
        swap_free_kb = meminfo.get("SwapFree", 0)
        snap["swap_total_mb"] = swap_total_kb // 1024
        snap["swap_used_mb"] = (swap_total_kb - swap_free_kb) // 1024
        snap["swap_used_pct"] = (
            round((swap_total_kb - swap_free_kb) / swap_total_kb * 100, 1)
            if swap_total_kb else None
        )
    except Exception as exc:
        snap["mem_error"] = str(exc)

    # Disk: WordPress root partition + filesystem root
    snap["disk"] = {}
    paths = []
    if wp_root and os.path.isdir(wp_root):
        paths.append(("wp_root", wp_root))
    paths.append(("root", "/"))
    for label, path in paths:
        try:
            st = os.statvfs(path)
            total_b = st.f_blocks * st.f_frsize
            avail_b = st.f_bavail * st.f_frsize   # non-root available bytes
            used_b = total_b - st.f_bfree * st.f_frsize
            snap["disk"][label] = {
                "path": path,
                "total_gb": round(total_b / 1073741824, 1),
                "free_gb": round(avail_b / 1073741824, 1),
                "used_gb": round(used_b / 1073741824, 1),
                "used_pct": round(used_b / total_b * 100, 1) if total_b else None,
            }
        except Exception as exc:
            snap["disk"][label] = {"path": path, "error": str(exc)}

    # Network I/O: two /proc/net/dev samples 1 s apart → KiB/s
    def _read_netdev():
        ifaces = {}
        try:
            for line in open("/proc/net/dev"):
                if ":" in line:
                    iface, rest = line.split(":", 1)
                    fields = rest.split()
                    if len(fields) >= 9:
                        ifaces[iface.strip()] = (int(fields[0]), int(fields[8]))
        except Exception:
            pass
        return ifaces

    try:
        n1 = _read_netdev()
        time.sleep(1)
        n2 = _read_netdev()
        active = [k for k in n1 if k != "lo" and k in n2]
        snap["net_ifaces"] = active
        snap["net_rx_kbps"] = round(
            sum(n2[i][0] - n1[i][0] for i in active) / 1024, 1
        )
        snap["net_tx_kbps"] = round(
            sum(n2[i][1] - n1[i][1] for i in active) / 1024, 1
        )
    except Exception as exc:
        snap["net_error"] = str(exc)

    # System uptime
    try:
        up = float(open("/proc/uptime").read().split()[0])
        snap["uptime_seconds"] = int(up)
        snap["uptime_hours"] = round(up / 3600, 1)
    except Exception as exc:
        snap["uptime_error"] = str(exc)

    # Visible process count (hidepid=2 limits to calling user's PIDs on cPanel)
    try:
        snap["proc_visible"] = len([d for d in os.listdir("/proc") if d.isdigit()])
    except Exception as exc:
        snap["proc_visible_error"] = str(exc)

    # Per-account LVE / cgroup limits (CloudLinux shared hosting)
    snap["account_limits"] = _lve_snapshot()

    # Per-cPanel-account quotas via UAPI (works under CageFS where /proc/lve
    # and /sys/fs/cgroup are blocked). This is the *real* account-scoped data.
    snap["account"] = _account_snapshot()

    # Inventory of every WordPress install under ~/public_html (table_prefix
    # + Redis DB mapping). Used by the alert layer to spot DB collisions.
    home_dir = os.environ.get("HOME") or os.path.expanduser("~")
    snap["sites_inventory"] = _scan_account_wp_configs(home_dir)

    # Scope:
    #   "account"     → either LVE/cgroup OR cPanel UAPI gave us per-account data
    #   "server_wide" → only /proc-derived numbers (shared totals)
    has_lve_data = snap["account_limits"].get("available")
    has_uapi     = snap["account"].get("available")
    snap["scope"] = "account" if (has_lve_data or has_uapi) else "server_wide"

    # Services listening on this server (detected from /proc/net/tcp)
    snap["services"] = detect_services()

    # Our own visible processes with resource usage
    snap["processes"] = scan_own_processes()

    # Per-domain breakdown of those processes (CPU / RSS / IO grouped by the
    # public_html/<folder> they belong to). Answers "which of MY sites is
    # eating I/O right now?" on a shared host.
    snap["account_by_domain"] = _breakdown_procs_by_domain(snap["processes"])

    # Full environment scan (web server, LSCache, CageFS, plugins, logs…)
    snap["environment"] = environment_scan(wp_root)

    # Per-vhost access-log size summary (uses ~/logs/*.gz that cPanel writes).
    # Added under environment.* to keep all log-related stuff in one place.
    if isinstance(snap.get("environment"), dict):
        snap["environment"]["vhost_logs"] = _vhost_logs_summary(home_dir)

    return snap


def _lve_snapshot():
    """Try to read per-account CloudLinux LVE resource limits and usage.

    Three sources tried in order:
      1. /proc/lve/list       — most complete; root-only on some cPanel configs.
      2. /sys/fs/cgroup/      — constructed from /proc/self/cgroup; works when
                                the host mounts cgroupfs inside the user namespace.
      3. None                 — graceful degradation when the host blocks all access
                                (cgroup fs not mounted, as seen on some dedicated
                                cPanel servers). Returns {"available": False}.

    CPU quota interpretation:
      cfs_quota_us / cfs_period_us  → fraction of one core.
      e.g. quota=100000, period=100000 → 1.0 cores.
      quota=-1 → unlimited.
    """
    uid = os.getuid()

    # ── Source 1: /proc/lve/list ──────────────────────────────────────
    try:
        lines = open("/proc/lve/list").readlines()
        if lines:
            # Header line maps column index → name.
            header = lines[0].strip().split()
            for line in lines[1:]:
                parts = line.split()
                if parts and parts[0] == str(uid):
                    row = dict(zip(header, parts))
                    result = {"available": True, "source": "lve_list"}
                    def _i(k):
                        try: return int(row[k])
                        except (KeyError, ValueError): return None
                    result["cpu_limit_pct"]   = _i("lCPU")
                    result["cpu_usage_pct"]   = _i("CPU")
                    result["mem_limit_kb"]    = _i("lMEM")
                    result["mem_usage_kb"]    = _i("MEM")
                    result["io_limit_kbps"]   = _i("lIO")
                    result["io_usage_kbps"]   = _i("IO")
                    result["ep_limit"]        = _i("lEP")
                    result["ep_current"]      = _i("EP")
                    result["nproc_limit"]     = _i("lNPROC")
                    result["nproc_current"]   = _i("PID")
                    result["fault_cpu"]       = _i("HIT[CPU")
                    result["fault_mem"]       = _i("HIT[MEM")
                    result["fault_io"]        = _i("HIT[IO")
                    result["fault_ep"]        = _i("HIT[EP")
                    return {k: v for k, v in result.items() if v is not None}
    except (PermissionError, OSError):
        pass

    # ── Source 2: /sys/fs/cgroup/ ─────────────────────────────────────
    cg_base = "/sys/fs/cgroup"
    if not os.path.isdir(cg_base):
        return {
            "available": False,
            "reason": "cgroup fs not mounted in user namespace",
            "lve_cgroup": _read_self_cgroup_name(),
        }

    try:
        cgroups = _parse_self_cgroups()
    except Exception:
        return {"available": False, "reason": "could not parse /proc/self/cgroup"}

    result = {"available": True, "source": "cgroup", "lve_cgroup": cgroups.get("memory", "")}

    # Memory
    mem_dir = os.path.join(cg_base, "memory" + cgroups.get("memory", ""))
    for fname, key, transform in [
        ("memory.limit_in_bytes",      "mem_limit_bytes",   int),
        ("memory.usage_in_bytes",      "mem_usage_bytes",   int),
        ("memory.failcnt",             "fault_mem",         int),
        ("memory.soft_limit_in_bytes", "mem_soft_limit_bytes", int),
    ]:
        try:
            result[key] = transform(open(os.path.join(mem_dir, fname)).read().strip())
        except Exception:
            pass

    # CPU (cfs_quota / cfs_period → cores)
    cpu_subsys = "cpu,cpuacct" if os.path.isdir(os.path.join(cg_base, "cpu,cpuacct")) else "cpu"
    cpu_dir = os.path.join(cg_base, cpu_subsys + cgroups.get("cpu", cgroups.get("cpuacct", "")))
    try:
        quota  = int(open(os.path.join(cpu_dir, "cpu.cfs_quota_us")).read().strip())
        period = int(open(os.path.join(cpu_dir, "cpu.cfs_period_us")).read().strip())
        if quota > 0 and period > 0:
            result["cpu_limit_cores"] = round(quota / period, 3)
        else:
            result["cpu_limit_cores"] = None  # unlimited
    except Exception:
        pass
    try:
        result["cpuacct_usage_ns"] = int(open(os.path.join(cpu_dir, "cpuacct.usage")).read().strip())
    except Exception:
        pass

    # PIDs
    pids_dir = os.path.join(cg_base, "pids" + cgroups.get("pids", ""))
    try:
        raw = open(os.path.join(pids_dir, "pids.max")).read().strip()
        result["nproc_limit"] = None if raw == "max" else int(raw)
    except Exception:
        pass
    try:
        result["nproc_current"] = int(open(os.path.join(pids_dir, "pids.current")).read().strip())
    except Exception:
        pass

    # If we got any real metrics beyond source/available/lve_cgroup, it worked.
    real_keys = [k for k in result if k not in ("available", "source", "lve_cgroup")]
    if real_keys:
        return result

    return {
        "available": False,
        "reason": "cgroup files not readable (likely permission-denied by host)",
        "lve_cgroup": cgroups.get("memory", ""),
    }


def _parse_self_cgroups():
    """Return {subsystem: cgroup_path} from /proc/self/cgroup."""
    cgroups = {}
    for line in open("/proc/self/cgroup"):
        parts = line.strip().split(":", 2)
        if len(parts) == 3:
            for sub in parts[1].split(","):
                cgroups[sub.lstrip("name=")] = parts[2]
    return cgroups


def _read_self_cgroup_name():
    """Return the memory cgroup path (e.g. '/lve1327') or empty string."""
    try:
        for line in open("/proc/self/cgroup"):
            parts = line.strip().split(":", 2)
            if len(parts) == 3 and "memory" in parts[1]:
                return parts[2]
    except Exception:
        pass
    return ""


# ─── Per-account snapshot (cPanel UAPI + WP sites inventory) ────────────────
#
# CageFS blocks /proc/lve/list and /sys/fs/cgroup/ on shared hosts, so the
# `_lve_snapshot()` path above returns `available: False`. To still give the
# user account-scoped numbers we shell out to cPanel's UAPI which is exposed
# to every cPanel user via the `uapi` binary in $PATH. Three calls:
#
#   uapi ResourceUsage get_usages
#       → disk_usage, mysql_disk_usage, bandwidth, addon_domains, email_accounts
#         (with maximum quotas where applicable)
#
#   uapi StatsBar       get_stats display='diskusage|bandwidthusage|...'
#       → same numbers but with `is_maxed`, `percent`, normalised units
#         (used as fallback / to fill metrics ResourceUsage omits)
#
#   uapi Bandwidth      query grouping=domain interval=daily
#       → bandwidth by VHOST so the user knows which domain in their cPanel
#         account is eating bandwidth
#
# All three failures are non-fatal: the block stays `available: False` with a
# short `reason`, and `cmd_report` keeps working exactly like before.


def _uapi_call(module, func, params=None, timeout=8):
    """Invoke `uapi --output=json <module> <func> [k=v ...]` and parse stdout.

    Returns the parsed `result` payload on success, or a dict
    `{"_error": "..."}` on any kind of failure (missing binary, timeout,
    non-zero exit, invalid JSON, API-level errors[]).
    """
    import subprocess
    import shutil

    binary = shutil.which("uapi")
    if not binary:
        return {"_error": "uapi binary not in PATH"}

    cmd = [binary, "--output=json", module, func]
    if params:
        for k, v in params.items():
            cmd.append(f"{k}={v}")

    try:
        proc = subprocess.run(
            cmd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=timeout, check=False,
        )
    except subprocess.TimeoutExpired:
        return {"_error": f"uapi timeout after {timeout}s"}
    except (FileNotFoundError, OSError) as exc:
        return {"_error": f"uapi exec failed: {exc}"}

    if proc.returncode != 0:
        err = proc.stderr.decode("utf-8", "replace").strip()[:200]
        return {"_error": f"uapi exit {proc.returncode}: {err}"}

    try:
        payload = json.loads(proc.stdout.decode("utf-8", "replace"))
    except (ValueError, UnicodeDecodeError) as exc:
        return {"_error": f"uapi non-JSON output: {exc}"}

    result = payload.get("result") or {}
    if result.get("errors"):
        return {"_error": "; ".join(str(e) for e in result["errors"])[:200]}
    return result


def _account_snapshot():
    """Per-cPanel-account resource usage via UAPI (works under CageFS).

    Returns a flat dict suitable for `system.account`. When `uapi` is not
    available (e.g. non-cPanel host) returns {"available": False, ...} so the
    rest of the report keeps working unchanged.
    """
    snap = {"available": False, "source": "uapi"}

    ru = _uapi_call("ResourceUsage", "get_usages")
    if "_error" in ru:
        snap["reason"] = ru["_error"]
        return snap

    snap["available"] = True

    # ResourceUsage returns a list of {id, usage, maximum, description, ...}.
    by_id = {}
    for row in (ru.get("data") or []):
        rid = row.get("id")
        if rid:
            by_id[rid] = row

    def _num(row, key):
        """Coerce '161061273600' / 161061273600 / null → int|None."""
        v = row.get(key) if row else None
        if v in (None, "", "unlimited"):
            return None
        try:
            return int(v)
        except (TypeError, ValueError):
            try:
                return int(float(v))
            except (TypeError, ValueError):
                return None

    def _bytes_to_gb(b):
        return round(b / (1024 ** 3), 2) if b is not None else None

    def _pct(used, maximum):
        if used is None or not maximum:
            return None
        try:
            return round(used / maximum * 100, 1)
        except ZeroDivisionError:
            return None

    # Disk
    disk = by_id.get("disk_usage", {})
    snap["disk_used_bytes"]    = _num(disk, "usage")
    snap["disk_quota_bytes"]   = _num(disk, "maximum")
    snap["disk_used_gb"]       = _bytes_to_gb(snap["disk_used_bytes"])
    snap["disk_quota_gb"]      = _bytes_to_gb(snap["disk_quota_bytes"])
    snap["disk_used_pct"]      = _pct(snap["disk_used_bytes"], snap["disk_quota_bytes"])

    # MySQL disk
    mdb = by_id.get("cachedmysqldiskusage", {}) or by_id.get("mysqldiskusage", {})
    snap["mysql_disk_used_bytes"]  = _num(mdb, "usage")
    snap["mysql_disk_quota_bytes"] = _num(mdb, "maximum")
    snap["mysql_disk_used_gb"]     = _bytes_to_gb(snap["mysql_disk_used_bytes"])
    snap["mysql_disk_quota_gb"]    = _bytes_to_gb(snap["mysql_disk_quota_bytes"])
    snap["mysql_disk_used_pct"]    = _pct(snap["mysql_disk_used_bytes"],
                                          snap["mysql_disk_quota_bytes"])

    # Bandwidth (monthly)
    bw = by_id.get("bandwidth", {})
    snap["bandwidth_used_bytes"]  = _num(bw, "usage")
    snap["bandwidth_quota_bytes"] = _num(bw, "maximum")
    snap["bandwidth_used_gb"]     = _bytes_to_gb(snap["bandwidth_used_bytes"])
    snap["bandwidth_quota_gb"]    = _bytes_to_gb(snap["bandwidth_quota_bytes"])
    snap["bandwidth_used_pct"]    = _pct(snap["bandwidth_used_bytes"],
                                         snap["bandwidth_quota_bytes"])

    # Domain / email counts (no maximum on most shared plans)
    for cid, key in (
        ("addon_domains",  "addon_domains"),
        ("subdomains",     "subdomains"),
        ("aliases",        "alias_domains"),
        ("email_accounts", "email_accounts"),
        ("mailing_lists",  "mailing_lists"),
    ):
        snap[key] = _num(by_id.get(cid, {}), "usage")

    # ── Bandwidth broken down by domain (CURRENT MONTH only) ──────────
    # Without an explicit start/end, cPanel's Bandwidth::query returns
    # cumulative-since-tracking-began (i.e. potentially years worth of TB),
    # not the current month. ResourceUsage's bandwidth counter IS the current
    # month, so we constrain Bandwidth::query to match. We use UTC epoch
    # seconds for the first-of-month and now; timezone=UTC keeps the buckets
    # aligned with what cPanel itself reports in its UI.
    now = datetime.utcnow()
    month_start = int(datetime(now.year, now.month, 1).timestamp())
    now_ts = int(now.timestamp())
    bw_dom = _uapi_call(
        "Bandwidth", "query",
        params={
            "grouping": "domain",
            "interval": "daily",
            "timezone": "UTC",
            "start":    month_start,
            "end":      now_ts,
        },
    )
    period = f"{now.strftime('%Y-%m-01')}..{now.strftime('%Y-%m-%d')}"
    if "_error" not in bw_dom:
        data = (bw_dom.get("data") or {})
        items = []
        for dom, raw in data.items():
            try:
                b = int(raw)
            except (TypeError, ValueError):
                continue
            items.append({
                "domain":          dom,
                "bandwidth_bytes": b,
                "bandwidth_mb":    round(b / (1024 ** 2), 1),
                "bandwidth_gb":    round(b / (1024 ** 3), 2),
            })
        items.sort(key=lambda x: x["bandwidth_bytes"], reverse=True)
        snap["bandwidth_by_domain"]        = items[:30]
        snap["bandwidth_by_domain_period"] = period
        snap["bandwidth_by_domain_total_gb"] = round(
            sum(i["bandwidth_bytes"] for i in items) / (1024 ** 3), 2
        )
    else:
        snap["bandwidth_by_domain_error"]  = bw_dom["_error"]
        snap["bandwidth_by_domain_period"] = period

    return snap


def _detect_site_kind(folder_path):
    """Inspect a directory under public_html and classify what's running.

    Returns a dict with:
        kind:                "wordpress" | "non_wordpress" | "empty"
        wp_config_path:      str|None
        pigcache_installed:  bool       (pigcache/ folder + pigcache.php exists)
        pigcache_active_hint: "yes"|"no"|"unknown"
            - "yes"     when wp-content/advanced-cache.php contains "pigcache"
                        (the dropin that pigcache writes when its HTML cache
                        is enabled; reliable on-disk indicator).
            - "no"      when neither the plugin folder nor the dropin exist
                        even though it's a WP install.
            - "unknown" when the plugin folder is present but no dropin (e.g.
                        installed but never activated, or object-cache only).
        wp_content_size_mb:  int|None
    """
    out = {
        "kind":                "non_wordpress",
        "wp_config_path":      None,
        "pigcache_installed":  False,
        "pigcache_active_hint":"unknown",
        "wp_content_size_mb":  None,
    }

    if not os.path.isdir(folder_path):
        return out
    try:
        entries = os.listdir(folder_path)
    except OSError:
        return out
    if not entries:
        out["kind"] = "empty"
        return out

    # WP-config can live at any of these layouts depending on the panel /
    # install style:
    #   <folder>/wp-config.php              cPanel, manual installs
    #   <folder>/wp/wp-config.php           classic split (rare)
    #   <folder>/web/wp-config.php          Bedrock
    #   <folder>/public_html/wp-config.php  DirectAdmin (domains/<dom>/public_html)
    #   <folder>/httpdocs/wp-config.php     Plesk (vhosts/<dom>/httpdocs)
    wp_cfg = None
    for candidate in (
        os.path.join(folder_path, "wp-config.php"),
        os.path.join(folder_path, "wp", "wp-config.php"),
        os.path.join(folder_path, "web", "wp-config.php"),
        os.path.join(folder_path, "public_html", "wp-config.php"),
        os.path.join(folder_path, "httpdocs", "wp-config.php"),
    ):
        if os.path.isfile(candidate):
            wp_cfg = candidate
            break
    if not wp_cfg:
        return out

    out["kind"]           = "wordpress"
    out["wp_config_path"] = wp_cfg

    wp_root = os.path.dirname(wp_cfg)
    plugin_dir = os.path.join(wp_root, "wp-content", "plugins", "pigcache")
    plugin_php = os.path.join(plugin_dir, "pigcache.php")
    out["pigcache_installed"] = os.path.isfile(plugin_php)

    # `advanced-cache.php` is the WP dropin file. Pigcache replaces it when
    # the user enables HTML page caching, and the file contains the literal
    # string 'pigcache' in its header. This is the cleanest on-disk signal
    # that pigcache is not just installed but actively serving pages.
    dropin = os.path.join(wp_root, "wp-content", "advanced-cache.php")
    try:
        if os.path.isfile(dropin):
            with open(dropin, "r", encoding="utf-8", errors="ignore") as f:
                head = f.read(2048)
            if "pigcache" in head.lower():
                out["pigcache_active_hint"] = "yes"
            elif out["pigcache_installed"]:
                out["pigcache_active_hint"] = "unknown"
            else:
                out["pigcache_active_hint"] = "no"
        else:
            out["pigcache_active_hint"] = (
                "unknown" if out["pigcache_installed"] else "no"
            )
    except OSError:
        out["pigcache_active_hint"] = "unknown"

    # Best-effort wp-content size (only the top of the tree, cheap shallow stat).
    wp_content = os.path.join(wp_root, "wp-content")
    if os.path.isdir(wp_content):
        try:
            total = 0
            for root, _dirs, files in os.walk(wp_content):
                # cap depth implicitly via early exit on very large trees
                if total > 10 * 1024 ** 3:  # >10 GiB, give up
                    break
                for f in files:
                    try:
                        total += os.path.getsize(os.path.join(root, f))
                    except OSError:
                        pass
            out["wp_content_size_mb"] = round(total / (1024 ** 2), 1)
        except OSError:
            pass

    return out


def _scan_account_wp_configs(home):
    """Inventory every folder under ~/public_html/, not just WP ones.

    Returns a dict with:
        sites_wp        : [{folder, path, table_prefix, redis_db, ...}, ...]
        sites_other     : [{folder, kind, reason}, ...]   (non-WP folders)
        collisions      : [{redis_db, sites, any_implicit_db}, ...]
        pigcache_active : count of WP sites where pigcache is wired in

    This expanded scope is what lets the user answer "is the resource hog a
    site WITHOUT pigcache?". The proc / bandwidth breakdowns already capture
    every domain regardless of plugin; this inventory tells the reader which
    of those domains is even a candidate for pigcache benefit / blame.
    """
    if not home:
        return {"available": False, "reason": "no $HOME"}

    # Try every well-known docroot layout in order of how common they are
    # for WordPress hosting. The first one that exists wins; the others
    # are reported in `searched_layouts` so the consumer knows we looked.
    layout_candidates = [
        ("cpanel",      os.path.join(home, "public_html")),
        ("plesk",       os.path.join(home, "httpdocs")),
        ("directadmin", os.path.join(home, "domains")),  # nested → handled below
        ("manual",      "/var/www/html"),
        ("plesk-vhosts","/var/www/vhosts"),
    ]
    public_html = None
    layout      = None
    searched    = []
    for label, path in layout_candidates:
        searched.append({"layout": label, "path": path})
        if os.path.isdir(path):
            public_html = path
            layout      = label
            break

    if not public_html:
        return {
            "available": False,
            "reason": "no known docroot layout found",
            "searched_layouts": searched,
        }

    try:
        top_entries = sorted(os.listdir(public_html))
    except OSError as exc:
        return {"available": False, "reason": str(exc), "layout": layout}

    sites_wp    = []
    sites_other = []

    # Also handle the case where public_html itself IS the WP root.
    if os.path.isfile(os.path.join(public_html, "wp-config.php")):
        top_entries = ["."] + top_entries

    for entry in top_entries:
        folder_path = public_html if entry == "." else os.path.join(public_html, entry)
        folder_name = "_root" if entry == "." else entry

        # Skip dotfiles, files (not directories), and obvious non-site dirs
        if entry not in (".",):
            if not os.path.isdir(folder_path):
                continue
            if entry.startswith(".") or entry in ("cgi-bin", "_vti_bin"):
                continue

        kind_info = _detect_site_kind(folder_path)
        wp_cfg = kind_info["wp_config_path"]

        if kind_info["kind"] != "wordpress" or not wp_cfg:
            sites_other.append({
                "folder": folder_name,
                "path":   folder_path,
                "kind":   kind_info["kind"],
            })
            continue

        try:
            cfg = parse_wp_config(wp_cfg)
        except Exception as exc:
            sites_other.append({
                "folder": folder_name,
                "path":   folder_path,
                "kind":   "wordpress_unreadable",
                "error":  str(exc),
            })
            continue

        redis_db_raw = cfg.get("PIGCACHE_REDIS_DATABASE")
        try:
            redis_db = int(redis_db_raw) if redis_db_raw not in (None, "") else None
        except (TypeError, ValueError):
            redis_db = None

        sites_wp.append({
            "folder":               folder_name,
            "path":                 wp_cfg,
            "kind":                 "wordpress",
            "table_prefix":         cfg.get("table_prefix"),
            "db_name":              cfg.get("DB_NAME"),
            "redis_db":             redis_db,
            "redis_db_explicit":    redis_db is not None,
            "redis_prefix":         cfg.get("PIGCACHE_REDIS_PREFIX") or "",
            "redis_password_set":   bool(cfg.get("PIGCACHE_REDIS_PASSWORD")),
            "redis_host":           cfg.get("PIGCACHE_REDIS_HOST") or "127.0.0.1",
            "redis_port":           cfg.get("PIGCACHE_REDIS_PORT") or "6379",
            "pigcache_installed":   kind_info["pigcache_installed"],
            "pigcache_active_hint": kind_info["pigcache_active_hint"],
            "wp_content_size_mb":   kind_info["wp_content_size_mb"],
        })

    # Collision detection: ONLY between sites that actually have pigcache
    # installed. A WP site without pigcache won't read or write any keys in
    # Redis, so its `table_prefix` matching another site is irrelevant.
    pc_sites = [s for s in sites_wp if s["pigcache_installed"]]

    by_db = defaultdict(list)
    for s in pc_sites:
        db = s["redis_db"] if s["redis_db"] is not None else 0
        by_db[db].append(s["folder"])

    collisions = []
    for db, folders in sorted(by_db.items()):
        if len(folders) > 1:
            implicit = any(
                s for s in pc_sites
                if s["folder"] in folders and not s["redis_db_explicit"]
            )
            same_prefix = len({s["table_prefix"] for s in pc_sites
                               if s["folder"] in folders}) < len(folders)
            collisions.append({
                "redis_db":             db,
                "sites":                folders,
                "any_implicit_db":      implicit,
                "same_table_prefix":    same_prefix,
            })

    # Also call out: a pigcache site WITHOUT an explicit PIGCACHE_REDIS_DATABASE
    # is risky on shared hosting (it falls to DB 0 which is the cPanel default
    # and is most likely to be shared with other tenants). Surface as its own
    # list so the alerts layer can fire on each one individually.
    risky_implicit_db = [
        s["folder"] for s in pc_sites
        if not s["redis_db_explicit"]
    ]

    return {
        "available":          True,
        "home":                home,
        "scan_root":           public_html,
        "layout":              layout,   # cpanel | plesk | directadmin | manual | plesk-vhosts
        "site_count":          len(sites_wp) + len(sites_other),
        "wp_site_count":       len(sites_wp),
        "pigcache_site_count": len(pc_sites),
        "non_wp_count":        len(sites_other),
        "sites":               sites_wp,         # backwards-compat alias
        "sites_wp":            sites_wp,
        "sites_other":         sites_other,
        "collisions":          collisions,
        "risky_implicit_db":   risky_implicit_db,
    }


def _redis_keyspace_per_db(client, sites_inventory=None):
    """Run `INFO keyspace` and (optionally) cross-reference with wp-configs.

    Output:
        {
            "available": True,
            "by_db": {
                "0": {"keys": 148893, "expires": 1553, "avg_ttl": 115572705,
                      "wp_sites_pointing_here": ["jalisciense", "ridolimpieza"],
                      "implicit_db_pointers": true,
                      "shared_with_other_tenants_possible": true},
                "7": {"keys": 766663, "expires": 4645, "avg_ttl": 746199532,
                      "wp_sites_pointing_here": ["jaloy"]},
                ...
            },
            "total_keys_across_dbs": 1266253
        }
    """
    out = {"available": False}
    try:
        ks = client.info("keyspace") or {}
    except Exception as exc:
        out["reason"] = f"INFO keyspace failed: {exc}"
        return out

    # ks looks like: {"db0": "keys=148893,expires=1553,avg_ttl=115572705", ...}
    # (Both the bare client and redis-py give us the same shape because the
    # value contains commas → not coercible to int/float by either parser.)
    by_db = {}
    total = 0
    for k, v in ks.items():
        if not isinstance(k, str) or not k.startswith("db"):
            continue
        # redis-py returns {"db0": {"keys": 148893, ...}} — already parsed.
        if isinstance(v, dict):
            fields = v
        else:
            fields = {}
            for kv in str(v).split(","):
                if "=" in kv:
                    kk, vv = kv.split("=", 1)
                    try:
                        fields[kk] = int(vv)
                    except ValueError:
                        fields[kk] = vv
        db_index = k[2:]
        keys = int(fields.get("keys", 0) or 0)
        total += keys
        by_db[db_index] = {
            "keys":    keys,
            "expires": int(fields.get("expires", 0) or 0),
            "avg_ttl": int(fields.get("avg_ttl", 0) or 0),
            "no_ttl_pct": (round((keys - int(fields.get("expires", 0) or 0)) / keys * 100, 1)
                           if keys else None),
        }

    if sites_inventory and sites_inventory.get("available"):
        # Map each DB → list of WP sites pointing at it.
        site_map = defaultdict(list)
        implicit_map = defaultdict(bool)
        for s in sites_inventory.get("sites") or []:
            if "folder" not in s:
                continue
            db = s["redis_db"] if s["redis_db"] is not None else 0
            site_map[str(db)].append(s["folder"])
            if not s["redis_db_explicit"]:
                implicit_map[str(db)] = True

        # Annotate each DB row.
        for db, info in by_db.items():
            info["wp_sites_pointing_here"]   = site_map.get(db, [])
            info["implicit_db_pointers"]     = implicit_map.get(db, False)
            # On shared hosts ANY DB can be touched by other cPanel users that
            # also point at the same Redis instance — but the risk is highest
            # on DB 0 (the default), where ours is mixed with theirs.
            info["shared_with_other_tenants_possible"] = (db == "0")

    out["available"]              = True
    out["by_db"]                  = by_db
    out["total_keys_across_dbs"]  = total
    return out


# ── Per-process → per-domain attribution ────────────────────────────────────
#
# Different PHP handlers expose the script path / pool name differently in
# /proc/PID/cmdline. We try them all in order of specificity:
#
#   LSPHP (LiteSpeed):
#     cmdline = "lsphp:/home/USER/public_html/SITE/index.php"
#     or truncated form  "lsphp:lic_html/SITE/index.php" (LS cuts the leading
#     "pub" of public_html to fit the title size limit).
#
#   PHP-FPM:
#     cmdline = "php-fpm: pool POOLNAME"
#     or       "php-fpm: pool POOLNAME [idle]"
#     POOLNAME is set in the FPM pool config; on cPanel/EA-PHP it's the
#     cPanel username; on per-site pools (some setups) it's the site folder.
#
#   Apache mod_php / Nginx + php-fpm worker:
#     cmdline = "httpd -DFOREGROUND" / "apache2 -k start" / "nginx: worker"
#     → no per-site info in cmdline; only `cwd` may have it (rare).
#
#   CGI/FastCGI:
#     cmdline = "php-cgi"  (no path; depends on web server passing it)
#
# Hosting layout differs too — handlers writes paths anchored on the docroot:
#   cPanel       :  /home/USER/public_html/<site>/
#   DirectAdmin  :  /home/USER/domains/<domain>/public_html/
#   Plesk        :  /var/www/vhosts/<domain>/httpdocs/
#   ISPConfig    :  /var/www/clients/clientN/webM/web/
#   Manual VPS   :  /var/www/html/<site>/  (no convention)
#
# Each layout has its own DOCROOT pattern; we match all of them so the
# breakdown works on any of these stacks.

# cPanel layouts (the most common pigcache target)
_LSPHP_PATH_RE  = re.compile(r"/(?:home/[^/]+/)?public_html/([^/]+)/")
_LSPHP_TAIL_RE  = re.compile(r"public_html/([^/]+)/")
# LSPHP truncates the process title — "pub" of public_html gets sliced
_LSPHP_TRUNC_RE = re.compile(r"(?:lic_html|ic_html|c_html|_html)/([^/]+)/")
# DirectAdmin / older shared layouts
_DA_PATH_RE     = re.compile(r"/(?:home/[^/]+/)?domains/([^/]+)/public_html/")
# Plesk
_PLESK_PATH_RE  = re.compile(r"/var/www/vhosts/([^/]+)/httpdocs/")
# ISPConfig
_ISP_PATH_RE    = re.compile(r"/var/www/clients/client\d+/(web\d+)/")
# PHP-FPM pool name
_FPM_POOL_RE    = re.compile(r"php-fpm:\s*(?:pool\s+)?([\w\-\.]+)")


def _try_layout_patterns(text):
    """Try every known docroot regex against `text`. Returns the matched
    folder/site name or None. Order = most specific first."""
    for rx in (_LSPHP_PATH_RE, _LSPHP_TAIL_RE, _LSPHP_TRUNC_RE,
               _DA_PATH_RE,    _PLESK_PATH_RE,  _ISP_PATH_RE):
        m = rx.search(text)
        if m:
            return m.group(1)
    return None


def _classify_proc_to_domain(proc):
    """Map one process dict (from scan_own_processes) to a domain folder.

    Tries (in order):
      1. cmdline against every known docroot pattern (cPanel/DA/Plesk/ISPConfig)
      2. cmdline against PHP-FPM pool name
      3. /proc/PID/cwd against same docroot patterns
      4. /proc/PID/cwd against PHP-FPM pool name detection via FPM master
      5. Special-case the LSPHP pool master (cmdline=='lsphp', cwd=/opt/cpanel/ea-php*)
      6. Special-case Apache children (no per-site info available → _httpd_worker)
      7. Fallback: "_unknown"
    """
    cmd  = proc.get("cmdline") or ""
    name = (proc.get("name") or "").lower()

    # 1) cmdline → docroot (works for LSPHP and any handler that includes
    # the script path in its argv).
    hit = _try_layout_patterns(cmd)
    if hit:
        return hit

    # 2) cmdline → PHP-FPM pool name. Pool name is usually the cPanel user
    # OR the site folder (depends on the pool config). Treat it as the site
    # bucket; downstream the user can rename via tenant.sibling_folders.
    if "php-fpm" in cmd or name == "php-fpm":
        m = _FPM_POOL_RE.search(cmd)
        if m:
            pool = m.group(1)
            if pool not in ("master", "process"):
                return f"fpm:{pool}"

    # 3) cwd → docroot. Works for handlers that don't expose the script in
    # cmdline (mod_php under Apache, php-fpm idle workers, etc.).
    pid = proc.get("pid")
    cwd = ""
    if pid:
        try:
            cwd = os.readlink(f"/proc/{pid}/cwd")
        except OSError:
            cwd = ""
    if cwd:
        hit = _try_layout_patterns(cwd + "/")
        if hit:
            return hit

    # 4) Pool/worker special cases anchored on cwd or comm
    if cwd and "ea-php" in cwd and name == "lsphp":
        return "_lsphp_master"
    if (name == "lsphp"
            and cmd.strip() in ("lsphp", "lsphp:", "")):
        return "_lsphp_master"
    if name in ("httpd", "apache2"):
        # Apache children don't expose the per-vhost they're serving.
        # All Apache MPM workers fall into one bucket so the report at
        # least surfaces the aggregate.
        return "_httpd_worker"
    if name == "nginx":
        return "_nginx_worker"
    if name == "php-fpm":
        return "_fpm_master"

    return "_unknown"


def _breakdown_procs_by_domain(processes):
    """Aggregate per-process CPU / RSS / IO into per-domain totals.

    `processes` is the list produced by `scan_own_processes()`. This function
    is the answer to "which of my sites is hammering disk IO?" on shared hosts
    where you can see your own PIDs but the rest of the OS counters are pooled.
    """
    if not processes:
        return {"available": False, "reason": "no processes visible"}

    buckets = defaultdict(lambda: {
        "procs":         0,
        "rss_mb":        0.0,
        "cpu_seconds":   0.0,
        "io_read_mb":    0.0,
        "io_write_mb":   0.0,
        "threads":       0,
        "states":        defaultdict(int),
        "top_cmdlines":  [],
    })

    for p in processes:
        bucket_key = _classify_proc_to_domain(p)
        b = buckets[bucket_key]
        b["procs"] += 1
        b["rss_mb"]      += float(p.get("rss_mb", 0) or 0)
        b["cpu_seconds"] += float(p.get("cpu_seconds", 0) or 0)
        b["io_read_mb"]  += float(p.get("io_read_mb", 0) or 0)
        b["io_write_mb"] += float(p.get("io_write_mb", 0) or 0)
        b["threads"]     += int(p.get("threads", 0) or 0)
        state = p.get("state", "?")
        b["states"][state] += 1
        if len(b["top_cmdlines"]) < 3:
            cmd = (p.get("cmdline") or p.get("name") or "")[:80]
            if cmd:
                b["top_cmdlines"].append(cmd)

    rows = []
    for folder, b in buckets.items():
        rows.append({
            "folder":      folder,
            "procs":       b["procs"],
            "rss_mb":      round(b["rss_mb"], 1),
            "cpu_seconds": round(b["cpu_seconds"], 2),
            "io_read_mb":  round(b["io_read_mb"], 1),
            "io_write_mb": round(b["io_write_mb"], 1),
            "threads":     b["threads"],
            "states":      dict(b["states"]),
            "samples":     b["top_cmdlines"],
        })

    # Sort by composite "pain score": IO read first (the user's main concern),
    # then RSS, then CPU. _lsphp_master is informational only, push to bottom.
    def _sort_key(r):
        is_special = r["folder"].startswith("_")
        return (
            is_special,
            -r["io_read_mb"],
            -r["rss_mb"],
            -r["cpu_seconds"],
        )
    rows.sort(key=_sort_key)

    return {
        "available":      True,
        "by_folder":      rows,
        "total_procs":    sum(r["procs"] for r in rows),
        "total_rss_mb":   round(sum(r["rss_mb"] for r in rows), 1),
        "total_cpu_s":   round(sum(r["cpu_seconds"] for r in rows), 2),
        "total_io_read_mb":  round(sum(r["io_read_mb"] for r in rows), 1),
        "total_io_write_mb": round(sum(r["io_write_mb"] for r in rows), 1),
    }


def _build_tenant_fingerprint(hostname, user, sites_inventory=None,
                               account_snapshot=None):
    """Build a stable identifier so the cloud API can group sibling sites.

    On shared cPanel hosting the same OS user owns multiple WP installs that
    SHARE the disk / RAM / bandwidth / Redis quotas. Without a tenant id, the
    cloud sees N independent sites and cannot tell that they all draw from
    one pie. With it, the cloud can render a "this account hosts N sites,
    M with pigcache, sharing X GB disk and Y GB bandwidth" view.

    The id is deliberately readable (`hostname::user`) so admins can match
    it to their server in one glance; for hashed-id requirements the cloud
    can sha1 it server-side. Returns a dict suitable for top-level
    `payload["tenant"]`.
    """
    out = {
        "tenant_id":   f"{hostname or '?'}::{user or '?'}",
        "hostname":    hostname,
        "cpanel_user": user,
    }

    inv = sites_inventory or {}
    if inv.get("available"):
        wp = inv.get("sites_wp") or []
        pc = [s for s in wp if s.get("pigcache_installed")]
        out["wp_site_count"]            = len(wp)
        out["pigcache_site_count"]      = len(pc)
        out["non_wp_site_count"]        = inv.get("non_wp_count", 0)
        out["shared_hosting"]           = (
            (len(wp) + (inv.get("non_wp_count") or 0)) > 1
        )
        out["sibling_folders"]          = [s["folder"] for s in wp]
        out["sibling_pigcache"]         = [s["folder"] for s in pc]
        out["sibling_non_wp_folders"]   = [s["folder"]
                                           for s in (inv.get("sites_other") or [])]
    else:
        out["shared_hosting"] = None  # unknown — cloud should treat as 1-tenant

    acct = account_snapshot or {}
    if acct.get("available"):
        out["account_quotas"] = {
            "disk_quota_gb":       acct.get("disk_quota_gb"),
            "mysql_disk_quota_gb": acct.get("mysql_disk_quota_gb"),
            "bandwidth_quota_gb":  acct.get("bandwidth_quota_gb"),
        }

    return out


_VHOST_LOG_FILENAME_RE = re.compile(
    r"^(?P<domain>.+?)"
    r"(?:-ssl_log)?"
    r"-(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)"
    r"-\d{4}"
    r"(?:\.gz)?$"
)


def _vhost_logs_summary(home):
    """Summarise ~/logs/ per VHOST (cPanel writes one log per domain).

    cPanel rotates these files monthly and gzips them in place daily, so the
    "live" log for the current month is the largest non-gzip file (or, when
    the host gzips daily, the largest .gz). We don't parse the log contents
    (would burn CPU on every cron run); we just group by domain so the user
    can see which one is eating I/O.

    NOTE: filenames look like:
        jaliscohoy.com.tocinoprime.com-ssl_log-May-2026.gz
        jaliscohoy.com.tocinoprime.com-May-2026.gz
        ftp.tocinoprime.com-ftp_log-Aug-2024.gz
    where everything before the month name is the vhost. The ".tocinoprime.com"
    tail is the cPanel "main domain" suffix and is harmless; we keep it intact
    so the keys match what `Bandwidth query grouping=domain` returns.
    """
    if not home:
        return {"available": False, "reason": "no $HOME"}

    # Try every known per-vhost log layout:
    #   cPanel       : ~/logs/<vhost>(-ssl_log|-ftp_log)?-MMM-YYYY(.gz)?
    #   Plesk        : /var/www/vhosts/<domain>/logs/access_log
    #   DirectAdmin  : /var/log/httpd/domains/<domain>.log
    log_candidates = [
        ("cpanel", os.path.join(home, "logs")),
        ("plesk",  "/var/www/vhosts"),     # parsed differently below
        ("directadmin", "/var/log/httpd/domains"),
    ]
    logs_dir = None
    layout   = None
    for label, path in log_candidates:
        if os.path.isdir(path):
            logs_dir = path
            layout   = label
            break
    if not logs_dir:
        return {"available": False, "reason": "no per-vhost log directory found"}

    # Plesk and DirectAdmin layouts have a different structure (one
    # access_log per vhost, not monthly rotated). Full parsing for those
    # is TODO — for now we surface the layout so the API knows the data
    # is partial and can suggest the user install logrotate-monthly hooks
    # OR run a separate per-vhost log probe.
    if layout != "cpanel":
        return {
            "available": True,
            "dir":       logs_dir,
            "layout":    layout,
            "parsed":    False,
            "reason":    (
                f"Detected {layout} layout but per-vhost parsing is only "
                "implemented for cPanel-style filenames; PR welcome."
            ),
        }

    try:
        entries = os.listdir(logs_dir)
    except OSError as exc:
        return {"available": False, "reason": str(exc), "layout": layout}

    by_domain = defaultdict(lambda: {
        "files":           0,
        "bytes_total":     0,
        "latest_mtime":    0,
        "latest_filename": None,
        "ssl_bytes":       0,
        "plain_bytes":     0,
        "ftp_bytes":       0,
    })

    for name in entries:
        full = os.path.join(logs_dir, name)
        try:
            st = os.stat(full)
        except OSError:
            continue
        if not (st.st_mode & 0o170000) == 0o100000:  # only regular files
            continue

        m = _VHOST_LOG_FILENAME_RE.match(name)
        if not m:
            continue
        domain = m.group("domain")
        is_ssl = "-ssl_log" in name
        is_ftp = "-ftp_log" in name

        b = by_domain[domain]
        b["files"] += 1
        b["bytes_total"] += st.st_size
        if is_ssl:
            b["ssl_bytes"]   += st.st_size
        elif is_ftp:
            b["ftp_bytes"]   += st.st_size
        else:
            b["plain_bytes"] += st.st_size
        if st.st_mtime > b["latest_mtime"]:
            b["latest_mtime"]    = st.st_mtime
            b["latest_filename"] = name

    rows = []
    for dom, b in by_domain.items():
        rows.append({
            "domain":            dom,
            "files":             b["files"],
            "bytes_total":       b["bytes_total"],
            "size_total_mb":     round(b["bytes_total"] / (1024 ** 2), 1),
            "ssl_size_mb":       round(b["ssl_bytes"]   / (1024 ** 2), 1),
            "plain_size_mb":     round(b["plain_bytes"] / (1024 ** 2), 1),
            "ftp_size_mb":       round(b["ftp_bytes"]   / (1024 ** 2), 1),
            "latest_filename":   b["latest_filename"],
            "latest_mtime_iso":  (datetime.utcfromtimestamp(b["latest_mtime"])
                                  .isoformat() + "Z") if b["latest_mtime"] else None,
        })
    rows.sort(key=lambda r: r["bytes_total"], reverse=True)

    return {
        "available":      True,
        "dir":            logs_dir,
        "layout":         layout,
        "parsed":         True,
        "domain_count":   len(rows),
        "by_domain":      rows[:30],
        "bytes_total":    sum(r["bytes_total"] for r in rows),
    }


# ─── Rendering helpers ───────────────────────────────────────────────────────

def _fmt_bytes(b):
    if b is None:
        return "-"
    if not isinstance(b, (int, float)):
        return str(b)
    for unit in ("B", "KiB", "MiB", "GiB", "TiB"):
        if b < 1024:
            return f"{b:.2f} {unit}" if unit != "B" else f"{int(b)} {unit}"
        b /= 1024
    return f"{b:.2f} PiB"


def _fmt_pct(p):
    return f"{p:.2f}%" if isinstance(p, (int, float)) else "-"


def _ascii_bar(pct, width=20):
    if not isinstance(pct, (int, float)):
        return "[" + " " * width + "]"
    p = max(0.0, min(100.0, pct))
    filled = int(p / 100 * width)
    return "[" + "#" * filled + "-" * (width - filled) + "]"


def render_snapshot_text(snap, cb_state):
    out = []
    e = snap["endpoint"]
    out.append("─── Redis health ─────────────────────────────────────")
    out.append(f"  endpoint                : {e['host']}:{e['port']} (db {e['db']})  driver={e.get('driver', '?')}")
    out.append(f"  redis_version           : {snap['redis_version']}")
    out.append(f"  uptime                  : {snap['uptime_seconds']}s")
    out.append(f"  ping p50 / max          : {snap['ping_p50_ms']} ms / {snap['ping_max_ms']} ms")
    out.append(f"  info() latency          : {snap['info_latency_ms']} ms")
    if cb_state["open"] is None:
        out.append(f"  circuit breaker         : unknown (APCu state, PHP-FPM local)")
    elif cb_state["open"]:
        age = cb_state.get("age_seconds")
        out.append(f"  circuit breaker         : OPEN  (age {age}s)")
    else:
        out.append(f"  circuit breaker         : closed")
    out.append("")
    out.append("─── Throughput ───────────────────────────────────────")
    out.append(f"  ops/sec (instant)       : {snap['instantaneous_ops_per_sec']}")
    out.append(f"  total commands          : {snap['total_commands_processed']:,}")
    out.append(f"  rejected connections    : {snap['rejected_connections']}")
    out.append(f"  connected clients       : {snap['connected_clients']} / maxclients {snap['maxclients']}")
    out.append(f"  blocked clients         : {snap['blocked_clients']}")
    out.append("")
    out.append("─── Cache effectiveness (since Redis boot) ───────────")
    out.append(f"  keyspace_hits           : {snap['keyspace_hits']:,}")
    out.append(f"  keyspace_misses         : {snap['keyspace_misses']:,}")
    hr = snap['hit_ratio_pct']
    out.append(f"  cumulative hit ratio    : {_fmt_pct(hr)}  {_ascii_bar(hr)}")
    out.append(f"  evicted_keys            : {snap['evicted_keys']:,}")
    out.append(f"  expired_keys            : {snap['expired_keys']:,}")
    out.append("")
    out.append("─── Memory ───────────────────────────────────────────")
    out.append(f"  used_memory             : {snap['used_memory_human']}")
    out.append(f"  used_memory_peak        : {snap['used_memory_peak_human']}")
    out.append(f"  used_memory_rss         : {_fmt_bytes(snap['used_memory_rss_bytes'])}")
    out.append(f"  fragmentation ratio     : {snap['mem_fragmentation_ratio']}")
    out.append(f"  maxmemory               : {_fmt_bytes(snap['maxmemory_bytes']) if snap['maxmemory_bytes'] else 'UNLIMITED (!)'}")
    out.append(f"  maxmemory-policy        : {snap['maxmemory_policy']}")
    fill = snap['maxmemory_fill_pct']
    out.append(f"  memory fill             : {_fmt_pct(fill)}  {_ascii_bar(fill)}")
    out.append(f"  total keys in this db   : {snap['dbsize']}")
    if snap["slowlog_top5"]:
        out.append("")
        out.append("─── Slowlog (top 5) ──────────────────────────────────")
        for s in snap["slowlog_top5"]:
            out.append(f"  {s['duration_us']:>8} µs  {s['command']}")
    return "\n".join(out)


def render_breakdown_text(b):
    out = []
    out.append("─── Cache breakdown by group ─────────────────────────")
    out.append(f"  pattern={b['pattern']}  scanned={b['scanned_keys']}  cap={b['scan_capped_at']}")
    out.append("")
    header = "  {:<28} {:>10} {:>11} {:>11} {:>11} {:>10}".format(
        "group", "keys", "no-ttl%", "avg ttl", "avg bytes", "est total"
    )
    out.append(header)
    out.append("  " + "-" * (len(header) - 2))
    for r in b["groups"]:
        out.append("  {:<28} {:>10} {:>10.1f}% {:>11} {:>11} {:>10}".format(
            r["group"][:28],
            f"{r['key_count']:,}",
            r["no_ttl_pct"],
            f"{r['avg_ttl_s']}s" if r["avg_ttl_s"] else "-",
            _fmt_bytes(r["avg_bytes_sampled"]) if r["avg_bytes_sampled"] else "-",
            _fmt_bytes(r["est_total_bytes"]) if r["est_total_bytes"] else "-",
        ))
    return "\n".join(out)


def render_hot_text(h):
    out = []
    out.append("─── Largest sampled keys ─────────────────────────────")
    out.append(f"  pattern={h['pattern']}  scanned={h['scanned_keys']}  cap={h['scan_capped_at']}")
    out.append("")
    for entry in h["top"]:
        out.append(f"  {_fmt_bytes(entry['bytes']):>12}  {entry['key']}")
    if not h["top"]:
        out.append("  (no keys sampled)")
    return "\n".join(out)


def render_stampede_text(s):
    out = []
    out.append("─── Stampede locks (pigcache_lock_*) ─────────────────")
    out.append(f"  active locks: {s['active_lock_count']}")
    if s["active_lock_count"] > 0:
        out.append("  ⚠ regenerations queueing — investigate slow pages or DB.")
    for lk in s["locks"][:20]:
        out.append(f"    ttl={lk['ttl']:>4}  {lk['key']}")
    return "\n".join(out)


def render_mysql_text(m):
    out = []
    out.append("─── MySQL connection saturation ──────────────────────")
    out.append(f"  threads_connected       : {m['threads_connected']} / max {m['max_connections']}"
               f"  ({_fmt_pct(m['conn_saturation_pct'])})  {_ascii_bar(m['conn_saturation_pct'])}")
    out.append(f"  max_used_connections    : {m['max_used_connections']}"
               f"  ({_fmt_pct(m['peak_saturation_pct'])} peak)")
    out.append(f"  threads_running         : {m['threads_running']}")
    out.append(f"  aborted_clients         : {m['aborted_clients']}")
    out.append(f"  aborted_connects        : {m['aborted_connects']}")
    out.append(f"  conn_errors_max_conn    : {m['connection_errors_max_connections']}")
    out.append(f"  slow_queries            : {m['slow_queries']}")
    out.append(f"  qps avg since boot      : {m['qps_avg_since_boot']}")
    out.append(f"  wait_timeout            : {m['wait_timeout_s']}s")
    if m["processlist_total"] is not None:
        out.append(f"  processlist total       : {m['processlist_total']}")
        for cmd, n in sorted(m["processlist_by_command"].items(), key=lambda x: -x[1]):
            out.append(f"    {n:>4}  {cmd}")
    return "\n".join(out)


def render_system_text(sys_snap):
    out = []
    out.append("─── System ───────────────────────────────────────────")
    cores = sys_snap.get("cpu_cores")
    l1 = sys_snap.get("load_1m")
    out.append(f"  cpu_cores               : {cores if cores is not None else '?'}")
    if l1 is not None:
        load_pct = round(l1 / cores * 100, 1) if isinstance(cores, int) and cores else None
        bar = _ascii_bar(load_pct) if load_pct is not None else ""
        suffix = f"  ({load_pct}% of cores)  {bar}" if load_pct is not None else ""
        out.append(
            f"  load (1m/5m/15m)        : {l1} / {sys_snap.get('load_5m')} / "
            f"{sys_snap.get('load_15m')}{suffix}"
        )
        out.append(
            f"  processes               : {sys_snap.get('proc_running', '?')} running"
            f" / {sys_snap.get('proc_total', '?')} total"
            f"  (user-visible: {sys_snap.get('proc_visible', '?')})"
        )
    mem_total = sys_snap.get("mem_total_mb")
    if mem_total is not None:
        out.append("")
        out.append(f"  ram_total               : {mem_total:,} MiB  ({mem_total // 1024} GiB)")
        used_mb = sys_snap.get("mem_used_mb", 0)
        used_pct = sys_snap.get("mem_used_pct")
        out.append(
            f"  ram_used                : {used_mb:,} MiB"
            f"  {_fmt_pct(used_pct)}  {_ascii_bar(used_pct)}"
        )
        out.append(f"  ram_available           : {sys_snap.get('mem_available_mb', '?'):,} MiB")
        swap_t = sys_snap.get("swap_total_mb", 0)
        if swap_t:
            swap_u = sys_snap.get("swap_used_mb", 0)
            swap_pct = sys_snap.get("swap_used_pct")
            out.append(
                f"  swap_used               : {swap_u:,} / {swap_t:,} MiB"
                f"  {_fmt_pct(swap_pct)}  {_ascii_bar(swap_pct)}"
            )
    disks = sys_snap.get("disk", {})
    if disks:
        out.append("")
        for label, d in disks.items():
            if "error" in d:
                out.append(f"  disk [{label:<8}] {d.get('path', ''):<20} : error: {d['error']}")
            else:
                used_pct = d.get("used_pct")
                out.append(
                    f"  disk [{label:<8}] {d.get('path', ''):<20}"
                    f" : {d['used_gb']} / {d['total_gb']} GiB"
                    f"  {_fmt_pct(used_pct)}  {_ascii_bar(used_pct)}"
                )
    net_rx = sys_snap.get("net_rx_kbps")
    if net_rx is not None:
        out.append("")
        ifaces = ", ".join(sys_snap.get("net_ifaces", []))
        out.append(f"  net ifaces              : {ifaces}")
        out.append(
            f"  net_rx / net_tx (1s)    : {net_rx} KiB/s"
            f"  /  {sys_snap.get('net_tx_kbps', '?')} KiB/s"
        )
    uptime_h = sys_snap.get("uptime_hours")
    if uptime_h is not None:
        out.append("")
        days = sys_snap.get("uptime_seconds", 0) // 86400
        out.append(f"  uptime                  : {uptime_h} h  ({days} days)")

    # Account limits (LVE / cgroup)
    lve = sys_snap.get("account_limits", {})
    out.append("")
    out.append("─── Account limits (LVE / cgroup) ────────────────────")
    if not lve.get("available"):
        cg = lve.get("lve_cgroup", "?")
        out.append(f"  status                  : not accessible  (cgroup: {cg})")
        out.append(f"  reason                  : {lve.get('reason', '?')}")
        out.append("  note: server metrics above are SHARED SERVER TOTALS, not your account quota.")
    else:
        src = lve.get("source", "?")
        out.append(f"  source                  : {src}  (cgroup: {lve.get('lve_cgroup', '')})")
        if lve.get("cpu_limit_cores") is not None:
            out.append(f"  cpu_limit               : {lve['cpu_limit_cores']} cores")
        elif lve.get("cpu_limit_pct") is not None:
            out.append(f"  cpu_limit               : {lve['cpu_limit_pct']}%")
        if lve.get("mem_limit_bytes") is not None:
            limit_mb = lve["mem_limit_bytes"] // (1024 * 1024)
            used_mb  = (lve.get("mem_usage_bytes") or 0) // (1024 * 1024)
            pct = round(used_mb / limit_mb * 100, 1) if limit_mb else None
            out.append(
                f"  mem_used/limit          : {used_mb} / {limit_mb} MiB"
                + (f"  {_fmt_pct(pct)}  {_ascii_bar(pct)}" if pct is not None else "")
            )
        elif lve.get("mem_limit_kb") is not None:
            out.append(f"  mem_limit               : {lve['mem_limit_kb']} KiB")
        if lve.get("ep_limit") is not None:
            out.append(f"  entry_processes         : {lve.get('ep_current', '?')} / {lve['ep_limit']}")
        if lve.get("nproc_limit") is not None:
            out.append(f"  nproc                   : {lve.get('nproc_current', '?')} / {lve['nproc_limit']}")
        if lve.get("io_limit_kbps") is not None:
            out.append(f"  io_limit                : {lve['io_limit_kbps']} KiB/s")
        faults = {k: lve[k] for k in ("fault_cpu", "fault_mem", "fault_io", "fault_ep") if lve.get(k)}
        if faults:
            out.append(f"  ⚠ throttle faults       : {faults}")

    scope = sys_snap.get("scope", "server_wide")
    if scope == "server_wide":
        out.append("  ─ metrics above = server totals, not your account quota ─")

    # ── Per-cPanel-account UAPI quotas ────────────────────────────────
    account = sys_snap.get("account", {}) or {}
    out.append("")
    out.append("─── Account (cPanel UAPI) ────────────────────────────")
    if not account.get("available"):
        out.append(f"  status                  : not available  ({account.get('reason', '?')})")
    else:
        def _line(label, used_gb, quota_gb, pct):
            qstr = f"{quota_gb} GiB" if quota_gb else "unlimited"
            pstr = f"  {_fmt_pct(pct)}  {_ascii_bar(pct)}" if pct is not None else ""
            out.append(f"  {label:<24}: {used_gb} / {qstr}{pstr}")

        _line("disk",        account.get("disk_used_gb"),
                              account.get("disk_quota_gb"),
                              account.get("disk_used_pct"))
        _line("mysql_disk",  account.get("mysql_disk_used_gb"),
                              account.get("mysql_disk_quota_gb"),
                              account.get("mysql_disk_used_pct"))
        _line("bandwidth_month", account.get("bandwidth_used_gb"),
                                  account.get("bandwidth_quota_gb"),
                                  account.get("bandwidth_used_pct"))
        for k, label in (("addon_domains",  "addon_domains"),
                         ("subdomains",     "subdomains"),
                         ("alias_domains",  "alias_domains"),
                         ("email_accounts", "email_accounts"),
                         ("mailing_lists",  "mailing_lists")):
            v = account.get(k)
            if v is not None:
                out.append(f"  {label:<24}: {v}")

        bw_dom = account.get("bandwidth_by_domain") or []
        if bw_dom:
            out.append("")
            out.append("  bandwidth_by_domain (monthly, top 10):")
            for row in bw_dom[:10]:
                out.append(
                    f"    {row['domain']:<48}  "
                    f"{row['bandwidth_gb']:>7.2f} GiB"
                )

    # ── Per-domain process breakdown ──────────────────────────────────
    abd = sys_snap.get("account_by_domain", {}) or {}
    if abd.get("available"):
        out.append("")
        out.append("─── My sites: live processes by folder ───────────────")
        out.append("  {:<20} {:>5} {:>10} {:>10} {:>14} {:>14}".format(
            "folder", "procs", "rss MiB", "cpu sec", "io read MiB", "io write MiB",
        ))
        out.append("  " + "-" * 80)
        for r in abd.get("by_folder") or []:
            tag = r["folder"]
            if tag == "_lsphp_master":
                tag = "_lsphp_pool"
            out.append("  {:<20} {:>5} {:>10} {:>10} {:>14} {:>14}".format(
                tag[:20],
                r["procs"],
                r["rss_mb"],
                r["cpu_seconds"],
                r["io_read_mb"],
                r["io_write_mb"],
            ))
        out.append(
            f"  totals (excl. _lsphp_pool counted separately): "
            f"{abd['total_procs']} procs, "
            f"{abd['total_rss_mb']} MiB RSS, "
            f"{abd['total_io_read_mb']} MiB read"
        )

    # ── Sites inventory + collisions ──────────────────────────────────
    inv = sys_snap.get("sites_inventory", {}) or {}
    if inv.get("available"):
        out.append("")
        out.append(
            f"─── My sites under ~/public_html  "
            f"({inv.get('wp_site_count', 0)} WP, "
            f"{inv.get('pigcache_site_count', 0)} with pigcache, "
            f"{inv.get('non_wp_count', 0)} non-WP) ─"
        )
        out.append("  {:<20} {:<14} {:>10} {:>11} {:>11}  {}".format(
            "folder", "kind", "tbl_prefix", "redis_db", "pigcache", "size",
        ))
        out.append("  " + "-" * 80)
        for s in inv.get("sites_wp") or []:
            db_label = (
                "(default→0)" if not s["redis_db_explicit"] else str(s["redis_db"])
            )
            if s["pigcache_installed"]:
                pc_label = s.get("pigcache_active_hint", "unknown")
            else:
                pc_label = "no"
            size = (f"{s['wp_content_size_mb']} MiB"
                    if s.get("wp_content_size_mb") is not None else "?")
            out.append("  {:<20} {:<14} {:>10} {:>11} {:>11}  {}".format(
                (s["folder"] or "_root")[:20],
                "wordpress",
                (s["table_prefix"] or "?")[:10],
                db_label,
                pc_label,
                size,
            ))
        for s in inv.get("sites_other") or []:
            out.append("  {:<20} {:<14} {:>10} {:>11} {:>11}".format(
                s["folder"][:20],
                s.get("kind", "?")[:14],
                "—", "—", "—",
            ))
        if inv.get("collisions"):
            out.append("  ⚠ redis-db collisions among pigcache sites:")
            for c in inv["collisions"]:
                marks = []
                if c.get("any_implicit_db") and c["redis_db"] == 0:
                    marks.append("DB 0 implicit")
                if c.get("same_table_prefix"):
                    marks.append("same table_prefix")
                mark = "  (" + ", ".join(marks) + ")" if marks else ""
                out.append(
                    f"    db={c['redis_db']}  sites={c['sites']}{mark}"
                )
        if inv.get("risky_implicit_db"):
            out.append(
                f"  ⚠ pigcache sites with no PIGCACHE_REDIS_DATABASE pin: "
                f"{inv['risky_implicit_db']}"
            )

    return "\n".join(out)


def render_redis_per_db_text(rpd):
    """Render the redis_per_db block as a small dashboard table."""
    out = ["─── Redis per-DB keyspace ────────────────────────────"]
    if not rpd or not rpd.get("available"):
        out.append(f"  status                  : not available  ({(rpd or {}).get('reason', '?')})")
        return "\n".join(out)
    out.append("  {:<5} {:>10} {:>10} {:>8}  {}".format(
        "db", "keys", "with TTL", "no_ttl%", "my sites pointing here",
    ))
    out.append("  " + "-" * 76)
    for db_idx in sorted(rpd.get("by_db") or {}, key=lambda k: int(k)):
        row = rpd["by_db"][db_idx]
        sites = row.get("wp_sites_pointing_here") or []
        warn = "  ← shared w/ other tenants" if row.get("shared_with_other_tenants_possible") and not sites else ""
        out.append("  {:<5} {:>10} {:>10} {:>7}%  {}{}".format(
            db_idx,
            row.get("keys", 0),
            row.get("expires", 0),
            row.get("no_ttl_pct") if row.get("no_ttl_pct") is not None else "?",
            ", ".join(sites) if sites else "—",
            warn,
        ))
    out.append(f"  total keys              : {rpd.get('total_keys_across_dbs', 0):,}")
    return "\n".join(out)


def render_memcached_text(m):
    out = ["─── Memcached ────────────────────────────────────────"]
    if not m.get("available"):
        out.append(f"  status                  : unreachable  ({m.get('error', '?')})")
        return "\n".join(out)
    out.append(f"  endpoint                : {m['host']}:{m['port']}  v{m['version']}")
    out.append(f"  uptime                  : {m['uptime_s']}s")
    out.append(f"  connections             : {m['curr_connections']} current / {m['total_connections']} total")
    out.append(f"  items                   : {m['curr_items']:,} current / {m['total_items']:,} total")
    hr = m.get("hit_ratio_pct")
    out.append(f"  hit ratio               : {_fmt_pct(hr)}  {_ascii_bar(hr)}")
    out.append(f"  get/set cmds            : {m['cmd_get']:,} / {m['cmd_set']:,}")
    out.append(f"  evictions               : {m['evictions']:,}")
    mp = m.get("mem_used_pct")
    out.append(
        f"  memory                  : {_fmt_bytes(m['used_bytes'])} / {_fmt_bytes(m['limit_bytes'])}"
        + (f"  {_fmt_pct(mp)}  {_ascii_bar(mp)}" if mp is not None else "")
    )
    return "\n".join(out)


def render_services_text(services, processes):
    out = ["─── Detected services ────────────────────────────────"]
    for port, info in sorted(services.items()):
        svc = info["service"]
        mark = "  " if svc not in ("unknown",) else "? "
        out.append(f"  {mark}:{port:<6}  {info['proto']:<5}  {svc}")
    if processes:
        out.append("")
        out.append("─── Account processes (visible) ──────────────────────")
        out.append("  {:<8} {:<16} {:>7} {:>9} {:>10} {:>10}".format(
            "PID", "name", "state", "RSS MiB", "CPU sec", "I/O r/w MiB"
        ))
        out.append("  " + "-" * 68)
        for p in processes:
            io_r = p.get("io_read_mb", "-")
            io_w = p.get("io_write_mb", "-")
            out.append("  {:<8} {:<16} {:>7} {:>9} {:>10} {:>5}/{:<5}".format(
                p.get("pid", "?"),
                (p.get("name") or "?")[:16],
                p.get("state", "?"),
                p.get("rss_mb", "-"),
                p.get("cpu_seconds", "-"),
                io_r, io_w,
            ))
    return "\n".join(out)


def render_environment_text(env):
    """Render the environment_scan() dict as a human-readable text block."""
    out = ["─── Environment scan ─────────────────────────────────"]

    # Web server
    ws = env.get("web_server", {})
    if ws.get("available") is not False:
        srv = ws.get("server", "unknown")
        php = ", ".join(ws.get("php_handler") or ["?"])
        ls_port = ws.get("litespeed_admin_port")
        ls_note = f"  (admin :{ls_port})" if ls_port else ""
        out.append(f"  web_server              : {srv}{ls_note}")
        out.append(f"  php_handler             : {php}")

    # LSCache
    lsc = env.get("lscache", {})
    if lsc.get("available"):
        files = lsc.get("cached_files", lsc.get("cached_files_approx", "?"))
        recent = lsc.get("recent_files_24h", 0)
        size_b = lsc.get("cached_bytes")
        size_str = _fmt_bytes(size_b) if isinstance(size_b, int) else "?"
        htaccess = lsc.get("htaccess_cachelookup")
        active = lsc.get("cache_active")
        if active and htaccess:
            status = "⚠ ACTIVE (CacheLookup on + recent files)"
        elif active and not htaccess:
            status = "files present, but .htaccess CacheLookup off → idle"
        elif files and not active:
            age_h = lsc.get("newest_file_age_h", "?")
            status = f"stale ({files} files, newest {age_h}h old)"
        else:
            status = "directory exists, no cached files"
        out.append(f"  lscache                 : {status}  ({files} files, {size_str})")
        out.append(
            f"    htaccess CacheLookup  : {'on' if htaccess else 'off / not set'}"
        )
        if isinstance(recent, int) and recent > 0:
            out.append(f"    cached in last 24h    : {recent} files")
        if lsc.get("lscm_data_dir"):
            out.append(f"    lscmData dir          : {lsc['lscm_data_dir']}")
        if lsc.get("wp_plugin_installed"):
            out.append("    wp plugin             : installed on disk")
        if lsc.get("conflict"):
            first = lsc["conflict"].split(".")[0] + "."
            out.append(f"  ⚠ CONFLICT              : {first}")
        elif lsc.get("note"):
            out.append(f"    note                  : {lsc['note'].split('.')[0]}.")
    else:
        out.append("  lscache                 : not detected")

    # CageFS
    cagefs = env.get("cagefs", {})
    if cagefs.get("available"):
        out.append(f"  cagefs                  : detected  ({cagefs.get('dir', '')})")
        if cagefs.get("note"):
            out.append(f"    note                  : {cagefs['note']}")
    else:
        out.append("  cagefs                  : not detected")

    # Imunify360
    imunify = env.get("imunify360", {})
    if imunify.get("available"):
        patch = imunify.get("patch_id", "?")
        ports = imunify.get("known_ports", [])
        out.append(f"  imunify360              : detected  patch_id={patch}  ports={ports}")
    else:
        out.append("  imunify360              : not detected")

    # WP-CLI
    wpcli = env.get("wpcli", {})
    if wpcli.get("available"):
        out.append(f"  wp-cli                  : {wpcli.get('path', '?')}")
    else:
        note = "config dir present" if wpcli.get("config_dir_exists") else "not installed"
        out.append(f"  wp-cli                  : not available  ({note})")

    # Redis RDB / AOF persistence files
    rdb = env.get("redis_rdb", {})
    if rdb.get("available"):
        out.append("  redis_rdb               :")
        for f in rdb.get("files", []):
            mtime = (f.get("mtime") or "?")[:10]
            out.append(
                f"    {f.get('path', '?'):<50}  "
                f"{f.get('size_mb', '?'):>6} MiB  mtime={mtime}"
            )
    else:
        out.append("  redis_rdb               : no dump files found")

    # AWStats
    aws = env.get("awstats", {})
    if aws.get("available"):
        out.append(
            f"  awstats                 : {aws.get('file_count', '?')} files in "
            f"{aws.get('dir', '?')}"
        )
        latest = os.path.basename(aws.get("latest_file") or "")
        out.append(
            f"    latest: {latest:<40}  {aws.get('latest_size_kb', '?')} KiB"
        )
    else:
        out.append("  awstats                 : not found")

    # Access logs
    logs = env.get("access_logs", {})
    if logs.get("available"):
        out.append(
            f"  access_logs             : {logs.get('file_count', '?')} files in "
            f"{logs.get('dir', '?')}"
        )
        if logs.get("largest_file"):
            out.append(
                f"    largest: {logs['largest_file']:<42}  "
                f"{logs.get('largest_size_mb', '?')} MiB"
            )
    else:
        out.append("  access_logs             : not found")

    # Per-vhost log breakdown (one file per domain, written by cPanel)
    vh = env.get("vhost_logs", {})
    if vh.get("available"):
        out.append(
            f"  vhost_logs              : {vh.get('domain_count', '?')} domain(s), top 8:"
        )
        for r in (vh.get("by_domain") or [])[:8]:
            out.append(
                f"    {r['domain']:<46}  "
                f"{r['size_total_mb']:>7.1f} MiB  "
                f"(ssl {r['ssl_size_mb']} / plain {r['plain_size_mb']} / ftp {r['ftp_size_mb']})"
            )

    # Object-cache.php drop-in (who actually owns the WP object cache)
    do = env.get("object_cache_dropin", {})
    if do.get("present"):
        owner = do.get("owner") or "unknown"
        owner_label = {
            "pigcache":               "✓ pigcache (this plugin)",
            "redis-cache-till-kruss": "⚠ Redis Object Cache (Till Krüss original)",
            "w3-total-cache":         "⚠ W3 Total Cache",
            "memcached":              "⚠ Memcached drop-in",
            "redis-other":            "⚠ unknown Redis drop-in",
        }.get(owner, owner)
        out.append(f"  object_cache_dropin     : {owner_label}")
        if do.get("plugin_name"):
            out.append(f"    plugin_name           : {do['plugin_name']}")
        if do.get("mtime_iso"):
            out.append(f"    mtime                 : {do['mtime_iso'][:19]}Z")
    else:
        out.append("  object_cache_dropin     : not present (no plugin owns it)")

    # Competing cache plugins (now informational unless conflicts are listed)
    cp = env.get("competing_plugins", {})
    if cp.get("found"):
        head = "⚠" if cp.get("conflicts") else "·"
        out.append(
            f"  competing_plugins       : {head} {len(cp['found'])} cache plugin(s) on disk"
        )
        for plug in cp.get("found", []):
            tag = "active" if plug.get("likely_active") else "inactive"
            layers = "+".join(plug.get("layers", []))
            out.append(
                f"    - {plug['name']:<40}  ({plug['slug']:<20}) [{tag}, {layers}]"
            )
            if plug.get("note"):
                out.append(f"        note: {plug['note']}")
        for conflict_msg in cp.get("conflicts") or []:
            out.append(f"    ⚠ {conflict_msg}")
        if not cp.get("conflicts") and cp.get("info"):
            out.append(f"    info: {cp['info']}")
    elif cp.get("plugins_dir_readable"):
        out.append("  competing_plugins       : none detected")
    else:
        out.append("  competing_plugins       : plugins dir not accessible")

    return "\n".join(out)


# ─── Health check (alerting) ─────────────────────────────────────────────────

DEFAULT_THRESHOLDS = {
    "ping_max_ms": 50,
    "hit_ratio_min_pct": 80.0,
    "memory_fill_max_pct": 90.0,
    "rejected_conn_delta": 1,           # any new rejection is bad
    "evictions_delta_warn": 100,        # per call window
    "stampede_locks_max": 25,
    "mysql_conn_saturation_max_pct": 80.0,
    "mysql_conn_errors_max_delta": 1,
}


def run_health(args, cfg):
    redis_client, endpoint = connect_redis(args, cfg)
    snap = redis_snapshot(redis_client, endpoint)
    cb = circuit_breaker_state(endpoint["host"], endpoint["port"])

    alerts = []

    if cb["open"]:
        alerts.append({
            "severity": "critical",
            "code": "redis_circuit_open",
            "msg": f"Circuit breaker is OPEN (age {cb['age_seconds']}s) — "
                   f"PigCache dropin recently failed to reach Redis."
        })

    if snap["ping_max_ms"] > args.ping_max_ms:
        alerts.append({
            "severity": "warn",
            "code": "redis_latency_high",
            "msg": f"Redis PING max {snap['ping_max_ms']} ms > "
                   f"{args.ping_max_ms} ms threshold."
        })

    if snap["hit_ratio_pct"] is not None and snap["hit_ratio_pct"] < args.hit_ratio_min:
        alerts.append({
            "severity": "warn",
            "code": "low_hit_ratio",
            "msg": f"Cumulative hit ratio {snap['hit_ratio_pct']}% < "
                   f"{args.hit_ratio_min}% (since Redis boot — see `watch` "
                   f"for current-window ratio)."
        })

    if snap["maxmemory_bytes"] == 0:
        alerts.append({
            "severity": "warn",
            "code": "no_maxmemory",
            "msg": "Redis maxmemory is UNLIMITED. With high traffic this "
                   "lets old keys accumulate; set maxmemory + an LRU policy."
        })
    elif snap["maxmemory_fill_pct"] and snap["maxmemory_fill_pct"] > args.memory_fill_max:
        alerts.append({
            "severity": "warn",
            "code": "memory_pressure",
            "msg": f"Redis memory fill {snap['maxmemory_fill_pct']}% > "
                   f"{args.memory_fill_max}% — evictions imminent."
        })

    if snap["maxmemory_policy"] in ("noeviction",):
        alerts.append({
            "severity": "warn",
            "code": "policy_noeviction",
            "msg": "maxmemory-policy=noeviction — once full, Redis returns "
                   "OOM errors for SETs. Use allkeys-lru or volatile-lru."
        })

    if snap["rejected_connections"] > 0:
        alerts.append({
            "severity": "warn",
            "code": "rejected_connections",
            "msg": f"{snap['rejected_connections']} rejected_connections "
                   f"since boot — Redis maxclients reached at some point."
        })

    # Stampede locks
    if not args.skip_stampede:
        lock_pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*pigcache_lock_*"
        st = scan_stampede_locks(redis_client, lock_pattern,
                                 cap=2000, count=500)
        if st["active_lock_count"] > args.stampede_max:
            alerts.append({
                "severity": "warn",
                "code": "stampede_locks_high",
                "msg": f"{st['active_lock_count']} active stampede locks "
                       f"(>{args.stampede_max}) — many pages are regenerating "
                       "simultaneously (slow MISS path)."
            })

    # MySQL saturation (uses gather_mysql so the mariadb/mysql CLI fallback
    # kicks in automatically when no Python driver is installed).
    mysql_block = None
    if not args.skip_mysql:
        mysql_block = gather_mysql(args, cfg)
        if mysql_block and "error" not in mysql_block:
            sat = mysql_block.get("conn_saturation_pct")
            if isinstance(sat, (int, float)) and sat > args.mysql_sat_max:
                alerts.append({
                    "severity": "critical",
                    "code": "mysql_conn_saturation",
                    "msg": f"MySQL connection usage {sat}% of max_connections "
                           f"({mysql_block['threads_connected']}/"
                           f"{mysql_block['max_connections']}). "
                           "This is exactly what causes 'Error establishing a "
                           "database connection'."
                })
            cme = mysql_block.get("connection_errors_max_connections", 0)
            if cme > 0:
                alerts.append({
                    "severity": "critical",
                    "code": "mysql_max_conn_errors",
                    "msg": f"MySQL has refused {cme} new connections "
                           "since boot due to max_connections — site is "
                           "definitely failing under load."
                })
        elif mysql_block:
            alerts.append({
                "severity": "info",
                "code": "mysql_skip",
                "msg": f"MySQL probe skipped: {mysql_block.get('error')}"
            })

    report = {
        "ok": all(a["severity"] not in ("critical", "warn") for a in alerts),
        "alerts": alerts,
        "redis": snap,
        "circuit_breaker": cb,
        "mysql": mysql_block,
    }

    if args.json:
        print(json.dumps(report, indent=2, default=str))
    else:
        if not alerts:
            print("[OK] PigCache health check passed.")
        for a in alerts:
            print(f"[{a['severity'].upper()}] {a['code']}: {a['msg']}")

    # Exit code policy.
    if any(a["severity"] == "critical" for a in alerts):
        sys.exit(2)
    if any(a["severity"] == "warn" for a in alerts):
        sys.exit(1)
    sys.exit(0)


# ─── Subcommand implementations ──────────────────────────────────────────────

def cmd_snapshot(args, cfg):
    client, endpoint = connect_redis(args, cfg)
    snap = redis_snapshot(client, endpoint)
    cb = circuit_breaker_state(endpoint["host"], endpoint["port"])

    mysql_block = None
    if not args.skip_mysql:
        mysql_block = gather_mysql(args, cfg)

    if args.json:
        print(json.dumps({
            "redis": snap, "circuit_breaker": cb, "mysql": mysql_block,
        }, indent=2, default=str))
        return

    print(render_snapshot_text(snap, cb))
    if mysql_block and "error" not in mysql_block:
        print()
        print(render_mysql_text(mysql_block))
    elif mysql_block and "error" in mysql_block:
        print()
        print("─── MySQL ────────────────────────────────────────────")
        print(f"  skipped: {mysql_block['error']}")


def _resolve_sample_cap(raw, client, label=""):
    """Turn --sample-cap string into a concrete integer cap.

    Supports:
      * integer                → returned as-is.
      * "auto" | "all" | "0"   → DBSIZE (full scan).
      * "pct:N"                → max(1000, DBSIZE * N / 100).
      * "Nk" / "Nm"            → N * 1000 or N * 1_000_000 (e.g. "200k").

    Returns (cap_int, dbsize_or_None). The dbsize is also surfaced so the
    caller can include it in --json output for visibility.
    """
    s = str(raw).strip().lower()
    dbsize = None

    def _get_dbsize():
        try:
            return int(client.dbsize())
        except Exception:
            return None

    if s in ("auto", "all", "max", "0"):
        dbsize = _get_dbsize()
        if dbsize is None or dbsize <= 0:
            return 20000, dbsize
        return dbsize, dbsize

    if s.startswith("pct:"):
        try:
            pct = float(s[4:])
        except ValueError:
            pct = 100.0
        dbsize = _get_dbsize() or 0
        cap = int(max(1000, dbsize * pct / 100.0))
        return cap, dbsize

    # Handle 200k / 1m shorthand
    mult = 1
    if s.endswith("k"):
        mult, s = 1000, s[:-1]
    elif s.endswith("m"):
        mult, s = 1_000_000, s[:-1]
    try:
        return int(float(s) * mult), None
    except ValueError:
        print(f"warn: could not parse --sample-cap={raw!r}, "
              f"using 20000 ({label})", file=sys.stderr)
        return 20000, None


def cmd_breakdown(args, cfg):
    client, endpoint = connect_redis(args, cfg)
    pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*"
    cap, dbsize = _resolve_sample_cap(args.sample_cap, client, label="breakdown")
    b = scan_breakdown(client, pattern,
                       cap=cap,
                       sample_per_group=args.sample_per_group,
                       count=args.scan_count)
    b["dbsize_total"] = dbsize
    b["coverage_pct"] = (
        round(b["scanned_keys"] / dbsize * 100, 2)
        if dbsize and dbsize > 0 else None
    )
    if args.json:
        print(json.dumps(b, indent=2, default=str))
    else:
        if dbsize is not None:
            print(f"# scanned {b['scanned_keys']:,} of {dbsize:,} keys "
                  f"({b['coverage_pct']}% coverage)")
        print(render_breakdown_text(b))


def cmd_hot_keys(args, cfg):
    client, _ = connect_redis(args, cfg)
    pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*"
    cap, _ = _resolve_sample_cap(args.sample_cap, client, label="hot-keys")
    h = scan_hot_keys(client, pattern,
                      cap=cap,
                      top_n=args.top,
                      count=args.scan_count)
    if args.json:
        print(json.dumps(h, indent=2, default=str))
    else:
        print(render_hot_text(h))


def cmd_stampede(args, cfg):
    client, _ = connect_redis(args, cfg)
    pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*pigcache_lock_*"
    cap, _ = _resolve_sample_cap(args.sample_cap, client, label="stampede")
    s = scan_stampede_locks(client, pattern, cap=cap,
                            count=args.scan_count)
    if args.json:
        print(json.dumps(s, indent=2, default=str))
    else:
        print(render_stampede_text(s))


def cmd_mysql(args, cfg):
    m = gather_mysql(args, cfg)
    if m is None or "error" in (m or {}):
        msg = (m or {}).get("error", "unknown error")
        print(f"error: {msg}", file=sys.stderr)
        sys.exit(2)
    if args.json:
        print(json.dumps(m, indent=2, default=str))
    else:
        print(render_mysql_text(m))


def cmd_watch(args, cfg):
    """Compute deltas across a time window — gives a *real* current hit ratio."""
    client, endpoint = connect_redis(args, cfg)
    snap1 = redis_snapshot(client, endpoint)
    t1 = time.time()
    if not args.json:
        print(f"Sampling for {args.window}s...", file=sys.stderr)
    time.sleep(args.window)
    snap2 = redis_snapshot(client, endpoint)
    t2 = time.time()
    elapsed = max(0.001, t2 - t1)

    d_hits = snap2["keyspace_hits"] - snap1["keyspace_hits"]
    d_miss = snap2["keyspace_misses"] - snap1["keyspace_misses"]
    d_evict = snap2["evicted_keys"] - snap1["evicted_keys"]
    d_expir = snap2["expired_keys"] - snap1["expired_keys"]
    d_cmd = snap2["total_commands_processed"] - snap1["total_commands_processed"]
    d_conn = snap2["total_connections_received"] - snap1["total_connections_received"]
    d_reject = snap2["rejected_connections"] - snap1["rejected_connections"]
    total = d_hits + d_miss
    window_ratio = (d_hits / total * 100.0) if total else None

    out = {
        "window_s": round(elapsed, 2),
        "hits_per_sec": round(d_hits / elapsed, 2),
        "miss_per_sec": round(d_miss / elapsed, 2),
        "cmd_per_sec": round(d_cmd / elapsed, 2),
        "new_conn_per_sec": round(d_conn / elapsed, 2),
        "evictions_per_sec": round(d_evict / elapsed, 2),
        "expirations_per_sec": round(d_expir / elapsed, 2),
        "rejected_in_window": d_reject,
        "hit_ratio_in_window_pct": round(window_ratio, 2) if window_ratio else None,
    }
    if args.json:
        print(json.dumps(out, indent=2))
    else:
        print("─── Live window ──────────────────────────────────────")
        for k, v in out.items():
            print(f"  {k:<25} : {v}")


def cmd_log_line(args, cfg):
    """Single compact line — best for `>> file 2>&1` cron tailing."""
    client, endpoint = connect_redis(args, cfg)
    snap = redis_snapshot(client, endpoint)
    cb = circuit_breaker_state(endpoint["host"], endpoint["port"])

    mysql_summary = ""
    if not args.skip_mysql:
        m = gather_mysql(args, cfg)
        if m and "error" not in m:
            mysql_summary = (
                f" mysql_conn={m['threads_connected']}/{m['max_connections']}"
                f" mysql_max_used={m['max_used_connections']}"
                f" mysql_aborted_c={m['aborted_clients']}"
                f" via={m.get('probe_via', '?')}"
            )

    line = (
        f"{datetime.utcnow().isoformat()}Z"
        f" host={endpoint['host']}:{endpoint['port']}/{endpoint['db']}"
        f" cb={'OPEN' if cb['open'] else 'ok'}"
        f" ops/s={snap['instantaneous_ops_per_sec']}"
        f" clients={snap['connected_clients']}"
        f" hit_ratio={snap['hit_ratio_pct']}"
        f" mem={snap['used_memory_human']}"
        f" mem_fill={snap['maxmemory_fill_pct']}"
        f" policy={snap['maxmemory_policy']}"
        f" evicted={snap['evicted_keys']}"
        f" rejected_conn={snap['rejected_connections']}"
        f" ping_max_ms={snap['ping_max_ms']}"
        f"{mysql_summary}"
    )
    print(line)


def cmd_report(args, cfg):
    """Build a complete monitoring payload and (optionally) POST it to the API.

    Payload schema (version 1):
        {
            "schema_version": 1,
            "monitor_version": "1.0.0",
            "captured_at":     "<UTC ISO-8601 with Z>",
            "site": {
                "site_url":      "<from wp_options 'home' or wp-config WP_HOME>",
                "site_id":       "<from option pigcache_cloud_site_id>",
                "table_prefix":  "<wp_>",
                "wp_config_path":"<absolute path>"
            },
            "monitor": {
                "python_version": "3.x.y",
                "redis_driver":   "redis-py" | "stdlib-bare",
                "hostname":       "<gethostname()>",
            },
            "redis":            { ...redis_snapshot() output... },
            "circuit_breaker":  { "open": bool|null, "age_seconds": int|null, "key": str },
            "mysql":            { ...mysql_probe() output... }   | null,
            "breakdown":        { ...scan_breakdown() output... } | null,
            "stampede":         { ...scan_stampede_locks() output... } | null,
            "system":           { ...system_snapshot() output... }     | null,
            "alerts":           [ { severity, code, msg }, ... ]
        }

    The same `Authorization: Bearer <api_key>` + `X-Site-Id: <id>` headers as
    bin/pigcache-cron.php are used so the API endpoint can reuse its existing
    auth middleware.
    """
    import getpass
    import platform

    # ── Gather Redis state ─────────────────────────────────────────────
    client, endpoint = connect_redis(args, cfg)
    snap = redis_snapshot(client, endpoint)
    cb = circuit_breaker_state(endpoint["host"], endpoint["port"])

    # ── Gather system metrics (CPU / RAM / disk / net / services) ──────
    sys_block = None
    if not args.no_sysinfo:
        wp_root = os.path.dirname(args.wp_config) if args.wp_config else None
        sys_block = system_snapshot(wp_root)
        # Probe Memcached if detected in the service scan
        if sys_block and sys_block.get("services", {}).get(11211):
            mc_host = args.memcached_host or "127.0.0.1"
            mc_port = args.memcached_port or 11211
            sys_block["memcached"] = memcached_probe(mc_host, mc_port)

    # ── Gather MySQL state ────────────────────────────────────────────
    # gather_mysql() transparently falls back to the mariadb/mysql CLI binary
    # when no Python driver is installed (typical cPanel shared host).
    mysql_block = None
    if not args.skip_mysql:
        mysql_block = gather_mysql(args, cfg)

    # ── Open a separate handle for wp_options lookup (also CLI-aware) ─
    # This is needed because the MySQL probe closes its own conn, and the
    # API credential resolver wants a live cursor on wp_options.
    mysql_conn_for_opts = None
    if not args.skip_mysql:
        mysql_conn_for_opts, _kind = _open_mysql_for_options(args, cfg)

    # ── Breakdown by group (optional) ─────────────────────────────────
    breakdown_block = None
    if not args.no_breakdown:
        pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*"
        try:
            cap, dbsize = _resolve_sample_cap(args.sample_cap, client,
                                              label="report")
            breakdown_block = scan_breakdown(
                client, pattern,
                cap=cap,
                sample_per_group=50,
                count=args.scan_count,
            )
            breakdown_block["dbsize_total"] = dbsize
            breakdown_block["coverage_pct"] = (
                round(breakdown_block["scanned_keys"] / dbsize * 100, 2)
                if dbsize and dbsize > 0 else None
            )
            breakdown_block["sample_cap_requested"] = args.sample_cap
            breakdown_block["sample_cap_resolved"] = cap
        except Exception as exc:
            breakdown_block = {"error": str(exc)}

    # ── Stampede (optional) ───────────────────────────────────────────
    stampede_block = None
    if not args.no_stampede:
        lock_pattern = (cfg.get("PIGCACHE_REDIS_PREFIX") or "") + "*pigcache_lock_*"
        try:
            stampede_block = scan_stampede_locks(
                client, lock_pattern, cap=2000, count=500,
            )
        except Exception as exc:
            stampede_block = {"error": str(exc)}

    # ── Per-DB Redis keyspace (cross-referenced with sites_inventory) ─
    # Cheap (single INFO keyspace call) and orthogonal to `breakdown`.
    sites_inv = (sys_block or {}).get("sites_inventory")
    try:
        redis_per_db_block = _redis_keyspace_per_db(client, sites_inv)
    except Exception as exc:
        redis_per_db_block = {"available": False, "reason": str(exc)}

    # ── Tenant fingerprint (lets the cloud API group sibling sites) ───
    tenant_block = _build_tenant_fingerprint(
        hostname=socket.gethostname(),
        user=getpass.getuser(),
        sites_inventory=sites_inv,
        account_snapshot=(sys_block or {}).get("account"),
    )

    # ── Alerts (run health-check logic against this snapshot) ─────────
    alerts = _compute_alerts(
        snap, cb, mysql_block, stampede_block,
        sys_block=sys_block,
        redis_per_db=redis_per_db_block,
    )

    # ── Resolve API credentials (constants → wp_options, like cron) ───
    api_url, api_key, site_id, site_url = resolve_api_credentials(
        args, cfg, mysql_conn=mysql_conn_for_opts,
    )

    if mysql_conn_for_opts is not None:
        try:
            mysql_conn_for_opts.close()
        except Exception:
            pass

    # ── Build payload ─────────────────────────────────────────────────
    payload = {
        "schema_version": 1,
        "monitor_version": "1.0.0",
        "captured_at": datetime.utcnow().isoformat() + "Z",
        "site": {
            "site_url": site_url,
            "site_id": site_id,
            "table_prefix": cfg.get("table_prefix") or "wp_",
            "wp_config_path": args.wp_config or "",
        },
        "monitor": {
            "python_version": platform.python_version(),
            "redis_driver": endpoint.get("driver", "?"),
            "hostname": socket.gethostname(),
            "user": getpass.getuser(),
        },
        "tenant": tenant_block,
        "redis": snap,
        "redis_per_db": redis_per_db_block,
        "circuit_breaker": cb,
        "mysql": mysql_block,
        "breakdown": breakdown_block,
        "stampede": stampede_block,
        "system": sys_block,
        "alerts": alerts,
    }

    # ── Optional local save ────────────────────────────────────────────
    if args.save:
        try:
            with open(args.save, "w", encoding="utf-8") as f:
                json.dump(payload, f, indent=2, default=str)
            print(f"info: wrote payload to {args.save}", file=sys.stderr)
        except OSError as exc:
            print(f"warn: could not write --save file: {exc}", file=sys.stderr)

    # ── Print to stdout (unless quiet) ─────────────────────────────────
    if not args.quiet:
        print(json.dumps(payload, indent=2, default=str))

    # ── Push to API ────────────────────────────────────────────────────
    if not args.push:
        return  # dry-run done

    if not api_key:
        print("error: no API key (PIGCACHE_API_KEY constant or "
              "pigcache_license_key option). Pass --api-key or skip --push.",
              file=sys.stderr)
        sys.exit(3)
    if not site_id:
        print("error: no site_id (pigcache_cloud_site_id option). "
              "Pass --site-id or skip --push.", file=sys.stderr)
        sys.exit(3)

    endpoint_path = args.endpoint.replace("{site_id}", site_id)
    if not endpoint_path.startswith("/"):
        endpoint_path = "/" + endpoint_path
    url = api_url + endpoint_path

    status, body = post_json_to_api(
        url, payload, api_key, site_id, timeout=args.http_timeout,
    )

    if 200 <= status < 300:
        print(f"info: API push OK → {url} (HTTP {status})", file=sys.stderr)
    else:
        snippet = (body or "")[:300].replace("\n", " ")
        print(f"error: API push FAILED → {url} (HTTP {status}) {snippet}",
              file=sys.stderr)
        sys.exit(4)


def _compute_alerts(snap, cb, mysql_block, stampede_block, sys_block=None,
                    ping_max_ms=50.0, hit_ratio_min=80.0,
                    memory_fill_max=90.0, stampede_max=25,
                    mysql_sat_max=80.0, redis_per_db=None,
                    account_quota_warn=85.0, account_quota_crit=95.0):
    """Pure-function version of the rules in run_health(). Used by `report`."""
    alerts = []

    if cb["open"]:
        alerts.append({
            "severity": "critical", "code": "redis_circuit_open",
            "msg": f"Circuit breaker is OPEN (age {cb['age_seconds']}s) — "
                   f"PigCache dropin recently failed to reach Redis.",
        })

    if snap["ping_max_ms"] > ping_max_ms:
        alerts.append({
            "severity": "warn", "code": "redis_latency_high",
            "msg": f"Redis PING max {snap['ping_max_ms']} ms > {ping_max_ms} ms.",
        })

    if snap["hit_ratio_pct"] is not None and snap["hit_ratio_pct"] < hit_ratio_min:
        alerts.append({
            "severity": "warn", "code": "low_hit_ratio",
            "msg": f"Cumulative hit ratio {snap['hit_ratio_pct']}% "
                   f"< {hit_ratio_min}%.",
        })

    if snap["maxmemory_bytes"] == 0:
        alerts.append({
            "severity": "warn", "code": "no_maxmemory",
            "msg": "Redis maxmemory is UNLIMITED — set a cap + LRU policy.",
        })
    elif snap["maxmemory_fill_pct"] and snap["maxmemory_fill_pct"] > memory_fill_max:
        alerts.append({
            "severity": "warn", "code": "memory_pressure",
            "msg": f"Redis memory fill {snap['maxmemory_fill_pct']}% "
                   f"> {memory_fill_max}% — evictions imminent.",
        })

    if snap["maxmemory_policy"] == "noeviction":
        alerts.append({
            "severity": "warn", "code": "policy_noeviction",
            "msg": "maxmemory-policy=noeviction — Redis returns OOM on SET "
                   "when full. Use allkeys-lru or volatile-lru.",
        })

    if snap["rejected_connections"] > 0:
        alerts.append({
            "severity": "warn", "code": "rejected_connections",
            "msg": f"{snap['rejected_connections']} rejected_connections "
                   "since boot — maxclients reached.",
        })

    if stampede_block and stampede_block.get("active_lock_count", 0) > stampede_max:
        alerts.append({
            "severity": "warn", "code": "stampede_locks_high",
            "msg": f"{stampede_block['active_lock_count']} active stampede "
                   "locks — many pages regenerating simultaneously.",
        })

    if mysql_block and "conn_saturation_pct" in mysql_block:
        sat = mysql_block.get("conn_saturation_pct")
        if isinstance(sat, (int, float)) and sat > mysql_sat_max:
            alerts.append({
                "severity": "critical", "code": "mysql_conn_saturation",
                "msg": f"MySQL connection usage {sat}% of max_connections "
                       f"({mysql_block.get('threads_connected')}/"
                       f"{mysql_block.get('max_connections')}).",
            })
        cme = mysql_block.get("connection_errors_max_connections", 0)
        if cme > 0:
            alerts.append({
                "severity": "critical", "code": "mysql_max_conn_errors",
                "msg": f"MySQL has refused {cme} new connections since boot.",
            })

    # System-level alerts (load, RAM, swap, disk)
    if sys_block and "load_error" not in sys_block:
        cores = sys_block.get("cpu_cores") or 1
        load_1m = sys_block.get("load_1m")
        if load_1m is not None:
            if load_1m > cores * 1.5:
                alerts.append({
                    "severity": "critical", "code": "high_load",
                    "msg": f"Load avg {load_1m} (1m) > 150% of {cores} cores — "
                           "server critically overloaded.",
                })
            elif load_1m > cores * 0.85:
                alerts.append({
                    "severity": "warn", "code": "high_load",
                    "msg": f"Load avg {load_1m} (1m) above 85% of {cores} cores.",
                })

    if sys_block and "mem_error" not in sys_block:
        mem_pct = sys_block.get("mem_used_pct")
        if isinstance(mem_pct, (int, float)):
            if mem_pct > 95:
                alerts.append({
                    "severity": "critical", "code": "low_memory",
                    "msg": f"RAM at {mem_pct}% — system near OOM.",
                })
            elif mem_pct > 90:
                alerts.append({
                    "severity": "warn", "code": "low_memory",
                    "msg": f"RAM at {mem_pct}% — less than 10% free.",
                })
        swap_pct = sys_block.get("swap_used_pct")
        if isinstance(swap_pct, (int, float)) and swap_pct > 50:
            alerts.append({
                "severity": "warn", "code": "high_swap",
                "msg": f"Swap at {swap_pct}% — physical RAM under pressure.",
            })

    if sys_block:
        for label, d in (sys_block.get("disk") or {}).items():
            used_pct = d.get("used_pct")
            if not isinstance(used_pct, (int, float)):
                continue
            if used_pct > 95:
                alerts.append({
                    "severity": "critical", "code": f"disk_full_{label}",
                    "msg": f"Disk [{label}] {d.get('path', '')} at {used_pct}% — near full.",
                })
            elif used_pct > 85:
                alerts.append({
                    "severity": "warn", "code": f"disk_usage_{label}",
                    "msg": f"Disk [{label}] {d.get('path', '')} at {used_pct}% used.",
                })

    # Memcached active alongside PigCache → competing object cache
    if sys_block:
        services = sys_block.get("services", {})
        # port 11211 present and Memcached has real traffic
        mc = sys_block.get("memcached", {})
        if services.get(11211) and mc.get("available") and (mc.get("cmd_get", 0) or 0) > 0:
            alerts.append({
                "severity": "warn", "code": "competing_cache_memcached",
                "msg": "Memcached is running and receiving GET requests alongside PigCache. "
                       "A plugin (W3TC, WP Super Cache, etc.) may be using it as object cache, "
                       "splitting cache traffic between Redis and Memcached.",
            })

    # LVE throttle faults (only when cgroup/lve data is accessible)
    lve = (sys_block or {}).get("account_limits", {})
    if lve.get("available"):
        for fault_key, label in [("fault_cpu", "CPU"), ("fault_mem", "MEM"),
                                  ("fault_io", "IO"),  ("fault_ep",  "EntryProcesses")]:
            faults = lve.get(fault_key, 0) or 0
            if faults > 0:
                alerts.append({
                    "severity": "warn", "code": f"lve_fault_{fault_key}",
                    "msg": f"LVE {label} limit hit {faults}× since last reset — "
                           "your account is being throttled by the host.",
                })
        # Memory pressure within account quota
        mem_limit = lve.get("mem_limit_bytes") or lve.get("mem_limit_kb", 0) * 1024
        mem_used  = lve.get("mem_usage_bytes", 0)
        if mem_limit and mem_used:
            pct = mem_used / mem_limit * 100
            if pct > 90:
                alerts.append({
                    "severity": "warn", "code": "lve_mem_pressure",
                    "msg": f"Account RAM at {round(pct, 1)}% of LVE quota "
                           f"({mem_used // (1024*1024)} / {mem_limit // (1024*1024)} MiB).",
                })

    # ── Per-account quotas (cPanel UAPI) ──────────────────────────────
    # When UAPI data is present, alert on disk / MySQL-disk quota pressure.
    # Bandwidth has no maximum on most plans → only alert if a quota is set.
    account = (sys_block or {}).get("account") or {}
    if account.get("available"):
        for metric, label in (
            ("disk_used_pct",       "Disk"),
            ("mysql_disk_used_pct", "MySQL disk"),
            ("bandwidth_used_pct",  "Bandwidth"),
        ):
            pct = account.get(metric)
            if not isinstance(pct, (int, float)):
                continue
            if pct >= account_quota_crit:
                alerts.append({
                    "severity": "critical",
                    "code": f"account_quota_{metric}",
                    "msg": f"cPanel account {label} usage {pct}% of quota "
                           "— host will refuse new writes once full.",
                })
            elif pct >= account_quota_warn:
                alerts.append({
                    "severity": "warn",
                    "code": f"account_quota_{metric}",
                    "msg": f"cPanel account {label} usage {pct}% of quota.",
                })

    # ── Sites inventory: Redis DB collisions across sibling sites ─────
    # Only fires for sites that actually have pigcache installed; WP sites
    # without pigcache aren't reading or writing Redis so a shared DB is fine.
    sites_inv = (sys_block or {}).get("sites_inventory") or {}
    if sites_inv.get("available"):
        for col in sites_inv.get("collisions") or []:
            db    = col["redis_db"]
            sites = col["sites"]
            implicit    = col.get("any_implicit_db")
            same_prefix = col.get("same_table_prefix")
            sev = "critical" if same_prefix else "warn"
            tail = " — and table_prefix matches → keys WILL collide." if same_prefix else ""
            if implicit and db == 0:
                alerts.append({
                    "severity": sev, "code": "redis_db_unset",
                    "msg": (
                        f"{len(sites)} pigcache sites have no "
                        "PIGCACHE_REDIS_DATABASE define and fall back to "
                        f"shared DB 0: {', '.join(sites)}{tail}"
                    ),
                })
            else:
                alerts.append({
                    "severity": sev, "code": "redis_db_collision",
                    "msg": (
                        f"{len(sites)} pigcache sites on this account share "
                        f"Redis DB {db}: {', '.join(sites)}{tail}"
                    ),
                })

        # Per-site warning for any pigcache install that didn't pin its DB.
        for folder in sites_inv.get("risky_implicit_db") or []:
            alerts.append({
                "severity": "warn",
                "code": "pigcache_no_db_pin",
                "msg": (
                    f"Site '{folder}' has pigcache installed but no "
                    "PIGCACHE_REDIS_DATABASE define — falls back to DB 0 "
                    "which other tenants on this shared Redis can FLUSHDB."
                ),
            })

    # ── Per-DB Redis stats: surface DB 0 being shared with other tenants ─
    if redis_per_db and redis_per_db.get("available"):
        db0 = (redis_per_db.get("by_db") or {}).get("0")
        if db0 and db0.get("keys", 0) > 0 and not db0.get("wp_sites_pointing_here"):
            # DB 0 has keys but NONE of *our* WP sites point there → those
            # keys belong to another cPanel tenant on the same Redis server.
            alerts.append({
                "severity": "warn",
                "code": "redis_db0_shared_tenant",
                "msg": (
                    f"Redis DB 0 holds {db0['keys']:,} keys that don't belong "
                    "to any of your WordPress sites — this Redis instance is "
                    "shared with other tenants. Make sure every wp-config.php "
                    "defines a unique PIGCACHE_REDIS_DATABASE so other tenants "
                    "cannot FLUSHDB your cache by accident."
                ),
            })

    # ── Per-domain process I/O hot-spot ───────────────────────────────
    by_domain = (sys_block or {}).get("account_by_domain") or {}
    if by_domain.get("available"):
        # Flag any single non-special domain doing >1 GiB of disk read
        for row in by_domain.get("by_folder") or []:
            if row["folder"].startswith("_"):
                continue
            if row["io_read_mb"] > 1024:
                alerts.append({
                    "severity": "warn",
                    "code": "domain_io_read_hot",
                    "msg": (
                        f"Domain folder '{row['folder']}' has read "
                        f"{row['io_read_mb']:.0f} MiB from disk across "
                        f"{row['procs']} process(es) since they started."
                    ),
                })

    # Environment-level alerts (LSCache conflict, competing plugins)
    env = (sys_block or {}).get("environment", {})
    if env:
        lsc = env.get("lscache", {})
        # Strict: fire only when LSCache has active cache files AND the
        # webserver is configured to use them via .htaccess CacheLookup.
        # A single stale file from months ago no longer trips this alert.
        if (lsc.get("available")
                and lsc.get("cache_active")
                and lsc.get("htaccess_cachelookup")):
            alerts.append({
                "severity": "critical", "code": "lscache_conflict",
                "msg": lsc.get("conflict")
                       or ("LSCache is actively serving pages and bypasses "
                           "PigCache HTML cache. Disable one of them."),
            })
        elif (lsc.get("available")
              and lsc.get("cached_files", 0) > 0
              and not lsc.get("cache_active")):
            alerts.append({
                "severity": "info", "code": "lscache_stale",
                "msg": lsc.get("note")
                       or "Found stale ~/lscache/ files but no recent activity.",
            })

        cp = env.get("competing_plugins", {})
        for conflict_msg in cp.get("conflicts", []) or []:
            alerts.append({
                "severity": "warn", "code": "competing_plugins",
                "msg": conflict_msg,
            })
        # Drop-in ownership signal: PigCache plugin in disk but drop-in owned
        # by someone else → PigCache object cache is silently inactive.
        dropin = env.get("object_cache_dropin", {})
        if (dropin.get("present")
                and dropin.get("owner")
                and dropin.get("owner") != "pigcache"
                and cp.get("pigcache_installed")):
            alerts.append({
                "severity": "critical", "code": "pigcache_dropin_hijacked",
                "msg": (
                    f"wp-content/object-cache.php is owned by "
                    f"'{dropin['owner']}' but PigCache plugin is installed. "
                    "PigCache object cache is NOT serving any requests "
                    "(another plugin took the drop-in). Re-activate from "
                    "PigCache settings or delete object-cache.php and "
                    "re-enable PigCache."
                ),
            })

    return alerts


def cmd_scan(args, _cfg):
    """Standalone environment scan — no Redis or MySQL required.

    Probes every available service/file independently and prints a human-
    readable summary. Each probe falls back gracefully when the source is
    absent or permission-denied, so the output always shows what IS there
    rather than crashing on what isn't.
    """
    wp_root = os.path.dirname(args.wp_config) if getattr(args, "wp_config", None) else None
    env = environment_scan(wp_root)
    if args.json:
        print(json.dumps(env, indent=2, default=str))
    else:
        print(render_environment_text(env))


# ─── CLI ─────────────────────────────────────────────────────────────────────

def _build_common_parent():
    """Common flags inherited by every subcommand (so they work *after* it too).

    This is what lets cron jobs write the natural order:
        pigcache-monitor.py snapshot --wp-config /path/to/wp-config.php --json
    """
    parent = argparse.ArgumentParser(add_help=False)
    parent.add_argument("--wp-config",
                        help="Path to wp-config.php (auto-fills creds)")
    parent.add_argument("--redis-host")
    parent.add_argument("--redis-port", type=int)
    parent.add_argument("--redis-password")
    parent.add_argument("--redis-db", type=int)
    parent.add_argument("--mysql-host")
    parent.add_argument("--mysql-user")
    parent.add_argument("--mysql-password")
    parent.add_argument("--mysql-db")
    parent.add_argument("--mysql-cli", metavar="PATH",
                        help="Path to the mariadb/mysql CLI binary used when "
                             "no Python MySQL driver is installed (auto-detected: "
                             "mariadb, mysql, /usr/bin/mariadb, /opt/cpanel/...)")
    parent.add_argument("--timeout", type=float, default=3.0,
                        help="Socket timeout for Redis/MySQL in seconds "
                             "(default: 3)")
    parent.add_argument("--json", action="store_true",
                        help="Emit JSON instead of text")
    parent.add_argument("--skip-mysql", action="store_true",
                        help="Skip MySQL probe (Redis-only run)")
    parent.add_argument("--sample-cap", default="20000", metavar="N|auto|all|pct:N",
                        help="Max keys to walk via SCAN. Accepts: "
                             "an integer (e.g. 50000), 'auto'/'all' (scan the "
                             "WHOLE keyspace based on DBSIZE — slowest but most "
                             "accurate), or 'pct:N' (sample N%% of DBSIZE, e.g. "
                             "'pct:25'). Default: 20000.")
    parent.add_argument("--scan-count", type=int, default=500,
                        help="COUNT hint per SCAN iteration (default: 500)")
    return parent


def cmd_memcached(args, _cfg):
    host = args.memcached_host or "127.0.0.1"
    port = args.memcached_port or 11211
    m = memcached_probe(host, port, timeout=float(args.timeout))
    if args.json:
        print(json.dumps(m, indent=2, default=str))
    else:
        print(render_memcached_text(m))


def cmd_services(args, _cfg):
    services = detect_services()
    procs    = scan_own_processes()
    if args.json:
        print(json.dumps({"services": services, "processes": procs}, indent=2, default=str))
    else:
        print(render_services_text(services, procs))


def cmd_sysinfo(args, _cfg):
    wp_root = os.path.dirname(args.wp_config) if args.wp_config else None
    sys_snap = system_snapshot(wp_root)
    if args.json:
        print(json.dumps(sys_snap, indent=2, default=str))
    else:
        print(render_system_text(sys_snap))
        env = sys_snap.get("environment")
        if env:
            print()
            print(render_environment_text(env))
        services = sys_snap.get("services")
        procs    = sys_snap.get("processes")
        if services is not None:
            print()
            print(render_services_text(services, procs or []))


def build_parser():
    common = _build_common_parent()

    # Common flags are attached to each subparser (not the root) to avoid the
    # well-known argparse pitfall where the subparser silently overwrites the
    # parent's value with the default of its own copy of the same option.
    p = argparse.ArgumentParser(
        prog="pigcache-monitor",
        description="PigCache Redis + MySQL operational monitor",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )

    # Python 3.6 compat: `required=True` kwarg was added in 3.7; setting the
    # attribute after creation is the supported workaround on both versions.
    sub = p.add_subparsers(dest="cmd")
    sub.required = True

    sub.add_parser("snapshot", parents=[common],
                   help="One-shot Redis (+MySQL) report")

    h = sub.add_parser("health", parents=[common],
                       help="Pass/fail health check (exits 0/1/2 for cron alerts)")
    h.add_argument("--ping-max-ms", type=float,
                   default=DEFAULT_THRESHOLDS["ping_max_ms"])
    h.add_argument("--hit-ratio-min", type=float,
                   default=DEFAULT_THRESHOLDS["hit_ratio_min_pct"])
    h.add_argument("--memory-fill-max", type=float,
                   default=DEFAULT_THRESHOLDS["memory_fill_max_pct"])
    h.add_argument("--stampede-max", type=int,
                   default=DEFAULT_THRESHOLDS["stampede_locks_max"])
    h.add_argument("--mysql-sat-max", type=float,
                   default=DEFAULT_THRESHOLDS["mysql_conn_saturation_max_pct"])
    h.add_argument("--skip-stampede", action="store_true")

    bd = sub.add_parser("breakdown", parents=[common],
                        help="Per-group key/memory breakdown via SCAN")
    bd.add_argument("--sample-per-group", type=int, default=50,
                    help="Keys per group to sample for MEMORY USAGE / TTL")

    hk = sub.add_parser("hot-keys", parents=[common],
                        help="Top largest keys via MEMORY USAGE sampling")
    hk.add_argument("--top", type=int, default=20)

    sub.add_parser("stampede", parents=[common],
                   help="List active pigcache_lock_* keys")

    sub.add_parser("mysql", parents=[common],
                   help="MySQL connection saturation snapshot")

    w = sub.add_parser("watch", parents=[common],
                       help="Compute deltas over a time window")
    w.add_argument("--window", type=int, default=30,
                   help="Seconds to sample (default: 30)")

    sub.add_parser("log-line", parents=[common],
                   help="Single compact line — append-friendly cron output")

    r = sub.add_parser(
        "report", parents=[common],
        help="Build a full monitoring payload (snapshot + breakdown + stampede "
             "+ mysql + alerts) and optionally POST it to the PigCache API "
             "(same auth flow as bin/pigcache-cron.php)",
    )
    r.add_argument("--push", action="store_true",
                   help="Actually POST the payload to the API (otherwise dry-run)")
    r.add_argument("--api-url",
                   help="Override API base URL (default: PIGCACHE_CLOUD_API_URL "
                        "from wp-config, or " + DEFAULT_API_URL + ")")
    r.add_argument("--api-key",
                   help="Override API key (default: PIGCACHE_API_KEY constant "
                        "or pigcache_license_key option)")
    r.add_argument("--site-id",
                   help="Override site ID (default: pigcache_cloud_site_id option)")
    r.add_argument("--endpoint", default="/sites/{site_id}/monitor-snapshot",
                   help="Endpoint path appended to api-url. {site_id} placeholder "
                        "is replaced. Default: /sites/{site_id}/monitor-snapshot")
    r.add_argument("--save", metavar="PATH",
                   help="Also write the payload JSON to this file")
    r.add_argument("--quiet", action="store_true",
                   help="Suppress stdout JSON (only useful with --push and/or --save)")
    r.add_argument("--no-breakdown", action="store_true",
                   help="Skip the SCAN-based group breakdown (faster, lighter payload)")
    r.add_argument("--no-stampede", action="store_true",
                   help="Skip stampede lock scan")
    r.add_argument("--http-timeout", type=int, default=20,
                   help="HTTP timeout in seconds for the API POST (default: 20)")
    r.add_argument("--no-sysinfo", action="store_true",
                   help="Skip OS-level metrics (CPU/RAM/disk/network) from the payload")
    r.add_argument("--memcached-host", default="127.0.0.1",
                   help="Memcached host (default: 127.0.0.1)")
    r.add_argument("--memcached-port", type=int, default=11211,
                   help="Memcached port (default: 11211)")

    sub.add_parser("sysinfo", parents=[common],
                   help="One-shot OS metrics snapshot (CPU, RAM, disk, network, services, processes)")

    mc = sub.add_parser("memcached", parents=[common],
                        help="Probe a Memcached instance (stats, hit ratio, memory, evictions)")
    mc.add_argument("--memcached-host", default="127.0.0.1")
    mc.add_argument("--memcached-port", type=int, default=11211)

    sub.add_parser("services", parents=[common],
                   help="List listening services and account processes from /proc")

    sub.add_parser(
        "scan", parents=[common],
        help="Environment scan — web server, LSCache, CageFS, Imunify360, WP-CLI, "
             "Redis RDB, access logs, AWStats, competing plugins. "
             "No Redis or MySQL connection required.",
    )

    return p


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)

    # Auto-discover wp-config.php if not explicitly given. Same strategy as
    # bin/pigcache-cron.php so both tools "just work" when dropped in
    # wp-content/plugins/pigcache/cli/ and run from a cron line that omits
    # --wp-config.
    cfg = {}
    if args.wp_config:
        cfg = parse_wp_config(args.wp_config)
    else:
        discovered = find_wp_config()
        if discovered:
            cfg = parse_wp_config(discovered)
            args.wp_config = discovered

    if args.cmd == "snapshot":
        cmd_snapshot(args, cfg)
    elif args.cmd == "health":
        run_health(args, cfg)
    elif args.cmd == "breakdown":
        cmd_breakdown(args, cfg)
    elif args.cmd == "hot-keys":
        cmd_hot_keys(args, cfg)
    elif args.cmd == "stampede":
        cmd_stampede(args, cfg)
    elif args.cmd == "mysql":
        cmd_mysql(args, cfg)
    elif args.cmd == "watch":
        cmd_watch(args, cfg)
    elif args.cmd == "log-line":
        cmd_log_line(args, cfg)
    elif args.cmd == "report":
        cmd_report(args, cfg)
    elif args.cmd == "sysinfo":
        cmd_sysinfo(args, cfg)
    elif args.cmd == "memcached":
        cmd_memcached(args, cfg)
    elif args.cmd == "services":
        cmd_services(args, cfg)
    elif args.cmd == "scan":
        cmd_scan(args, cfg)
    else:
        parser.error(f"unknown command: {args.cmd}")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
    except (BrokenPipeError, socket.error):
        sys.exit(141)
