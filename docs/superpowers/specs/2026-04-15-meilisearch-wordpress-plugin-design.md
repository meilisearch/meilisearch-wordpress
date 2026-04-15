# Meilisearch WordPress / WooCommerce Plugin — Design Spec

**Date:** 2026-04-15
**Status:** Approved
**Linear:** [SID-13](https://linear.app/meilisearch/issue/SID-13/plugin-wordpress-woocommerce)
**Sibling reference:** `../../meilisearch-drupal/docs/superpowers/specs/2026-04-14-meilisearch-drupal-plugin-design.md`

## Overview

Official Meilisearch plugin for WordPress with first-class WooCommerce support. Single monolithic plugin published to wordpress.org — WooCommerce features auto-activate when `class_exists('WooCommerce')` is true. Feature parity with the Drupal plugin: semantic/hybrid search, synonyms, stop words, highlighting, geo, analytics, facets. Configuration that can be managed by Meilisearch itself (synonyms, stop words, ranking rules, embedders, typo tolerance) defers to the Meilisearch Cloud dashboard with deep-links — the WordPress admin only owns configuration that must live in WordPress (connection, post-type selection, WP-field mapping, sync behavior, front-end toggles).

## Requirements

- PHP `>=8.1`, tested through PHP 8.5
- WordPress `>=6.2`
- WooCommerce `>=8.0` (optional, auto-detected)
- Meilisearch `>=1.10` (federated search and `queryUid` analytics metadata require 1.10+)
- Meilisearch Cloud and self-hosted support
- Semantic/hybrid search (embedder configured in Cloud; mode and embedder name configured in WP)
- Multisite-aware (per-site settings with optional network fallback)
- WordPress Settings API (classic) for admin UI — no React pages
- `meilisearch/meilisearch-php ^1.16` SDK, scoped via `php-scoper` to `Meilisearch\WordPress\Vendor\`
- Published to wordpress.org/plugins AND Packagist
- Internationalization: `/languages/` folder with POT template

## Design Pillars

1. **One Meilisearch index per post type.** Unified cross-type search uses `/multi-search` with `federation: {limit, offset}` (Meilisearch ≥ 1.10) for merged, globally-ranked results.
2. **Minimal WP admin, defer to Cloud.** WordPress only owns config that must live in WordPress. Everything Meilisearch itself can manage (synonyms, stop words, ranking rules, typo tolerance, embedders) is surfaced as "Configure in Cloud →" deep-links. The plugin supports these features at runtime but does not configure them from WP.
3. **Action Scheduler for all async work.** Real-time sync (`save_post`, stock changes) and bulk re-index both dispatch Action Scheduler jobs. WP-Cron fallback when AS is unavailable.
4. **Server-side search replacement by default.** Hook `pre_get_posts` / `posts_pre_query` so every theme's built-in search uses Meilisearch with no template changes. Opt-in `[meilisearch_search]` shortcode and Gutenberg block provide a facet-rich InstantSearch UI for sites that want it.
5. **Full WooCommerce model when active.** Simple + variable products (variations attached to parent), hierarchical categories (`lvl0/lvl1/lvl2`), WC attributes as facets, stock status, price with sale handling, reviews, ACF meta.
6. **Analytics: ship the tracking, defer the viewing.** Plugin implements click-tracking JS, proxy REST route, and WooCommerce conversion hooks. The Cloud analytics dashboard is the viewing UI.
7. **Multisite-aware.** Per-site Meilisearch config with optional network-level fallback. Index UIDs include `blog_id` to prevent cross-site collisions.

## Plugin Identity

| Field | Value |
|---|---|
| Slug (wordpress.org) | `meilisearch` |
| Main file | `meilisearch.php` |
| Text domain | `meilisearch` |
| PHP namespace | `Meilisearch\WordPress\` |
| Vendor namespace (scoped) | `Meilisearch\WordPress\Vendor\` |
| License | GPL-2.0-or-later |

## Directory Layout

```
meilisearch/
├── meilisearch.php                  # plugin bootstrap, WP headers, autoloader
├── readme.txt                       # WP.org format
├── README.md                        # GitHub-facing
├── composer.json                    # PHP deps + php-scoper config
├── package.json                     # JS build (InstantSearch bundle, admin scripts)
├── uninstall.php                    # cleanup hook
├── LICENSE                          # GPL-2.0-or-later
│
├── src/
│   ├── Plugin.php                   # singleton, wires up subsystems
│   ├── Admin/
│   │   ├── SettingsPage.php         # Settings API definitions
│   │   ├── ConnectionSection.php    # host + key + self-hosted/Cloud toggle
│   │   ├── PostTypesSection.php     # per-post-type field mapping
│   │   ├── FrontendSection.php      # server replacement + shortcode toggles
│   │   ├── WooCommerceSection.php   # WC-specific (shown when active)
│   │   ├── AnalyticsSection.php     # tracking toggles, userId strategy
│   │   ├── ReindexDashboard.php     # full-reindex trigger + progress UI
│   │   └── CloudLinks.php           # helper: "configure X in Cloud →" cards
│   ├── Api/
│   │   ├── Client.php               # wraps meilisearch-php; Cloud detection
│   │   ├── ClientFactory.php        # per-site client (multisite-aware)
│   │   └── Exception.php
│   ├── Indexing/
│   │   ├── IndexManager.php         # create/update/delete Meilisearch indexes
│   │   ├── SettingsBuilder.php      # computes full settings state per index
│   │   ├── DocumentBuilder.php      # WP_Post → MS document
│   │   ├── FieldMapper.php          # WP field types → MS types
│   │   └── PostTypeConfig.php       # value object for per-PT config
│   ├── Sync/
│   │   ├── SyncController.php       # save_post / delete_post hooks
│   │   ├── AsyncDispatcher.php      # Action Scheduler wrapper
│   │   ├── Jobs/
│   │   │   ├── IndexPostJob.php
│   │   │   ├── DeletePostJob.php
│   │   │   └── BulkReindexJob.php   # batched
│   │   └── Queue.php                # fallback to wp-cron when AS unavailable
│   ├── Search/
│   │   ├── ServerSideSearch.php     # pre_get_posts / posts_pre_query hook
│   │   ├── QueryBuilder.php         # WP_Query args → MS search params
│   │   ├── FilterBuilder.php        # WP_Query filter trees → MS filter strings
│   │   ├── FederatedSearch.php      # multi-PT search via /multi-search
│   │   ├── ResultSet.php            # MS hits → WP_Post results
│   │   └── Highlighter.php          # applies highlight tags to excerpts
│   ├── Shortcode/
│   │   ├── SearchShortcode.php      # [meilisearch_search] renderer
│   │   └── Block/                   # Gutenberg block (block.json + edit/save)
│   ├── WooCommerce/
│   │   ├── Integration.php          # bootstraps only if WC active
│   │   ├── ProductDocumentBuilder.php
│   │   ├── VariationHandler.php
│   │   ├── AttributeFacets.php
│   │   ├── CategoryHierarchy.php    # lvl0/lvl1/lvl2 convention
│   │   └── StockSync.php            # listens to woocommerce_* stock hooks
│   ├── Analytics/
│   │   ├── ClickTrackingRoute.php   # WP REST /meilisearch/v1/events proxy
│   │   ├── QueryUidRoute.php        # WP REST /meilisearch/v1/queryuid
│   │   ├── OrderConversionHook.php  # woocommerce_* → conversion event
│   │   ├── QueryUidStore.php        # short-lived transient store
│   │   └── CustomFieldsProvider.php # analyticsCustomFields on searches
│   ├── Multisite/
│   │   └── SiteSettings.php         # per-site + network fallback
│   ├── Support/
│   │   ├── Logger.php               # WP error_log + optional admin notice
│   │   ├── Sanitizer.php
│   │   └── Capabilities.php         # manage_options + meilisearch_manage
│   └── Frontend/
│       ├── InstantSearchAssets.php  # enqueues @meilisearch/instant-meilisearch
│       └── ClickTrackingAssets.php  # enqueues click-tracking snippet
│
├── assets/
│   ├── js/
│   │   ├── admin/                   # reindex progress poller, test-connection
│   │   ├── instant-search.js        # InstantSearch.js bundle entry
│   │   └── click-tracking.js        # lightweight tracker
│   ├── css/
│   │   ├── admin.css
│   │   └── instant-search.css
│   └── images/
│
├── templates/
│   ├── shortcode-search.php         # overridable via theme
│   └── admin/...
│
├── languages/
│   └── meilisearch.pot
│
├── bin/
│   ├── release-to-svn.sh            # GitHub → plugins.svn.wordpress.org
│   └── scope-vendor.sh              # runs php-scoper on vendor/
│
├── tests/
│   ├── unit/                        # PHPUnit, no WP bootstrap
│   ├── integration/                 # WP Test Suite + real Meilisearch container
│   └── e2e/                         # @playwright/test against wp-env site
│
└── docs/
    ├── docker-compose.example.yml
    ├── local-by-flywheel.md
    ├── wp-env.md
    ├── cloud-setup.md
    ├── multisite.md
    ├── bedrock.md
    └── superpowers/
        ├── specs/
        └── plans/
```

## Bootstrap Flow

1. `meilisearch.php` defines constants, registers the PSR-4 autoloader (`src/` + scoped `vendor/`), hooks `plugins_loaded` → `Plugin::boot()`.
2. `Plugin::boot()` instantiates subsystems in order: settings → API client factory → index manager → sync controller → (conditionally) WooCommerce integration → analytics → frontend.
3. Activation hook: seeds option keys with safe defaults, registers Action Scheduler groups, flags "needs re-index" if connection settings change.
4. Deactivation hook: cancels pending scheduled actions, leaves options and Meilisearch indexes intact (uninstall removes them).
5. `uninstall.php`: optionally drops Meilisearch indexes (admin checkbox "remove remote data on uninstall"), deletes options, drops the sync queue table.

## API Client Layer

### `Api\Client`

Thin wrapper around `Meilisearch\Client` from `meilisearch-php ^1.16`.

- Created by `Api\ClientFactory` from stored site options (URL, API key).
- Adds WP-aware concerns the raw SDK does not handle:
  - `User-Agent: Meilisearch-WordPress/<plugin_version> WordPress/<wp_version>`
  - `Meili-Include-Metadata: true` header on search requests (for analytics `queryUid`)
  - Cloud detection: URL matches `*.meilisearch.io` OR `/version` response signals Cloud
  - Timeouts: default 10s, configurable via `meilisearch_http_timeout` filter
- Exceptions: all SDK exceptions caught, wrapped into `Api\Exception` with context (endpoint, HTTP status, site id). Consumed by `Support\Logger` → `error_log` + optional admin notice.
- HTTP transport: `php-http/discovery` auto-picks a PSR-18 client. `symfony/http-client` is bundled (wp-scoped) as the vendored default to guarantee availability.

### `Api\ClientFactory`

- `forSite($site_id)` returns an instance keyed on site options.
- `forNetwork()` uses network-level fallback when a subsite has no overrides.
- Per-site cache of client instances to avoid re-instantiation within a request.

## Index Lifecycle — `Indexing\IndexManager`

| Event | Action |
|---|---|
| Admin enables post type "X" for indexing | `createIndex($uid)` with uid `wp_{blog_id}_{post_type}`; push initial settings. |
| Admin changes field mapping for post type "X" | Recompute full settings state, push via `updateSettings()` (single bulk call). |
| Admin disables post type "X" | `deleteIndex($uid)` (behind a confirm modal — destructive). |
| Connection host or key changes | Mark all configured post types dirty, prompt admin for full re-index. |
| Plugin uninstall with "remove remote data" checked | Delete all plugin-managed indexes. |

**Index UID scheme:** `wp_{blog_id}_{post_type}` on multisite (avoids collision when multiple subsites share one Meilisearch instance), `wp_{post_type}` on single-site installs. Sanitized to match Meilisearch's `[a-zA-Z0-9_-]` rule.

**Task handling:** every index-mutating call is async on Meilisearch's side. We poll `getTask()` up to 30s (configurable via `meilisearch_task_timeout` filter) and log any failure. `IndexManager::updateIndexSettings()` is idempotent — safe to re-run.

### `Indexing\SettingsBuilder`

Computes full desired Meilisearch settings state for one post type:

```
searchableAttributes  ← admin-ordered list of mapped WP fields (priority matters)
filterableAttributes  ← all fields marked "facetable" + post_type, post_status, author, date_int
sortableAttributes    ← all fields marked "sortable" + date_int
displayedAttributes   ← all indexed fields
distinctAttribute     ← null (or "parent_id" for variable-product variation dedup)
```

We deliberately do **not** push `synonyms`, `stopWords`, `rankingRules`, `typoTolerance`, or `embedders` — those live in Cloud. First-time index creation uses Meilisearch's defaults; admin configures the rest in Cloud via deep-linked settings cards.

## Document Build — `Indexing\DocumentBuilder`

Turns a `WP_Post` into a Meilisearch document.

### Core WP fields always included

| MS field | Source |
|---|---|
| `id` | `post_ID` (already numeric, safe) |
| `wp_id` | original post id (for mapping back to `WP_Post`) |
| `post_type` | `$post->post_type` |
| `post_status` | `$post->post_status` |
| `title` | `$post->post_title` |
| `content` | `wp_strip_all_tags(apply_filters('the_content', $post->post_content))` |
| `excerpt` | `$post->post_excerpt` or auto-generated from content |
| `permalink` | `get_permalink($post)` |
| `date_int` | `strtotime($post->post_date_gmt)` (sortable Unix timestamp) |
| `author_id` | `$post->post_author` |
| `author_name` | `get_the_author_meta('display_name', $post->post_author)` |
| `thumbnail_url` | `get_the_post_thumbnail_url($post, 'medium')` |

### Configurable additions

- **Taxonomies** — admin picks which taxonomies to include per post type. Each taxonomy yields `tax_{slug}_names` (array of term names, facetable) and `tax_{slug}_ids`.
- **Meta fields** — admin lists meta keys to index. Each `_meta_{key}` becomes a top-level field. Numeric meta detected via `is_numeric()` and indexed as a number (sortable).
- **ACF fields** — auto-discovered via `get_field_objects($post_id)` when ACF is active and admin enables ACF sync. Field type determines MS type (`number` → number, `true_false` → boolean, etc.).

### Handling

- Multi-value fields stay arrays.
- Strings truncated to 65,535 chars (Meilisearch soft limit).
- WP filter `meilisearch_document` receives `(array $doc, WP_Post $post, string $index_uid)` — third-party plugins can add or modify fields.

## Sync — `Sync\SyncController` + Action Scheduler

### Hooks registered

```php
add_action('save_post',         [$this, 'onPostSaved'],   10, 3);
add_action('delete_post',       [$this, 'onPostDeleted'], 10, 2);
add_action('trashed_post',      [$this, 'onPostTrashed']);
add_action('untrashed_post',    [$this, 'onPostUntrashed']);
add_action('set_object_terms',  [$this, 'onTermsChanged'], 10, 6);
add_action('updated_post_meta', [$this, 'onMetaChanged'],  10, 4);
```

### Behavior

Each handler enqueues an Action Scheduler job rather than calling Meilisearch directly:

```php
as_enqueue_async_action('meilisearch_index_post', [$post_id, $site_id], 'meilisearch');
```

### Job handlers

- `IndexPostJob::handle($post_id, $site_id)` — builds document, calls `addDocuments([$doc])`. On failure: rescheduled with exponential backoff, 3 attempts.
- `DeletePostJob::handle($post_id, $site_id)` — calls `deleteDocument($id)`.
- `BulkReindexJob::handle($post_type, $site_id, $batch_offset, $batch_size=50)` — queries 50 posts at that offset, indexes them, chains the next batch if more exist.

### Debouncing

Meta and term changes fire multiple hooks per post update. The sync controller debounces via a per-request static flag — a single post update yields at most one index job per request regardless of how many `updated_post_meta`, `set_object_terms`, and `save_post` calls fire.

### Full re-index UI

Admin → Meilisearch → Re-index:

- Per-post-type row showing post count, indexed doc count, last-synced timestamp.
- "Re-index this post type" button → enqueues `BulkReindexJob` with offset 0.
- Live progress via AJAX poll every 3s to `/wp-json/meilisearch/v1/reindex/status`. Reads `as_get_scheduled_actions()` for pending / completed counts. Plain jQuery polling, no React.
- "Re-index everything" button → enqueues one bulk job per enabled post type.

### Fallback

`Sync\Queue` provides a custom table + `wp_schedule_event` recurring dispatcher. Action Scheduler is always preferred; Queue is the safety net when AS is not available (rare — see below).

### Action Scheduler dependency strategy

- `composer.json` declares `woocommerce/action-scheduler` as a runtime dependency so non-WC installs get it automatically. AS is designed for co-existence: if another plugin (or WooCommerce itself) already loaded AS, our bundled copy yields via AS's built-in version-negotiation logic.
- `php-scoper` does **not** scope AS — AS is the community standard and must share a single global instance across plugins to avoid duplicate job tables.
- On activation, the plugin verifies AS is available; if not (e.g., Composer install skipped), it registers the `Sync\Queue` fallback and surfaces an admin notice recommending the user install Action Scheduler.

## Search Path — Server-Side Replacement

### `Search\ServerSideSearch`

Hooks `pre_get_posts` with priority 5 (before most theme code).

Triggers when all apply:

- `is_search() && is_main_query() && !is_admin()`
- Server-side replacement enabled in settings
- At least one indexed post type matches the query's `post_type` arg

### Flow

1. `QueryBuilder` translates `WP_Query` args → Meilisearch search params.
2. If one post type → single-index `search()`. If multiple → `multiSearch` with `federation: {limit, offset}`.
3. Hook `posts_pre_query` returns an array of `WP_Post` stubs built from MS hits (using `wp_id` and filling cached post objects via `get_posts(['post__in' => $ids, 'orderby' => 'post__in'])`).
4. `found_posts` and `max_num_pages` set from Meilisearch's `estimatedTotalHits`.
5. Original `posts_request` replaced with an empty SELECT to prevent the MySQL fulltext query from running.

### `QueryBuilder` mapping

| WP_Query arg | MS parameter |
|---|---|
| `s` | `q` |
| `posts_per_page` | `limit` |
| `paged` (computed) | `offset` |
| `orderby=date` | `sort=["date_int:desc"]` |
| `post_type` array | index selection (federated if >1) |
| `tax_query` | filter string built via `FilterBuilder` |
| `meta_query` | filter string |
| `date_query` | `filter: date_int >= X AND date_int <= Y` |
| `author__in` | `filter: author_id IN [...]` |

**Post-status filter** always enforced server-side: `filter: post_status = "publish"`. Private/draft searches are handled separately for authenticated admins using a capability-gated code path.

### `FilterBuilder`

Same design as the Drupal `FilterBuilder`: recursive walker, supports `=`, `!=`, `<`, `<=`, `>`, `>=`, `IN`, `NOT IN`, `BETWEEN`, `IS NULL`, `IS NOT NULL`, `_geoRadius`, `_geoBoundingBox`, and nested AND/OR groups with parentheses.

### Highlighting

The SDK's `_formatted` block is pulled from each hit. Title / excerpt fields are replaced with formatted versions when rendering templates. Default tags `<em>` / `</em>` are configurable via `meilisearch_highlight_tags` filter.

### queryUid

Each search response (because we send `Meili-Include-Metadata: true`) returns a `queryUid`. Stored in `Analytics\QueryUidStore` so click / conversion tracking can reference it later.

## Shortcode + Gutenberg Block (InstantSearch UI)

```
[meilisearch_search post_type="product" facets="category,brand,price"]
```

Renders:

```html
<div id="meilisearch-root" data-config="..."></div>
```

Config is a server-rendered JSON blob containing the Meilisearch host, the search-only API key, target index UID, facet definitions, and hit template ID. No admin key ever exposed.

The enqueued bundle (`assets/js/instant-search.js`) uses `instantsearch.js` + `@meilisearch/instant-meilisearch` and renders:

- `searchBox`, `hits`, `pagination`, `stats` (always)
- `refinementList` per configured string facet
- `rangeSlider` for numeric facets
- `hierarchicalMenu` for WC categories (lvl0/lvl1/lvl2)
- Overridable via `templates/shortcode-search.php` in the active theme

Gutenberg block (`block.json` with `meilisearch/search`) is a thin wrapper — same config, block attributes become shortcode attrs.

**Search-only API key**: second field in Connection settings (separate from admin key). If blank, the InstantSearch UI is disabled with an admin notice ("Configure a search-only key to enable the InstantSearch frontend").

## WooCommerce Integration

### Bootstrapping

`src/WooCommerce/Integration.php` only bootstraps when `class_exists('WooCommerce')` is true **and** the admin has enabled the WC integration in settings (top-level toggle, on by default when WC is detected).

### Product indexing model

**Index naming:** `wp_{blog_id}_products`. Variations are NOT a separate index — they ride inside the parent product document.

**`WooCommerce\ProductDocumentBuilder`** extends `Indexing\DocumentBuilder` with product-specific fields:

| MS field | Source | Type |
|---|---|---|
| `sku` | `$product->get_sku()` | string (searchable, filterable) |
| `price` | `wc_get_price_to_display($product)` | number (sortable, filterable) |
| `regular_price` | `$product->get_regular_price()` | number |
| `sale_price` | `$product->get_sale_price()` | number (nullable) |
| `on_sale` | `$product->is_on_sale()` | bool (filterable) |
| `stock_status` | `$product->get_stock_status()` | string (filterable) |
| `stock_quantity` | `$product->get_stock_quantity()` | number (nullable) |
| `backorders_allowed` | `$product->backorders_allowed()` | bool |
| `rating_average` | `$product->get_average_rating()` | number (filterable, sortable) |
| `rating_count` | `$product->get_review_count()` | number |
| `product_type` | `simple` / `variable` / `grouped` / `external` | string (filterable) |
| `featured` | `$product->is_featured()` | bool (filterable) |
| `total_sales` | `get_post_meta($id, 'total_sales', true)` | number (sortable) |
| `weight` / `length` / `width` / `height` | `$product->get_*()` | number |
| `image_gallery_urls` | from `get_gallery_image_ids()` | array |

Reviews are indexed **as aggregate rating only** — individual review text is not indexed as searchable content in v1. Per-review indexing is an explicit v2 concern.

### Variable products & variations — `WooCommerce\VariationHandler`

For a variable product, the parent document includes aggregated variation data so facet counts and price ranges work naturally:

```json
{
  "id": 123,
  "product_type": "variable",
  "price_min": 19.99,
  "price_max": 49.99,
  "price":     19.99,
  "variations": [
    {
      "id": 124,
      "sku": "SHIRT-S-RED",
      "price": 19.99,
      "attributes": { "size": "S", "color": "red" },
      "stock_status": "instock"
    }
  ],
  "available_sizes":  ["S","M","L"],
  "available_colors": ["red","blue","green"],
  "in_stock_any":     true
}
```

**Why flattened attribute arrays?** Meilisearch cannot efficiently facet on nested object fields, so every WC attribute taxonomy is flattened into a top-level array field (`available_sizes`, `available_colors`, etc.). Facet widgets refer to the flat field; the `variations` sub-array is for result-card rendering.

Variation-level stock changes (`woocommerce_variation_set_stock`, `woocommerce_product_set_stock`) trigger a parent re-index via Action Scheduler.

### Attributes as facets — `WooCommerce\AttributeFacets`

- Every **global attribute** (`pa_*` taxonomy) auto-maps to a facet field named `attr_{slug}`.
- **Custom product attributes** (non-taxonomy) are included when admin opts in; field name `custom_attr_{slug}`.
- Admin sees a table in the WC settings section: each attribute row has an on/off toggle + optional display-label override for the InstantSearch UI.
- These fields land in `filterableAttributes` of the products index.

### Hierarchical categories — `WooCommerce\CategoryHierarchy`

Follows Meilisearch's documented `lvl0 / lvl1 / lvl2` convention for the InstantSearch `hierarchicalMenu` widget:

```json
{
  "categories": {
    "lvl0": "Clothing",
    "lvl1": "Clothing > T-Shirts",
    "lvl2": "Clothing > T-Shirts > Graphic"
  },
  "category_ids": [42, 87, 112]
}
```

Walks `$product->get_category_ids()` and their ancestors. One product can belong to multiple root hierarchies — in that case each `lvl*` field becomes an array of strings (still compatible with `hierarchicalMenu`). Tags use a simpler flat `tags` array (WC tags are not hierarchical).

### Stock sync — `WooCommerce\StockSync`

Dedicated hooks so stock changes propagate fast without waiting for a full post save:

```php
add_action('woocommerce_product_set_stock',        [...]);
add_action('woocommerce_variation_set_stock',      [...]);
add_action('woocommerce_product_set_stock_status', [...]);
add_action('woocommerce_reduce_order_stock',       [...]);  # order completion
add_action('woocommerce_restore_order_stock',      [...]);  # refund / cancel
```

Each enqueues a lightweight `UpdateStockJob` that issues a **partial update** (`updateDocuments()` with only stock fields) — avoiding a full product rebuild for high-frequency stock-only changes.

### WooCommerce admin settings

*Meilisearch → WooCommerce* tab (shown when WC is active):

- Enable product indexing (on/off) — default on.
- Variation strategy — v1 ships only "Attach variations to parent product"; architected so a future "Index each variation as its own document" mode can slot in.
- Attributes to index — table of detected attributes with toggles.
- Custom meta to index — WC-specific meta keys (SKU additionals, vendor-plugin meta).
- Replace WooCommerce shop search? — toggle; hooks `pre_get_posts` on `is_shop()`, `is_product_taxonomy()`, `is_product_search()`.
- Replace shortcodes — toggle for `[products]`, `[woocommerce_product_search]` to use Meilisearch.

### Shop page UX

WooCommerce product search drops `?s=` and relies on `WP_Query` with `post_type=product`. Our `pre_get_posts` hook handles that because `is_main_query()` catches the WC shop search. Special case: when `is_product_taxonomy()` is true, we inject a default filter (`filter: category_ids IN [X]`) so category archive pages list correctly when Meilisearch takes over.

## Analytics — Tracking Layer

### Endpoint & auth

- Endpoint: `POST /events` on the Meilisearch host.
- Auth: **search API key** (same key used by the InstantSearch shortcode). The admin key never touches `/events` — the browser never sees the admin key at all.

### Event shape

Both supported event types (`click`, `conversion`) share the same body:

```json
{
  "eventType":  "click" | "conversion",
  "eventName":  "Search Result Clicked",
  "indexUid":   "wp_1_products",
  "userId":     "<hashed>",
  "queryUid":   "abc-123",
  "objectId":   "456",
  "objectName": "Red Cotton T-Shirt",
  "position":   3
}
```

`userId` is sent via the `X-MS-USER-ID` request header (cleaner than inlining in the body; keeps a consistent shape across events).

### Events the plugin fires

| Event name | `eventType` | Trigger | Where |
|---|---|---|---|
| `Search Result Clicked` | `click` | User clicks any result with `data-meili-id` | `click-tracking.js` |
| `Product Added To Cart` | `conversion` | `woocommerce_add_to_cart` hook | `OrderConversionHook` |
| `Order Completed` | `conversion` | `woocommerce_order_status_completed` | `OrderConversionHook` |

Event names are customizable via `meilisearch_analytics_event_name` filter.

### `analyticsCustomFields` — segmentation

Every Meilisearch search the plugin makes includes a server-rendered `analyticsCustomFields` block, automatically surfaced in Cloud analytics for slicing:

```json
"analyticsCustomFields": {
  "wp_locale": "en_US",
  "wp_user_role": "customer",
  "wp_multisite_blog": "1",
  "device":  "mobile",
  "context": "shop_search" | "main_search" | "instant_search_shortcode"
}
```

Admins extend via `meilisearch_analytics_custom_fields` filter.

### Proxy route — `/wp-json/meilisearch/v1/events`

All browser-originated events go through the proxy. Why still proxy even though the browser only sees a search key?

1. **Server-controls `indexUid`** — the client shouldn't decide which index it's reporting against.
2. **Server-controls `userId`** — hashing / logged-in-ID resolution happens in PHP, not JS, so it's consistent regardless of client.
3. **Server-adds `X-MS-USER-ID` header** — never exposes the key rotation path.
4. **Rate limiting** — transient-backed limiter (30 events / IP / minute, configurable filter).
5. **Nonce-checked** — prevents random cross-origin posts.
6. **Event-type whitelist** — only `click` passes the proxy from the browser. `conversion` events only originate server-side from WooCommerce hooks.

Flow for a click:

```
browser click → POST /wp-json/meilisearch/v1/events
             → validate nonce
             → validate eventType=click, shape-check fields
             → rate-limit check
             → resolve userId (hashed session / WP user id / anonymous)
             → resolve indexUid from the stored queryUid context
             → forward to POST {meili}/events  (X-MS-USER-ID header, search key)
             → return 202 Accepted
```

Flow for a conversion (server-side only):

```
woocommerce_order_status_completed → OrderConversionHook::onOrderCompleted
                                   → for each line item:
                                       look up queryUid in QueryUidStore
                                       if found → POST /events directly using
                                                  the stored search-only key
                                                  (no proxy needed — already server-side)
```

**Key used:** server-side conversion events use the **search-only API key**, not the admin key — matching the browser click path. This means the plugin always has both keys available server-side. If the search-only key is unset, conversion tracking is disabled with an admin notice.

### `queryUid` store

Every search the plugin issues includes `Meili-Include-Metadata: true`. The returned `queryUid` is:

- **Server-side search path**: stored in `Analytics\QueryUidStore` keyed by session + position → post id mapping, TTL 30 min (transient). Used by conversion hooks later.
- **InstantSearch shortcode path**: returned to the browser in each response, cached in JS, attached to every click event. A mirror copy posted to a `PUT /wp-json/meilisearch/v1/queryuid` endpoint so WooCommerce conversions (which fire server-side later) can find it.

### Admin UI

*Meilisearch → Analytics* page — single-screen, tracking-only:

- Enable click tracking (on/off)
- Enable conversion tracking (on/off, shown only when WC active)
- Conversion hook selector — `woocommerce_add_to_cart`, `_processing`, `_completed` (multi-select)
- `userId` strategy — `logged_in_user` | `hashed_session` (default) | `anonymous_session`
- Privacy notice banner: "Click tracking sends anonymous interaction data to your Meilisearch instance. Provide appropriate disclosure in your privacy policy."
- Deep link: "View analytics →" opens `https://cloud.meilisearch.com/projects/{id}/analytics` (Cloud) or a docs link to the analytics API (self-hosted).

### Privacy / GDPR

- `userId` defaults to a **hashed session cookie** — not a personal identifier.
- If the browser sends the `DNT: 1` header, the proxy returns 204 and drops the event (overridable via filter).
- Admin can disable the analytics module entirely with a single top-level toggle — removes JS enqueue, unregisters REST routes, unregisters WC hooks.
- Events are **never stored in the WP database** — they pass straight through to Meilisearch.

## Multisite — `Multisite\SiteSettings`

- Options stored at the **site level** (`get_option`), not network level — each subsite has its own Meilisearch config.
- Optional **network default fallback**: a Network Admin → Meilisearch page. A subsite with no local host / key falls back to the network-level values. Subsite admins can override.
- Index UIDs always include `blog_id` to prevent collisions if multiple subsites share one Meilisearch instance with the same post type name.
- Activation hook iterates `get_sites()` and seeds safe defaults per site.
- `Capabilities::can_manage()` uses `manage_options` on single-site, `manage_network_options` for network-level admin.

## Geo Search

WP has no native geo field, so geo is opt-in per meta key pair.

Admin → post type config → "Geo location" section:

- Latitude meta key (e.g., `_lat`)
- Longitude meta key (e.g., `_lng`)

When both are set:

- `DocumentBuilder` emits `_geo: {lat, lng}` on each document.
- `SettingsBuilder` adds `_geo` to filterable + sortable attributes.
- `FilterBuilder` supports `_geoRadius` / `_geoBoundingBox`.

Search-time geo: consuming code passes `['geo_radius' => [$lat, $lng, $meters]]` as a custom `WP_Query` arg (registered via the `query_vars` filter).

## Deployment Examples

### Docker Compose (shipped as `docs/docker-compose.example.yml`)

Full local WP + Meilisearch stack:

```yaml
services:
  wordpress:
    image: wordpress:6.7-php8.3-apache
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: mysql
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_DB_NAME: wp
    volumes:
      - wp_content:/var/www/html/wp-content
    depends_on:
      mysql: { condition: service_healthy }
      meilisearch: { condition: service_healthy }

  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: wp
      MYSQL_USER: wp
      MYSQL_PASSWORD: wp
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      retries: 5

  meilisearch:
    image: getmeili/meilisearch:latest
    ports:
      - "7700:7700"
    environment:
      MEILI_MASTER_KEY: "masterKey123"
      MEILI_ENV: "development"
    volumes:
      - meili_data:/meili_data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:7700/health"]
      interval: 5s
      retries: 5

volumes:
  wp_content:
  db_data:
  meili_data:
```

### Other environments

- **`docs/local-by-flywheel.md`** — walkthrough for Local by Flywheel / WP Engine: install in wp-content, point at Meilisearch Cloud or local Docker.
- **`docs/wp-env.md`** — `@wordpress/env` contributor setup. `.wp-env.json` ships with a Meilisearch sidecar container.
- **`docs/cloud-setup.md`** — Meilisearch Cloud signup, project creation, API key generation (admin + search-only), embedder configuration for semantic search, analytics dashboard tour.
- **`docs/multisite.md`** — single-instance vs multi-instance strategies, index naming, network defaults.
- **`docs/bedrock.md`** — install via Composer + Bedrock/Roots.

## Testing Strategy

Three tiers, runnable via `composer test` or `npm test`.

### Unit tests (PHPUnit + Brain Monkey for WP mocks — no WP bootstrap, no Meilisearch)

- `FilterBuilderTest` — all operator mappings, AND/OR groups, geo, null handling
- `DocumentBuilderTest` — field type mapping, taxonomy flattening, meta handling
- `QueryBuilderTest` — WP_Query → MS params
- `SettingsBuilderTest` — settings shape per config
- `ProductDocumentBuilderTest` — variable-product flattening, attribute facets
- `CategoryHierarchyTest` — lvl0/lvl1/lvl2, multi-hierarchy products
- `QueryUidStoreTest` — TTL behavior
- `ClickTrackingRouteTest` — nonce + rate limit + validation

### Integration tests (WP Test Suite bootstrapped, Meilisearch Docker container as CI service)

- `SyncControllerTest` — save_post → Action Scheduler job enqueued → document present in index
- `ServerSideSearchTest` — real query against indexed fixtures returns expected `WP_Post` array
- `FederatedSearchTest` — multi-post-type unified search
- `WooCommerceIntegrationTest` — product save → variations flattened → attribute facet counts correct
- `StockSyncTest` — stock hook → partial update arrives without full rebuild
- `MultisiteTest` — two subsites, two independent index sets
- `RestRouteTest` — `/meilisearch/v1/events` nonce + rate limit + forwarding
- `OrderConversionHookTest` — queryUid resolution across the WC checkout flow

### E2E tests (Playwright against `wp-env` + Meilisearch)

- `admin-settings.spec.ts` — install, configure, test-connection button works
- `reindex.spec.ts` — trigger full reindex, wait for completion, verify counts
- `frontend-search.spec.ts` — search from theme, results from Meilisearch
- `instant-search.spec.ts` — shortcode on a page, facets filter, click tracked
- `woocommerce.spec.ts` — add variable product, search by attribute, facet counts match, add-to-cart conversion recorded

### CI (GitHub Actions)

- Matrix: PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5 × WP 6.2 / 6.7 / trunk
- Meilisearch container as a service on the integration and E2E jobs
- Separate job: `phpcs` (WordPress Coding Standards), `phpstan` (level 6), `@wordpress/scripts lint`

## Release Pipeline

GitHub is source of truth. Three artifacts flow out of each tagged release:

1. **wordpress.org SVN** — `bin/release-to-svn.sh` tags the repo, runs `composer install --no-dev`, runs `php-scoper` on `vendor/` (scopes to `Meilisearch\WordPress\Vendor\`), runs `npm run build`, copies the result into a checkout of `plugins.svn.wordpress.org/meilisearch/`, commits to `trunk` + creates a `/tags/X.Y.Z/` tag. Triggered manually from the GitHub Actions `release` workflow.
2. **Packagist** — `composer.json` declares the plugin; Packagist auto-updates on tag push. Bedrock / Roots users install via `composer require meilisearch/wordpress-plugin`.
3. **GitHub Releases** — a zip artifact uploaded to the GH release, identical to what SVN ships. Useful for manual "upload zip" installs outside wordpress.org.

`readme.txt` (WP.org canonical format) is the release-notes source; it's rendered on the plugin's wordpress.org page. `README.md` is a more developer-facing variant for GitHub.

## Security & wordpress.org review checklist

Handled before first submission:

- All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`).
- All input sanitized (`sanitize_text_field`, `absint`, `rest_sanitize_*`).
- Every form carries a nonce; every AJAX / REST endpoint verifies it.
- Capability checks (`current_user_can`) on every admin action.
- No direct file access (`if (!defined('ABSPATH')) exit;` at the top of every PHP file).
- No `eval`, no `create_function`, no dynamic class loading from user input.
- No obfuscated code; all scripts are human-readable.
- Bundled vendor dependencies scoped via `php-scoper` to avoid class collisions across plugins.
- No phone-home / telemetry without explicit opt-in.
- Plugin does not block uninstall — `uninstall.php` cleanly removes options.
- `readme.txt` has valid `Tested up to` and `Requires PHP` headers.

## Out of Scope (v2 or later)

- React-based admin screens (drag-reorder ranking rules, live facet preview).
- "Index each variation as its own document" product strategy.
- Orders / customers / reviews as independent searchable indexes.
- In-WP analytics dashboard.
- Admin-surfaced configuration for synonyms, stop words, ranking rules, typo tolerance, embedders (these remain Cloud-only by design).
- A/B testing harness for search relevance experiments.

## Open Questions

None at spec-approval time. Any open implementation questions will surface during plan writing and be resolved then.
