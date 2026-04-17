# Layer 4 — Fragment Cache (partial output buffer)

## What it is and how it works

Fragment cache stores **sections of a page** — individual widgets, menus, product attribute tables, sidebar blocks — rather than the whole page. It is most useful when:

- The page cannot be fully HTML-cached (logged-in users, cart, personalized content)
- The page can be HTML-cached but contains sections that change at different rates
- A shortcode or widget is expensive to render and appears across many pages

**File:** `includes/class-pigcache-fragments.php`  
**Class:** `PigCache_Fragments`  
**Global function:** `pigcache_fragment()` (defined in `pigcache.php`)

---

## API

```php
pigcache_fragment( string $key, callable $generator, int $ttl = 3600, array $tags = [] ): void
```

| Parameter | Description |
|---|---|
| `$key` | Unique identifier for this fragment |
| `$generator` | Callable that `echo`s or `print`s the HTML content |
| `$ttl` | Cache lifetime in seconds (default 3600). Stored as Redis TTL. |
| `$tags` | **Pro only** — tag strings used for selective invalidation |

The function echoes the cached or freshly generated HTML inline. It does not return a value.

### Internal flow

```php
// pigcache.php — global function definition
function pigcache_fragment( string $key, callable $generator, int $ttl = 3600, array $tags = [] ): void {
    PigCache_Fragments::render( $key, $generator, $ttl, $tags );
}

// includes/class-pigcache-fragments.php
class PigCache_Fragments {

    const GROUP = 'pc_frag';

    public static function render( string $key, callable $generator, int $ttl, array $tags ): void {

        $cached = wp_cache_get( $key, self::GROUP );
        // PigCache_Object_Cache::get($key, 'pc_frag')
        // Redis GET wp:1:pc_frag:{key}

        if ( false !== $cached ) {
            echo $cached;   // Serve from Redis — generator never runs
            return;
        }

        // Generator runs — capture its output
        ob_start();
        call_user_func( $generator );
        $html = ob_get_clean();

        wp_cache_set( $key, $html, self::GROUP, $ttl );
        // PigCache_Object_Cache::set($key, $html, 'pc_frag', $ttl)
        // Redis SET wp:1:pc_frag:{key} $html EX $ttl

        // Pro only: register this cache entry under each tag
        if ( ! empty( $tags ) && PigCache_License::can_use_tag_invalidation() ) {
            PigCache_Tag_Index::link_all( $tags, self::GROUP . ':' . $key );
            // Redis SADD pc_tagidx:{tag} 'pc_frag:{key}'  (for each tag)
        }

        echo $html;   // Send freshly generated HTML to browser
    }

    public static function flush_all(): void {
        wp_cache_flush_group( self::GROUP );
        // Increments group version counter — all pc_frag keys become unreachable
    }
}
```

Note that fragments are stored in the object cache (Redis) using the `pc_frag` group — the same Redis instance that holds object cache data, HTML cache, and SQL cache. There is no separate storage layer.

---

## Example A — Popular Posts sidebar widget

A sidebar widget shows the 5 most-viewed posts of the week. The underlying `WP_Query` sorts by a `view_count` meta value — a slow `ORDER BY meta_value_num` scan that takes 200–400 ms on large sites.

```php
// In sidebar.php, widget render, or functions.php sidebar registration:
pigcache_fragment( 'popular_posts_weekly', function () {

    $posts = new WP_Query( [
        'posts_per_page' => 5,
        'meta_key'       => 'view_count',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'date_query'     => [ [ 'after' => '1 week ago' ] ],
    ] );

    echo '<ul class="popular-posts">';
    while ( $posts->have_posts() ) {
        $posts->the_post();
        printf(
            '<li><a href="%s">%s</a> <span>(%s views)</span></li>',
            esc_url( get_permalink() ),
            esc_html( get_the_title() ),
            esc_html( get_post_meta( get_the_ID(), 'view_count', true ) )
        );
    }
    echo '</ul>';
    wp_reset_postdata();

}, 3600 ); // Cache for 1 hour
```

**What happens inside `pigcache_fragment()`:**

1. `wp_cache_get('popular_posts_weekly', 'pc_frag')` → Redis GET
2. **HIT:** echo the stored HTML string, return. WP_Query never runs.
3. **MISS:** `ob_start()` → run the WP_Query + template output → `ob_get_clean()` → store in Redis with TTL 3600 → echo HTML.

**Impact:** The WP_Query (200–400 ms) runs once per hour. Every other request (across all pages where this sidebar appears) gets the cached HTML in < 1 ms.

---

## Example B — Navigation menu (Pro with tag invalidation)

`wp_nav_menu()` resolves the menu object, fetches all menu items via a `WP_Query` for `nav_menu_item` posts, builds a tree structure, and renders it through the walker class. On a menu with 40 items this can be 8–15 queries and 30–60 ms.

```php
pigcache_fragment(
    'main_nav_v1',
    function () {
        wp_nav_menu( [
            'theme_location' => 'primary',
            'container'      => 'nav',
            'container_class' => 'site-navigation',
        ] );
    },
    86400,                            // 24-hour TTL — menus rarely change
    [ 'nav_menu', 'terms', 'posts' ]  // Pro: tag for selective invalidation
);
```

**Free:** Fragment expires after 24 h, or `PigCache_Fragments::flush_all()` drops it on any save event.

**Pro — how tag invalidation fires:**

```
Admin saves a menu:
  WordPress core fires: do_action('wp_update_nav_menu', $menu_id)

PigCache_Invalidation::on_nav_menu_updated( $menu_id )
  └── self::can_use_tag_invalidation() → true
  └── self::purge_by_tags( ['nav_menu'] )
        └── PigCache_Tag_Index::purge_by_tags(['nav_menu'])
              └── wp_cache_get('nav_menu', 'pc_tagidx')
                    → ['pc_frag:main_nav_v1', 'pc_html:homepage_hash...']
              └── wp_cache_delete('main_nav_v1', 'pc_frag')
                    Redis DEL wp:1:pc_frag:main_nav_v1
              └── wp_cache_delete('homepage_hash...', 'pc_html')
                    Redis DEL wp:1:pc_html:homepage_hash...
```

Only the nav menu fragment (and any HTML-cached pages that embedded it) are dropped. All other fragments remain.

---

## Example C — Logged-in user header (per-user variant)

A membership site shows a personalized top bar: username, avatar, unread notification count. The page is not HTML-cacheable (logged-in), but these values only change when the user's profile is updated.

```php
$user_id = get_current_user_id();

pigcache_fragment(
    'user_topbar_' . $user_id,        // Key includes user ID → per-user cache entry
    function () use ( $user_id ) {
        $user         = get_userdata( $user_id );
        $notification = count( get_user_meta( $user_id, 'unread_notification' ) );

        echo '<div class="topbar">';
        echo '<span class="name">' . esc_html( $user->display_name ) . '</span>';
        echo get_avatar( $user_id, 32 );
        printf( '<span class="badge">%d</span>', $notification );
        echo '</div>';
    },
    1800,                              // 30-minute TTL
    [ 'user:' . $user_id ]            // Pro: purge when this specific user changes
);
```

**Redis key:** `wp:1:pc_frag:user_topbar_42`

With 1 000 active members:
- **Without fragment cache:** every page load for every user runs `get_userdata()` + meta query + avatar lookup = 3–5 queries per request
- **With fragment cache:** each user's topbar is rendered once per 30 minutes. 1 000 users × 50 page views each = 50 000 requests → only ~1 000 generator runs per 30 minutes instead of 50 000

**Pro invalidation:**  
When user #42 updates their display name or avatar:
```
PigCache_Invalidation::on_profile_updated(42)
  └── purge_by_tags(['user:42'])
        └── Redis DEL wp:1:pc_frag:user_topbar_42
```
Only user 42's cache entry is dropped. All other users' topbars remain cached.

---

## Example D — WooCommerce product attributes table

WooCommerce's `woocommerce_product_attributes()` template function calls `wc_get_product_terms()` for each attribute group. A product with 10 attribute groups (color, size, material, fit, etc.) fires 10–30 extra queries per render.

```php
// In single-product/tabs/attributes.php (child theme file):
pigcache_fragment(
    'product_attrs_' . get_the_ID(),
    function () {
        // Outputs the full attributes HTML table for the current product
        woocommerce_product_attributes();
        // Internally calls: wc_get_product_terms() → get_terms() → wp_cache_get → MySQL
    },
    3600,
    [ 'product:' . get_the_ID(), 'terms' ]
);
```

**Redis key:** `wp:1:pc_frag:product_attrs_88`

**Pro tag index entries created:**
```
pc_tagidx:product:88 → ['pc_frag:product_attrs_88', 'pc_html:b7e2a1f...']
pc_tagidx:terms      → ['pc_frag:product_attrs_88', 'pc_frag:main_nav_v1', ...]
```

When product #88 is updated in WooCommerce admin:
```
PigCache_Invalidation::on_save_post(88, $post)
  └── purge_by_tags(['post:88', 'product:88'])
        └── Redis DEL wp:1:pc_frag:product_attrs_88
        └── Redis DEL wp:1:pc_html:b7e2a1f...
```

On a 5 000 product catalog with 200 concurrent visitors during a sale, this eliminates ~30 million unnecessary term queries per hour.

---

## Example E — Expensive shortcode (return-value pattern)

WordPress shortcodes must **return** a string, not echo. Since `pigcache_fragment()` echoes directly, wrap it with `ob_start()`:

```php
add_shortcode( 'monthly_sales_chart', function ( $atts ) {

    $period = sanitize_text_field( $atts['period'] ?? 'last-30-days' );

    ob_start();
    pigcache_fragment(
        'sales_chart_' . $period,
        function () use ( $period ) {
            global $wpdb;
            // Slow aggregation: SUM, GROUP BY date across large orders table
            $data = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(post_date) as day, SUM(meta_value) as revenue
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed', 'wc-processing')
                   AND pm.meta_key = '_order_total'
                   AND p.post_date >= %s
                 GROUP BY DATE(post_date)
                 ORDER BY day ASC",
                date( 'Y-m-d', strtotime( '-30 days' ) )
            ) );
            echo render_chart_html( $data );  // custom chart rendering function
        },
        900  // 15-minute TTL — chart data refreshes every 15 minutes
    );
    return ob_get_clean();  // Returns the echoed HTML as a string to the shortcode system

} );
```

The outer `ob_start()` / `ob_get_clean()` intercepts what `pigcache_fragment()` echoes and converts it to a return value. This pattern is safe to nest — `ob_start()` is a stack in PHP.

---

## Fragment cache vs HTML cache: decision guide

| Situation | Use fragment cache | Use HTML cache |
|---|---|---|
| User is logged in | Yes — HTML cache won't fire | No |
| Cart/session present | Yes | No |
| Whole page is expensive | Not ideal (cache the whole thing instead) | Yes |
| Only one section is expensive | Yes — cache that section | Optionally also cache the full page |
| Need per-user variants | Yes — append user ID to key | No |
| Need per-currency or per-lang | Yes — append variant to key | Yes — with `PIGCACHE_HTML_VARY_COOKIES` |
| Widget appears on many pages | Yes — all pages share the same fragment hit | Yes — each URL is a separate HTML cache entry |

The two caches are **complementary**. An HTML-cached page was generated using the fragment cache. On a full HTML cache HIT the fragment cache is never consulted (WordPress never boots). On a full HTML cache MISS, the fragment cache reduces the render time of the page being freshly generated and stored.

---

## Invalidation reference

`PigCache_Invalidation` hooks that affect fragments:

| WordPress action | `PigCache_Invalidation` method | Free | Pro |
|---|---|---|---|
| `save_post` | `on_save_post( $post_id )` | `flush_all()` | `purge_by_tags(['post:{id}'])` |
| `deleted_post` | `on_delete_post( $post_id )` | `flush_all()` | `purge_by_tags(['post:{id}'])` |
| `edited_term` | `on_term_edited( $term_id )` | `flush_all()` | `purge_by_tags(['term:{id}'])` |
| `wp_update_nav_menu` | `on_nav_menu_updated()` | `flush_all()` | `purge_by_tags(['nav_menu'])` |
| TTL expiry | _(Redis handles automatically)_ | Individual key expires | Individual key expires |
| Manual admin flush | Settings page → Flush Cache | `flush_all()` | `flush_all()` |
