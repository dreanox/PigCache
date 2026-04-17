# PigCache — Manual completo

Guía de uso, administración y desarrollo de PigCache: object cache Redis,
HTML cache, SQL cache (con profiler) y fragmentos.

---

## Índice

1. [Instalación](#1-instalación)
2. [HTML Cache — Caché de página completa](#2-html-cache)
3. [SQL Cache — Caché de consultas](#3-sql-cache)
4. [Fragment Cache — Caché de fragmentos](#4-fragment-cache)
5. [Sistema de tags (invalidación selectiva)](#5-sistema-de-tags)
6. [SQL Profiler — Per-table epochs](#6-sql-profiler)
7. [PigCache Pro — Cloud SQL Profiles](#7-pigcache-pro)
8. [Administración desde wp-admin](#8-administración-desde-wp-admin)
9. [Constantes de configuración (wp-config.php)](#9-constantes-de-configuración)
10. [Filtros para desarrolladores](#10-filtros-para-desarrolladores)
11. [CLI y herramientas externas](#11-cli-y-herramientas-externas)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Instalación

### Requisitos

- WordPress 5.8+
- PHP 7.4+
- Redis servidor accesible (default: `127.0.0.1:6379`)

### Pasos

1. Copia la carpeta `pigcache/` a `wp-content/plugins/`.
2. Activa el plugin en **Plugins**.
3. Ve a **Ajustes -> PigCache** y pulsa **Enable object cache**.
4. (Opcional) Instala el **db.php drop-in** para activar el SQL cache.
5. (Opcional) Inicia el **SQL Profiler** para per-table epoch invalidation.

### Clientes Redis soportados

| Cliente | Cómo se usa |
|---------|-------------|
| **PhpRedis** | Automático si la extensión `redis` está cargada (recomendado). |
| **Predis** | Incluido en `vendor/`. Se usa si PhpRedis no está disponible. |
| **Relay** | Soportado vía constante `WP_REDIS_CLIENT`. |

---

## 2. HTML Cache

### Qué hace

Captura la salida HTML completa de cada página y la almacena en Redis.
La siguiente visita del mismo URL se sirve directamente desde Redis sin
ejecutar WordPress ni MySQL.

### Quién se cachea

- Visitantes **no logueados**
- Peticiones **GET** sin datos POST
- No admin, no AJAX, no cron, no REST, no preview
- No páginas WooCommerce sensibles (cart, checkout, account)

### Cómo funciona

```
Visitante -> GET /mi-post/

  1. Redis: ¿existe "doc_{md5(/mi-post/)}" en pigcache_html?
     -> SÍ (HIT): echo HTML, exit. Cero MySQL.
     -> NO (MISS): WordPress renderiza la página normalmente.

  2. Durante el render, el Tag Collector registra qué objetos
     aparecen en la página:
     - post:123, post_type:post, author:5
     - term:10, taxonomy:category
     - nav_menu:3, sidebar:sidebar-1
     - zone:header, zone:footer

  3. Al terminar el render:
     a) Se guarda en Redis: { html, tags[], time }
     b) Se guardan los tags en MySQL: wp_pigcache_tags
     c) Se libera el stampede lock.
```

### Invalidación (tag-based)

Cuando un editor guarda un post, PigCache:

1. Resuelve los tags del post: `post:123`, `post_type:post`, `author:5`,
   todos sus terms, `home`, `feed`, `date:2026-04`.
2. Consulta MySQL: `SELECT cache_key FROM wp_pigcache_tags WHERE tag IN (...)`
3. Borra solo esas keys en Redis.
4. Limpia las filas de MySQL.

Las 10,000 otras páginas cacheadas **no se tocan**.

### Administrar el HTML cache

| Acción | Cómo |
|--------|------|
| **Ver páginas cacheadas** | En admin: Dashboard de Cache > HTML Cache |
| **Configurar TTL** | Ajustes -> PigCache > Cache TTL > Full-page HTML cache |
| **Desactivar** | Filtro `pigcache_skip_html_cache` retornando `true` |
| **Excluir una URL** | `add_filter('pigcache_skip_html_cache', fn() => is_page('contacto'));` |
| **Ajustar TTL por URL** | Filtro `pigcache_html_ttl` |
| **Purgar todo** | Flush object cache desde admin (borra todo Redis) |
| **Tagear custom** | `pigcache_tag('widget:recent_posts')` en tu template |

---

## 3. SQL Cache

### Qué hace

Intercepta consultas `SELECT` elegibles y guarda el resultado en Redis.
La siguiente ejecución de la misma query se sirve desde Redis.

### Dónde opera

- Solo en el **frontend** (visitantes, no logueados por defecto).
- **NO** se usa en: admin, AJAX, REST, cron, WP-CLI, XMLRPC.

### Dos modos de invalidación

#### Modo 1: Global epoch (default, sin profiler)

Un solo contador epoch. Cualquier mutación (INSERT, UPDATE, DELETE)
incrementa el epoch y **todas** las queries cacheadas se vuelven stale.

```
INSERT INTO wp_posts ...  ->  INCR pigcache_sql_epoch  ->  TODO stale
```

Simple y seguro. Es el modo por defecto si no has activado el profiler.

#### Modo 2: Per-table epochs (con profiler compilado)

Cada tabla tiene su propio epoch. Un INSERT en `wp_posts` solo invalida
queries que tocan `wp_posts`. Queries que solo leen `wp_options` o
`wp_terms` siguen cacheadas.

```
INSERT INTO wp_posts ...  ->  INCR epoch:wp_posts  ->  solo queries de wp_posts stale
                              queries de wp_options siguen frescas
```

Requiere haber ejecutado el profiler (ver sección 6).

### Administrar el SQL cache

| Acción | Cómo |
|--------|------|
| **Activar** | Ajustes -> PigCache > Install db.php drop-in |
| **Desactivar** | Ajustes -> PigCache > Remove PigCache db.php |
| **Ver modo actual** | Admin > Dashboard de Cache > SQL Cache |
| **Configurar TTL** | Ajustes -> PigCache > Cache TTL > SQL SELECT cache |
| **Excluir queries** | Filtro `pigcache_sql_cache_is_cacheable` |
| **Desactivar globalmente** | Filtro `pigcache_sql_cache_enabled` |

---

## 4. Fragment Cache

### Qué hace

Cachea fragmentos de output (widgets, sidebars, componentes) vía
object cache con tags opcionales para invalidación selectiva.

### Uso básico

```php
// Sin tags (solo TTL):
$html = pigcache_fragment( 'mi_widget', function() {
    return '<div>Contenido del widget</div>';
}, 300 );

echo $html;
```

### Con tags (invalidación selectiva)

```php
$html = pigcache_fragment(
    'sidebar_popular',
    function() {
        // renderizar los 5 posts más populares
        $posts = get_posts(['numberposts' => 5, 'orderby' => 'comment_count']);
        $out = '<ul>';
        foreach ($posts as $p) {
            pigcache_tag('post:' . $p->ID);  // taguear cada post mostrado
            $out .= '<li>' . esc_html($p->post_title) . '</li>';
        }
        $out .= '</ul>';
        return $out;
    },
    300,                    // TTL: 5 minutos
    'pigcache_fragments',   // grupo
    ['post_type:post', 'sidebar:primary']  // tags para invalidación
);
```

Cuando se edita cualquier post de tipo `post`, este fragmento se purgará
automáticamente porque tiene el tag `post_type:post`.

---

## 5. Sistema de tags (invalidación selectiva)

### Concepto

Cada página/fragmento cacheado se asocia con "tags" que describen qué
objetos muestra. Cuando un objeto cambia, solo se purgan las entries
que lo referencian.

### Tags automáticos

| Hook WordPress | Tags generados | Ejemplo |
|----------------|---------------|---------|
| `the_post` | `post:{ID}`, `post_type:{type}`, `author:{ID}` | `post:123`, `post_type:post`, `author:5` |
| `get_the_terms` | `term:{term_id}`, `taxonomy:{taxonomy}` | `term:10`, `taxonomy:category` |
| `wp_get_nav_menu_items` | `nav_menu:{menu_id}` | `nav_menu:3` |
| `dynamic_sidebar_before` | `sidebar:{sidebar_id}` | `sidebar:sidebar-1` |
| `get_header` | `zone:header` | `zone:header` |
| `get_footer` | `zone:footer` | `zone:footer` |

### Tags implícitos

| Condición | Tag |
|-----------|-----|
| Front page | `home` |
| URL con `/feed` | `feed` |

### Agregar tags manualmente

```php
// En cualquier template, widget o plugin:
pigcache_tag( 'widget:recent_posts' );
pigcache_tag( 'custom:mi_slider' );
pigcache_tag( 'woo:product_list' );
```

Solo funciona durante un cache MISS (cuando el Tag Collector está activo).
En cache HIT el collector no corre — cero overhead.

### Purgar por tags desde tu plugin

```php
// Purgar todo lo que tenga el tag 'widget:recent_posts':
if ( class_exists( 'PigCache_Tag_Index' ) ) {
    PigCache_Tag_Index::purge_by_tags( ['widget:recent_posts'] );
}
```

### Personalizar tags de invalidación por post

```php
// Agregar tags extras cuando se invalida un post:
add_filter( 'pigcache_invalidation_tags', function( $tags, $post_id ) {
    $tags[] = 'custom:mi_componente';
    return $tags;
}, 10, 2 );
```

### Debuggear tags (MySQL)

```sql
-- Ver qué páginas referencian un post:
SELECT cache_key, tag FROM wp_pigcache_tags WHERE tag = 'post:123';

-- Ver todos los tags de una página:
SELECT tag FROM wp_pigcache_tags
WHERE cache_key = 'doc_abc123' AND grp = 'pigcache_html';

-- Páginas con más tags:
SELECT cache_key, COUNT(*) AS total
FROM wp_pigcache_tags GROUP BY cache_key ORDER BY total DESC LIMIT 20;
```

---

## 6. SQL Profiler (Premium)

> **Requiere PigCache Pro** o periodo de prueba activo.
> Al activar el plugin por primera vez, se inicia un **trial de 14 días**
> para que puedas evaluar el profiler en tu sitio. Una vez expirado el
> trial, necesitas una licencia Pro para iniciar learning, compilar
> profiles o re-aprender. Los profiles ya compilados siguen funcionando
> en runtime (read-only) tras expirar el trial.

### Concepto

El profiler aprende qué queries ejecuta tu sitio durante un periodo
de observación, extrae qué tablas toca cada query, y compila un archivo
PHP estático. En runtime, ese archivo se carga una vez y permite
invalidar solo las queries que tocan la tabla que cambió.

### Workflow completo

#### Paso 1: Iniciar learning

Desde **Ajustes -> PigCache > SQL Query Profiler**:
- Selecciona la duración (1-30 días, recomendado 7).
- Pulsa **Start Learning**.

O desde `wp-config.php`:
```php
define( 'PIGCACHE_SQL_PROFILE_LEARN', true );
```

#### Paso 2: Esperar

Durante el periodo de learning, cada SELECT interceptado se:
- Normaliza (valores literales -> `?`)
- Fingerprint (`md5` del template normalizado)
- Extrae tablas (`FROM`, `JOIN`)
- Guarda en `wp_pigcache_sql_fingerprints`

Puedes ver el progreso en la sección del admin:
- Templates únicos encontrados
- Total de queries grabadas
- Días restantes

#### Paso 3: Compilar

Cuando el periodo termina (o antes si quieres):
- Pulsa **Compile Now** en el admin.
- Se genera `wp-content/pigcache-sql-profile.php`.
- El per-table epoch mode se activa automáticamente.

#### Paso 4: Runtime

```
SELECT ... FROM wp_posts JOIN wp_postmeta ...
  -> normalize -> fingerprint -> lookup en profile estático
  -> tables = [wp_posts, wp_postmeta]
  -> check epoch:wp_posts = 5, epoch:wp_postmeta = 3
  -> pack has {wp_posts:5, wp_postmeta:3} -> FRESH HIT

INSERT INTO wp_posts ...
  -> extract table = "wp_posts"
  -> INCR epoch:wp_posts only
  -> queries de wp_options, wp_terms siguen cacheadas
```

#### Re-learn automático

Cuando se activa/desactiva un plugin, se cambia el tema, o se actualiza
WordPress, el profiler automáticamente:

1. Hace backup del profile actual (`.bak`).
2. Inicia un nuevo periodo de learning.
3. Usa el backup como fallback durante el re-learning.

Desactivar con: `define('PIGCACHE_SQL_PROFILE_AUTO_RELEARN', false);`

#### Análisis profundo (CLI, opcional)

```bash
pip3 install mysql-connector-python

# Analizar con credenciales de wp-config:
python3 cli/pigcache-analyze.py --wp-config /path/to/wp-config.php

# Guardar report en JSON:
python3 cli/pigcache-analyze.py --wp-config /path/to/wp-config.php -o report.json
```

El analyzer produce:
- Ranking de queries por frecuencia
- Mapa de dependencias entre tablas
- Estimación de eficiencia: "X% de queries sobrevivirían un save_post"
- Recomendaciones específicas

---

## 7. PigCache Pro — Cloud SQL Profiles

### Free vs Pro

| Característica | Free | Trial (14 días) | Pro |
|---------------|------|-----------------|-----|
| Object cache Redis | Si | Si | Si |
| HTML cache con tags | Si | Si | Si |
| SQL cache con global epoch | Si | Si | Si |
| Fragment cache | Si | Si | Si |
| SQL Profiler (local learning) | No | **Si** | **Si** |
| Per-table epoch invalidation | No | **Si** | **Si** |
| **Cloud SQL profiles** | No | No | **Si** |
| **Instant per-table epochs** (sin esperar learning) | No | No | **Si** |
| **Cross-site intelligence** (WooCommerce, Jetpack, etc.) | No | No | **Si** |
| **Environment-aware profiles** | No | No | **Si** |

### Cómo funciona

1. **Activar licencia**: Ingresa tu API key en Ajustes -> PigCache > PigCache Pro.
2. **Detección de entorno**: El plugin envía la lista de plugins activos, tema y
   versión de WP al backend (solo slugs, nunca datos del sitio).
3. **Profile instantáneo**: Si otro sitio con el mismo stack ya contribuyó datos,
   recibes un profile compilado de inmediato. No necesitas esperar 7 días de learning.
4. **Sync continuo**: Si no hay profile aún, el learning local corre normalmente
   y los fingerprints se sincronizan al cloud cada 12 horas.
5. **Inteligencia agregada**: El backend combina fingerprints de muchos sitios con
   el mismo entorno, produciendo profiles más completos y confiables.

### Qué datos se envían

El plugin envía **templates SQL normalizados** (sin valores reales):

```
Original:  SELECT * FROM wp_posts WHERE post_author = 5 AND post_status = 'publish'
Se envía:  SELECT * FROM wp_posts WHERE post_author = ? AND post_status = ?
```

Además:
- Nombres de tablas extraídos de la query
- Conteo de hits y promedio de filas
- Slugs de plugins activos (e.g. "woocommerce")
- Slug del tema
- Versión mayor de WP

**Nunca se envían**: datos reales, contenido del sitio, usuarios, passwords,
ni credenciales de conexión.

### Activar desde wp-config.php

```php
define( 'PIGCACHE_LICENSE_KEY', 'pc_live_abc123...' );
define( 'PIGCACHE_CLOUD_API_URL', 'https://api.pigcache.com/v1' );  // default
define( 'PIGCACHE_CLOUD_SYNC', true );  // default cuando es Pro
```

### Administrar Pro desde wp-admin

| Acción | Dónde |
|--------|-------|
| **Activar licencia** | Ajustes -> PigCache > PigCache Pro |
| **Ver plan y status** | Misma sección — muestra plan, Site ID, last sync |
| **Sync manual** | Botón "Sync Now" — sube fingerprints y descarga profile |
| **Desactivar** | Botón "Deactivate License" — libera el seat |
| **Ver source del profile** | Cloud vs Local en la tabla de status |

### Fallback

Si el cloud no está disponible (sin internet, API caída, etc.), el plugin:
1. Usa el profile local si existe.
2. Si no hay profile, usa global epoch (mode por defecto).
3. Nunca crashea ni bloquea el sitio.

---

## 8. Administración desde wp-admin

### Ajustes -> PigCache

| Sección | Qué muestra/controla |
|---------|---------------------|
| **Cache Dashboard** | Vista en tiempo real: HTML pages cacheadas, SQL mode, tag index stats, epochs |
| **Redis object cache** | Estado del drop-in, botones Enable/Disable/Flush/Update |
| **Cache TTL** | Configurar TTL para SQL y HTML cache |
| **Object cache groups** | Ver/agregar grupos no-persistentes |
| **SQL result cache** | Estado de db.php, Install/Remove |
| **SQL Query Profiler** | Learning status, Start/Compile/Re-learn/Delete |
| **PigCache Pro** | Licencia, Cloud sync status, Sync Now, Deactivate |
| **Remove drop-ins** | Eliminar object-cache.php y/o db.php |

### Ajustes -> PigCache metrics

| Sección | Qué muestra |
|---------|-------------|
| **Object cache (this request)** | Hits, misses, ratio, bytes, Redis calls |
| **Redis server (INFO)** | Memoria, conexiones, keyspace hits/misses, versión |
| **Cached keys** | Lista paginada de keys con TTL, tipo, y label inferido |

### Cache Dashboard (nuevo)

El dashboard muestra de un vistazo:

- **HTML Cache**: páginas en el tag index, páginas cacheadas, invalidación mode
- **SQL Cache**: mode (global epoch / per-table epoch), epoch actual, profile status
- **Fragments**: entries en el tag index
- **Tag Index**: total de rows en `wp_pigcache_tags`, tags únicos

---

## 9. Constantes de configuración

### Redis

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `WP_REDIS_HOST` | `127.0.0.1` | Host Redis |
| `WP_REDIS_PORT` | `6379` | Puerto |
| `WP_REDIS_DATABASE` | `0` | DB lógica (ver nota abajo) |
| `WP_REDIS_PASSWORD` | _(vacío)_ | Password |
| `WP_REDIS_PREFIX` | _(auto: `md5(DB_NAME\|table_prefix)`)_ | Prefijo para keys Redis. Se auto-genera si no se define. |
| `WP_REDIS_SELECTIVE_FLUSH` | _(auto: `true` si hay prefix)_ | Solo borrar keys con el prefijo del sitio al hacer flush. Se activa automáticamente cuando hay un prefix. |
| `WP_REDIS_CLIENT` | _(auto)_ | Forzar: `phpredis`, `predis`, `relay` |

> **Auto-prefix:** Si no defines `WP_REDIS_PREFIX`, `WP_CACHE_KEY_SALT`,
> ni estás en Cloudways, PigCache genera un hash de 8 caracteres a partir
> de `DB_NAME` y `$table_prefix`. Esto garantiza que dos sitios con bases
> de datos diferentes nunca colisionen en Redis, incluso si comparten la
> misma instancia y la misma DB lógica.

> **Nota sobre `WP_REDIS_DATABASE`:** Muchos hostings compartidos usan
> proxies Redis (Twemproxy, Redis Cluster, servicios single-DB) que
> **ignoran el comando `SELECT`** silenciosamente. No confíes solo en
> esta constante para aislar sitios. El auto-prefix es la forma segura.

### Hosting compartido — varios sitios, un solo Redis

En hosting compartido donde varias instalaciones WordPress comparten el
mismo servidor Redis (`public_html/sitio1/`, `public_html/sitio2/`, etc.):

**Sin configurar nada** (recomendado si las bases de datos son distintas):

PigCache genera automáticamente un prefijo único por sitio. Cada sitio
tendrá sus propias claves Redis sin colisión. Al hacer flush en un sitio,
solo se borran las claves de ese sitio.

**Con configuración manual** (si quieres prefijos legibles):

```php
// wp-config.php — Sitio 1 (tienda.ejemplo.com)
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PREFIX', 'tienda:' );

// wp-config.php — Sitio 2 (blog.ejemplo.com)
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PREFIX', 'blog:' );
```

**Con DB lógicas separadas** (se puede combinar con prefix):

```php
// wp-config.php — Sitio 1 (usa DB 0 por defecto)

// wp-config.php — Sitio 2
define( 'WP_REDIS_DATABASE', 7 );
```

**Qué pasa con el flush:**

| Escenario | Qué borra el flush |
|-----------|-------------------|
| Con prefix (auto o manual) | Solo keys del sitio actual (selective flush) |
| Sin prefix + sin selective flush | `FLUSHDB` — borra **todas** las keys del DB lógica actual |

Por eso PigCache activa `WP_REDIS_SELECTIVE_FLUSH` automáticamente
cuando hay un prefix activo.

### Invalidación

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `PIGCACHE_INVALIDATE_THROTTLE` | `2` | Segundos mínimos entre invalidaciones |
| `PIGCACHE_INVALIDATE_ON_OPTION` | `false` | Invalidar en cambios de opciones |
| `PIGCACHE_INVALIDATE_ON_TERM` | `false` | Invalidar en cambios de términos |
| `PIGCACHE_INVALIDATE_ON_COMMENT` | `false` | Invalidar en cambios de comentarios |
| `PIGCACHE_INVALIDATE_ON_NAV_MENU` | `false` | Invalidar en cambios de menús |
| `PIGCACHE_INVALIDATE_ON_WIDGET` | `false` | Invalidar en cambios de widgets |
| `PIGCACHE_INVALIDATE_ON_THEME` | `false` | Invalidar en cambio de tema |
| `PIGCACHE_INVALIDATE_ON_USER` | `false` | Invalidar en cambios de usuarios |

### SQL Profiler (Premium — Trial 14 días / Pro)

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `PIGCACHE_SQL_PROFILE_LEARN` | `false` | Forzar learning mode (requiere Pro/trial) |
| `PIGCACHE_SQL_PROFILE_PATH` | `wp-content/pigcache-sql-profile.php` | Ruta del profile compilado |
| `PIGCACHE_SQL_PROFILE_AUTO_RELEARN` | `true` | Re-learn en cambio de plugin/tema (requiere Pro/trial) |

### PigCache Pro (Cloud)

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `PIGCACHE_LICENSE_KEY` | _(vacío)_ | API key (alternativa al admin UI) |
| `PIGCACHE_CLOUD_API_URL` | `https://api.pigcache.com/v1` | URL del backend |
| `PIGCACHE_CLOUD_SYNC` | `true` (cuando es pro) | Habilitar/deshabilitar sync |

### Ejemplo wp-config.php — Sitio único

```php
// Redis (PigCache genera auto-prefix, no necesitas WP_REDIS_PREFIX)
define( 'WP_REDIS_HOST', '127.0.0.1' );

// Invalidación
define( 'PIGCACHE_INVALIDATE_THROTTLE', 3 );
define( 'PIGCACHE_INVALIDATE_ON_COMMENT', true );
define( 'PIGCACHE_INVALIDATE_ON_TERM', true );

// SQL Profiler
define( 'PIGCACHE_SQL_PROFILE_AUTO_RELEARN', true );

// PigCache Pro
define( 'PIGCACHE_LICENSE_KEY', 'pc_live_abc123...' );
```

### Ejemplo wp-config.php — Hosting compartido (varios sitios)

```php
// Sitio A (public_html/tienda/) — wp-config.php
define( 'WP_REDIS_HOST', '127.0.0.1' );
// Sin WP_REDIS_PREFIX ni WP_REDIS_DATABASE: PigCache aísla
// automáticamente usando DB_NAME como semilla del prefix.

// Sitio B (public_html/blog/) — wp-config.php
define( 'WP_REDIS_HOST', '127.0.0.1' );
// Mismo Redis, distinta DB MySQL = auto-prefix distinto = aislado.

// Alternativa: prefixes explícitos para mayor claridad en redis-cli
define( 'WP_REDIS_PREFIX', 'blog:' );
```

---

## 10. Filtros para desarrolladores

| Filtro | Parámetros | Uso |
|--------|-----------|-----|
| `pigcache_html_ttl` | `$ttl, $uri` | Ajustar TTL de HTML por URI |
| `pigcache_skip_html_cache` | `$skip` | Saltar HTML cache para esta request |
| `pigcache_sql_cache_ttl` | `$ttl` | Ajustar TTL de SQL cache |
| `pigcache_sql_cache_enabled` | `$enabled` | Desactivar SQL cache globalmente |
| `pigcache_sql_cache_skip_context` | `$skip` | Forzar skip del SQL cache en un contexto |
| `pigcache_sql_cache_is_cacheable` | `$cacheable, $sql` | Marcar un SELECT como no cacheable |
| `pigcache_invalidation_tags` | `$tags, $post_id` | Agregar/modificar tags en invalidación |

### Ejemplos

```php
// No cachear HTML en páginas de búsqueda:
add_filter( 'pigcache_skip_html_cache', function() {
    return is_search();
});

// TTL de 5 minutos para el feed, 1 hora para todo lo demás:
add_filter( 'pigcache_html_ttl', function( $ttl, $uri ) {
    if ( strpos( $uri, '/feed' ) !== false ) {
        return 300;
    }
    return 3600;
}, 10, 2 );

// No cachear queries que contengan 'wp_wc_orders':
add_filter( 'pigcache_sql_cache_is_cacheable', function( $cacheable, $sql ) {
    if ( stripos( $sql, 'wp_wc_orders' ) !== false ) {
        return false;
    }
    return $cacheable;
}, 10, 2 );
```

---

## 11. CLI y herramientas externas

### Python analyzer

```bash
# Instalar dependencia
pip3 install mysql-connector-python

# Analizar con wp-config
python3 cli/pigcache-analyze.py --wp-config /var/www/html/wp-config.php

# Analizar con credenciales manuales
python3 cli/pigcache-analyze.py \
    --host 127.0.0.1 \
    --user root \
    --password mipass \
    --database wordpress

# Guardar report
python3 cli/pigcache-analyze.py --wp-config /var/www/html/wp-config.php -o report.json
```

### WP-CLI (futuro)

Comandos planificados para futuras versiones:
- `wp pigcache flush html` — Purgar todo el HTML cache
- `wp pigcache flush sql` — Bump SQL epoch
- `wp pigcache profiler start` — Iniciar learning
- `wp pigcache profiler compile` — Compilar profile
- `wp pigcache tags list --post=123` — Ver tags de un post

---

## 12. Troubleshooting

### El HTML cache no funciona

1. Verifica que el object cache esté activo (Ajustes -> PigCache).
2. Verifica que no estés logueado (el HTML cache es solo para visitantes).
3. Revisa si algún filtro retorna `true` en `pigcache_skip_html_cache`.
4. Verifica en Redis: `redis-cli GET wp:1:pigcache_html:doc_*`

### El SQL cache no funciona

1. Verifica que `db.php` esté instalado (Ajustes -> PigCache).
2. El SQL cache no opera en admin, AJAX, REST, cron ni WP-CLI.
3. Verifica el contexto con `pigcache_sql_cache_skip_context`.

### El profiler no compila

1. Verifica que el learning haya corrido al menos unos días.
2. Revisa que `wp-content/` sea escribible por PHP.
3. Si el profile está vacío, verifica que el db.php drop-in esté activo.

### Las páginas no se invalidan

1. Revisa que el tag collector esté recogiendo tags:
   ```sql
   SELECT * FROM wp_pigcache_tags WHERE tag = 'post:123';
   ```
2. Si no hay filas, la página no fue taqueada durante el render.
3. Agrega `pigcache_tag('post:123')` manualmente en tu template.

### Un sitio muestra el contenido de otro (hosting compartido)

1. Verifica que ambos sitios tengan **bases de datos diferentes** (`DB_NAME`
   distinto en cada wp-config.php). El auto-prefix se calcula a partir de
   `DB_NAME`, así que DBs diferentes = prefixes diferentes = aislamiento.
2. Si ambos sitios usan la misma DB MySQL (con distinto `$table_prefix`),
   el auto-prefix también los diferencia.
3. Si aun así hay contaminación, define `WP_REDIS_PREFIX` explícitamente
   en cada sitio con un valor único.
4. Después de cualquier cambio de prefix, haz flush de caché en **todos**
   los sitios (o `redis-cli FLUSHALL` una sola vez).

### Redis no conecta

1. Verifica que Redis esté corriendo: `redis-cli ping`
2. Revisa las constantes `WP_REDIS_HOST`, `WP_REDIS_PORT`.
3. Si usas password: `WP_REDIS_PASSWORD`.
4. Revisa el log de PHP para errores de conexión.

### Demasiada memoria en Redis

1. Revisa TTLs (Ajustes -> PigCache > Cache TTL).
2. Ejecuta el cleanup de tags stale:
   ```php
   PigCache_Tag_Index::cleanup_stale();
   ```
3. Considera `redis-cli INFO memory` para ver el uso.
