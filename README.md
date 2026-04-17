# PigCache

Plugin de WordPress que sustituye la necesidad del plugin **Redis Object Cache**: incluye un *drop-in* propio para `wp_cache_*` respaldado por **Redis**, más caché de **consultas SQL** (con profiler de per-table epochs), **HTML de página completa** (con invalidación selectiva por tags), **fragmentos** y un **dashboard de cache** en el administrador.

- **WordPress:** 5.8 o superior  
- **PHP:** 7.4 o superior  
- **Redis:** servidor accesible (por defecto `127.0.0.1:6379`, base de datos lógica `0`)  
- **Licencia:** GPL-3.0-or-later  

El *drop-in* de object cache está basado en [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) (GPLv3, Till Krüss / Rhubarb Group). En el paquete va **Predis** en `vendor/`; no hace falta ejecutar Composer en el servidor para usar el plugin.

> **Manual completo**: ver [MANUAL.md](MANUAL.md) para guía detallada de uso,
> administración, tags, profiler, filtros y troubleshooting.
>
> **Flujo de cache**: ver [CACHE-FLOW.md](CACHE-FLOW.md) para el mapa técnico
> del ciclo de vida del cache, tags, y extensibilidad.

---

## Instalación básica

1. **Copia la carpeta `pigcache`** dentro de `wp-content/plugins/` de tu instalación WordPress (la ruta final debe ser `wp-content/plugins/pigcache/pigcache.php`).

2. En **Plugins**, activa **PigCache**.

3. Ve a **Ajustes → PigCache** y pulsa **Enable object cache (copy drop-in)**.  
   Esto copia el archivo a `wp-content/object-cache.php`. A partir de ahí WordPress usará Redis para el object cache (PhpRedis si la extensión está cargada; si no, Predis).

4. **Comprueba Redis:** en el mismo servidor debe estar corriendo Redis y aceptar conexiones en el host/puerto que uses (sin `wp-config`, PigCache usa `127.0.0.1:6379`).

5. **Opcional — caché SQL:** en **Ajustes → PigCache**, instala **Install db.php drop-in** para activar `wp-content/db.php` y cachear `SELECT` en Redis (con reglas e invalidación descritas abajo).

6. **No actives** el plugin **Redis Object Cache** a la vez: PigCache ya cumple ese papel y advertirá si detecta el otro plugin.

### Hosting compartido — varios sitios, un solo Redis

Si varias instalaciones de WordPress comparten la misma instancia de Redis
(típico en cPanel, Plesk o cualquier hosting compartido), PigCache **aísla
cada sitio automáticamente** generando un prefijo único a partir de `DB_NAME`
y `$table_prefix`. No necesitas configurar nada extra para que funcione.

Si prefieres control manual o necesitas un esquema específico, define
constantes en el `wp-config.php` de **cada sitio**:

```php
// wp-config.php — Sitio A (ejemplo: tienda)
define( 'WP_REDIS_PREFIX', 'tienda:' );

// wp-config.php — Sitio B (ejemplo: blog)
define( 'WP_REDIS_PREFIX', 'blog:' );
```

Opcionalmente puedes usar bases de datos lógicas distintas de Redis:

```php
// wp-config.php — Sitio A (DB 0 por defecto, no necesitas definirla)

// wp-config.php — Sitio B
define( 'WP_REDIS_DATABASE', 7 );
```

> **Nota:** Muchos hostings compartidos usan proxies Redis que ignoran
> `SELECT` (el comando para cambiar de DB). El auto-prefix de PigCache
> garantiza aislamiento incluso en ese caso. Si defines `WP_REDIS_PREFIX`
> manualmente, asegúrate de que sea único por sitio.

Cuando hay un prefijo activo (automático o manual), PigCache activa
`WP_REDIS_SELECTIVE_FLUSH` automáticamente para que al vaciar la caché
de un sitio **no se borren las claves de los demás**.

### Desactivar el plugin

Al desactivar PigCache, si el *drop-in* de object cache es el de PigCache, el plugin puede quitar `object-cache.php`. **No** elimina automáticamente `db.php`; revísalo manualmente si lo instalaste.

---

## Funcionalidades

### Object cache (Redis)

- Reemplaza el almacenamiento por defecto de `wp_cache_*` por **Redis**.
- Alineado en comportamiento con **Redis Object Cache** (hits/misses, grupos, *flush*, etc.).
- **Ajustes → PigCache metrics** (submenú bajo Ajustes): resumen tipo `info()`, `INFO` del servidor, listado paginado de claves (100 por página), TTL y tipo por clave.

### Caché de consultas SQL (`db.php`)

- Intercepta `SELECT` elegibles y guarda el resultado en Redis (grupo `pigcache_sql`).
- **Dos modos de invalidación**:
  - **Global epoch** (default): cualquier mutación invalida todas las queries.
  - **Per-table epochs** (con profiler): solo queries de la tabla mutada se invalidan.
- **SQL Profiler** *(Premium)*: aprende las queries de tu sitio, compila un profile estático, y activa per-table epochs automáticamente. Trial de 14 días al activar el plugin; luego requiere licencia Pro.
- **No** se usa en admin, AJAX, REST, cron, WP-CLI, etc. (solo contextos front típicos).
- El **TTL** se configura en **Ajustes → PigCache** y filtro `pigcache_sql_cache_ttl`.

### Caché HTML de página completa

- Para visitantes **no logueados**, peticiones **GET** sin POST, fuera de admin/preview.
- Guarda el HTML completo en Redis; la siguiente petición se sirve sin ejecutar WordPress.
- **Invalidación selectiva por tags**: al guardar un post, solo se purgan las páginas que muestran ese post. Las demás 10,000 páginas siguen cacheadas.
- **`pigcache_tag()`**: API pública para que temas/plugins tagueen la página actual.
- **TTL** configurable en **Ajustes → PigCache** y filtro `pigcache_html_ttl`.

### Fragmentos

- **`pigcache_fragment( $key, $callback, $ttl, $group, $tags )`** para cachear trozos de salida con tags opcionales para invalidación selectiva.

### Ajustes en el administrador

- **Cache Dashboard**: vista en tiempo real de qué se está cacheando (HTML pages, SQL mode, tag index stats).
- Activar / actualizar / desactivar *drop-in* de object cache y vaciar caché.
- Instalar o quitar `db.php`.
- **SQL Profiler**: Start Learning / Compile / Re-learn / Delete Profile.
- **TTL** para caché SQL y caché HTML.
- Enlace a **PigCache metrics** con hits, Redis INFO, listado de keys.

### PigCache Pro — SQL Profiler + Cloud Profiles

- **Freemium**: versión gratuita incluye object cache, HTML cache (tags), SQL cache (global epoch), y fragmentos. El SQL Profiler (per-table epochs) requiere Pro o trial activo.
- **Trial**: 14 días automáticos al activar el plugin por primera vez.
  Permite evaluar el profiler localmente sin licencia.
- **Cloud sync**: el plugin sube templates normalizados al backend y descarga
  profiles compilados de forma agregada de otros sitios con el mismo stack.
- **Instant intelligence**: un nuevo sitio WooCommerce recibe un profile
  listo sin esperar 7 días de learning, gracias a los datos de otros sitios.
- **Environment-aware**: profiles específicos para cada combinación de
  plugins + tema + versión de WP.
- **Referencia del backend**: la carpeta `backend/` contiene la especificación
  completa de la API, schema MySQL, y lógica de servicios extraída para
  construir el backend en Laravel.

### CLI Analyzer (opcional)

- `cli/pigcache-analyze.py`: script Python 3.6+ que analiza los datos de learning del profiler y genera un report JSON con frecuencias, dependencias entre tablas, eficiencia estimada y recomendaciones.

---

## Filtros útiles (desarrolladores)

| Filtro | Uso |
|--------|-----|
| `pigcache_sql_cache_ttl` | Ajustar TTL de SQL tras leer la opción del admin |
| `pigcache_html_ttl` | Ajustar TTL de HTML por URI |
| `pigcache_sql_cache_enabled` | Desactivar caché SQL |
| `pigcache_sql_cache_skip_context` | Forzar omisión del caché SQL en un contexto |
| `pigcache_sql_cache_is_cacheable` | Marcar un `SELECT` como no cacheable |
| `pigcache_skip_html_cache` | Saltar caché HTML |
| `pigcache_redis_client` | Forzar cliente Redis (junto con constantes del drop-in) |

---

## Constantes de invalidación (wp-config.php)

Además del comportamiento por defecto (invalidar al guardar/borrar posts), puedes activar
hooks adicionales definiendo constantes en `wp-config.php`:

| Constante | Efecto |
|-----------|--------|
| `PIGCACHE_INVALIDATE_THROTTLE` | Segundos mínimos entre epoch bumps (default **2**). Previene stampede en sitios de alto tráfico. |
| `PIGCACHE_INVALIDATE_ON_OPTION` | Invalida al añadir/actualizar/borrar opciones (`update_option`, etc.). |
| `PIGCACHE_INVALIDATE_ON_TERM` | Invalida al crear/editar/borrar términos y taxonomías. |
| `PIGCACHE_INVALIDATE_ON_COMMENT` | Invalida al insertar/editar/borrar/cambiar estado de comentarios. |
| `PIGCACHE_INVALIDATE_ON_NAV_MENU` | Invalida al actualizar/eliminar menús de navegación. |
| `PIGCACHE_INVALIDATE_ON_WIDGET` | Invalida al cambiar sidebars o widgets. |
| `PIGCACHE_INVALIDATE_ON_THEME` | Invalida al cambiar de tema o guardar el Customizer. |
| `PIGCACHE_INVALIDATE_ON_USER` | Invalida al registrar/editar/borrar usuarios. |
| `PIGCACHE_HTML_GRACE` | Segundos de *grace period* para servir HTML stale mientras se regenera (default **10**). |

Ejemplo:

```php
// wp-config.php
define( 'PIGCACHE_INVALIDATE_THROTTLE', 3 );
define( 'PIGCACHE_INVALIDATE_ON_COMMENT', true );
define( 'PIGCACHE_HTML_GRACE', 15 );
```

---

## Soporte y desarrollo

Para ampliar TTL, reglas de invalidación o nuevas métricas, el código principal está en `includes/` y el *drop-in* en `includes/dropin/object-cache.php`.
