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
| **Relay** | Soportado vía constante `PIGCACHE_REDIS_CLIENT`. |

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
define( 'PIGCACHE_LICENSE_KEY', 'a1b2c3d4e5f678901234567890abcdef0123456789abcdef0123456789abcd' );
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
| `PIGCACHE_REDIS_HOST` | `127.0.0.1` | Host Redis |
| `PIGCACHE_REDIS_PORT` | `6379` | Puerto |
| `PIGCACHE_REDIS_DATABASE` | `0` | DB lógica (ver nota abajo) |
| `PIGCACHE_REDIS_PASSWORD` | _(vacío)_ | Password |
| `PIGCACHE_REDIS_PREFIX` | _(auto: `md5(DB_NAME\|table_prefix)`)_ | Prefijo para keys Redis. Se auto-genera si no se define. |
| `PIGCACHE_REDIS_SELECTIVE_FLUSH` | _(auto: `true` si hay prefix)_ | Solo borrar keys con el prefijo del sitio al hacer flush. Se activa automáticamente cuando hay un prefix. |
| `PIGCACHE_REDIS_CLIENT` | _(auto)_ | Forzar: `phpredis`, `predis`, `relay` |
| `PIGCACHE_REDIS_TIMEOUT` | `1` | Timeout de conexión TCP en segundos. Aumentar en redes con latencia alta. |
| `PIGCACHE_REDIS_READ_TIMEOUT` | `1` | Timeout de lectura de socket en segundos. Si Redis tarda más de este tiempo en responder (p. ej. durante un `BGSAVE` en servidores con mucha RAM), phpredis lanza "read error on connection". **Recomendado: `3` en sitios de alto tráfico.** |

> **Auto-prefix:** Si no defines `PIGCACHE_REDIS_PREFIX`, `WP_CACHE_KEY_SALT`,
> ni estás en Cloudways, PigCache genera un hash de 8 caracteres a partir
> de `DB_NAME` y `$table_prefix`. Esto garantiza que dos sitios con bases
> de datos diferentes nunca colisionen en Redis, incluso si comparten la
> misma instancia y la misma DB lógica.

> **Nota sobre `PIGCACHE_REDIS_DATABASE`:** Muchos hostings compartidos usan
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
define( 'PIGCACHE_REDIS_HOST', '127.0.0.1' );
define( 'PIGCACHE_REDIS_PREFIX', 'tienda:' );

// wp-config.php — Sitio 2 (blog.ejemplo.com)
define( 'PIGCACHE_REDIS_HOST', '127.0.0.1' );
define( 'PIGCACHE_REDIS_PREFIX', 'blog:' );
```

**Con DB lógicas separadas** (se puede combinar con prefix):

```php
// wp-config.php — Sitio 1 (usa DB 0 por defecto)

// wp-config.php — Sitio 2
define( 'PIGCACHE_REDIS_DATABASE', 7 );
```

**Qué pasa con el flush:**

| Escenario | Qué borra el flush |
|-----------|-------------------|
| Con prefix (auto o manual) | Solo keys del sitio actual (selective flush) |
| Sin prefix + sin selective flush | `FLUSHDB` — borra **todas** las keys del DB lógica actual |

Por eso PigCache activa `PIGCACHE_REDIS_SELECTIVE_FLUSH` automáticamente
cuando hay un prefix activo.

### Resiliencia — fallback y circuit breaker

Si Redis no está disponible (reinicio, fallo de red, timeout de lectura), PigCache
puede degradarse silenciosamente en lugar de mostrar una pantalla de error a los
visitantes. El sitio sigue funcionando con MySQL; simplemente no hay caché esa request.

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `PIGCACHE_REDIS_GRACEFUL` | `true` | **`true`** (default): si Redis falla, PigCache cae en fallback silencioso — el object cache usa solo la memoria PHP de la request, el SQL cache hace miss, el HTML cache se salta. Los visitantes no ven ningún error. **`false`**: muestra la pantalla de error "Error establishing a Redis connection" y detiene la carga de WordPress hasta que Redis vuelva. Solo útil para depuración. |
| `PIGCACHE_REDIS_RETRY_INTERVAL` | `30` | Segundos que el circuit breaker mantiene Redis "desconectado" tras un fallo. Durante este intervalo **ninguna** request intenta conectarse a Redis (evita acumular N × `PIGCACHE_REDIS_READ_TIMEOUT` de latencia extra durante una caída). Transcurrido el intervalo, una sola request "sonda" la conexión; si tiene éxito el circuit se cierra automáticamente. |

#### Cómo funciona el circuit breaker

```
Request 1 → intenta conectar Redis → fallo (read error, 1 s de timeout)
            → handle_exception() → redis_connected = false
            → escribe /tmp/pigcache_cb_{hash}.flag con timestamp
            → fallback: PHP array cache para esta request

Request 2–N (siguientes 30 s) → detecta flag, lo lee, ve que tiene < 30 s
            → salta el intento de conexión completamente (0 ms de overhead)
            → fallback: PHP array cache

Request (tras 30 s) → detecta flag, ve que tiene ≥ 30 s → borra flag
            → intenta conexión → si Redis volvió: éxito → circuit cerrado
            →                    si Redis sigue caído: flag se crea de nuevo
```

#### Configuración recomendada para sitios de alto tráfico

```php
// wp-config.php

// Dar a Redis 3 s para responder — previene "read error" durante BGSAVE
// en instancias con mucha RAM (854 MB+, 1 GB+).
define( 'PIGCACHE_REDIS_READ_TIMEOUT', 3 );

// Fallback silencioso activado por defecto (no necesitas esta línea
// a menos que quieras DESACTIVARLO para depuración):
// define( 'PIGCACHE_REDIS_GRACEFUL', false );

// Extender la ventana del circuit breaker a 60 s en entornos donde
// los reinicios de Redis tardan más de 30 s:
// define( 'PIGCACHE_REDIS_RETRY_INTERVAL', 60 );
```

#### Por qué aparece "read error on connection"

El error `read error on connection to 127.0.0.1:6379` **no significa que Redis
esté caído** — significa que la conexión TCP se estableció pero la respuesta tardó
más de `PIGCACHE_REDIS_READ_TIMEOUT` (default `1 s`). Causas frecuentes:

- **`BGSAVE` / `BGREWRITEAOF`**: Redis hace un `fork()` para guardar en disco.
  Con 500 MB+ en memoria, el fork tarda decenas o cientos de ms y puede incrementar
  la latencia de otras operaciones durante ese lapso.
- **`read_timeout` demasiado corto**: el default de 1 s es apropiado para Redis
  en la misma máquina bajo carga normal, pero puede ser insuficiente bajo picos.
- **Conexiones TCP stale**: con conexiones persistentes y reinicios de Redis,
  el socket puede quedar "muerto" del lado del OS.

Diagnosticar con:

```bash
# Ver latencia en tiempo real (1 muestra por segundo):
redis-cli --latency-history -i 1

# Ver los últimos comandos lentos (> 10 ms por defecto):
redis-cli SLOWLOG GET 25

# Ver configuración actual de saves:
redis-cli CONFIG GET save

# Deshabilitar BGSAVE periódico si no necesitas RDB snapshots
# (solo si usas AOF o no necesitas persistencia):
redis-cli CONFIG SET save ""
```

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

### Continuous Learning (Pro)

| Constante | Default | Descripción |
|-----------|---------|-------------|
| `PIGCACHE_CONTINUOUS_LEARNING` | _(desde API)_ | `true`/`false` para forzar on/off independientemente de la config remota |
| `PIGCACHE_LEARNING_SAMPLE_RATE` | `0.10` | Fracción de requests que registran queries (0.0–1.0) |
| `PIGCACHE_ADAPTIVE_TTL` | _(desde API)_ | `true` activa TTL adaptivo por tiempo de ejecución |
| `PIGCACHE_FLUSH_INTERVAL` | `15` | Intervalo del cron en minutos (1–60). Cambia la frecuencia con que el cron standalone y WP-Cron vacían el buffer. Ver [intervalo del cron](#intervalo-del-cron). |
| `PIGCACHE_USE_WP_CRON` | _(no definido)_ | Usar WP-Cron como fallback en lugar del cron standalone. No recomendado en producción. |

### Ejemplo wp-config.php — Sitio único

```php
// Redis (PigCache genera auto-prefix, no necesitas PIGCACHE_REDIS_PREFIX)
define( 'PIGCACHE_REDIS_HOST', '127.0.0.1' );

// Invalidación
define( 'PIGCACHE_INVALIDATE_THROTTLE', 3 );
define( 'PIGCACHE_INVALIDATE_ON_COMMENT', true );
define( 'PIGCACHE_INVALIDATE_ON_TERM', true );

// SQL Profiler
define( 'PIGCACHE_SQL_PROFILE_AUTO_RELEARN', true );

// PigCache Pro
define( 'PIGCACHE_LICENSE_KEY', 'a1b2c3d4e5f678901234567890abcdef0123456789abcdef0123456789abcd' );
```

### Ejemplo wp-config.php — Hosting compartido (varios sitios)

```php
// Sitio A (public_html/tienda/) — wp-config.php
define( 'PIGCACHE_REDIS_HOST', '127.0.0.1' );
// Sin PIGCACHE_REDIS_PREFIX ni PIGCACHE_REDIS_DATABASE: PigCache aísla
// automáticamente usando DB_NAME como semilla del prefix.

// Sitio B (public_html/blog/) — wp-config.php
define( 'PIGCACHE_REDIS_HOST', '127.0.0.1' );
// Mismo Redis, distinta DB MySQL = auto-prefix distinto = aislado.

// Alternativa: prefixes explícitos para mayor claridad en redis-cli
define( 'PIGCACHE_REDIS_PREFIX', 'blog:' );
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

### Cron standalone (`bin/pigcache-cron.php`)

Script CLI que ejecuta el pipeline de analytics **sin cargar WordPress**.
Lee `wp-config.php` por regex, conecta a MySQL directamente y usa `curl` para la API.

#### Tareas que ejecuta (en orden)

| # | Tarea | Condición |
|---|-------|-----------|
| 1 | Flush buffer APCu/Redis → `wp_pigcache_query_stats` | Siempre (salvo `--send-only` o `--cloud-only`) |
| 2 | Enviar query stats pendientes → API | Siempre (salvo `--flush-only` o `--cloud-only`) |
| 3 | **Cloud sync** — environment + fingerprints + profile | Siempre (salvo `--flush-only`, `--send-only`, o `--no-cloud`) |

#### Tarea 3 — Cloud sync detallado

1. **Environment** — lee plugins activos y tema de `wp_options`, versión WP de `wp-includes/version.php` → `POST /sites/{id}/environment`
2. **Fingerprints** — vacía `wp_pigcache_sql_fingerprints` (unsynced) en batches de 500 → `POST /sites/{id}/fingerprints`; marca cada batch como `synced_at = NOW()`
3. **Profile** — `GET /sites/{id}/profile`; si hay perfil compilado lo escribe en `wp-content/pigcache-sql-profile.php`

Esta tarea es la que hace que los **templates de consulta se vean reflejados** sin necesidad de WP-Cron.

#### Flags disponibles

```
--flush-only    Solo tarea 1. Útil para sites sin API key.
--send-only     Solo tarea 2. Solo envío de stats.
--cloud-only    Solo tarea 3 (cloud sync). Útil para diagnóstico.
--no-cloud      Tareas 1 y 2, sin cloud sync.
--wp-config /ruta/wp-config.php   Path explícito al wp-config.
```

#### Intervalo del cron

El intervalo por defecto es **15 minutos**. Puedes cambiarlo con:

```php
// wp-config.php
define( 'PIGCACHE_FLUSH_INTERVAL', 5 );  // minutos; rango válido: 1–60
```

- Afecta al schedule de WP-Cron (cuando `PIGCACHE_USE_WP_CRON` está activo).
- Para el cron real de servidor (cPanel), ajusta también la expresión crontab para que coincida.
- El panel de admin considera el cron "activo" si corrió en los últimos `PIGCACHE_FLUSH_INTERVAL + 10` minutos.

**Ejemplo crontab cada 5 minutos:**
```
*/5 * * * *  /usr/local/bin/php -q /path/to/pigcache/bin/pigcache-cron.php \
  > /dev/null 2>> /path/to/pigcache/logs/pigcache-cron.log
```

#### Diagnóstico con modo verbose (API)

Para verificar qué perfil tiene el backend y qué templates contiene:

```
GET /api/v1/sites/{siteId}/profile?verbose=1
```

La respuesta incluye `debug.templates` con la lista de fingerprints, hits y template de cada query, y `debug.fingerprints_stored_in_db` vs `debug.fingerprints_in_profile` para detectar si el cron compiló el perfil correctamente.

#### Por qué el admin no reconocía el cron

El script standalone escribe directamente a MySQL sin pasar por WordPress. Si el site usa un object cache persistente (Redis o APCu), `get_option()` devolvía el valor cacheado anterior. La solución implementada es llamar `wp_cache_delete()` antes de cada `get_option()` en `is_cron_confirmed()` y en la lectura de timestamps del admin.

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

### Monitor operativo (`cli/pigcache-monitor.py`)

Script Python con varios subcomandos para vigilar la salud de Redis + MySQL
**fuera de wp-admin**, pensado para correr por cron en cPanel. No requiere
cargar WordPress.

#### Instalación

```bash
pip3 install --user redis PyMySQL
# (mysql-connector-python también sirve)
```

#### Sin pip / cPanel bloqueado

El script tiene **dos fallbacks de stdlib pura** para que funcione sin
instalar ni un solo paquete Python:

* **Redis**: si `redis-py` no está instalado, usa un cliente RESP interno
  hecho a mano con `socket` (driver reportado como `stdlib-bare`).
* **MySQL**: si ni `PyMySQL` ni `mysql-connector-python` están instalados,
  hace `subprocess.run(["mariadb", ...])` (o `mysql`) y parsea el output
  tab-separado de `SHOW GLOBAL STATUS` / `SHOW VARIABLES`. El binario se
  auto-detecta vía `shutil.which()` con paths típicos de cPanel
  (`/usr/bin/mariadb`, `/opt/cpanel/ea-mariadb*/bin/mariadb`, etc.).
  Override manual: `--mysql-cli /ruta/al/mariadb`.

Resultado: **TODOS** los subcomandos (`snapshot`, `health`, `breakdown`,
`hot-keys`, `stampede`, `mysql`, `watch`, `log-line`, `report --push`)
funcionan en un cPanel bloqueado con solo Python 3 + el binario `mariadb`
(que ya viene siempre en los hosts compartidos).

La contraseña al CLI nunca aparece en `ps` — se pasa por la variable de
entorno `MYSQL_PWD` (la forma documentada por MySQL/MariaDB).

Diagnóstico rápido de qué hay en el cPanel:

```bash
python3 --version
python3 -m pip --version 2>&1                 # pip ya instalado?
python3 -m ensurepip --version 2>&1 | head -1 # se puede bootstrappear?
python3 -c "import redis"   2>&1              # paquete presente?
python3 -c "import pymysql" 2>&1
which mysql redis-cli
```

Caminos en orden de preferencia si pip falta:

1. `python3 -m ensurepip --user --upgrade && python3 -m pip install --user redis PyMySQL`
2. cPanel **Setup Python App** → crea un virtualenv con su propio pip → activarlo y `pip install redis PyMySQL`. En el cron usa la ruta absoluta al `python3` de ese virtualenv.
3. `curl -sS https://bootstrap.pypa.io/get-pip.py | python3 - --user` y luego `python3 -m pip install --user redis PyMySQL`.
4. Vendor manual: descarga los `.whl` (son zips), descomprime en `~/pigcache-deps/` y corre con `PYTHONPATH=$HOME/pigcache-deps python3 pigcache-monitor.py ...`.
5. **No hagas nada** y deja que el script use el fallback de stdlib. Pierdes algo de rendimiento en `breakdown` con `--sample-cap` muy alto, pero para uso normal de monitoreo es perfectamente bueno.

#### Subcomandos

| Subcomando | Qué hace |
|------------|----------|
| `snapshot` | Reporte completo de una sola pasada (Redis + MySQL): versión, latencia PING, hit ratio acumulado, memoria vs `maxmemory`, política de evicción, evicciones/expiraciones, slowlog top 5, saturación MySQL. |
| `health` | Pass/fail con exit codes (`0` OK, `1` warn, `2` critical). Pensado para cron con email-on-failure. Chequea circuit-breaker, ping, hit ratio, fill de memoria, política, conexiones rechazadas, stampede locks, saturación MySQL. |
| `breakdown` | Recorre el keyspace con `SCAN` y agrupa por grupo de cache (`pigcache_html`, `pigcache_sql`, `pigcache_fragments`, `options`, `posts`, `transient`…). Muestra conteo, % sin TTL, TTL promedio, bytes muestreados con `MEMORY USAGE` y estimación de bytes totales por grupo. **Esta es la vista clave para responder "¿en qué se está usando mi memoria de Redis?".** |
| `hot-keys` | Top N keys más grandes vía `MEMORY USAGE` (muestreo). Detecta keys gordas que están comiendo memoria. |
| `watch` | Toma dos snapshots separados por una ventana de tiempo y calcula deltas reales (hits/s, miss/s, ops/s, evicciones/s, hit ratio **de la ventana**, no acumulado). |
| `mysql` | Solo MySQL: `Threads_connected`, `Max_used_connections`, `Aborted_clients`, `Connection_errors_max_connections`, `Slow_queries` y `processlist` por estado. Respuesta directa al "Error establishing a database connection". |
| `stampede` | Lista las keys `pigcache_lock_*` activas. Si hay muchas, hay regeneraciones en cola (cache MISS en páginas hot y/o DB lenta). |
| `log-line` | Una sola línea compacta con las métricas clave. Ideal para `>> archivo.log 2>&1` y analizar con `grep`/`awk` o ingestar en cualquier monitor que tail-ee logs. |
| `report` | Arma un payload JSON con TODO (snapshot + breakdown + stampede + mysql + alerts) y opcionalmente lo **manda al API** vía `POST` con los mismos headers de auth que el `bin/pigcache-cron.php` (`Authorization: Bearer ...` + `X-Site-Id: ...`). Útil para alimentar dashboards remotos y para que el backend tome decisiones (TTL adaptivo, alertas, autoscaling). |

#### Flags comunes (sirven en todos los subcomandos)

```
--wp-config PATH       Auto-rellena credenciales Redis y MySQL desde wp-config.php
--redis-host, --redis-port, --redis-password, --redis-db
--mysql-host (acepta host:port), --mysql-user, --mysql-password, --mysql-db
--timeout 3            Timeout de socket para Redis y MySQL (segundos)
--json                 Emite JSON en vez de texto (para jq / ingesta)
--skip-mysql           Solo Redis
--sample-cap 20000     Límite de keys que `SCAN` puede recorrer
--scan-count 500       COUNT hint para cada iteración de SCAN
```

`health` añade thresholds tuneables: `--ping-max-ms 50`, `--hit-ratio-min 80`,
`--memory-fill-max 90`, `--stampede-max 25`, `--mysql-sat-max 80`.

#### Cron en cPanel (Cron Jobs)

Reemplaza `/home/USER` por tu home real y la ruta del plugin.

```cron
# Cada minuto: una línea compacta para tail / grep
* * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py log-line --wp-config /home/USER/public_html/wp-config.php >> /home/USER/logs/pigcache-monitor.log 2>&1

# Cada 5 minutos: health check, cPanel envía email si exit != 0
*/5 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py health --wp-config /home/USER/public_html/wp-config.php

# Cada hora: breakdown por grupo en JSON (histórico de cómo se distribuye la memoria)
0 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py breakdown --wp-config /home/USER/public_html/wp-config.php --json >> /home/USER/logs/pigcache-breakdown.jsonl 2>&1

# Cada 6 horas: top 20 keys más grandes (para detectar bloat)
0 */6 * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py hot-keys --wp-config /home/USER/public_html/wp-config.php --top 20 >> /home/USER/logs/pigcache-hotkeys.log 2>&1
```

#### `report` — payload completo y push al API

El subcomando `report` arma un snapshot completo en JSON y opcionalmente lo
envía al backend, reutilizando exactamente el mismo flujo de autenticación
que `bin/pigcache-cron.php`:

* **wp-config.php**: si no le pasas `--wp-config`, el script camina hacia
  arriba desde `cli/` (hasta 6 niveles) buscando `wp-config.php`, igual que
  el cron PHP.
* **API URL**: `--api-url` → `PIGCACHE_CLOUD_API_URL` de wp-config → default
  `https://bluecache.pigworlds.com/api/v1`.
* **API key**: `--api-key` → `PIGCACHE_API_KEY` constante → opción
  `pigcache_license_key` en `wp_options`.
* **Site ID**: `--site-id` → opción `pigcache_cloud_site_id` en `wp_options`.
* **HTTP**: `urllib.request` (stdlib), `Content-Type: application/json`,
  `Authorization: Bearer <api_key>`, `X-Site-Id: <site_id>`, timeout 20s.

Por defecto el endpoint es `/sites/{site_id}/monitor-snapshot` (`{site_id}`
se reemplaza al vuelo). Cámbialo con `--endpoint /tu/ruta` o
`--endpoint /sites/{site_id}/health-report`.

##### Esquema del payload (schema_version: 1)

```json
{
  "schema_version": 1,
  "monitor_version": "1.0.0",
  "captured_at": "2026-05-22T23:33:07.080267Z",
  "site": {
    "site_url": "https://example.com",
    "site_id":  "site_demo_123",
    "table_prefix": "wp_",
    "wp_config_path": "/home/USER/public_html/wp-config.php"
  },
  "monitor": {
    "python_version": "3.6.8",
    "redis_driver": "redis-py | stdlib-bare",
    "hostname": "bh8972.hostingcpanel.example",
    "user": "bvkegbla"
  },
  "redis": {
    "endpoint": { "host": "127.0.0.1", "port": 6379, "db": 5, "driver": "..." },
    "redis_version": "7.4.9",
    "ping_p50_ms": 0.21, "ping_max_ms": 0.41,
    "uptime_seconds": 12345,
    "connected_clients": 14, "blocked_clients": 0, "maxclients": 10000,
    "instantaneous_ops_per_sec": 1830,
    "keyspace_hits": 4823155, "keyspace_misses": 412009,
    "hit_ratio_pct": 92.13,
    "evicted_keys": 0, "expired_keys": 9831,
    "used_memory_bytes": 67108864, "used_memory_human": "64M",
    "maxmemory_bytes": 2147483648, "maxmemory_policy": "allkeys-lru",
    "maxmemory_fill_pct": 3.12,
    "rejected_connections": 0,
    "mem_fragmentation_ratio": 1.07,
    "dbsize": 18420,
    "slowlog_top5": [ { "duration_us": 12500, "command": "..." } ]
  },
  "circuit_breaker": { "open": false, "age_seconds": null, "path": "/tmp/pigcache_cb_*.flag" },
  "mysql": {
    "threads_connected": 87, "threads_running": 4,
    "max_connections": 150, "max_used_connections": 142,
    "conn_saturation_pct": 58.0, "peak_saturation_pct": 94.67,
    "aborted_clients": 0, "aborted_connects": 12,
    "connection_errors_max_connections": 0,
    "slow_queries": 38, "qps_avg_since_boot": 1247.3,
    "processlist_total": 87,
    "processlist_by_command": { "Sleep": 65, "Query": 22 }
  },
  "breakdown": {
    "scanned_keys": 18420, "scan_capped_at": 20000, "pattern": "ab12:*",
    "groups": [
      { "group": "pigcache_html",      "key_count": 5230, "no_ttl_pct": 0.0,   "avg_ttl_s": 178, "avg_bytes_sampled": 28432, "est_total_bytes": 148... },
      { "group": "pigcache_sql",       "key_count": 9100, "no_ttl_pct": 0.0,   "avg_ttl_s": 119, "avg_bytes_sampled": 1420,  "est_total_bytes": ... },
      { "group": "options",            "key_count": 1240, "no_ttl_pct": 100.0, "avg_ttl_s": null, ... }
    ]
  },
  "stampede": { "active_lock_count": 3, "locks": [ {"key": "...", "ttl": 18} ] },
  "alerts": [
    { "severity": "warn", "code": "low_hit_ratio", "msg": "..." }
  ]
}
```

##### Flags

```
--push                       POST el payload al API (sin esto, es dry-run)
--api-url URL                Override de la base URL del API
--api-key KEY                Override de la API key
--site-id ID                 Override del site ID
--endpoint PATH              Override del path; soporta {site_id}
                             (default: /sites/{site_id}/monitor-snapshot)
--save PATH                  Escribe el payload JSON a un archivo local
--quiet                      No imprime el JSON en stdout
--no-breakdown               Omite el SCAN por grupos (más rápido)
--no-stampede                Omite el scan de pigcache_lock_*
--http-timeout 20            Timeout HTTP del POST
```

##### Ejemplos de cron

```cron
# Cada 5 min: snapshot completo enviado al API, sin output local
*/5 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py report --push --quiet >> /home/USER/logs/pigcache-report.err 2>&1

# Cada minuto: solo guardar localmente (sin push) — útil si no tienes API
* * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py report --save /home/USER/logs/pigcache-last.json --quiet 2>&1

# Cada 15 min: push Y archivar histórico
*/15 * * * * /usr/bin/python3 /home/USER/public_html/wp-content/plugins/pigcache/cli/pigcache-monitor.py report --push --save /home/USER/logs/pigcache-$(date +\%Y\%m\%d-\%H\%M).json --quiet
```

Nota: al correr el script desde `wp-content/plugins/pigcache/cli/`, omitir
`--wp-config` funciona porque el auto-discovery encuentra el archivo
automáticamente.

##### Endpoint del lado del backend (PigCache API)

Para que el push sirva, el backend debe exponer:

```
POST /api/v1/sites/{site_id}/monitor-snapshot
Headers: Authorization: Bearer <api_key>
         X-Site-Id: <site_id>
         Content-Type: application/json
Body:    <schema_version: 1 payload>
Returns: 2xx para aceptar, 4xx/5xx para que el monitor exit 4 (alerta cron)
```

Casos de uso típicos en el backend:

* **Auto-tuning de Adaptive TTL**: si `redis.hit_ratio_pct` cae bajo un
  umbral o `mysql.conn_saturation_pct` sube, el backend puede subir el TTL
  base que sirve al perfil compilado del sitio.
* **Alertas**: el array `alerts` ya viene pre-calculado con severidades
  `info`/`warn`/`critical` y un `code` estable, listo para encolarlo en
  PagerDuty/Slack/email.
* **Detección de bloat**: `breakdown.groups` con `no_ttl_pct=100%` y
  `est_total_bytes` alto identifica plugins que están metiendo basura sin TTL.
* **Forensics de outages**: `circuit_breaker.open=true` con `age_seconds`
  bajo significa que el dropin acaba de marcar Redis como caído.

#### Diagnóstico de "Error establishing a database connection"

Ese error es de **MySQL**, no de Redis, pero suele aparecer cuando Redis no
está absorbiendo carga y MySQL se queda sin slots de conexión. Para confirmar
qué está pasando en el momento exacto, corre en paralelo:

```bash
python3 cli/pigcache-monitor.py snapshot --wp-config /ruta/wp-config.php
python3 cli/pigcache-monitor.py watch    --wp-config /ruta/wp-config.php --window 60
python3 cli/pigcache-monitor.py stampede --wp-config /ruta/wp-config.php
```

Señales por orden de gravedad:

1. `circuit breaker: OPEN` → el dropin de PigCache ya marcó Redis como caído.
   Las requests siguientes se sirven sin cache → tormenta de queries a MySQL.
2. `rejected_connections > 0` o `connected_clients` cerca de `maxclients` →
   Redis tirando conexiones de PHP-FPM, esos workers también caen sin cache.
3. `mysql_conn_saturation_pct > 80%` o `connection_errors_max_connections > 0`
   → MySQL está al límite. Sube `max_connections` y/o reduce `wait_timeout`,
   pero la causa raíz casi siempre es cache MISS.
4. `active locks > N` en `stampede` → muchas páginas se regeneran a la vez
   (cache stampede). Suele coincidir con un purge global reciente.
5. `evicted_keys` creciendo + `memory fill ~100%` → Redis está reciclando
   keys útiles. Sube `maxmemory` o limpia keys sin TTL (ver `breakdown`).

#### Recomendaciones de configuración de Redis

Para alto tráfico estas opciones del `redis.conf` (o `CONFIG SET` en caliente)
son las que más impacto tienen:

```conf
# Tope estricto, NUNCA dejarlo en 0 con WordPress detrás. Ajusta al ~70% de la
# RAM dedicada al proceso redis-server.
maxmemory 2gb

# Estrategia de evicción. allkeys-lru es la sana por defecto en WordPress:
# desaloja las keys menos recientes sin importar si tienen TTL o no. Con
# `noeviction` Redis devuelve OOM en cada SET cuando se llena → cache miss
# permanente.
maxmemory-policy allkeys-lru

# Sube el cap de clientes si tienes muchos workers PHP-FPM (por ejemplo
# 4 pools x 200 children = 800). Default es 10000.
maxclients 10000

# Latencia: matar lazy clients para liberar slots cuando hay picos.
timeout 300
tcp-keepalive 60

# Activa slowlog para investigar comandos lentos (>10ms) que ralenticen
# requests:
slowlog-log-slower-than 10000
slowlog-max-len 256
```

En el `wp-config.php` del sitio:

```php
// Reintenta cada N segundos cuando Redis se cae (default 30). Bájalo si el
// servicio se recupera rápido para que el dropin reintente antes:
define( 'PIGCACHE_REDIS_RETRY_INTERVAL', 15 );

// Falla con elegancia (el sitio sigue funcionando sin cache si Redis muere):
define( 'PIGCACHE_REDIS_GRACEFUL', true );
```

### Cómo se complementan `pigcache-cron.php` y `pigcache-monitor.py`

Los dos scripts envían telemetría al mismo backend pero **observan capas
diferentes**. Combinados dan a la API la información completa para tomar
decisiones automatizadas (ajuste de TTLs, alertas, throttling, recomendaciones
de configuración).

#### División de responsabilidades

| Aspecto | `bin/pigcache-cron.php` | `cli/pigcache-monitor.py` |
|---|---|---|
| **Capa** | Aplicación (lo que el plugin observó) | Infra (estado real de Redis + MySQL) |
| **Necesita WP** | No (lee `wp-config.php` por regex) | No (lee `wp-config.php` por regex) |
| **Frecuencia típica** | 5–15 min | 1–5 min |
| **Origen de datos** | `wp_pigcache_query_stats`, `wp_pigcache_sql_fingerprints`, `wp_pigcache_table_stability`, `wp_pigcache_url_traffic`, `pigcache_html_tier_log`, `wp_options` | `INFO`, `DBSIZE`, `SCAN`, `MEMORY USAGE`, `SLOWLOG`, `SHOW GLOBAL STATUS`, `SHOW VARIABLES` |
| **Reporta al API** | `POST /query-stats`, `/sites/{id}/environment`, `/sites/{id}/fingerprints`, `/cron-error`, `/adaptive-ttl-report` | `POST /<endpoint custom>` con un payload `schema_version: 1` que contiene `redis`, `circuit_breaker`, `mysql`, `breakdown`, `stampede`, `alerts` |
| **Descarga del API** | Perfil SQL compilado → `wp-content/pigcache-sql-profile.php` | Nada (solo emite) |
| **Modifica MySQL** | Sí (escribe stats, marca rows como `synced_at`, almacena `pigcache_traffic_map`/`pigcache_stability_map` como transients) | No (todo es read-only) |
| **Modifica Redis** | Lee y borra el buffer `pigcache_qbuf:*` (consume y vacía) | No toca Redis (todo `INFO`/`SCAN`/`MEMORY USAGE`) |
| **Latencia tolerada** | Lento OK (cada 15 min) | Rápido (cron de 1 min no puede tardar más de pocos segundos) |
| **Falla típica** | "No envió fingerprints" / "Profile vacío" | "Redis caído", "MySQL saturado", "Hit ratio cayó" |

Regla mental: si tu pregunta es *"¿qué consultas SQL está haciendo este sitio?"*
mirá el cron; si es *"¿por qué el sitio va lento ahora mismo?"* mirá el monitor.

#### Esquema combinado del payload

El backend, al recibir el snapshot del monitor (`POST` con `Authorization:
Bearer …` + `X-Site-Id: …`), puede cruzarlo con los datos que el cron ya
acumuló para ese mismo `site_id`. Las claves de cross-reference son:

| Clave en cron | Clave en monitor | Ejemplo de join |
|---|---|---|
| `wp_pigcache_query_stats.normalized` | `redis.keyspace["pigcache_sql"]` count | "Cliente tiene 12,000 templates únicos pero solo 800 keys SQL en Redis → invalidaciones por epoch demasiado agresivas" |
| `wp_pigcache_table_stability` mutations | `mysql.threads_connected`, `slow_queries` | "Tabla X mutó 50k veces hoy + 11k slow queries → recomendar índice" |
| `pigcache_html_tier_log.hot_dynamic` | `redis.breakdown["pigcache_html"].avg_ttl` | "URI marcado hot_dynamic pero su TTL real es 60s → Adaptive TTL no se aplicó, profile desactualizado" |
| `wp_pigcache_url_traffic.hit_count` | `redis.breakdown["pigcache_html"].keys` | "Top-10 URLs por tráfico no están en cache → revisar gating" |
| Errores en `wp_pigcache_kv.cron_last_error` | `alerts[].code == "redis_circuit_open"` | "Cron lleva 3 ciclos fallando + breaker abierto → autenticación, no Redis" |

#### Matriz de decisión — qué hacer ante cada señal

El backend puede automatizar respuestas basándose en `alerts[].code` del monitor
combinado con los datos del cron. Esta tabla es la fuente de la verdad para
implementar el sistema de recomendaciones / notificaciones:

| Señal del monitor | Confirmar con (cron) | Diagnóstico | Acción automatizable |
|---|---|---|---|
| `redis_circuit_open` | `cron_last_error` reciente con `"context":"redis"` | Redis estuvo down ≥ `RETRY_INTERVAL` | Email "Redis caído"; abrir incidente si > 5 min |
| `redis_circuit_open` (sin cron error) | Cron OK pero monitor falla | Firewall / iptables entre WP-PHP y Redis | Notificar al hoster (cPanel) |
| `redis_latency_high` (>50 ms PING) | `breakdown.dbsize_total` > 500k | Redis CPU-bound o swap | Sugerir `maxmemory-policy=allkeys-lru` + reducir keyspace |
| `low_hit_ratio` (<80%) + `redis.keyspace_hits` creciendo | `pigcache_html_tier_log` mostrando muchos `cold` | Adaptive TTL marca casi todo como frío | Forzar `pigcache_html_ttl` mínimo más alto vía filtro remoto |
| `low_hit_ratio` + DBSIZE bajo | `query_stats` con `hit_count` alto en pocos templates | Las queries top se invalidan demasiado | Revisar mutaciones de la tabla → ¿necesita per-table epoch? |
| `no_maxmemory` | `breakdown.total_bytes` > 70% de la RAM del nodo | Riesgo de OOM kill del proceso Redis | Bloquear nuevas writes hasta que el hoster configure `maxmemory` |
| `memory_pressure` (>90%) + `policy_noeviction` | `breakdown` muestra grupo dominante (ej. `posts` 60%) | Grupo creció sin control | Lanzar `wp_cache_flush_group("posts")` remoto + alertar |
| `policy_noeviction` solo | n/a | Configuración subóptima | Recomendar `allkeys-lru` (no es urgente) |
| `rejected_connections` > 0 | `slowlog` con comandos largos | Pico de tráfico + comandos lentos | Sugerir subir `maxclients`; revisar `KEYS *` rogue |
| `stampede_locks_high` (>25) | `query_stats.max_exec_ms` alto en queries de portada | Página pesada regenerándose en paralelo | Subir `pigcache_lock_ttl_seconds` a 20; activar adaptive TTL más agresivo |
| `mysql_conn_saturation` (>80%) | `pigcache_url_traffic` mostrando spike de URLs sin cache | Demasiados misses cayendo a MySQL | Webhook al sitio para activar modo "high-load" (TTL más largo, gating relajado) |
| `mysql_max_conn_errors` >0 | Cualquier error | MySQL rechazó conexiones nuevas | Crítico → SMS / PagerDuty; sugerir bajar `wait_timeout` o subir `max_connections` |

#### Casos de uso del backend

##### 1. Auto-tuning de TTL del HTML cache

```
Datos:
  - cron: pigcache_html_tier_log con distribución hot_stable/hot_dynamic/cold
  - cron: wp_pigcache_url_traffic top 1000 URLs por hits
  - monitor: redis.breakdown["pigcache_html"]{keys, total_bytes, avg_ttl}
  - monitor: redis.maxmemory_fill_pct

Regla:
  SI maxmemory_fill_pct > 80 Y avg_ttl > 1800
    → API responde con "ttl_recommendation": 600
    → el plugin lo lee en el siguiente cron y aplica via filtro pigcache_html_ttl
  SI hot_dynamic > 60% AND avg_ttl < 300
    → API responde con "ttl_recommendation": 900 (las páginas dinámicas son las dominantes)
```

##### 2. Detección de "tabla problemática"

```
Datos:
  - cron: wp_pigcache_table_stability (mutaciones/h por tabla)
  - cron: query_stats con tables_json y max_exec_ms
  - monitor: mysql.slow_queries, mysql.threads_running

Regla:
  SI tabla T tiene >10k mutaciones/h AND query_stats[T].max_exec_ms > 1000
    → notificar al cliente: "wp_postmeta crece sin freno + queries lentas;
       considera limpiar postmeta huérfano"
  SI mysql.slow_queries crece >100/min AND no hay tabla con stability >5k
    → no es PigCache, problema externo (¿plugin nuevo? ¿bot scraping?)
```

##### 3. Alerta de "circuit breaker aleteando"

```
Datos:
  - monitor: alerts contiene redis_circuit_open
  - monitor: circuit_breaker.age_seconds < 60 en 3 reportes seguidos
  - cron: wp_pigcache_kv.cron_last_error

Regla:
  SI 3 reportes consecutivos abren breaker dentro de los 5 min
    → Redis está flapping (DOWN brevemente y volviendo)
    → posible memoria insuficiente del nodo Redis, OOM kill
    → SMS al admin: revisar `dmesg | grep -i redis`
```

##### 4. Recomendación automática de `--sample-cap` óptimo

```
Datos:
  - monitor: breakdown.dbsize_total, coverage_pct, sample_cap_resolved

Regla:
  SI coverage_pct < 5
    → API responde con "monitor_recommendation": {
         "sample_cap": <dbsize_total // 10>,
         "frequency": "cada 15 min en vez de 5"
       }
    → admin del sitio ve el aviso en su dashboard
```

##### 5. Healthcheck combinado para el dashboard del backend

```
Datos:
  - monitor: alerts[]
  - cron: cron_flush_last, cron_send_last (heartbeats en wp_pigcache_kv)

Estado del sitio = peor de:
  - cron_flush_last < (NOW - 30 min) → "cron stalled" CRITICAL
  - cron_send_last  < (NOW - 60 min) → "no telemetry"  WARN
  - alerts[severity=critical] presente → CRITICAL
  - alerts[severity=warn] presente    → WARN
  - sin alerts                        → OK
```

##### 6. Webhook para flush selectivo desde el API

Cuando el backend detecta (vía monitor) que un grupo concreto está
saturando memoria, puede pedir al sitio que purgue ESE grupo:

```php
// El plugin escucha un endpoint webhook (Pro). Pseudocódigo:
add_action( 'rest_api_init', function () {
    register_rest_route( 'pigcache/v1', '/admin-action', [
        'methods'  => 'POST',
        'permission_callback' => 'pigcache_verify_api_signature',
        'callback' => function ( $req ) {
            $action = $req->get_param( 'action' );
            $group  = sanitize_key( $req->get_param( 'group' ) );

            if ( 'flush_group' === $action && $group ) {
                wp_cache_flush_group( $group );  // surgical
                return [ 'ok' => true, 'flushed' => $group ];
            }
            // ... más acciones: bump_epoch, set_ttl_override, etc.
        },
    ] );
} );
```

#### Para implementar todo esto en el backend

1. Definir un endpoint `POST /sites/{id}/monitor-report` que acepte el
   schema del monitor (ver sección anterior).
2. Persistir cada snapshot en una tabla `monitor_reports` con `captured_at`
   indexado — sirve para detectar tendencias (¿el hit ratio cae los lunes?).
3. Cruzar con `query_stats` y `table_stability` ya almacenados por el cron
   en `pigcache-cron.php` para validar/correlacionar alertas (ej. confirmar
   `mysql_conn_saturation` con un spike de inserts en `wp_options`).
4. Responder a `POST /query-stats` y `POST /monitor-report` con un payload
   opcional `recommendations: [...]` que el plugin lea y aplique vía filtros
   (`pigcache_html_ttl`, `pigcache_sql_cache_ttl`, etc).
5. Las acciones más invasivas (flush, bump epoch) requieren un webhook
   firmado, no son respuesta del POST.

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
3. Si aun así hay contaminación, define `PIGCACHE_REDIS_PREFIX` explícitamente
   en cada sitio con un valor único.
4. Después de cualquier cambio de prefix, haz flush de caché en **todos**
   los sitios (o `redis-cli FLUSHALL` una sola vez).

### Redis no conecta

1. Verifica que Redis esté corriendo: `redis-cli ping`
2. Revisa las constantes `PIGCACHE_REDIS_HOST`, `PIGCACHE_REDIS_PORT`.
3. Si usas password: `PIGCACHE_REDIS_PASSWORD`.
4. Revisa el log de PHP para errores de conexión.

### Flush de Redis desde línea de comandos

Después de actualizar PigCache o cambiar prefixes, necesitas limpiar las
claves viejas de Redis. Desde SSH:

```bash
# Borrar TODAS las claves de TODAS las DB lógicas (nuclear, úsalo solo
# si Redis es exclusivo para tus sitios WordPress):
redis-cli FLUSHALL

# Si Redis tiene password:
redis-cli -a tu_password FLUSHALL
```

Si necesitas borrar solo una DB lógica específica (sin afectar las demás):

```bash
# Borrar solo DB 0 (la default):
redis-cli -n 0 FLUSHDB

# Borrar solo DB 7:
redis-cli -n 7 FLUSHDB

# Con password + DB específica:
redis-cli -a tu_password -n 7 FLUSHDB
```

Si solo quieres borrar las claves de **un sitio** (sin tocar los demás)
y conoces su prefix:

```bash
# Ver qué prefixes hay:
redis-cli KEYS "*" | head -20

# Borrar solo las keys de un prefix (ejemplo: a3f8b21c:*):
redis-cli --scan --pattern "a3f8b21c:*" | xargs redis-cli DEL

# Con password:
redis-cli -a tu_password --scan --pattern "a3f8b21c:*" | xargs redis-cli -a tu_password DEL
```

> **Tip:** Después del flush, visita cada sitio una vez para que se
> regenere la caché. La primera visita será más lenta de lo normal.

### Demasiada memoria en Redis

1. Revisa TTLs (Ajustes -> PigCache > Cache TTL).
2. Ejecuta el cleanup de tags stale:
   ```php
   PigCache_Tag_Index::cleanup_stale();
   ```
3. Considera `redis-cli INFO memory` para ver el uso.
