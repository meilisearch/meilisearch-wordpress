=== Meilisearch ===
Contributors: meilisearch
Tags: search, woocommerce, autocomplete, product search, meilisearch
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, typo-tolerant search for WordPress and WooCommerce, powered by Meilisearch. Real-time sync, no theme changes, optional autocomplete.

== Description ==

This is the official Meilisearch plugin for WordPress and WooCommerce. It answers your theme's built-in search and WooCommerce product search with [Meilisearch](https://www.meilisearch.com/), without any template change.

* **Relevant, typo-tolerant results.** Visitors who type "mountian photografy" still find "Mountain Photography Tips".
* **Works with every theme.** The plugin replaces the results of the standard WordPress search query, so your search results page, pagination and templates stay the same.
* **WooCommerce product search.** Price, popularity, rating and date sorting, price filters and layered navigation filters work on search results. Products of every type are indexed, including types added by extensions; variations are folded into their parent product; categories, attributes, SKUs, stock and ratings are indexed.
* **Always in sync.** Publishing, editing, unpublishing, trashing or deleting content updates Meilisearch within seconds, through Action Scheduler. Only public content is ever indexed: drafts, private and password-protected posts and hidden products never leave your site. Post types you stop indexing, and all products when product indexing is turned off, are removed from Meilisearch as soon as you save. Renaming an author, or deleting a user and assigning their posts to another author, also updates those posts. Changes are not tracked while the plugin is deactivated, so after you reactivate it the plugin asks for a full reindex.
* **Safe full reindex.** A reindex updates documents in place and then removes documents that no longer exist in WordPress, so search keeps answering during the reindex. Settings you changed in Meilisearch or Meilisearch Cloud (synonyms, ranking rules, embedders) are never overwritten.
* **Graceful fallback.** The normal WordPress database search answers whenever the query cannot be translated to Meilisearch, whenever it includes a post type that is not indexed (the Search tab and Site Health list them), whenever the logged-in user is allowed to read private posts (the index only holds public content), until a full reindex has filled the index (after connecting, after changing the host, admin key, index prefix or site address, after `wp meilisearch clear`, and after you add a post type, taxonomy or custom field on the Content tab), and whenever the circuit breaker is open: if Meilisearch is slow or unavailable, search falls back within 2 seconds and keeps using the database for a minute before trying again.
* **Optional autocomplete.** An accessible as-you-type dropdown (WAI-ARIA combobox, keyboard navigation) attaches to your existing search forms.
* **Optional hybrid search.** Combine keyword and semantic search with an embedder configured in Meilisearch.
* **Built for operations.** WP-CLI commands for large sites, Site Health checks, a Status screen with document counts and errors, multisite support with separate indexes per site.

= Requirements =

WordPress 6.9 or later, PHP 8.1 or later and Meilisearch 1.34 or later. WooCommerce is optional.

= Self-hosted or Meilisearch Cloud =

Use any Meilisearch instance, version 1.34 or later: your own server (Meilisearch is open source) or a [Meilisearch Cloud](https://www.meilisearch.com/cloud) project. Several sites (for example staging and production) can share one instance: each site uses its own index prefix.

The default index prefix is derived from the Site Address stored in **Settings > General** (on multisite, from the network address), so it is the same in the admin, on the front end, in WP-CLI and in cron. Changing that address, even only from `http://` to `https://`, changes the default prefix: save the Connection tab and run a full reindex, or set a prefix on the Connection tab to keep the current one. If your site is served over HTTPS but its stored address still starts with `http://`, correct the address or set a prefix.

= Bundled library =

The plugin bundles [Action Scheduler](https://actionscheduler.org/) (GPL-3.0-or-later), the background job library also used by WooCommerce. Because of this bundled library, the distributed plugin as a whole is licensed under GPL-3.0-or-later.

== Installation ==

1. Install and activate the plugin from **Plugins > Add New**, or upload the zip file.
2. Open **Meilisearch > Connection**, enter the URL of your Meilisearch instance and an **admin** API key, and save. The plugin checks the version, creates the indexes and a search-only key for the browser.
3. Open **Meilisearch > Content** and choose the post types, taxonomies and custom fields to index. With WooCommerce, choose the product options on **Meilisearch > WooCommerce**.
4. Open **Meilisearch > Status** and click **Reindex**.
5. When the first reindex finishes, accept the prompt to **replace site search** (or enable it on **Meilisearch > Search**). Until then, searches keep using the WordPress database. Default theme searches include every searchable post type: index them all for Meilisearch to answer them.

You can also define the connection in `wp-config.php`; the fields then become read-only:

`define( 'MEILISEARCH_HOST', 'https://ms-example.meilisearch.io' );`
`define( 'MEILISEARCH_ADMIN_KEY', 'your-admin-key' );`

After you change these constants, run `wp meilisearch connect` (or save the Connection tab), then reindex. Until then site search uses the WordPress database, autocomplete is off, a reindex is refused, and an admin notice asks you to reconnect. The same applies after the site address changes.

== Frequently Asked Questions ==

= Do I need Meilisearch Cloud? =

No. The plugin works with any Meilisearch instance, version 1.34 or later, including one you host yourself. Meilisearch Cloud is the managed option if you do not want to run a server.

= What data is sent to Meilisearch? =

Only published, public content of the post types you select: title, content, excerpt, permalink, dates, author display name, featured image URL, the taxonomies and custom fields you choose, and for products the product data needed for search (SKU, price, stock, attributes, categories, ratings). Only public content is indexed. Search terms are sent when visitors search; with autocomplete enabled they go from the visitor's browser straight to your Meilisearch host. Nothing is sent before you configure a connection. See "External services" below.

= Does it work with WooCommerce? =

Yes, with WooCommerce 8.5 or later (optional). Product search needs a theme with WooCommerce support or a block theme. Product search results support WooCommerce sorting (price, popularity, rating, date), price filters and attribute filters, and the stock status, category and tag filters of the Product Filters blocks. Product searches filtered by brand or by another product taxonomy are answered by the WordPress database. While the products index waits for a full reindex (for example after you add a global attribute or change the attribute selection), product searches that filter on an attribute are also answered by the database until the reindex has run. The plugin is compatible with High-Performance Order Storage and the cart and checkout blocks; it never reads or writes orders.

= My site is very large. How do I index it? =

Use WP-CLI: `wp meilisearch reindex` indexes synchronously with a progress bar and is not limited by PHP time limits. `wp meilisearch connect` checks the connection, creates the indexes and the browser search key (for sites configured in wp-config.php). Other commands: `wp meilisearch status`, `wp meilisearch sync <id>...`, `wp meilisearch clear` and `wp meilisearch check`. On shared hosting without shell access, the Reindex button runs the same work in the background.

= Can I use it together with another search plugin? =

Do not enable search replacement while Relevanssi, SearchWP, ElasticPress or Jetpack Search also replace search: only one plugin can answer the search query. The plugin shows a notice when it detects one of them; each administrator can dismiss it.

= Does it support semantic (AI) search? =

Yes, as hybrid search. Configure an embedder in Meilisearch (for example in your Meilisearch Cloud project), then enter the embedder name and a semantic ratio on **Meilisearch > Search**.

= What happens if Meilisearch is down? =

Search falls back to the normal WordPress database search within 2 seconds and keeps doing so for 60 seconds (circuit breaker) before trying Meilisearch again. The database also answers queries the plugin cannot translate and searches by users who can read private posts. Content changes are retried for several hours; if Meilisearch is still unreachable after the last retry (about 8.6 hours), the plugin asks for a full reindex in an admin notice, on the Status screen and in Site Health. A reindex retries a step that hits a temporary error three times (after 30 seconds, 2 minutes and 10 minutes, or after 1, 5 and 15 seconds with WP-CLI) before it fails. Site Health reports the problem.

= Is my admin API key exposed? =

No. The admin key is stored without autoloading and is never printed in pages. Browsers only receive a separate key that the plugin creates with the "search" permission on this site's indexes only. A search key pasted by hand is never sent to browsers when it is the admin key or a key Meilisearch does not know (such as the master key). When the admin key can read the details of the pasted key, a key that can do more than search on this site's indexes is not sent either, and Site Health reports it as critical. When the admin key cannot read them, the plugin can only make a partial check: the key is sent to browsers with a warning that it could not be verified, so make sure in Meilisearch that it only allows the "search" action on this site's indexes.

= Why do search results stop at 1,000? =

Meilisearch serves at most 1,000 results per search by default (the `pagination.maxTotalHits` index setting), so result counts and page numbers stop there. If you changed that setting in Meilisearch, return the same value from the `meilisearch_max_total_hits( int $limit, string $logical )` filter. `$logical` is `content` or `products`.

= Is all the text of a long post searchable? =

Only the first 20,000 characters of a post's text (after shortcodes and HTML tags are removed) are indexed, so words after that point do not match searches answered by Meilisearch. To index other text, replace the `content` field in the `meilisearch_document( array $doc, WP_Post $post, string $index )` filter. The `meilisearch_document_content` filter changes the text before the limit applies.

= Do other plugins' search restrictions still apply? =

Not when they are added to the SQL query. Searches answered by Meilisearch do not run the database query, so restrictions that other plugins (for example some multilingual or access-control plugins) add through SQL filters such as `posts_where`, `posts_join` or `posts_clauses` do not apply to them. Return `false` from the `meilisearch_should_intercept( bool $intercept, WP_Query $query )` filter to let the database answer those searches, or keep restricted posts out of the index with the `meilisearch_should_index_post( bool $index, WP_Post $post )` filter.

= How do I show a second line under each autocomplete suggestion? =

Return the name of a top-level document field from the `meilisearch_autocomplete_subtitle_field( ?string $field, string $logical )` filter. `$logical` is `content` or `products`. The field must be in the index's displayed attributes. Its text is shown as plain text under the title.

= WordPress reaches Meilisearch on a private address. How do browsers reach it for autocomplete? =

Autocomplete calls Meilisearch from the visitor's browser with the search-only key, using the configured host by default. If PHP uses a private address (`http://127.0.0.1:7700`, an internal network name), return the public URL from the `meilisearch_autocomplete_host( string $host )` filter.

== External services ==

This plugin connects to a Meilisearch server to index your content and to answer searches. It is required for the plugin to work. The server is chosen by the site administrator: either a self-hosted Meilisearch instance or a Meilisearch Cloud project operated by Meilisearch. No data is sent anywhere until the administrator saves a Meilisearch URL and API key.

What is sent, and when:

* **When content is published, updated, unpublished or deleted, and during a reindex** (from your server): the published, public content of the selected post types — title, content, excerpt, permalink, publication and modification dates, author ID and display name, featured image URL, the selected taxonomy terms and custom fields — and, for WooCommerce products, SKUs, prices, stock status and quantity, attributes, categories, tags, ratings and total sales. Deleted or unpublished items are removed.
* **On each search on your site** (from your server, when "Replace site search" is enabled): the search terms, pagination, sorting and filters of the search.
* **On each keystroke in a search field** (from the visitor's browser, only when autocomplete is enabled): the characters typed, sent directly to the Meilisearch server with the restricted search key. Like any web request, it carries the visitor's IP address and browser information.
* **When the connection, Content or WooCommerce settings are saved and on the Site Health and Status screens** (from your server): requests to check the version, create the search key, create indexes and update their settings, delete the documents of post types or products that are no longer indexed, and read document counts and task status.

Meilisearch Cloud is provided by Meilisearch: [Terms of use](https://www.meilisearch.com/terms-of-use), [Privacy policy](https://www.meilisearch.com/privacy-policy). A self-hosted instance is operated by whoever runs it; its terms and privacy practices are those of that operator.

== Screenshots ==

1. Connection tab: Meilisearch URL, admin key and connection status.
2. Content tab: choose post types, taxonomies and custom fields to index.
3. Status tab: document counts, reindex progress and recent errors.
4. Theme search results served by Meilisearch, with typo tolerance.
5. Accessible autocomplete dropdown on an existing search form.
6. WooCommerce product search sorted by price.

== Changelog ==

= 1.0.0 =
* Highlighted excerpts also work with block themes (the Post Excerpt block no longer strips the highlights).
* Autocomplete: a "See all N results" option submits the search form.
* First release: content and WooCommerce product indexing with real-time sync, in-place reindex with orphan sweep, theme and WooCommerce search replacement with fallback, optional hybrid search, excerpt highlighting and autocomplete, WP-CLI commands, Site Health checks, privacy policy text and multisite support.

== Upgrade Notice ==

= 1.0.0 =
First release.
