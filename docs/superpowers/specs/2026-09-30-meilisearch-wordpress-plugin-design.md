# Meilisearch for WordPress & WooCommerce — Design Spec (v1.0)

**Date:** 2026-09-30
**Status:** Draft — awaiting review
**Linear:** [SID-13](https://linear.app/meilisearch/issue/SID-13/plugin-wordpress-woocommerce)
**Supersedes:** `2026-04-15-meilisearch-wordpress-plugin-design.md` (see git history). The April spec and plan were reviewed on 2026-09-30 and restarted; this spec folds in every blocker from that review.

## 1. Goal

Ship the official Meilisearch plugin to wordpress.org (and Packagist) quickly, with a scope small enough to pass plugin review on the first attempt and to be correct, while still delivering the core value:

- Every theme's built-in search and WooCommerce's product search are answered by Meilisearch (relevancy, typo tolerance, optional hybrid/semantic search) with **no template changes**.
- Content stays in sync in near-real time; nothing unpublished, private, password-protected or hidden is ever indexed.
- An optional, accessible as-you-type autocomplete attaches to existing search forms.

### Success criteria

1. Passes `wp plugin check` and wordpress.org review; slug `meilisearch`.
2. On a fresh site: install → paste host + admin key → one click reindex → theme search served by Meilisearch, with correct pagination.
3. A post moved out of `publish` (or given a password) disappears from Meilisearch within one Action Scheduler run.
4. Meilisearch being down never breaks search: it degrades to MySQL within 2 s, and subsequent requests skip Meilisearch for 60 s.
5. Works on shared hosting (no CLI access) and scales to 100k+ posts via WP-CLI.

## 2. Scope

### In v1.0

- Content indexing for admin-selected post types (posts, pages, CPTs), taxonomies and meta keys.
- WooCommerce product indexing: simple/variable/grouped/external products, variations folded into the parent, category hierarchy, attributes, price, stock, ratings.
- Real-time sync via Action Scheduler; atomic full reindex via index swap.
- Server-side search replacement (`posts_pre_query`) for theme search and WooCommerce product search, including WC ordering and layered-nav/price filters on search results.
- Optional hybrid search (embedder configured in Meilisearch/Cloud; embedder name + semantic ratio set in WP).
- Optional excerpt highlighting.
- Optional autocomplete dropdown on existing search forms.
- Admin UI (Settings API), WP-CLI, Site Health, privacy text, multisite (per-site config).

### Deferred (1.1+)

| Feature | Reason |
|---|---|
| Faceted search UI (Interactivity API or InstantSearch — decide then) | Largest, riskiest front-end surface; server-side search already delivers core value. `categories.lvl*` and `attr_*` fields are indexed now so no reindex is needed later. |
| Analytics (click / conversion events) | `/events` is Meilisearch Cloud-only; `queryUid` metadata needs engine ≥ 1.24; attribution needs a cart/order-meta design. |
| Shop / category archive takeover (no `s`) | Largest WooCommerce compatibility surface (product blocks, Store API, page builders). |
| Network-admin default connection | Per-site config covers v1; super-admin-only editing already handles the security concern. |
| Geo search | Niche; no native WP geo field. |
| ACF auto-discovery | Admin can list ACF meta keys explicitly in v1. |
| Per-review indexing, orders/customers indexes | Not search features most stores need. |

## 3. Requirements & identity

| Item | Value |
|---|---|
| PHP | ≥ 8.1 (Composer `config.platform.php = 8.1.0`; no 8.2+ syntax such as `readonly class`) |
| WordPress | ≥ 6.5; `Tested up to: 7.1` |
| WooCommerce | ≥ 8.5, optional |
| Meilisearch | ≥ 1.13 (federated multi-search with `page`/`hitsPerPage`, stable hybrid search). Checked via `GET /version` on connect. |
| Slug / text domain | `meilisearch` (verified available on wordpress.org, 2026-09-30) |
| Main file | `meilisearch.php` |
| Namespace | `Meilisearch\WordPress\` (PSR-4, `src/`) |
| License | GPL-2.0-or-later (bundled Action Scheduler is GPL-3.0-or-later → distributed zip is effectively GPLv3; stated in readme) |
| Runtime dependencies | `woocommerce/action-scheduler` only. No Meilisearch SDK, no HTTP library, no scoping step. |

## 4. Architecture

### 4.1 Bootstrap

`meilisearch.php` (top level, not inside a hook):

1. Guard `ABSPATH`; define `MEILISEARCH_VERSION`, `MEILISEARCH_FILE`, `MEILISEARCH_DIR`.
2. Require `vendor/autoload.php` (Composer classmap/PSR-4 for `src/` only).
3. `require_once vendor/woocommerce/action-scheduler/action-scheduler.php` — must run at plugin load so AS's version registry can negotiate with WooCommerce's copy.
4. Register activation/deactivation hooks.
5. `add_action('plugins_loaded', [Plugin::class, 'boot'])`.

### 4.2 Composition root

`Plugin::boot()` constructs every service by hand (no container library) and calls `register()` on each object implementing `Registrable`. This is the **only** place hooks are attached. An integration test boots the plugin and asserts every expected action, filter, REST route, CLI command and AS handler is registered.

WooCommerce services are constructed only when `class_exists('WooCommerce')` and "Index products" is enabled.

### 4.3 Units

| Unit | Purpose | Depends on |
|---|---|---|
| `Api\Transport` (interface) / `Api\WpTransport` | Send an HTTP request; `WpTransport` uses `wp_remote_request`. Tests use a fake. | — |
| `Api\Client` | Typed Meilisearch calls: version, keys (create/get/delete), indexes (create/delete/swap), settings (get/patch), documents (add-or-replace/delete-batch), tasks (get/list), search, multi-search. Timeouts: 2 s search, 15 s writes. Maps non-2xx to `ApiError` (code, message, HTTP status). | `Transport` |
| `Api\Task` | Wraps a task UID; `wait(int $timeout)` polls and throws `ApiError` if status is `failed` or `canceled`. | `Client` |
| `Settings\Options` | Typed read/write of plugin options (§ 7.2). Constants in `wp-config.php` (`MEILISEARCH_HOST`, `MEILISEARCH_ADMIN_KEY`) override options and render the fields read-only. | — |
| `Settings\IndexNames` | Computes prefix and index UIDs (§ 5.1). | `Options` |
| `Admin\*` | One class per tab (Connection, Content, WooCommerce, Search, Status) plus `Admin\Menu` and `Admin\RestController`. | `Options`, `Client`, `IndexManager` |
| `Indexing\Indexability` | Single rule deciding whether a post may exist in an index (§ 5.2). | `Options` |
| `Indexing\ContentDocumentBuilder` | `WP_Post` → content document. | `Options` |
| `Indexing\SettingsBuilder` | Required filterable/sortable fields and initial searchable order per index. | `Options` |
| `Indexing\IndexManager` | Create indexes, apply/merge settings, drift detection, create/rotate scoped key. | `Client`, `SettingsBuilder`, `IndexNames` |
| `Indexing\Reindexer` | Full reindex into a temp index, then swap (§ 6.3). | `Client`, `IndexManager`, builders |
| `Sync\ChangeCollector` | Collects changed post IDs per index during a request; flushes on `shutdown`. | `Indexability` (post-type check only) |
| `Sync\SyncJob` | AS handler: reconciles a batch of IDs (upsert or delete); retry with backoff. | builders, `Indexability`, `Client` |
| `Sync\TermJob` | AS handler: pages through posts attached to a changed term and feeds `SyncJob`. | `ChangeCollector` |
| `Sync\ErrorLog` | Capped (50) error log in an option; `error_log` when `WP_DEBUG`. | — |
| `Search\Interceptor` | `pre_get_posts` + `posts_pre_query`: decides whether to intercept and replaces results. | `QueryTranslator`, `Searcher`, `ResultMapper`, `CircuitBreaker` |
| `Search\QueryTranslator` | `WP_Query` → Meilisearch request, or `null` meaning "do not intercept". | `FilterBuilder`, `Options` |
| `Search\FilterBuilder` | Builds filter expressions with strict field-name validation and value escaping. | — |
| `Search\Searcher` | Executes single-index search or federated multi-search. | `Client`, `IndexNames` |
| `Search\ResultMapper` | Hits → ordered `WP_Post[]`; sets `found_posts`, `max_num_pages`; keeps `_formatted` map for highlighting. | — |
| `Search\Highlighter` | Optional `get_the_excerpt` filter for intercepted results. | `ResultMapper` |
| `Search\CircuitBreaker` | Transient-backed "skip Meilisearch for 60 s" after an error/timeout. | — |
| `Frontend\Autocomplete` | Enqueues and configures the autocomplete script (§ 10). | `Options`, `IndexNames` |
| `WooCommerce\Compatibility` | Declares HPOS + cart/checkout blocks compatibility on `before_woocommerce_init`. | — |
| `WooCommerce\ProductDocumentBuilder` | `WC_Product` → product document (§ 8.2). | `ContentDocumentBuilder`, `CategoryHierarchy`, `AttributeCollector` |
| `WooCommerce\CategoryHierarchy` | `lvl0/lvl1/lvl2` + ancestor-inclusive category IDs. | — |
| `WooCommerce\AttributeCollector` | Attribute term names across parent + variations; custom attributes. | — |
| `WooCommerce\ProductSync` | WC CRUD, stock and review hooks → `ChangeCollector`. | `ChangeCollector` |
| `WooCommerce\ProductQueryTranslator` | Extends translation for WC ordering, `filter_*`, `query_type_*`, `min_price`/`max_price`. | `FilterBuilder` |
| `Ops\Cli` | WP-CLI commands (§ 11.2). | `Reindexer`, `SyncJob`, `Client` |
| `Ops\SiteHealth` | Site Health tests (§ 11.3). | `Client`, `IndexManager` |
| `Ops\Privacy` | `wp_add_privacy_policy_content`. | — |
| `Lifecycle\Activator` / `Deactivator` / `uninstall.php` | Seed defaults, cancel actions, clean up (§ 11.5). | — |

Each unit is small and single-purpose; builders and translators are pure (inputs in, arrays out) so they are unit-testable without WordPress loaded beyond Brain Monkey stubs.

## 5. Data model

### 5.1 Index names

- **Prefix:** `wp_{h}` on single-site, `wp_{h}_{blog_id}` on multisite, where `{h}` = first 6 hex chars of `md5(network_home_url())` (single-site: `home_url()`). Editable in Connection tab; changing it after indexing requires a reindex.
- **Indexes:** `{prefix}_content`, and `{prefix}_products` when WooCommerce indexing is on.
- **Temp indexes during reindex:** `{prefix}_content_tmp_{run}` / `{prefix}_products_tmp_{run}`.
- Prefix contains only `[a-z0-9_]`, so all UIDs satisfy Meilisearch's `[a-zA-Z0-9_-]` rule.
- Distinct prefixes per site mean staging and production can share one Cloud project safely.

### 5.2 Indexability rule

A post is indexable iff **all** hold:

1. Its post type is enabled for indexing (content) or it is a `product` and WooCommerce indexing is on.
2. `post_status === 'publish'`.
3. `post_password === ''`.
4. For products: catalog visibility is `visible` or `search` (not `catalog`, not `hidden`); and if `woocommerce_hide_out_of_stock_items === 'yes'`, the product is in stock.
5. `apply_filters('meilisearch_should_index_post', true, $post)` returns true.

Everything in either index is therefore public. This is the invariant that makes the browser search key safe and removes the need for status/visibility filters at search time.

### 5.3 Content document (`{prefix}_content`)

| Field | Source |
|---|---|
| `id` | post ID (int) |
| `post_type` | `$post->post_type` |
| `title` | `get_the_title($post)` decoded, tags stripped |
| `content` | `wp_strip_all_tags(do_blocks(strip_shortcodes($post->post_content)))`, whitespace-collapsed, truncated to 20 000 chars. Filter `meilisearch_document_content` may substitute (e.g. `the_content` for page-builder sites). |
| `excerpt` | `$post->post_excerpt` or first 55 words of `content` |
| `permalink` | `get_permalink($post)` |
| `date` / `modified` | Unix timestamps from `post_date_gmt` / `post_modified_gmt` |
| `author_id` / `author_name` | `post_author` / display name |
| `thumbnail_url` | `get_the_post_thumbnail_url($post, 'thumbnail')` or null |
| `tax_{taxonomy}` | term names (array) for each enabled taxonomy |
| `tax_{taxonomy}_ids` | term IDs **including ancestors** (array) |
| `meta_{key}` | each admin-listed meta key; scalar only (arrays/objects skipped); numeric strings cast to int/float |

Field names derived from taxonomy slugs and meta keys are normalized to `[A-Za-z0-9_]` (other characters → `_`). Final filter: `apply_filters('meilisearch_document', $doc, $post, $index)`.

### 5.4 Index settings ownership

The plugin **owns only what it needs to function**:

- `filterableAttributes` and `sortableAttributes`: plugin computes the required set and PATCHes the **union** of required ∪ current (read via `GET /settings` first). Cloud-added attributes survive.
- `searchableAttributes`: set once at index creation to an ordered list (content: `title`, `tax_*`, `excerpt`, `content`, `meta_*`; products: § 8.3). Later, newly enabled fields are **appended** if the current value is not `["*"]`; existing order is never changed.
- Never touched: `displayedAttributes` (stays `["*"]`), `distinctAttribute`, ranking rules, synonyms, stop words, typo tolerance, embedders, faceting, pagination settings.
- `SettingsBuilder` output is filterable via `meilisearch_index_settings`.
- Site Health reports drift (required attributes missing).

Content index required settings:

- filterable: `post_type`, `author_id`, `date`, `modified`, `tax_*`, `tax_*_ids`, `meta_*`
- sortable: `date`, `modified`, `title`, numeric `meta_*`

## 6. Sync

### 6.1 Hooks → collector

| Hook | Effect |
|---|---|
| `save_post`, `transition_post_status` | collect post ID |
| `before_delete_post` | collect post ID |
| `set_object_terms` | collect object ID |
| `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | collect only if meta key is an indexed key (so `_edit_lock`, `_edit_last` etc. never trigger) |
| `edited_term`, `delete_term` (enabled taxonomies) | enqueue `meilisearch_sync_term` |
| WooCommerce hooks | § 8.4 |

Revisions, autosaves and non-enabled post types are ignored at collection time. `ChangeCollector` keeps a per-request set keyed by `(blog_id, index)`. On `shutdown` it enqueues `meilisearch_sync_posts` in chunks of 100 IDs via `as_schedule_single_action(time() + 5, ...)` in group `meilisearch`. The 5 s delay lets REST saves finish writing terms/meta after `save_post`.

### 6.2 Reconciliation job (`meilisearch_sync_posts`)

Args: `{index, ids[], attempt}`. For each ID: reload the post; if indexable → build document; else → mark for deletion. Then one `documents` add-or-replace call and one `documents/delete-batch` call. The job reads current state, so ordering and duplicate jobs are harmless.

- While a reindex is running (§ 6.3), the same writes also go to the temp index.
- On `ApiError` or transport failure: reschedule the same payload with `attempt + 1` after 1 m, 5 m, 30 m, 2 h, 6 h. After attempt 5, record in `ErrorLog` and throw so AS marks the action failed.
- Jobs do not wait on Meilisearch tasks (fire-and-forget); failed tasks surface through Site Health drift and the Status tab's "recent failed tasks" (`GET /tasks?statuses=failed&indexUids=...`).

`meilisearch_sync_term` args `{taxonomy, term_id, page}`: queries 500 post IDs with that term, feeds them to `ChangeCollector`, chains the next page.

### 6.3 Full reindex

1. Create `{index}_tmp_{run}`; copy the live index's full settings (`GET /settings` → `PATCH`) then merge required settings. If no live index exists, apply initial settings.
2. Record run state in `meilisearch_state` (`run`, `index`, `tmp`, `last_id`, `sent`, `task_uids`, `started_at`, `status`).
3. Batches: indexable posts with `ID > last_id` ordered by `ID`, 200 per batch (filter `meilisearch_reindex_batch_size`). Admin-triggered runs chain `meilisearch_reindex_batch` AS actions; CLI runs loop synchronously with a progress bar.
4. Finalize: wait for all recorded task UIDs; if any failed → mark run failed, delete temp, keep live, log. Otherwise `POST /swap-indexes` (live ↔ temp), wait, delete the old (now temp-named) index, clear run state.
5. Only one run per index at a time; a new request while running is rejected with the current progress.

Result: orphans disappear, Cloud-configured settings are preserved, and search never sees a partial index.

## 7. Admin

### 7.1 Screens

Top-level menu **Meilisearch** (capability `manage_options`; on multisite the Connection tab requires `manage_network_options`):

| Tab | Contents |
|---|---|
| Connection | Host URL, admin key (never echoed; placeholder "•••• saved"; empty submit keeps stored value), index prefix, status line (version, key OK). |
| Content | Post types to index; per post type: taxonomies and meta keys. |
| WooCommerce | Index products; attributes to index (all global by default); custom attributes; variation SKUs. Only shown when WooCommerce is active. |
| Search | Replace site search; highlight excerpts; embedder name + semantic ratio; autocomplete on/off. |
| Status | Per-index doc count vs indexable count, reindex buttons + live progress, recent errors, recent failed Meilisearch tasks. |

Each tab is its own Settings API option group and form, so saving one tab never touches another tab's options. Sanitize callbacks accept `mixed` and are idempotent (WordPress may call them twice on first save).

Buttons call REST routes under `meilisearch/v1` (`POST /connection/test`, `POST /reindex`, `GET /reindex/status`), each with `permission_callback` = `current_user_can('manage_options')` and the standard `wp_rest` nonce. Admin JS is enqueued only on the plugin's screens with a localized config.

### 7.2 Options

| Option | Autoload | Contents |
|---|---|---|
| `meilisearch_connection` | no | host, prefix, search key value + uid |
| `meilisearch_admin_key` | no | admin key |
| `meilisearch_content` | yes | post types, taxonomies, meta keys |
| `meilisearch_woocommerce` | yes | WC toggles, attribute list |
| `meilisearch_search` | yes | replace, highlight, embedder, ratio, autocomplete |
| `meilisearch_state` | no | reindex run state, "needs reindex" flags, schema version |
| `meilisearch_log` | no | last 50 errors |

### 7.3 Connection flow

On saving the Connection tab:

1. Validate the URL (`http`/`https`, host present). On multisite only super admins can reach this form (prevents SSRF from subsite admins).
2. `GET /version` with the admin key (authenticated, unlike `/health`) → reject invalid keys and versions < 1.13 with a clear message.
3. Create the scoped browser key: `POST /keys` with `actions: ["search"]`, `indexes: ["{prefix}_content", "{prefix}_products"]`, `expiresAt: null`, name `WordPress search ({home_url})`. Store value + uid. Delete the previous plugin-created key if one existed.
4. Create missing indexes and apply settings (waits on tasks, max 30 s, failures shown).
5. If host, key or prefix changed: set "needs reindex" and show a notice.

Fallback: if `POST /keys` returns 403, show a "Search key" field; the admin pastes a key. If the admin key can read `GET /keys/{key}`, verify `actions == ["search"]`; otherwise show a warning that the key could not be verified.

## 8. WooCommerce

### 8.1 Compatibility

On `before_woocommerce_init`: `FeaturesUtil::declare_compatibility('custom_order_tables', MEILISEARCH_FILE, true)` and `('cart_checkout_blocks', ..., true)`. The plugin never reads or writes orders.

### 8.2 Product document (`{prefix}_products`)

Only parent products (simple, variable, grouped, external); variations never get their own document. Includes all content-document core fields plus:

| Field | Source |
|---|---|
| `sku`, `variation_skus[]` | product / child variation SKUs (variation SKUs optional) |
| `product_type`, `featured` | `get_type()`, `is_featured()` |
| `price` | `wc_get_price_to_display($product)` computed in a context with the store base location (documented: prices reflect base tax display) |
| `price_min`, `price_max` | variable products: `get_variation_price('min'/'max', true)` |
| `regular_price`, `sale_price`, `on_sale` | as floats / null / bool |
| `in_stock`, `stock_status`, `stock_quantity` | bool / string / int or null |
| `rating_average`, `rating_count`, `total_sales` | numbers |
| `tax_product_cat`, `tax_product_cat_ids` | names / IDs with ancestors |
| `tax_product_tag`, `tax_product_tag_ids` | names / IDs |
| `categories.lvl0/lvl1/lvl2` | `CategoryHierarchy` (arrays when multiple roots), ready for a future hierarchical facet UI |
| `attr_{slug}` | global `pa_*` attribute **term names**, union of parent + all variations |
| `custom_attr_{slug}` | custom (non-taxonomy) attribute values, when enabled |
| `variations[]` | up to 100 × `{id, sku, price, attributes: {slug: name}, in_stock}` for display |

Variations are read via `get_children()` + `wc_get_product()` (not `get_available_variations()`, which is heavy and drops hidden/out-of-stock variations).

### 8.3 Products index settings

- searchable (initial order): `title`, `sku`, `variation_skus`, `attr_*`, `tax_product_cat`, `tax_product_tag`, `excerpt`, `content`
- filterable: `tax_product_cat_ids`, `tax_product_tag_ids`, `attr_*`, `custom_attr_*`, `price`, `in_stock`, `stock_status`, `on_sale`, `featured`, `product_type`, `rating_average`
- sortable: `price`, `total_sales`, `rating_average`, `date`, `title`

### 8.4 Product sync hooks

All feed `ChangeCollector` with the **parent** product ID:

- `woocommerce_new_product`, `woocommerce_update_product` (product trash/delete already arrive through the core `transition_post_status` / `before_delete_post` hooks of § 6.1; WooCommerce 11.x has no dedicated product delete/trash actions)
- `woocommerce_new_product_variation`, `woocommerce_update_product_variation`, `woocommerce_before_delete_product_variation`, `woocommerce_trash_product_variation`
- `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_product_set_stock_status`, `woocommerce_variation_set_stock_status`
- `wp_update_comment_count` for products (rating changes)

To keep checkout bursts from flooding the queue, the collector skips product IDs already queued in the last 60 s, tracked with a short-lived `meilisearch_pending_{id}` transient that the job clears **before** reading the posts (so a change arriving mid-job is never lost). (`as_has_scheduled_action` cannot dedupe here because job args are batches of IDs.) Updating `woocommerce_hide_out_of_stock_items` or catalog visibility settings sets "needs reindex" for products.

### 8.5 Product search

Handled by `Search\Interceptor` when the main query is a search with `post_type=product`:

- `product_visibility` tax clauses added by `WC_Query` are dropped (hidden products are not indexed), except the `featured` term → `featured = true`.
- `orderby`: `price` → `price:asc`, `price-desc` → `price:desc`, `popularity` → `total_sales:desc`, `rating` → `rating_average:desc`, `date` → `date:desc`, `relevance`/default → relevance.
- Layered nav: `filter_{attr}` (comma-separated term slugs → resolved to term names) with `query_type_{attr}` = `and`/`or`; `min_price` / `max_price` → `price` range. Read from the query's vars / request.
- Any other unrecognised product-query constraint → do not intercept.

Shop and category archives without `s` are not intercepted in v1.

## 9. Search

### 9.1 When to intercept

`pre_get_posts` marks a query for interception when **all** hold:

- `$query->is_main_query()`, not `is_admin()`, not a REST request, `is_search()`, and trimmed `s` non-empty;
- the requested post types (or, if none, all `exclude_from_search = false` types) are all indexed;
- "Replace site search" is enabled and the circuit breaker is closed;
- `QueryTranslator` returns a request (not `null`);
- `apply_filters('meilisearch_should_intercept', true, $query)`.

Developers can opt any `WP_Query` in with `'meilisearch' => true` (same translation rules; still falls back when untranslatable).

"Replace site search" defaults **off**. After the first successful full reindex an admin notice offers to enable it. If Relevanssi, SearchWP, ElasticPress or Jetpack Search is active, a conflict notice is shown and the enable prompt is suppressed.

### 9.2 Translation

| WP_Query | Meilisearch |
|---|---|
| `s` | `q` |
| `paged`, `posts_per_page` | `page`, `hitsPerPage` (exact `totalHits`); `-1` → 1000 |
| `orderby` = relevance/none | default ranking |
| `orderby` = `date` / `modified` / `title` (+ `order`) | `sort` |
| `post_type` | `post_type IN [...]` (content index) |
| `author`, `author__in`, `author__not_in` | `author_id` filters |
| `$query->tax_query->queries` (includes `cat`, `tag`, `category_name`, `product_cat`, ...) with `field` = `term_id`/`slug`/`name` (resolved to term IDs), operators `IN`/`NOT IN`/`AND`, `include_children` (default true → `_ids` already include ancestors), nested relations | `tax_{t}_ids` filters with parentheses |
| `meta_query` on indexed keys: `=`, `!=`, `<`, `<=`, `>`, `>=`, `IN`, `NOT IN`, `BETWEEN`, `EXISTS`, `NOT EXISTS` | `meta_{key}` filters |
| `date_query` with only `after` / `before` (string or array form) | `date` range |
| hybrid configured | `hybrid: {embedder, semanticRatio}` |

Anything else (other meta compares, `orderby=meta_value*`, `post__in`, `post_parent`, complex `date_query`, unindexed taxonomies/keys, `fields` other than default, `post_status` other than publish) → `null` → MySQL handles the query unchanged.

### 9.3 `FilterBuilder`

- Field names must match `^[A-Za-z0-9_.]+$` and be in the index's known-filterable set, otherwise translation returns `null`.
- String values: escape `\` → `\\` first, then `"` → `\"`, wrap in double quotes.
- Numbers emitted unquoted via `json_encode`-style formatting (no locale issues, `49.0` stays `49.0`).
- Supports `=`, `!=`, `<`, `<=`, `>`, `>=`, `IN`, `NOT IN`, `TO`, `EXISTS`, `NOT EXISTS`, `AND`, `OR`, `NOT`, parentheses.

### 9.4 Execution

- Content-only → `POST /indexes/{prefix}_content/search`.
- `post_type=product` only → products index (+ `ProductQueryTranslator`).
- Mixed → `POST /multi-search` with `federation: {page, hitsPerPage}` and one query per index (filters per index; `post_type` filter only on content).
- All requests: `attributesToRetrieve: ["id"]` (plus `title`, `content` when highlighting, so `_formatted` is returned).

### 9.5 Results

`ResultMapper` takes hit IDs in order, calls `_prime_post_caches($ids)`, returns `WP_Post` objects via `posts_pre_query`, and sets `$query->found_posts = totalHits` and `$query->max_num_pages = totalPages` (WordPress does not compute these when `posts_pre_query` short-circuits). Hits whose post no longer loads are dropped (defensive; counts unchanged).

### 9.6 Highlighting (optional, default off)

When enabled, intercepted searches add `attributesToCrop: ["content:30"]`, `attributesToHighlight: ["title","content"]`, `highlightPreTag: "<mark>"`, `highlightPostTag: "</mark>"`. `Highlighter` filters `get_the_excerpt` for posts in the current intercepted result set, returning the cropped `_formatted.content` through `wp_kses($html, ['mark' => []])`.

### 9.7 Failure handling

- Search timeout 2 s (filter `meilisearch_search_timeout`).
- Any `ApiError`/timeout → log, open the circuit breaker (transient `meilisearch_circuit_open`, 60 s), return `null` from `posts_pre_query` so WordPress runs its normal MySQL query for this request.

## 10. Frontend autocomplete

- Vanilla JS, no framework, no build step beyond minification; unminified source ships alongside (`assets/js/autocomplete.js` + `.min.js`).
- Off by default; enabled in Search tab. Enqueued on the front end only when enabled and a search key exists.
- Attaches to `form[role=search] input[name=s]` and `input[name=s]` (selector filterable via `meilisearch_autocomplete_selector`).
- On input (debounced 150 ms, ≥ 2 chars): one `POST /multi-search` (non-federated) with two queries — content (`limit` 5) and products (`limit` 5, if enabled) — `attributesToRetrieve` limited to display fields (`id,title,permalink,post_type,thumbnail_url` + `price` for products), `attributesToHighlight: ["title"]` with pre/post tags `\u0002`/`\u0003`.
- Renders grouped results ("Products", "Posts") as links; DOM built with `createElement`/`textContent`; highlight markers split in JS into `<mark>` elements — no index HTML is ever inserted.
- Enter submits the original form (theme results page is canonical).
- Accessibility: WAI-ARIA combobox pattern (`role=combobox`, `aria-expanded`, `aria-controls`, `aria-activedescendant`), arrow/Escape/Enter keys, `aria-live=polite` result count, visible focus styles, respects `prefers-reduced-motion`. Minimal CSS using `currentColor` so it inherits the theme.
- Config via `wp_localize_script`: host, scoped key, index UIDs, limits, translated strings.
- Network failure → dropdown hidden silently; the form keeps working.

## 11. Operations

### 11.1 Activation / deactivation

- Activation: seed default options for the current site (multisite: new sites seeded on `wp_initialize_site`; existing sites seeded lazily on first admin load). No remote calls.
- Deactivation: `as_unschedule_all_actions('', [], 'meilisearch')`. Options and indexes untouched.

### 11.2 WP-CLI (`wp meilisearch`)

| Command | Purpose |
|---|---|
| `status` | Connection, version, per-index doc count vs indexable count, pending/failed actions |
| `reindex [--index=<content\|products>] [--batch-size=<n>]` | Synchronous swap reindex with progress bar |
| `sync <id>...` | Reconcile specific posts now |
| `clear [--index=...] [--yes]` | Delete all documents of an index |
| `check` | Run Site Health checks and print results |

### 11.3 Site Health

Tests: connection + version ≥ 1.13; doc count vs indexable count (warn when drift > 2 %); required settings present; sync backlog (> 500 pending) and failed actions; browser key has only `search` and only this site's indexes; "needs reindex" flag.

### 11.4 Privacy

`wp_add_privacy_policy_content` text: indexed public content is sent to the configured Meilisearch host (self-hosted or Meilisearch Cloud); when autocomplete is enabled, visitors' typed queries are sent from their browser directly to that host. No tracking, no personal data indexed by default (author display names of published content only).

### 11.5 Uninstall (`uninstall.php`)

- For every site (batched `get_sites(['number' => 100, 'offset' => ...])`): delete all `meilisearch_*` options and transients; unschedule AS group `meilisearch`.
- If the opt-in "Delete indexes and search key on uninstall" was enabled: delete both indexes and the plugin-created key using stored credentials (errors ignored).

## 12. Testing

### 12.1 Unit (PHPUnit 9.6 + `yoast/phpunit-polyfills` + Brain Monkey)

Collaborators are injected (notably `Api\Transport`, clock, and WordPress function wrappers where needed) so no PHP internal function is ever mocked (no Patchwork `redefinable-internals`).

- `FilterBuilderTest` — every operator, escaping (`"`, `\`, `\"`), invalid field names, number formatting.
- `QueryTranslatorTest` — each supported arg; each unsupported arg yields `null`.
- `ProductQueryTranslatorTest` — WC orderby, `filter_*`/`query_type_*`, price range, visibility clause dropping.
- `IndexabilityTest` — statuses, passwords, post types, WC visibility, hide-out-of-stock, filter.
- `ContentDocumentBuilderTest`, `ProductDocumentBuilderTest`, `CategoryHierarchyTest`, `AttributeCollectorTest`.
- `SettingsBuilderTest` — required sets; merge preserves existing; append-only searchable.
- `ClientTest` — request method/path/body/headers per call; error mapping; `Task::wait` failure handling.
- `IndexNamesTest`, `OptionsTest` (constants override, admin key never returned for rendering).

### 12.2 Integration (WordPress test suite via `wp-env run tests-cli`, real Meilisearch container)

- `BootTest` — all hooks, routes, CLI commands and AS handlers registered.
- `SyncTest` — publish → indexed; draft/private/pending/password → removed; trash/delete → removed; term rename updates docs; `_edit_lock` updates enqueue nothing.
- `ReindexTest` — swap preserves a Cloud-set synonym and ranking rule; orphans removed; failed task keeps live index.
- `SearchTest` — real `WP_Query` search: order, `found_posts`, `max_num_pages`, tax/meta filters; untranslatable query falls back; Meilisearch stopped → MySQL results + breaker open.
- `WooCommerceTest` — variable product attributes/prices; hidden and out-of-stock handling; stock change resync; product search with orderby and price filter.
- `ConnectionTest` — invalid key rejected; scoped key created with only `search` on the two indexes.
- `MultisiteTest` — two sites, distinct prefixes, independent indexes.

### 12.3 E2E (Playwright + `wp-env`)

Connect and reindex from admin; theme search served by Meilisearch; autocomplete keyboard navigation + axe accessibility scan; WooCommerce product search with sorting and price filter.

### 12.4 CI (GitHub Actions)

- Lint: PHPCS (WordPress Coding Standards), PHPStan level 6 (`szepeviktor/phpstan-wordpress`, `php-stubs/woocommerce-stubs`), `wp plugin check`.
- Unit: PHP 8.1 / 8.3 / 8.5.
- Integration: {WP 6.5, latest} × {Meilisearch 1.13, latest}, plus a WooCommerce-latest job.
- E2E: latest WP + WC + Meilisearch.
- All config files (`phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist` with separate unit/integration bootstraps, `.wp-env.json`, `playwright.config.ts`) are part of the plan, not assumed.

## 13. Release

- **Build:** `composer install --no-dev --optimize-autoloader`; minify autocomplete JS; `.distignore` excludes tests, docs, dotfiles, `bin/`, dev configs; `wp dist-archive`.
- **Version guard:** release job fails unless git tag == `Version:` header == `Stable tag` == `MEILISEARCH_VERSION`.
- **Deploy on tag:** `10up/action-wordpress-plugin-deploy` to wordpress.org SVN (assets from `.wordpress-org/`: banners 772×250 + 1544×500, icons 128 + 256, screenshots); Packagist auto-update (`type: wordpress-plugin`); GitHub Release with the same zip. Tests must pass before deploy.
- **readme.txt:** valid headers (`Requires at least: 6.5`, `Tested up to: 7.1`, `Requires PHP: 8.1`, `Stable tag`, `License`), description, installation, FAQ, **External services** section (what is sent, when, to which host; links to Meilisearch terms and privacy policy), changelog.
- **First submission:** upload the 1.0.0 zip for review from an `@meilisearch.com` wordpress.org account; `Contributors:` lists existing wordpress.org usernames.
- **Docs:** readme + a WordPress integration guide on the Meilisearch documentation site.

## 14. Public extension points

| Hook | Type | Purpose |
|---|---|---|
| `meilisearch_should_index_post` | filter | veto indexing a post |
| `meilisearch_document` | filter | modify a document before sending |
| `meilisearch_document_content` | filter | replace how `content` is built |
| `meilisearch_index_settings` | filter | adjust required settings |
| `meilisearch_should_intercept` | filter | veto search interception |
| `meilisearch_search_params` | filter | modify the Meilisearch request |
| `meilisearch_search_timeout` | filter | search timeout (s) |
| `meilisearch_reindex_batch_size` | filter | reindex batch size |
| `meilisearch_autocomplete_selector` | filter | inputs autocomplete attaches to |

## 15. Open questions

None. Decisions taken during the 2026-09-30 redesign: v1 scope (search + WooCommerce), index model (one content + one products index), own HTTP client on `wp_remote_request` (no SDK), plugin-created scoped search key, autocomplete instead of InstantSearch.
