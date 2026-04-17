# Layer 4 — Fragment Cache (partial output buffer)

Fragment cache stores **sections of a page** rather than the whole thing. It is useful when a page cannot be fully HTML-cached (e.g., the user is logged in, or the page has a dynamic header) but contains expensive sections that are the same for everyone.

---

## API

```php
pigcache_fragment( string $key, callable $generator, int $ttl = 3600, array $tags = [] ): void
```

| Parameter | Description |
|---|---|
| `$key` | Unique identifier for this fragment |
| `$generator` | Callable that `echo`s / `print`s the HTML |
| `$ttl` | Seconds to cache (default 3600) |
| `$tags` | Pro only — tag strings for selective invalidation |

The function echoes the cached or freshly generated HTML directly. It does not return a value.

---

## Example A — Sidebar widget (same for all visitors)

A sidebar shows "Popular Posts" computed from a slow meta query. The widget is the same for every visitor but the query takes 300 ms uncached.

```php
// In sidebar.php or widget render method:
pigcache_fragment( 'popular_posts', function () {
    $posts = new WP_Query( [
        'posts_per_page' => 5,
        'meta_key'       => 'view_count',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    ] );
    while ( $posts->have_posts() ) {
        $posts->the_post();
        echo '<li><a href="' . get_permalink() . '">' . get_the_title() . '</a></li>';
    }
    wp_reset_postdata();
}, 3600 ); // Cache for 1 hour
```

Without fragment cache: every page load (even HTML-cached misses from logged-in users) runs the WP_Query.
With fragment cache: the WP_Query runs once per hour; everyone else gets the Redis string.

---

## Example B — Navigation menu (Pro with tags)

Navigation menus are expensive to render (JOIN across 3 term tables, then PHP tree-building). They rarely change.

```php
pigcache_fragment(
    'primary_nav_v1',
    function () {
        wp_nav_menu( [ 'theme_location' => 'primary' ] );
    },
    86400,                      // 24-hour TTL
    [ 'nav_menu', 'terms' ]     // Pro: invalidate when any menu or term changes
);
```

**Free:** TTL expires after 24 h or a global flush drops it.
**Pro:** When the user saves a menu in wp-admin, PigCache detects the `wp_update_nav_menu` action, fires `purge_by_tags(['nav_menu'])`, and drops only this fragment. All other fragments remain cached.

---

## Example C — Logged-in user header (per-user fragment)

The top bar shows the current user's name and avatar. The rest of the page is the same for everyone. Fragment cache a per-user variant:

```php
$user_id = get_current_user_id();

pigcache_fragment(
    'user_header_' . $user_id,  // Key is per user
    function () use ( $user_id ) {
        $user = get_userdata( $user_id );
        echo '<span>' . esc_html( $user->display_name ) . '</span>';
        echo get_avatar( $user_id, 32 );
    },
    1800,                       // 30-minute TTL
    [ 'user:' . $user_id ]      // Pro: purge when this user's profile changes
);
```

The HTML cache still cannot cache the full page (logged-in user). But the avatar/name lookup only hits the database once per 30 minutes per user instead of on every request.

---

## Example D — WooCommerce product attributes table

Product attribute rendering in WooCommerce calls `wc_get_product_terms()` which fires multiple queries per attribute group. For a product with 10 attribute groups this is 20–30 extra queries.

```php
// In single-product/tabs/attributes.php (child theme override):
pigcache_fragment(
    'product_attrs_' . get_the_ID(),
    function () {
        woocommerce_product_attributes(); // renders the attributes table
    },
    3600,
    [ 'product:' . get_the_ID(), 'terms' ]
);
```

Result: attributes table cached per product. Invalidated (Pro) when the product is saved or any term changes. On a 5 000 product catalog this eliminates ~150 000 term queries per hour during busy traffic.

---

## Example E — Expensive shortcode output

A plugin shortcode renders a complex chart from aggregated order data:

```php
add_shortcode( 'sales_chart', function ( $atts ) {
    ob_start();
    pigcache_fragment(
        'sales_chart_' . $atts['period'],
        function () use ( $atts ) {
            // Expensive aggregation query + chart rendering
            echo render_sales_chart( $atts['period'] );
        },
        3600
    );
    return ob_get_clean();
} );
```

The shortcode wraps `pigcache_fragment` in its own `ob_start` because shortcodes must return a string. The fragment cache itself uses `echo` internally; the outer `ob_start/ob_get_clean` captures that output.

---

## Fragment cache vs HTML cache: when to use each

| Use fragment cache when… | Use HTML cache when… |
|---|---|
| User is logged in | Page is fully public |
| Page has a cart/session component | Page has no personalization |
| Only part of the page is expensive | Entire render is expensive |
| You need per-user or per-param variants | URL uniquely identifies the full content |
| Shortcode output should be cached | Full page output should be cached |

The two caches complement each other: an HTML-cached page can contain fragments that were themselves generated from the fragment cache on the original render pass.

---

## Invalidation summary

| Trigger | Free | Pro |
|---|---|---|
| TTL expiry | Drop individual fragment | Drop individual fragment |
| Post updated | `PigCache_Fragments::flush_all()` | `purge_by_tags(['post:42'])` |
| Manual admin flush | Flush all fragments | Flush all fragments |
| `wp_nav_menu` saved | Flush all | Purge `['nav_menu']` tag |
