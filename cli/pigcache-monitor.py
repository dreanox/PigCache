#!/usr/bin/env python3
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


# ─── Redis circuit-breaker flag (matches dropin) ─────────────────────────────

def circuit_breaker_state(host, port):
    """Mirror class PigCache_Dropin_Object_Cache::pigcache_circuit_path."""
    import hashlib
    import tempfile
    flag = os.path.join(
        tempfile.gettempdir(),
        "pigcache_cb_" + hashlib.md5(f"{host}:{port}".encode()).hexdigest() + ".flag",
    )
    if not os.path.exists(flag):
        return {"open": False, "path": flag, "age_seconds": None}
    try:
        with open(flag, "r") as f:
            ts = int(f.read().strip() or "0")
    except OSError:
        return {"open": True, "path": flag, "age_seconds": None}
    return {"open": True, "path": flag, "age_seconds": int(time.time()) - ts}


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
    if cb_state["open"]:
        age = cb_state.get("age_seconds")
        out.append(f"  circuit breaker         : OPEN  (flag {cb_state['path']}, age {age}s)")
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
            "circuit_breaker":  { "open": bool, "age_seconds": int|null, "path": str },
            "mysql":            { ...mysql_probe() output... }   | null,
            "breakdown":        { ...scan_breakdown() output... } | null,
            "stampede":         { ...scan_stampede_locks() output... } | null,
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

    # ── Alerts (run health-check logic against this snapshot) ─────────
    alerts = _compute_alerts(snap, cb, mysql_block, stampede_block)

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
        "redis": snap,
        "circuit_breaker": cb,
        "mysql": mysql_block,
        "breakdown": breakdown_block,
        "stampede": stampede_block,
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


def _compute_alerts(snap, cb, mysql_block, stampede_block,
                    ping_max_ms=50.0, hit_ratio_min=80.0,
                    memory_fill_max=90.0, stampede_max=25,
                    mysql_sat_max=80.0):
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

    return alerts


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
    else:
        parser.error(f"unknown command: {args.cmd}")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
    except (BrokenPipeError, socket.error):
        sys.exit(141)
