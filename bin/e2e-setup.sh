#!/usr/bin/env bash
# Installs and configures the E2E WordPress site inside the compose "wordpress" service. Idempotent.
# Usage: docker compose up -d --build --wait db meilisearch wordpress && bash bin/e2e-setup.sh
set -euo pipefail
cd "$(dirname "$0")/.."

SITE_URL="${E2E_SITE_URL:-http://localhost:8080}"
ADMIN_USER="${E2E_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:-password}"

wp() {
	docker compose exec -T -u www-data wordpress wp "$@"
}

ensure_post() { # <post_type> <slug> <title> <content>
	if [ -z "$(wp post list --post_type="$1" --name="$2" --post_status=publish --format=ids)" ]; then
		wp post create --post_type="$1" --post_status=publish --post_name="$2" --post_title="$3" --post_content="$4" --porcelain > /dev/null
	fi
}

ensure_product() { # <name> <regular price>
	if [ "$(wp wc product list --search="$1" --user="$ADMIN_USER" --format=count)" = "0" ]; then
		wp wc product create --name="$1" --regular_price="$2" --status=publish --user="$ADMIN_USER" --porcelain > /dev/null
	fi
}

if ! wp core is-installed > /dev/null 2>&1; then
	wp core install --url="$SITE_URL" --title="Meilisearch E2E" --admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASSWORD" --admin_email="admin@example.com" --skip-email
fi
wp rewrite structure '/%postname%/' --hard

# WooCommerce from wordpress.org, without onboarding redirects or the "coming soon" storefront.
wp plugin is-installed woocommerce || wp plugin install woocommerce
wp plugin activate woocommerce
wp option update woocommerce_coming_soon no
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp transient delete _wc_activation_redirect || true

# A theme with WooCommerce support (Storefront, classic loop): the product search path only runs for such themes.
wp theme is-installed storefront || wp theme install storefront
wp theme activate storefront

wp plugin activate meilisearch

ensure_post post mountain-photography-tips "Mountain Photography Tips" "Golden hour light on alpine ridges and how to meter snow."
ensure_post post baking-sourdough-bread "Baking Sourdough Bread" "Feeding a starter, folding the dough and scoring the loaf."
ensure_post post remote-work-productivity "Remote Work Productivity" "Routines, calendars and deep-work blocks for distributed teams."
ensure_post page search-demo "Search demo" '<!-- wp:search {"label":"Search","buttonText":"Search"} /-->'
ensure_product "Meili Mug Small" 5
ensure_product "Meili Mug Large" 15
ensure_product "Meili Mug Deluxe" 25

# Patch only host and prefix: rewriting the whole option would drop the search key, and every run would create another one.
wp option patch update meilisearch_connection host "http://meilisearch:7700"
wp option patch update meilisearch_connection prefix "e2e"
wp option update meilisearch_admin_key masterKey
wp option update meilisearch_content '{"post_types":["post","page"],"taxonomies":{"post":["category","post_tag"]},"meta_keys":{}}' --format=json
wp option update meilisearch_woocommerce '{"enabled":true,"attributes":null,"custom_attributes":false,"variation_skus":false}' --format=json
wp option update meilisearch_search '{"replace":true,"highlight":false,"embedder":"","semantic_ratio":0.5,"autocomplete":false}' --format=json

# Same steps as saving the Connection tab: version check, scoped search key, indexes and settings.
wp eval '\Meilisearch\WordPress\Plugin::instance()->get( "index_manager" )->connect();'
# Finish whatever a previous run left queued (WP-CLI cannot filter by group here, see tests/e2e/utils.ts), then reindex.
wp action-scheduler run --force --batch-size=100 > /dev/null
wp meilisearch reindex
wp meilisearch status

echo "E2E site ready at ${SITE_URL} (admin: ${ADMIN_USER})."
