# PigCache — Developer Docs

Internal reference. This file is excluded from all distribution ZIPs.

---

## Versioning & Git Tags

### Where the version lives

Every release requires updating **four places** in sync. The build script
asks you to confirm this before generating any ZIP.

| File | What to change |
|------|----------------|
| `pigcache.php` | `Version:` header (line ~5) |
| `pigcache.php` | `PIGCACHE_VERSION` constant |
| `readme.txt` | `Stable tag:` |
| `readme.txt` | New entry under `== Changelog ==` |

---

### Tagging a release

#### Tag a specific past commit (e.g. retroactively marking v1.0.0)

```bash
git tag -a v1.0.0 <commit-hash> -m "Version 1.0.0 — initial WordPress.org submission"
git push origin v1.0.0
```

Find the right commit hash with:

```bash
git log --oneline
```

#### Tag the current HEAD (normal release flow)

```bash
git tag -a v1.0.1 -m "Version 1.0.1 — security fixes, free/pro invalidation split"
git push origin v1.0.1
```

Always use `-a` (annotated tag). Annotated tags carry a message, date,
and author — GitHub displays them as proper releases. Lightweight tags
(without `-a`) are just pointers with no metadata.

---

### Full release workflow

```
1. Finish all changes on your branch

2. Update version in the four places listed above

3. Commit the version bump:
   git add pigcache.php readme.txt
   git commit -m "bump version to x.x.x"

4. Create the annotated tag:
   git tag -a vx.x.x -m "Version x.x.x — short description"

5. Push commits and tag:
   git push origin <branch>
   git push origin vx.x.x

6. Build the ZIPs:
   ./bin/build.sh free     ← generates dist/pigcache-x.x.x.zip
   ./bin/build.sh pro      ← generates dist/pigcache-pro-x.x.x.zip

7. Upload dist/pigcache-x.x.x.zip to WordPress.org
```

---

### Deleting a tag (if you made a mistake)

```bash
# Delete locally
git tag -d v1.0.1

# Delete from GitHub
git push origin --delete v1.0.1
```

Then recreate and push the corrected tag.

---

### Listing all existing tags

```bash
git tag -l
```

---

## Adaptive TTL v2 — Plan de implementación

La lógica completa de diseño y el flujo de datos están en `CACHE-FLOW.md § 15`.
Este documento cubre los pasos de implementación, los archivos nuevos, los cambios
en archivos existentes, y el schema de las nuevas tablas.

---

### Resumen de qué hay que hacer

| # | Qué | Dónde | Prioridad |
|---|-----|-------|-----------|
| 1 | Crear `PigCache_Adaptive_Ttl` | nuevo archivo | Core |
| 2 | Crear `PigCache_Mutation_Tracker` | nuevo archivo | Core |
| 3 | Crear `PigCache_Traffic_Reader` | nuevo archivo | Core |
| 4 | Schema: tabla `wp_pigcache_table_stability` | `class-pigcache-plugin.php` | Core |
| 5 | Schema: tabla `wp_pigcache_url_traffic` | `class-pigcache-plugin.php` | Core |
| 6 | Añadir mutation counter en `bump_table_epoch()` | `class-pigcache-sql-cache.php` | Core |
| 7 | Añadir HIT counter en HTML cache | `class-pigcache-html-cache.php` | Core |
| 8 | Integrar `decide()` al escribir HTML cache | `class-pigcache-html-cache.php` | Core |
| 9 | Tasks 4 y 5 en el cron standalone | `bin/pigcache-cron.php` | Core |
| 10 | Tasks 4 y 5 en el cron WP (fallback) | `class-pigcache-continuous-learner.php` | Core |
| 11 | Admin UI — sección Adaptive TTL | `class-pigcache-admin-pro.php` | UX |
| 12 | Actualizar constantes en plugin.php / docs | varios | Docs |

---

### 1. `includes/class-pigcache-adaptive-ttl.php` (nuevo)

Decision engine. Stateless — solo lee datos que el cron ya preparó.

```php
class PigCache_Adaptive_Ttl {

    // Devuelve el TTL a usar. 0 = no cachear.
    public static function decide( string $uri, array $tags ): int {
        // 1. Leer hits de esta URL
        $hits = self::get_url_hits( $uri );

        $cold_threshold = defined('PIGCACHE_TRAFFIC_COLD_THRESHOLD')
            ? (int) PIGCACHE_TRAFFIC_COLD_THRESHOLD : 100;

        if ( $hits < $cold_threshold ) {
            $cold_ttl = defined('PIGCACHE_TTL_COLD') ? (int) PIGCACHE_TTL_COLD : 0;
            return $cold_ttl; // ❄️ Cold
        }

        // 2. Resolver tablas de esta página a partir de los tags
        $tables = self::tags_to_tables( $tags );

        // 3. Leer mutation rate máximo entre las tablas de la página
        $max_mutations = self::get_max_mutation_rate( $tables );

        $dyn_threshold = defined('PIGCACHE_MUTATION_DYNAMIC_THRESHOLD')
            ? (int) PIGCACHE_MUTATION_DYNAMIC_THRESHOLD : 10;

        if ( $max_mutations > $dyn_threshold ) {
            return defined('PIGCACHE_TTL_HOT_DYNAMIC')
                ? (int) PIGCACHE_TTL_HOT_DYNAMIC : 60; // ⚡ Hot + Dynamic
        }

        return defined('PIGCACHE_TTL_HOT_STABLE')
            ? (int) PIGCACHE_TTL_HOT_STABLE : 86400; // 🔥 Hot + Stable
    }

    // Resolver tags tipo post:123 → tabla wp_posts via SQL profile
    private static function tags_to_tables( array $tags ): array { ... }

    // Leer hits del store (Redis transient del stability_map)
    private static function get_url_hits( string $uri ): int { ... }

    // Leer tasa de mutación máxima por tabla
    private static function get_max_mutation_rate( array $tables ): int { ... }

    // Invalidar la caché en memoria del stability_map y traffic_map
    public static function flush_decision_cache(): void { ... }
}
```

**Notas de diseño:**
- `get_url_hits()` lee de un transient de WordPress que el cron actualiza (no query MySQL en hot path).
- `get_max_mutation_rate()` lee del mismo transient del stability map.
- Si no hay datos disponibles (cron no corrió aún), devolver TTL estático por defecto.

---

### 2. `includes/class-pigcache-mutation-tracker.php` (nuevo)

Responsable del stability map: escribir contadores, leerlos, y exponer el mapa para el decision engine.

```php
class PigCache_Mutation_Tracker {

    const REDIS_KEY_PREFIX = 'pigcache_mut_window:';
    const REDIS_TTL        = 86400; // 24h — ventana de conteo
    const MAP_TRANSIENT    = 'pigcache_stability_map';
    const MAP_TTL          = 20 * MINUTE_IN_SECONDS; // cron lo refresca cada 15 min

    // Llamado desde bump_table_epoch() — incrementa el contador de la ventana
    public static function record_mutation( string $table ): void {
        wp_cache_incr( self::REDIS_KEY_PREFIX . $table, 1, 'pigcache' );
    }

    // Llamado por el cron — harvest Redis → MySQL → actualiza transient
    public static function harvest(): void {
        // 1. Leer todos los contadores pigcache_mut_window:* de Redis
        // 2. Upsert en wp_pigcache_table_stability
        // 3. Calcular mutations_per_day por tabla
        // 4. Guardar mapa en transient pigcache_stability_map
    }

    // Llamado por el decision engine
    public static function get_stability_map(): array {
        $map = get_transient( self::MAP_TRANSIENT );
        return is_array( $map ) ? $map : array();
    }
}
```

---

### 3. `includes/class-pigcache-traffic-reader.php` (nuevo)

Lee datos de tráfico desde AWStats o desde contadores propios. Expone el traffic map para el decision engine y los HIT counters para el HTML cache.

```php
class PigCache_Traffic_Reader {

    const HIT_KEY_PREFIX = 'pigcache_html_hits:';
    const MAP_TRANSIENT  = 'pigcache_traffic_map';
    const MAP_TTL        = 25 * HOUR_IN_SECONDS; // se refresca 1x/día

    // Llamado en HTML cache HIT (fallback cuando no hay AWStats)
    public static function record_hit( string $doc_key ): void {
        if ( self::source() === 'self' ) {
            wp_cache_incr( self::HIT_KEY_PREFIX . $doc_key, 1, 'pigcache' );
        }
    }

    // Llamado por el cron — harvest según la fuente configurada
    public static function harvest(): void {
        $source = self::source();
        if ( 'awstats' === $source || 'auto' === $source ) {
            $data = self::read_awstats();
            if ( ! empty( $data ) ) {
                self::store_traffic_map( $data );
                return;
            }
        }
        // Fallback: contadores propios
        $data = self::read_own_counters();
        self::store_traffic_map( $data );
    }

    // Parsear la sección BEGIN_URLS del archivo AWStats más reciente
    private static function read_awstats(): array {
        $dir  = self::awstats_dir();
        // glob awstats*.txt, ordenar por más reciente, abrir, parsear
        // Retorna: [ '/about/' => 4500, '/blog/' => 3200, ... ]
    }

    // Leer Redis pigcache_html_hits:* y acumular
    private static function read_own_counters(): array { ... }

    // Detectar path de AWStats automáticamente
    private static function awstats_dir(): string {
        if ( defined('PIGCACHE_AWSTATS_DIR') ) return PIGCACHE_AWSTATS_DIR;
        // Probar ~/tmp/awstats/, /tmp/awstats/
    }

    private static function source(): string {
        return defined('PIGCACHE_TRAFFIC_SOURCE') ? PIGCACHE_TRAFFIC_SOURCE : 'auto';
    }

    public static function get_traffic_map(): array {
        $map = get_transient( self::MAP_TRANSIENT );
        return is_array( $map ) ? $map : array();
    }

    private static function store_traffic_map( array $data ): void {
        // Upsert en wp_pigcache_url_traffic
        // Actualizar transient MAP_TRANSIENT
    }
}
```

**AWStats — detalle del parsing:**

```
BEGIN_URLS N
/url/   pages  hits  bandwidth  date  time  ...
END_URLS
```

El campo `hits` es el índice 2 (base 0) de cada línea dentro de la sección.
Ignorar líneas que empiezan con `#`. Ignorar URLs de recursos estáticos
(`.jpg`, `.css`, `.js`, `.png`, `.woff`, `.svg`, etc.).

---

### 4 y 5. Schema — tablas nuevas

En `class-pigcache-plugin.php`, método `activate()`:

```php
// Tabla: frecuencia de mutaciones por tabla MySQL
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pigcache_table_stability (
    table_name      varchar(128)  NOT NULL,
    window_start    datetime      NOT NULL,
    mutation_count  int unsigned  NOT NULL DEFAULT 0,
    PRIMARY KEY (table_name, window_start),
    KEY idx_window (window_start)
) {$charset_collate}" );

// Tabla: hits de tráfico por URL
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pigcache_url_traffic (
    url_hash     char(32)      NOT NULL,
    url          varchar(2083) NOT NULL,
    hit_count    int unsigned  NOT NULL DEFAULT 0,
    period_start datetime      NOT NULL,
    PRIMARY KEY (url_hash, period_start),
    KEY idx_period (period_start)
) {$charset_collate}" );
```

En `uninstall.php` / `deactivate()`: DROP de ambas tablas.

Retención: al igual que `query_stats`, pruning de registros > 30 días en el cron.

---

### 6. Cambio en `class-pigcache-sql-cache.php`

En `bump_table_epoch()`, añadir una sola línea:

```php
public static function bump_table_epoch( $table ) {
    // ... código existente de INCR del epoch ...

    // Nuevo — registrar mutación para adaptive TTL v2
    if ( class_exists( 'PigCache_Mutation_Tracker', false ) ) {
        PigCache_Mutation_Tracker::record_mutation( $table );
    }
}
```

Sin cambio en `bump_epoch()` global (el global epoch no tiene tabla específica,
no aporta info de estabilidad por página).

---

### 7 y 8. Cambios en `class-pigcache-html-cache.php`

**En el HIT** — registrar visita para el fallback de contadores propios:

```php
// Cuando se sirve un HIT del HTML cache:
if ( class_exists( 'PigCache_Traffic_Reader', false ) ) {
    PigCache_Traffic_Reader::record_hit( $doc_key );
}
```

**En el MISS** — usar el decision engine antes de persistir:

```php
// ob_callback — al final del render, antes del wp_cache_set:
$ttl = PigCache_Sql_Cache::DEFAULT_TTL; // fallback al TTL estático

if (
    defined( 'PIGCACHE_ADAPTIVE_TTL' ) && PIGCACHE_ADAPTIVE_TTL
    && class_exists( 'PigCache_Adaptive_Ttl', false )
) {
    $ttl = PigCache_Adaptive_Ttl::decide( $_SERVER['REQUEST_URI'] ?? '', $tags );
}

if ( 0 === $ttl ) {
    // Cold — no persistir, solo devolver el HTML al visitor
    return $buffer;
}

wp_cache_set( $doc_key, $pack, 'pigcache_html', $ttl );
```

---

### 9. Cambios en `bin/pigcache-cron.php`

Añadir dos tasks al bloque de control existente:

```php
$run_mutation_harvest = true;
$run_traffic_harvest  = true;

foreach ( $argv ?? array() as $arg ) {
    if ( $arg === '--no-adaptive' ) {
        $run_mutation_harvest = false;
        $run_traffic_harvest  = false;
    }
}
```

**Task 4 — Mutation harvest** (cada ejecución, ~15 min):

```php
if ( $run_mutation_harvest ) {
    // Leer Redis: pigcache_mut_window:*
    $mut_data = _pigcache_cron_read_mutation_counters( $redis_cfg );
    if ( ! empty( $mut_data ) ) {
        _pigcache_cron_upsert_mutations( $db, $stability_table, $mut_data, $window_start );
    }
    // Calcular mutations_per_day y escribir stability_map en wp_options
    $stability_map = _pigcache_cron_build_stability_map( $db, $stability_table );
    _pigcache_cron_update_option( $db, $options_table, 'pigcache_stability_map', json_encode( $stability_map ) );
    // Pruning > 30 días
    $db->query( "DELETE FROM `{$stability_table}` WHERE window_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    _pigcache_cron_log( 'mutation harvest done — ' . count( $mut_data ) . ' tables' );
}
```

**Task 5 — Traffic harvest** (corre solo si han pasado ≥ 23 h desde el último):

```php
if ( $run_traffic_harvest ) {
    $last_traffic = (int) _pigcache_cron_get_option( $db, $options_table, 'pigcache_traffic_harvest_last' );
    if ( time() - $last_traffic >= 23 * 3600 ) {
        $traffic_data = _pigcache_cron_read_awstats( $cfg, $wp_config_path )
                     ?: _pigcache_cron_read_hit_counters( $redis_cfg );
        if ( ! empty( $traffic_data ) ) {
            _pigcache_cron_upsert_traffic( $db, $traffic_table, $traffic_data, $window_start );
        }
        $traffic_map = _pigcache_cron_build_traffic_map( $db, $traffic_table );
        _pigcache_cron_update_option( $db, $options_table, 'pigcache_traffic_map', json_encode( $traffic_map ) );
        _pigcache_cron_update_option( $db, $options_table, 'pigcache_traffic_harvest_last', (string) time() );
        $db->query( "DELETE FROM `{$traffic_table}` WHERE period_start < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
        _pigcache_cron_log( 'traffic harvest done — ' . count( $traffic_data ) . ' URLs' );
    }
}
```

---

### 10. WP-Cron fallback (`class-pigcache-continuous-learner.php`)

Cuando `PIGCACHE_USE_WP_CRON` está activo, añadir dos eventos al `schedule()`:

```php
const CRON_MUTATION_HARVEST = 'pigcache_mutation_harvest';
const CRON_TRAFFIC_HARVEST  = 'pigcache_traffic_harvest';

// En schedule():
if ( ! wp_next_scheduled( self::CRON_MUTATION_HARVEST ) ) {
    wp_schedule_event( time(), 'pigcache_flush', self::CRON_MUTATION_HARVEST ); // cada 15 min
}
if ( ! wp_next_scheduled( self::CRON_TRAFFIC_HARVEST ) ) {
    wp_schedule_event( time(), 'daily', self::CRON_TRAFFIC_HARVEST );
}

// En init():
add_action( self::CRON_MUTATION_HARVEST, [ 'PigCache_Mutation_Tracker', 'harvest' ] );
add_action( self::CRON_TRAFFIC_HARVEST,  [ 'PigCache_Traffic_Reader',   'harvest' ] );
```

---

### 11. Admin UI (`class-pigcache-admin-pro.php`)

Nueva sección **Adaptive TTL** en la página Pro, que muestra:

- Toggle enable/disable (`PIGCACHE_ADAPTIVE_TTL` o guardado en `wp_options`).
- Umbrales configurables: cold threshold, dynamic threshold, TTL por tier.
- Fuente de tráfico detectada: "AWStats (ruta detectada)" o "Contadores propios".
- Tabla con las top 20 URLs clasificadas: URL, hits, mutation_rate, tier asignado.
- Timestamp del último harvest de tráfico y mutaciones.

---

### 12. Constantes a documentar en `pigcache.php` (docblock)

```php
// Adaptive TTL v2
define( 'PIGCACHE_ADAPTIVE_TTL',               true );
define( 'PIGCACHE_TTL_HOT_STABLE',             86400 );  // 1 día
define( 'PIGCACHE_TTL_HOT_DYNAMIC',            60 );     // 1 minuto
define( 'PIGCACHE_TTL_COLD',                   0 );      // no cachear
define( 'PIGCACHE_TRAFFIC_COLD_THRESHOLD',     100 );    // hits/mes
define( 'PIGCACHE_MUTATION_DYNAMIC_THRESHOLD', 10 );     // mutaciones/día
define( 'PIGCACHE_AWSTATS_DIR',                '/home/user/tmp/awstats' );
define( 'PIGCACHE_TRAFFIC_SOURCE',             'auto' ); // 'awstats' | 'self' | 'auto'
```

---

### Orden de implementación recomendado

```
1. Schema (activate/uninstall)               ← sin esto nada persiste
2. PigCache_Mutation_Tracker                 ← datos de estabilidad
3. bump_table_epoch() + record_mutation()    ← empieza a acumular datos
4. Cron Task 4 (mutation harvest)            ← lleva datos a MySQL
5. PigCache_Traffic_Reader (AWStats)         ← datos de tráfico
6. Cron Task 5 (traffic harvest)             ← lleva datos a MySQL
7. PigCache_Adaptive_Ttl::decide()          ← motor de decisión
8. Integración en HTML cache                 ← punto donde aplica el TTL
9. Admin UI                                  ← visibilidad y configuración
10. WP-Cron fallback                         ← paridad con el cron standalone
```

Dejar el sistema **inactivo por defecto** (`PIGCACHE_ADAPTIVE_TTL = false`) hasta
completar al menos los pasos 1–8 y tener datos de al menos 24–48 horas para que
el stability map y el traffic map sean representativos.

---

## Cron Heartbeat — Migración futura a tabla dedicada

### Estado actual

El cron detecta si está activo mediante un timestamp en `wp_options`:

| Opción | Escrita por | Leída por |
|--------|------------|-----------|
| `pigcache_cron_flush_last` | `pigcache-cron.php` (directo a MySQL) | `is_cron_confirmed()`, admin Pro |
| `pigcache_cron_send_last` | `pigcache-cron.php` (directo a MySQL) | Admin Pro |

El problema estructural: el cron escribe directo a MySQL sin pasar por WordPress, lo que obliga a invalidar **dos capas de cache** en cada lectura (el valor de la opción y `notoptions`). Este patrón está centralizado en `get_flush_last()` / `get_send_last()` para evitar que se repita el bug donde se olvidaba limpiar `notoptions`.

### Cuándo migrar

Migrar si alguno de estos casos se vuelve real:
- El doble `wp_cache_delete` sigue causando bugs después de refactors.
- Se añaden más claves de heartbeat y el patrón escala mal.
- Se necesita historial de ejecuciones (no solo la última).

### Cómo hacerlo

**1. Crear la tabla en la activación del plugin**

En `class-pigcache-plugin.php`, método `activate()`:

```php
global $wpdb;
$charset = $wpdb->get_charset_collate();
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pigcache_meta (
    meta_key   varchar(64)  NOT NULL,
    meta_value varchar(255) NOT NULL DEFAULT '',
    updated_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (meta_key)
) {$charset}" );
```

**2. Cambiar la escritura en el cron**

En `bin/pigcache-cron.php`, reemplazar `_pigcache_cron_update_option()` por una query directa a la nueva tabla:

```php
// Antes:
_pigcache_cron_update_option( $db, $options_table, 'pigcache_cron_flush_last', (string) time() );

// Después:
$meta_table = $table_prefix . 'pigcache_meta';
$stmt = $db->prepare(
    "INSERT INTO `{$meta_table}` (meta_key, meta_value) VALUES ('cron_flush_last', ?)
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
);
$now = (string) time();
$stmt->bind_param( 's', $now );
$stmt->execute();
$stmt->close();
```

**3. Cambiar la lectura en `PigCache_Continuous_Learner`**

```php
// Antes (wp_options con doble cache-bust):
public static function get_flush_last(): int {
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( self::OPT_FLUSH_LAST, 'options' );
    return (int) get_option( self::OPT_FLUSH_LAST, 0 );
}

// Después (query directa, sin problema de cache):
public static function get_flush_last(): int {
    global $wpdb;
    $val = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}pigcache_meta WHERE meta_key = %s",
            'cron_flush_last'
        )
    );
    return (int) $val;
}
```

**4. Eliminar las constantes y métodos obsoletos**

Una vez migrado, se pueden eliminar:
- `OPT_FLUSH_LAST` y `OPT_SEND_LAST` de `PigCache_Continuous_Learner`
- Las filas `pigcache_cron_flush_last` / `pigcache_cron_send_last` de `wp_options` (limpiar en `activate()` con `delete_option()`)
- El doble `wp_cache_delete` en cualquier parte del admin que lo tuviera

**5. Migración de datos existentes (opcional)**

En `activate()`, para no perder el timestamp del último flush al actualizar:

```php
$old = get_option( 'pigcache_cron_flush_last' );
if ( $old ) {
    $wpdb->replace( $wpdb->prefix . 'pigcache_meta', [
        'meta_key'   => 'cron_flush_last',
        'meta_value' => $old,
    ] );
    delete_option( 'pigcache_cron_flush_last' );
}
```

### Archivos a tocar en esa migración

| Archivo | Cambio |
|---------|--------|
| `includes/class-pigcache-plugin.php` | `CREATE TABLE` en `activate()`, `DROP TABLE` en `uninstall()` |
| `bin/pigcache-cron.php` | Escritura heartbeat a la nueva tabla |
| `includes/class-pigcache-continuous-learner.php` | `get_flush_last()`, `get_send_last()`, `is_cron_confirmed()` |
| `includes/class-pigcache-admin-pro.php` | Verificar que no haya lecturas directas de `OPT_FLUSH_LAST` |
