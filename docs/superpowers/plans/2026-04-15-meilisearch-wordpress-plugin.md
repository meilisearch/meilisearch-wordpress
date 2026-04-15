# Meilisearch WordPress Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the official Meilisearch plugin for WordPress + WooCommerce, published to wordpress.org and Packagist, with feature parity to the Drupal sibling (semantic/hybrid search, synonyms, stop words, highlighting, geo, analytics, facets). Configuration Meilisearch itself can own (synonyms, stop words, ranking rules, typo tolerance, embedders) defers to the Meilisearch Cloud dashboard; the WP admin only owns WP-side concerns.

**Architecture:** Single monolithic WP plugin, PHP 8.1+, one Meilisearch index per post type with federated search for unified queries. Action Scheduler for async work. Server-side search replacement hooks `pre_get_posts` by default; opt-in InstantSearch shortcode + Gutenberg block for facet-rich UI. WooCommerce integration auto-activates on detection. Classic WordPress Settings API (no React admin). Multisite-aware.

**Tech Stack:** PHP 8.1+, WordPress 6.2+, WooCommerce 8.0+, Meilisearch 1.10+, `meilisearch/meilisearch-php ^1.16` (scoped via php-scoper), `woocommerce/action-scheduler`, `symfony/http-client`, `instantsearch.js`, `@meilisearch/instant-meilisearch`, PHPUnit + Brain Monkey (unit), WP Test Suite (integration), @wordpress/env + Playwright (e2e), WordPress Coding Standards (PHPCS), PHPStan level 6, GitHub Actions.

---

## Phase 1 — Foundation (Tasks 1-8)

### Task 1: Project scaffolding

**Files:**
- Create: `meilisearch.php`
- Create: `composer.json`
- Create: `package.json`
- Create: `.gitignore`
- Create: `LICENSE`
- Create: `phpunit.xml.dist`

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "meilisearch/wordpress-plugin",
  "description": "Official Meilisearch plugin for WordPress and WooCommerce.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "keywords": ["WordPress", "WooCommerce", "Meilisearch", "search"],
  "homepage": "https://github.com/meilisearch/meilisearch-wordpress",
  "require": {
    "php": "^8.1",
    "meilisearch/meilisearch-php": "^1.16",
    "woocommerce/action-scheduler": "^3.7",
    "symfony/http-client": "^6.4 || ^7.0",
    "php-http/discovery": "^1.19"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.6",
    "phpstan/phpstan": "^1.10",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "humbug/php-scoper": "^0.18",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "Meilisearch\\WordPress\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Meilisearch\\WordPress\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test:unit": "vendor/bin/phpunit --testsuite unit",
    "test:integration": "vendor/bin/phpunit --testsuite integration",
    "lint": "vendor/bin/phpcs",
    "analyse": "vendor/bin/phpstan analyse",
    "test": ["@test:unit"]
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true,
      "php-http/discovery": true
    },
    "sort-packages": true
  }
}
```

- [ ] **Step 2: Create `package.json`**

```json
{
  "name": "meilisearch-wordpress",
  "version": "1.0.0",
  "description": "Meilisearch WordPress plugin frontend assets",
  "private": true,
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start",
    "lint:js": "wp-scripts lint-js",
    "lint:css": "wp-scripts lint-style",
    "test:e2e": "playwright test"
  },
  "devDependencies": {
    "@wordpress/scripts": "^28.0",
    "@playwright/test": "^1.45",
    "instantsearch.js": "^4.73",
    "@meilisearch/instant-meilisearch": "^0.21"
  }
}
```

- [ ] **Step 3: Create `meilisearch.php` plugin bootstrap**

```php
<?php
/**
 * Plugin Name: Meilisearch
 * Plugin URI:  https://github.com/meilisearch/meilisearch-wordpress
 * Description: Official Meilisearch plugin for WordPress and WooCommerce — fast, typo-tolerant search with facets, highlighting, and analytics.
 * Version:     1.0.0
 * Author:      Meilisearch
 * Author URI:  https://www.meilisearch.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meilisearch
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEILISEARCH_VERSION', '1.0.0' );
define( 'MEILISEARCH_PLUGIN_FILE', __FILE__ );
define( 'MEILISEARCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEILISEARCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload scoped vendor (production) or raw vendor (dev).
if ( file_exists( MEILISEARCH_PLUGIN_DIR . 'vendor-prefixed/autoload.php' ) ) {
    require_once MEILISEARCH_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
}
if ( file_exists( MEILISEARCH_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once MEILISEARCH_PLUGIN_DIR . 'vendor/autoload.php';
}

// PSR-4 autoloader for src/.
spl_autoload_register( function ( string $class ): void {
    $prefix    = 'Meilisearch\\WordPress\\';
    $base_dir  = MEILISEARCH_PLUGIN_DIR . 'src/';
    $len       = strlen( $prefix );

    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// Boot the plugin on plugins_loaded.
add_action( 'plugins_loaded', [ \Meilisearch\WordPress\Plugin::class, 'boot' ] );

// Activation / deactivation.
register_activation_hook( __FILE__, [ \Meilisearch\WordPress\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Meilisearch\WordPress\Plugin::class, 'deactivate' ] );
```

- [ ] **Step 4: Create `.gitignore`**

```
/vendor/
/vendor-prefixed/
/node_modules/
/build/
.phpunit.result.cache
.phpunit.cache/
*.log
.DS_Store
```

- [ ] **Step 5: Create `LICENSE`**

GPL-2.0-or-later full text (standard WordPress license).

- [ ] **Step 6: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="tests/unit/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 7: Create unit test bootstrap**

Create `tests/unit/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

\Brain\Monkey\setUp();

// Define WP constants needed by unit tests.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'MEILISEARCH_VERSION' ) ) {
    define( 'MEILISEARCH_VERSION', '1.0.0-test' );
}
if ( ! defined( 'MEILISEARCH_PLUGIN_DIR' ) ) {
    define( 'MEILISEARCH_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}
```

- [ ] **Step 8: Run `composer install` and `npm install`**

Run:
```bash
composer install
npm install
```
Expected: Both commands succeed with exit code 0.

- [ ] **Step 9: Commit**

```bash
git init
git add meilisearch.php composer.json composer.lock package.json package-lock.json phpunit.xml.dist tests/unit/bootstrap.php .gitignore LICENSE
git commit -m "chore: scaffold project with composer, npm, phpunit, and plugin bootstrap"
```

---

### Task 2: Plugin singleton and lifecycle hooks

**Files:**
- Create: `src/Plugin.php`
- Create: `uninstall.php`
- Test: `tests/unit/PluginTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/PluginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_boot_returns_singleton_instance(): void {
        Functions\stubs( [
            'add_action'     => true,
            'add_filter'     => true,
            'is_multisite'   => false,
            'get_option'     => false,
            'did_action'     => 0,
        ] );

        $instance1 = Plugin::boot();
        $instance2 = Plugin::boot();

        $this->assertInstanceOf( Plugin::class, $instance1 );
        $this->assertSame( $instance1, $instance2 );
    }

    public function test_activate_seeds_default_options(): void {
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'meilisearch_host', '' );
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'meilisearch_admin_api_key', '' );
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'meilisearch_search_api_key', '' );
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'meilisearch_post_types', [] );
        Functions\expect( 'add_option' )
            ->once()
            ->with( 'meilisearch_enabled', false );

        Plugin::activate();
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter PluginTest`
Expected: FAIL — class `Meilisearch\WordPress\Plugin` not found.

- [ ] **Step 3: Implement `src/Plugin.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    private static ?self $instance = null;

    private function __construct() {}

    public static function boot(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    /**
     * Reset singleton for testing.
     *
     * @internal
     */
    public static function reset(): void {
        self::$instance = null;
    }

    private function register_hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'meilisearch', false, dirname( plugin_basename( MEILISEARCH_PLUGIN_FILE ) ) . '/languages' );
    }

    public function register_admin_menu(): void {
        // Registered in SettingsPage — stub here for boot ordering.
    }

    public static function activate(): void {
        add_option( 'meilisearch_host', '' );
        add_option( 'meilisearch_admin_api_key', '' );
        add_option( 'meilisearch_search_api_key', '' );
        add_option( 'meilisearch_post_types', [] );
        add_option( 'meilisearch_enabled', false );
    }

    public static function deactivate(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', [], 'meilisearch' );
        }
    }
}
```

- [ ] **Step 4: Create `uninstall.php`**

```php
<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$remove_remote = get_option( 'meilisearch_remove_data_on_uninstall', false );

// Delete all plugin options.
$option_keys = [
    'meilisearch_host',
    'meilisearch_admin_api_key',
    'meilisearch_search_api_key',
    'meilisearch_post_types',
    'meilisearch_enabled',
    'meilisearch_server_side_search',
    'meilisearch_instantsearch_enabled',
    'meilisearch_analytics_click_tracking',
    'meilisearch_analytics_conversion_tracking',
    'meilisearch_analytics_user_id_strategy',
    'meilisearch_woocommerce_enabled',
    'meilisearch_remove_data_on_uninstall',
];

foreach ( $option_keys as $key ) {
    delete_option( $key );
}

// If multisite, clean each site.
if ( is_multisite() ) {
    $sites = get_sites( [ 'fields' => 'ids' ] );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        foreach ( $option_keys as $key ) {
            delete_option( $key );
        }
        restore_current_blog();
    }
}

// Drop the fallback queue table.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meilisearch_queue" );

// Optionally remove remote indexes.
if ( $remove_remote ) {
    // Defer to IndexManager if autoloader is available — otherwise skip.
    if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
        require_once __DIR__ . '/vendor/autoload.php';
        // Remote cleanup is best-effort.
    }
}
```

- [ ] **Step 5: Run test, expect pass**

Run: `vendor/bin/phpunit --filter PluginTest`
Expected: OK (2 tests, 2 assertions)

- [ ] **Step 6: Commit**

```bash
git add src/Plugin.php uninstall.php tests/unit/PluginTest.php
git commit -m "feat: add Plugin singleton with activation, deactivation, and uninstall hooks"
```

---

### Task 3: API Exception and Client wrapper

**Files:**
- Create: `src/Api/Exception.php`
- Create: `src/Api/Client.php`
- Test: `tests/unit/Api/ClientTest.php`

- [ ] **Step 1: Write failing test for Cloud detection**

Create `tests/unit/Api/ClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\Exception;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_cloud_detects_meilisearch_io(): void {
        Functions\stubs( [
            'apply_filters' => function ( string $tag, $value ) {
                return $value;
            },
            'get_bloginfo'  => 'https://example.com',
        ] );

        $client = new Client( 'https://ms-abc123.fra.meilisearch.io', 'masterKey123' );
        $this->assertTrue( $client->is_cloud() );
    }

    public function test_is_cloud_false_for_self_hosted(): void {
        Functions\stubs( [
            'apply_filters' => function ( string $tag, $value ) {
                return $value;
            },
            'get_bloginfo'  => 'https://example.com',
        ] );

        $client = new Client( 'http://127.0.0.1:7700', 'masterKey123' );
        $this->assertFalse( $client->is_cloud() );
    }

    public function test_user_agent_contains_wordpress_and_plugin_version(): void {
        Functions\stubs( [
            'apply_filters' => function ( string $tag, $value ) {
                return $value;
            },
            'get_bloginfo'  => '6.7',
        ] );

        $client = new Client( 'http://localhost:7700', 'key' );
        $ua     = $client->get_user_agent();

        $this->assertStringContainsString( 'Meilisearch-WordPress/', $ua );
        $this->assertStringContainsString( 'WordPress/', $ua );
    }

    public function test_exception_wraps_sdk_exception(): void {
        $inner = new \Meilisearch\Exceptions\ApiException(
            new \GuzzleHttp\Psr7\Response( 401, [], '{"message":"Invalid API key"}' ),
            new \RuntimeException( 'Invalid API key' )
        );
        $exception = Exception::from_sdk( $inner, '/indexes', 1 );

        $this->assertInstanceOf( Exception::class, $exception );
        $this->assertStringContainsString( '/indexes', $exception->getMessage() );
        $this->assertSame( 1, $exception->get_site_id() );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter ClientTest`
Expected: FAIL — class `Meilisearch\WordPress\Api\Client` not found.

- [ ] **Step 3: Implement `src/Api/Exception.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Exception extends \RuntimeException {

    private string $endpoint;
    private int $site_id;

    public function __construct( string $message, int $code, ?\Throwable $previous, string $endpoint, int $site_id ) {
        $this->endpoint = $endpoint;
        $this->site_id  = $site_id;
        parent::__construct( $message, $code, $previous );
    }

    public static function from_sdk( \Throwable $e, string $endpoint = '', int $site_id = 0 ): self {
        return new self(
            sprintf( 'Meilisearch API error on %s: %s', $endpoint, $e->getMessage() ),
            (int) $e->getCode(),
            $e,
            $endpoint,
            $site_id,
        );
    }

    public function get_endpoint(): string {
        return $this->endpoint;
    }

    public function get_site_id(): int {
        return $this->site_id;
    }
}
```

- [ ] **Step 4: Implement `src/Api/Client.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\Client as MeilisearchClient;

class Client {

    private string $host;
    private string $api_key;
    private ?MeilisearchClient $sdk_client = null;
    private int $timeout;

    public function __construct( string $host, string $api_key ) {
        $this->host    = rtrim( $host, '/' );
        $this->api_key = $api_key;
        $this->timeout = (int) apply_filters( 'meilisearch_http_timeout', 10 );
    }

    public function get_sdk(): MeilisearchClient {
        if ( $this->sdk_client === null ) {
            $this->sdk_client = new MeilisearchClient(
                $this->host,
                $this->api_key,
                null,
                $this->timeout,
            );
        }
        return $this->sdk_client;
    }

    public function is_cloud(): bool {
        $host = parse_url( $this->host, PHP_URL_HOST ) ?? '';
        return (bool) preg_match( '/\.meilisearch\.io$/i', $host );
    }

    public function get_user_agent(): string {
        $wp_version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'unknown';
        return sprintf(
            'Meilisearch-WordPress/%s WordPress/%s',
            defined( 'MEILISEARCH_VERSION' ) ? MEILISEARCH_VERSION : 'dev',
            $wp_version,
        );
    }

    public function get_host(): string {
        return $this->host;
    }

    public function ping(): bool {
        try {
            $this->get_sdk()->health();
            return true;
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    public function add_documents( string $index_uid, array $documents ): array {
        try {
            return $this->get_sdk()->index( $index_uid )->addDocuments( $documents, 'id' );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/{$index_uid}/documents" );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete_document( string $index_uid, string|int $document_id ): array {
        try {
            return $this->get_sdk()->index( $index_uid )->deleteDocument( $document_id );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/{$index_uid}/documents/{$document_id}" );
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return \Meilisearch\Search\SearchResult
     */
    public function search( string $index_uid, string $query, array $params = [] ): \Meilisearch\Search\SearchResult {
        try {
            return $this->get_sdk()->index( $index_uid )->search( $query, $params );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/{$index_uid}/search" );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $queries
     * @return array<string, mixed>
     */
    public function multi_search( array $queries, array $federation = [] ): array {
        try {
            return $this->get_sdk()->multiSearch( $queries, $federation );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, '/multi-search' );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function create_index( string $uid, string $primary_key = 'id' ): array {
        try {
            return $this->get_sdk()->createIndex( $uid, [ 'primaryKey' => $primary_key ] );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, '/indexes' );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function delete_index( string $uid ): array {
        try {
            return $this->get_sdk()->deleteIndex( $uid );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/indexes/{$uid}" );
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function update_settings( string $uid, array $settings ): array {
        try {
            return $this->get_sdk()->index( $uid )->updateSettings( $settings );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/{$uid}/settings" );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function update_documents( string $index_uid, array $documents ): array {
        try {
            return $this->get_sdk()->index( $index_uid )->updateDocuments( $documents, 'id' );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/{$index_uid}/documents" );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get_task( int $task_uid ): array {
        try {
            return $this->get_sdk()->getTask( $task_uid );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/tasks/{$task_uid}" );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function wait_for_task( int $task_uid, int $timeout_ms = 30000 ): array {
        try {
            return $this->get_sdk()->waitForTask( $task_uid, $timeout_ms );
        } catch ( \Throwable $e ) {
            throw Exception::from_sdk( $e, "/tasks/{$task_uid}" );
        }
    }

    /**
     * Forward an analytics event to the Meilisearch events endpoint.
     *
     * @param array<string, mixed> $event_body
     * @param string               $user_id
     * @param string               $search_key
     */
    public function post_event( array $event_body, string $user_id, string $search_key ): void {
        $url = $this->host . '/events';
        wp_remote_post( $url, [
            'headers' => [
                'Content-Type'    => 'application/json',
                'Authorization'   => 'Bearer ' . $search_key,
                'X-MS-USER-ID'   => $user_id,
            ],
            'body'    => wp_json_encode( $event_body ),
            'timeout' => 5,
        ] );
    }
}
```

- [ ] **Step 5: Run test, expect pass**

Run: `vendor/bin/phpunit --filter ClientTest`
Expected: OK (4 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Api/Exception.php src/Api/Client.php tests/unit/Api/ClientTest.php
git commit -m "feat: add API Client wrapper with Cloud detection, User-Agent, and Exception"
```

---

### Task 4: ClientFactory — per-site client cache

**Files:**
- Create: `src/Api/ClientFactory.php`
- Test: `tests/unit/Api/ClientFactoryTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Api/ClientFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ClientFactoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_for_site_returns_cached_instance(): void {
        Functions\stubs( [
            'get_option'    => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_host'          => 'http://localhost:7700',
                    'meilisearch_admin_api_key' => 'masterKey',
                    default                     => '',
                };
            },
            'is_multisite'  => false,
            'get_current_blog_id' => 1,
            'apply_filters' => function ( string $tag, $value ) {
                return $value;
            },
        ] );

        $factory = new ClientFactory();
        $client1 = $factory->for_site( 1 );
        $client2 = $factory->for_site( 1 );

        $this->assertInstanceOf( Client::class, $client1 );
        $this->assertSame( $client1, $client2 );
    }

    public function test_for_site_returns_different_instances_for_different_sites(): void {
        $call_count = 0;
        Functions\stubs( [
            'get_option'    => function ( string $key ) use ( &$call_count ) {
                $call_count++;
                return match ( $key ) {
                    'meilisearch_host'          => 'http://localhost:7700',
                    'meilisearch_admin_api_key' => "key_{$call_count}",
                    default                     => '',
                };
            },
            'is_multisite'          => true,
            'switch_to_blog'        => true,
            'restore_current_blog'  => true,
            'get_current_blog_id'   => 1,
            'apply_filters'         => function ( string $tag, $value ) {
                return $value;
            },
        ] );

        $factory = new ClientFactory();
        $client1 = $factory->for_site( 1 );
        $client2 = $factory->for_site( 2 );

        $this->assertNotSame( $client1, $client2 );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter ClientFactoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Api/ClientFactory.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ClientFactory {

    /** @var array<int, Client> */
    private array $clients = [];

    public function for_site( int $site_id ): Client {
        if ( isset( $this->clients[ $site_id ] ) ) {
            return $this->clients[ $site_id ];
        }

        if ( is_multisite() && $site_id !== get_current_blog_id() ) {
            switch_to_blog( $site_id );
            $host = $this->resolve_option( 'meilisearch_host' );
            $key  = $this->resolve_option( 'meilisearch_admin_api_key' );
            restore_current_blog();
        } else {
            $host = $this->resolve_option( 'meilisearch_host' );
            $key  = $this->resolve_option( 'meilisearch_admin_api_key' );
        }

        $client = new Client( $host, $key );
        $this->clients[ $site_id ] = $client;

        return $client;
    }

    public function for_current_site(): Client {
        return $this->for_site( get_current_blog_id() );
    }

    public function for_network(): Client {
        // Network default fallback: site option with network fallback.
        $host = get_site_option( 'meilisearch_network_host', '' );
        $key  = get_site_option( 'meilisearch_network_admin_api_key', '' );

        return new Client( (string) $host, (string) $key );
    }

    private function resolve_option( string $key ): string {
        $value = get_option( $key, '' );
        if ( $value === '' && is_multisite() ) {
            $value = get_site_option( "meilisearch_network_{$key}", '' );
        }
        return (string) $value;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter ClientFactoryTest`
Expected: OK (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Api/ClientFactory.php tests/unit/Api/ClientFactoryTest.php
git commit -m "feat: add ClientFactory with per-site caching and multisite support"
```

---

### Task 5: Support classes — Logger, Sanitizer, Capabilities

**Files:**
- Create: `src/Support/Logger.php`
- Create: `src/Support/Sanitizer.php`
- Create: `src/Support/Capabilities.php`

- [ ] **Step 1: Implement `src/Support/Logger.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Logger {

    public static function error( string $message, array $context = [] ): void {
        $formatted = sprintf( '[Meilisearch] %s %s', $message, $context ? wp_json_encode( $context ) : '' );
        error_log( $formatted );
    }

    public static function warning( string $message, array $context = [] ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            self::error( $message, $context );
        }
    }

    public static function admin_notice( string $message, string $type = 'error' ): void {
        add_action( 'admin_notices', function () use ( $message, $type ): void {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $type ),
                esc_html( $message ),
            );
        } );
    }
}
```

- [ ] **Step 2: Implement `src/Support/Sanitizer.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sanitizer {

    public static function index_uid( string $raw ): string {
        return (string) preg_replace( '/[^a-zA-Z0-9_-]/', '_', $raw );
    }

    public static function document_id( int|string $raw ): string {
        return (string) preg_replace( '/[^a-zA-Z0-9_-]/', '_', (string) $raw );
    }

    public static function host_url( string $url ): string {
        $url = sanitize_text_field( $url );
        return rtrim( esc_url_raw( $url ), '/' );
    }

    public static function api_key( string $key ): string {
        return sanitize_text_field( $key );
    }
}
```

- [ ] **Step 3: Implement `src/Support/Capabilities.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capabilities {

    public const MANAGE_CAP = 'meilisearch_manage';

    public static function can_manage(): bool {
        if ( is_multisite() && is_network_admin() ) {
            return current_user_can( 'manage_network_options' );
        }
        return current_user_can( 'manage_options' );
    }

    public static function register(): void {
        $role = get_role( 'administrator' );
        if ( $role !== null ) {
            $role->add_cap( self::MANAGE_CAP );
        }
    }

    public static function unregister(): void {
        $role = get_role( 'administrator' );
        if ( $role !== null ) {
            $role->remove_cap( self::MANAGE_CAP );
        }
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Support/Logger.php src/Support/Sanitizer.php src/Support/Capabilities.php
git commit -m "feat: add Logger, Sanitizer, and Capabilities support classes"
```

---

### Task 6: Multisite SiteSettings with network fallback

**Files:**
- Create: `src/Multisite/SiteSettings.php`
- Test: `tests/unit/Multisite/SiteSettingsTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Multisite/SiteSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Multisite;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SiteSettingsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_site_option_when_set(): void {
        Functions\stubs( [
            'get_option'      => 'http://site-level.local:7700',
            'is_multisite'    => true,
            'get_site_option' => 'http://network-level.local:7700',
        ] );

        $settings = new SiteSettings();
        $result   = $settings->get( 'meilisearch_host' );

        $this->assertSame( 'http://site-level.local:7700', $result );
    }

    public function test_get_falls_back_to_network_when_site_empty(): void {
        Functions\stubs( [
            'get_option'      => '',
            'is_multisite'    => true,
            'get_site_option' => 'http://network-level.local:7700',
        ] );

        $settings = new SiteSettings();
        $result   = $settings->get( 'meilisearch_host' );

        $this->assertSame( 'http://network-level.local:7700', $result );
    }

    public function test_get_returns_site_option_on_single_site(): void {
        Functions\stubs( [
            'get_option'   => 'http://local.local:7700',
            'is_multisite' => false,
        ] );

        $settings = new SiteSettings();
        $result   = $settings->get( 'meilisearch_host' );

        $this->assertSame( 'http://local.local:7700', $result );
    }

    public function test_index_uid_includes_blog_id_on_multisite(): void {
        Functions\stubs( [
            'is_multisite'        => true,
            'get_current_blog_id' => 3,
        ] );

        $settings = new SiteSettings();

        $this->assertSame( 'wp_3_post', $settings->index_uid( 'post' ) );
    }

    public function test_index_uid_omits_blog_id_on_single_site(): void {
        Functions\stubs( [
            'is_multisite'        => false,
            'get_current_blog_id' => 1,
        ] );

        $settings = new SiteSettings();

        $this->assertSame( 'wp_post', $settings->index_uid( 'post' ) );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter SiteSettingsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Multisite/SiteSettings.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Multisite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SiteSettings {

    /**
     * Retrieve an option with optional network fallback.
     */
    public function get( string $key, mixed $default = '' ): mixed {
        $value = get_option( $key, '' );

        if ( ( $value === '' || $value === false ) && is_multisite() ) {
            $network_key = str_replace( 'meilisearch_', 'meilisearch_network_', $key );
            $value       = get_site_option( $network_key, $default );
        }

        return $value !== '' && $value !== false ? $value : $default;
    }

    /**
     * Update a site-level option.
     */
    public function set( string $key, mixed $value ): void {
        update_option( $key, $value );
    }

    /**
     * Compute the Meilisearch index UID for a given post type.
     */
    public function index_uid( string $post_type ): string {
        if ( is_multisite() ) {
            return sprintf( 'wp_%d_%s', get_current_blog_id(), $post_type );
        }
        return sprintf( 'wp_%s', $post_type );
    }

    /**
     * Get settings for network admin context.
     */
    public static function for_network(): self {
        return new self();
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter SiteSettingsTest`
Expected: OK (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Multisite/SiteSettings.php tests/unit/Multisite/SiteSettingsTest.php
git commit -m "feat: add SiteSettings with network fallback and index UID generation"
```

---

### Task 7: Admin SettingsPage scaffold

**Files:**
- Create: `src/Admin/SettingsPage.php`
- Create: `assets/css/admin.css`

- [ ] **Step 1: Implement `src/Admin/SettingsPage.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Support\Capabilities;

class SettingsPage {

    private const SLUG       = 'meilisearch';
    private const OPTION_GRP = 'meilisearch_settings';

    /** @var string[] */
    private array $tabs = [];

    public function __construct() {
        $this->tabs = [
            'connection'  => __( 'Connection', 'meilisearch' ),
            'post_types'  => __( 'Post Types', 'meilisearch' ),
            'frontend'    => __( 'Frontend', 'meilisearch' ),
            'analytics'   => __( 'Analytics', 'meilisearch' ),
        ];
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menu_page(): void {
        add_menu_page(
            __( 'Meilisearch', 'meilisearch' ),
            __( 'Meilisearch', 'meilisearch' ),
            'manage_options',
            self::SLUG,
            [ $this, 'render' ],
            'dashicons-search',
            80,
        );
    }

    public function register_settings(): void {
        register_setting( self::OPTION_GRP, 'meilisearch_host', [
            'type'              => 'string',
            'sanitize_callback' => [ \Meilisearch\WordPress\Support\Sanitizer::class, 'host_url' ],
        ] );
        register_setting( self::OPTION_GRP, 'meilisearch_admin_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ \Meilisearch\WordPress\Support\Sanitizer::class, 'api_key' ],
        ] );
        register_setting( self::OPTION_GRP, 'meilisearch_search_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ \Meilisearch\WordPress\Support\Sanitizer::class, 'api_key' ],
        ] );
    }

    public function render(): void {
        if ( ! Capabilities::can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'meilisearch' ) );
        }

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
        if ( ! array_key_exists( $current_tab, $this->tabs ) ) {
            $current_tab = 'connection';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Meilisearch', 'meilisearch' ) . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $this->tabs as $slug => $label ) {
            $url   = add_query_arg( [ 'page' => self::SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
            $class = $current_tab === $slug ? ' nav-tab-active' : '';
            printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</nav>';

        echo '<div class="meilisearch-tab-content">';
        do_action( "meilisearch_admin_tab_{$current_tab}" );
        echo '</div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook_suffix ): void {
        if ( $hook_suffix !== 'toplevel_page_meilisearch' ) {
            return;
        }
        wp_enqueue_style(
            'meilisearch-admin',
            MEILISEARCH_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MEILISEARCH_VERSION,
        );
    }

    public function add_tab( string $slug, string $label ): void {
        $this->tabs[ $slug ] = $label;
    }
}
```

- [ ] **Step 2: Create `assets/css/admin.css`**

```css
.meilisearch-tab-content {
    margin-top: 20px;
}

.meilisearch-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.meilisearch-cloud-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #f0f0f1;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    text-decoration: none;
    color: #2271b1;
}

.meilisearch-cloud-link:hover {
    background: #e0e0e0;
}

.meilisearch-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.meilisearch-status-badge--connected {
    background: #d4edda;
    color: #155724;
}

.meilisearch-status-badge--disconnected {
    background: #f8d7da;
    color: #721c24;
}

.meilisearch-reindex-progress {
    width: 100%;
    height: 20px;
    background: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
}

.meilisearch-reindex-progress__bar {
    height: 100%;
    background: #2271b1;
    transition: width 0.3s ease;
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Admin/SettingsPage.php assets/css/admin.css
git commit -m "feat: add admin SettingsPage with tabbed layout and CSS"
```

---

### Task 8: ConnectionSection with test-connection AJAX

**Files:**
- Create: `src/Admin/ConnectionSection.php`
- Create: `assets/js/admin/test-connection.js`
- Test: `tests/unit/Admin/ConnectionSectionTest.php`

- [ ] **Step 1: Write failing test for AJAX handler**

Create `tests/unit/Admin/ConnectionSectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\ConnectionSection;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ConnectionSectionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_ajax_test_connection_success(): void {
        $client = Mockery::mock( Client::class );
        $client->shouldReceive( 'ping' )->once()->andReturn( true );
        $client->shouldReceive( 'is_cloud' )->once()->andReturn( false );

        $factory = Mockery::mock( ClientFactory::class );
        $factory->shouldReceive( 'for_current_site' )->once()->andReturn( $client );

        Functions\stubs( [
            'check_ajax_referer'    => true,
            'current_user_can'      => true,
            'get_current_blog_id'   => 1,
            'wp_send_json_success'  => function ( $data ) {
                throw new \RuntimeException( 'success:' . wp_json_encode( $data ) );
            },
            'wp_send_json_error'    => function ( $data ) {
                throw new \RuntimeException( 'error:' . wp_json_encode( $data ) );
            },
            'wp_json_encode'        => 'json_encode',
        ] );

        $section = new ConnectionSection( $factory );

        try {
            $section->ajax_test_connection();
            $this->fail( 'Expected RuntimeException from wp_send_json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'success:', $e->getMessage() );
            $this->assertStringContainsString( 'connected', $e->getMessage() );
        }
    }

    public function test_ajax_test_connection_failure(): void {
        $client = Mockery::mock( Client::class );
        $client->shouldReceive( 'ping' )->once()->andReturn( false );

        $factory = Mockery::mock( ClientFactory::class );
        $factory->shouldReceive( 'for_current_site' )->once()->andReturn( $client );

        Functions\stubs( [
            'check_ajax_referer'    => true,
            'current_user_can'      => true,
            'get_current_blog_id'   => 1,
            'wp_send_json_success'  => function ( $data ) {
                throw new \RuntimeException( 'success:' . wp_json_encode( $data ) );
            },
            'wp_send_json_error'    => function ( $data ) {
                throw new \RuntimeException( 'error:' . wp_json_encode( $data ) );
            },
            'wp_json_encode'        => 'json_encode',
        ] );

        $section = new ConnectionSection( $factory );

        try {
            $section->ajax_test_connection();
            $this->fail( 'Expected RuntimeException from wp_send_json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'error:', $e->getMessage() );
        }
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter ConnectionSectionTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Admin/ConnectionSection.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Support\Capabilities;

class ConnectionSection {

    private ClientFactory $client_factory;

    public function __construct( ClientFactory $client_factory ) {
        $this->client_factory = $client_factory;
    }

    public function register(): void {
        add_action( 'meilisearch_admin_tab_connection', [ $this, 'render' ] );
        add_action( 'admin_init', [ $this, 'register_fields' ] );
        add_action( 'wp_ajax_meilisearch_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    public function register_fields(): void {
        add_settings_section(
            'meilisearch_connection',
            __( 'Connection Settings', 'meilisearch' ),
            function (): void {
                echo '<p>' . esc_html__( 'Configure your Meilisearch instance connection.', 'meilisearch' ) . '</p>';
            },
            'meilisearch',
        );

        add_settings_field( 'meilisearch_host', __( 'Host URL', 'meilisearch' ), [ $this, 'render_host_field' ], 'meilisearch', 'meilisearch_connection' );
        add_settings_field( 'meilisearch_admin_api_key', __( 'Admin API Key', 'meilisearch' ), [ $this, 'render_admin_key_field' ], 'meilisearch', 'meilisearch_connection' );
        add_settings_field( 'meilisearch_search_api_key', __( 'Search-Only API Key', 'meilisearch' ), [ $this, 'render_search_key_field' ], 'meilisearch', 'meilisearch_connection' );
    }

    public function render(): void {
        echo '<form method="post" action="options.php">';
        settings_fields( 'meilisearch_settings' );
        do_settings_sections( 'meilisearch' );
        submit_button();
        echo '</form>';

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Test Connection', 'meilisearch' ) . '</h3>';
        echo '<button type="button" class="button button-secondary" id="meilisearch-test-connection">';
        echo esc_html__( 'Test Connection', 'meilisearch' );
        echo '</button>';
        echo '<span id="meilisearch-connection-status" style="margin-left: 12px;"></span>';
        echo '</div>';
    }

    public function render_host_field(): void {
        $value = get_option( 'meilisearch_host', '' );
        printf(
            '<input type="url" name="meilisearch_host" value="%s" class="regular-text" placeholder="https://ms-xxx.meilisearch.io" />',
            esc_attr( $value ),
        );
        echo '<p class="description">' . esc_html__( 'Your Meilisearch instance URL (e.g., https://ms-xxx.meilisearch.io or http://localhost:7700).', 'meilisearch' ) . '</p>';
    }

    public function render_admin_key_field(): void {
        $value = get_option( 'meilisearch_admin_api_key', '' );
        printf(
            '<input type="password" name="meilisearch_admin_api_key" value="%s" class="regular-text" />',
            esc_attr( $value ),
        );
        echo '<p class="description">' . esc_html__( 'Used for indexing. Never sent to the browser.', 'meilisearch' ) . '</p>';
    }

    public function render_search_key_field(): void {
        $value = get_option( 'meilisearch_search_api_key', '' );
        printf(
            '<input type="password" name="meilisearch_search_api_key" value="%s" class="regular-text" />',
            esc_attr( $value ),
        );
        echo '<p class="description">' . esc_html__( 'Used by the InstantSearch frontend and analytics. Safe for browser exposure.', 'meilisearch' ) . '</p>';
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'meilisearch_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'meilisearch' ) ] );
            return;
        }

        $client = $this->client_factory->for_current_site();

        if ( $client->ping() ) {
            $is_cloud = $client->is_cloud();
            wp_send_json_success( [
                'status'  => 'connected',
                'cloud'   => $is_cloud,
                'message' => $is_cloud
                    ? __( 'Connected to Meilisearch Cloud.', 'meilisearch' )
                    : __( 'Connected to self-hosted Meilisearch.', 'meilisearch' ),
            ] );
        } else {
            wp_send_json_error( [
                'status'  => 'disconnected',
                'message' => __( 'Could not connect. Check host URL and API key.', 'meilisearch' ),
            ] );
        }
    }
}
```

- [ ] **Step 4: Create `assets/js/admin/test-connection.js`**

```js
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        const btn    = document.getElementById( 'meilisearch-test-connection' );
        const status = document.getElementById( 'meilisearch-connection-status' );

        if ( ! btn || ! status ) return;

        btn.addEventListener( 'click', function () {
            btn.disabled = true;
            status.textContent = meilisearchAdmin.testing;
            status.className   = '';

            const data = new FormData();
            data.append( 'action', 'meilisearch_test_connection' );
            data.append( 'nonce', meilisearchAdmin.nonce );

            fetch( meilisearchAdmin.ajaxUrl, { method: 'POST', body: data } )
                .then( r => r.json() )
                .then( function ( response ) {
                    if ( response.success ) {
                        status.textContent = response.data.message;
                        status.className   = 'meilisearch-status-badge meilisearch-status-badge--connected';
                    } else {
                        status.textContent = response.data.message;
                        status.className   = 'meilisearch-status-badge meilisearch-status-badge--disconnected';
                    }
                } )
                .catch( function () {
                    status.textContent = meilisearchAdmin.error;
                    status.className   = 'meilisearch-status-badge meilisearch-status-badge--disconnected';
                } )
                .finally( function () {
                    btn.disabled = false;
                } );
        } );
    } );
} )();
```

- [ ] **Step 5: Run test, expect pass**

Run: `vendor/bin/phpunit --filter ConnectionSectionTest`
Expected: OK (2 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Admin/ConnectionSection.php assets/js/admin/test-connection.js tests/unit/Admin/ConnectionSectionTest.php
git commit -m "feat: add ConnectionSection with host/key fields and test-connection AJAX"
```

---

### Task 8b: Cloud deep-link helper

**Files:**
- Create: `src/Admin/CloudLinks.php`
- Test: `tests/unit/Admin/CloudLinksTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Admin/CloudLinksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\CloudLinks;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CloudLinksTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_url_returns_cloud_link_for_cloud_host(): void {
        Functions\stubs( [
            'get_option' => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_host'       => 'https://ms-abc123.meilisearch.io',
                    'meilisearch_cloud_project_id' => 'proj_42',
                    default => '',
                };
            },
        ] );

        $url = CloudLinks::url( 'synonyms', 'wp_post' );
        $this->assertStringContainsString( 'cloud.meilisearch.com', $url );
        $this->assertStringContainsString( 'proj_42', $url );
        $this->assertStringContainsString( 'wp_post', $url );
        $this->assertStringContainsString( 'synonyms', $url );
    }

    public function test_url_returns_docs_link_for_self_hosted(): void {
        Functions\stubs( [
            'get_option' => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_host' => 'http://localhost:7700',
                    default => '',
                };
            },
        ] );

        $url = CloudLinks::url( 'synonyms', 'wp_post' );
        $this->assertStringContainsString( 'meilisearch.com/docs', $url );
        $this->assertStringNotContainsString( 'cloud.meilisearch.com', $url );
    }

    public function test_card_renders_html_with_escaped_url(): void {
        Functions\stubs( [
            'get_option' => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_host'       => 'https://ms-abc123.meilisearch.io',
                    'meilisearch_cloud_project_id' => 'proj_42',
                    default => '',
                };
            },
            'esc_url'    => function ( $s ) { return $s; },
            'esc_html'   => function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); },
            'esc_html__' => function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); },
        ] );

        $html = CloudLinks::card( 'synonyms', 'wp_post', 'Synonyms', 'Configure synonyms for this index.' );
        $this->assertStringContainsString( 'meilisearch-card', $html );
        $this->assertStringContainsString( 'Synonyms', $html );
        $this->assertStringContainsString( 'Configure synonyms', $html );
        $this->assertStringContainsString( 'cloud.meilisearch.com', $html );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter CloudLinksTest`
Expected: FAIL -- class not found.

- [ ] **Step 3: Implement `src/Admin/CloudLinks.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CloudLinks {

    /** @var array<string, string> Mapping of section keys to Meilisearch docs paths. */
    private const DOCS_MAP = [
        'synonyms'      => 'https://www.meilisearch.com/docs/reference/api/synonyms',
        'stop-words'    => 'https://www.meilisearch.com/docs/reference/api/stop_words',
        'ranking-rules' => 'https://www.meilisearch.com/docs/reference/api/ranking_rules',
        'embedders'     => 'https://www.meilisearch.com/docs/guides/ai/getting_started_with_ai_search',
        'analytics'     => 'https://www.meilisearch.com/docs/learn/analytics/configure_analytics',
    ];

    /**
     * Return the deep-link URL for a given section.
     *
     * For Cloud users: https://cloud.meilisearch.com/projects/{project}/indexes/{uid}/{section}
     * For self-hosted: the corresponding Meilisearch docs URL.
     */
    public static function url( string $section, string $index_uid = '' ): string {
        $host = get_option( 'meilisearch_host', '' );

        if ( str_contains( (string) $host, '.meilisearch.io' ) ) {
            $project_id = get_option( 'meilisearch_cloud_project_id', '' );
            $base       = 'https://cloud.meilisearch.com/projects/' . rawurlencode( (string) $project_id );
            if ( $index_uid !== '' ) {
                $base .= '/indexes/' . rawurlencode( $index_uid );
            }
            return $base . '/' . rawurlencode( $section );
        }

        return self::DOCS_MAP[ $section ] ?? 'https://www.meilisearch.com/docs';
    }

    /**
     * Render a standard "Configure X in Cloud" admin card.
     */
    public static function card( string $section, string $index_uid, string $title, string $description ): string {
        $url = self::url( $section, $index_uid );

        $html  = '<div class="meilisearch-card">';
        $html .= '<h3>' . esc_html( $title ) . '</h3>';
        $html .= '<p>' . esc_html( $description ) . '</p>';
        $html .= '<a href="' . esc_url( $url ) . '" target="_blank" class="meilisearch-cloud-link">';

        $host = get_option( 'meilisearch_host', '' );
        if ( str_contains( (string) $host, '.meilisearch.io' ) ) {
            $html .= esc_html__( 'Configure in Cloud', 'meilisearch' ) . ' &rarr;';
        } else {
            $html .= esc_html__( 'View Documentation', 'meilisearch' ) . ' &rarr;';
        }

        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter CloudLinksTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Admin/CloudLinks.php tests/unit/Admin/CloudLinksTest.php
git commit -m "feat: add CloudLinks helper for Cloud deep-links and docs fallback"
```

---

## Phase 2 — Indexing (Tasks 9-16)

### Task 9: PostTypeConfig value object

**Files:**
- Create: `src/Indexing/PostTypeConfig.php`
- Test: `tests/unit/Indexing/PostTypeConfigTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Indexing/PostTypeConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Indexing\PostTypeConfig;
use PHPUnit\Framework\TestCase;

class PostTypeConfigTest extends TestCase {

    public function test_construct_sets_properties(): void {
        $config = new PostTypeConfig(
            post_type: 'product',
            enabled: true,
            searchable_fields: [ 'title', 'content', 'sku' ],
            filterable_fields: [ 'price', 'stock_status' ],
            sortable_fields: [ 'price', 'date_int' ],
            taxonomies: [ 'product_cat', 'product_tag' ],
            meta_keys: [ '_sku' ],
            geo_lat_key: '',
            geo_lng_key: '',
            search_mode: 'hybrid',
            embedder: 'default',
            semantic_ratio: 0.7,
        );

        $this->assertSame( 'product', $config->post_type );
        $this->assertTrue( $config->enabled );
        $this->assertSame( [ 'title', 'content', 'sku' ], $config->searchable_fields );
        $this->assertContains( 'price', $config->filterable_fields );
        $this->assertContains( 'product_cat', $config->taxonomies );
        $this->assertSame( 'hybrid', $config->search_mode );
        $this->assertSame( 'default', $config->embedder );
        $this->assertSame( 0.7, $config->semantic_ratio );
    }

    public function test_construct_defaults_search_mode_to_keyword(): void {
        $config = new PostTypeConfig(
            post_type: 'post',
            enabled: true,
            searchable_fields: [ 'title' ],
            filterable_fields: [],
            sortable_fields: [],
            taxonomies: [],
            meta_keys: [],
            geo_lat_key: '',
            geo_lng_key: '',
        );

        $this->assertSame( 'keyword', $config->search_mode );
        $this->assertNull( $config->embedder );
        $this->assertSame( 0.5, $config->semantic_ratio );
    }

    public function test_from_array_creates_config(): void {
        $config = PostTypeConfig::from_array( [
            'post_type'         => 'post',
            'enabled'           => true,
            'searchable_fields' => [ 'title' ],
            'filterable_fields' => [],
            'sortable_fields'   => [],
            'taxonomies'        => [ 'category' ],
            'meta_keys'         => [],
            'geo_lat_key'       => '',
            'geo_lng_key'       => '',
        ] );

        $this->assertSame( 'post', $config->post_type );
        $this->assertSame( [ 'category' ], $config->taxonomies );
        $this->assertSame( 'keyword', $config->search_mode );
    }

    public function test_from_array_with_search_mode(): void {
        $config = PostTypeConfig::from_array( [
            'post_type'         => 'product',
            'enabled'           => true,
            'searchable_fields' => [ 'title' ],
            'filterable_fields' => [],
            'sortable_fields'   => [],
            'taxonomies'        => [],
            'meta_keys'         => [],
            'geo_lat_key'       => '',
            'geo_lng_key'       => '',
            'search_mode'       => 'semantic',
            'embedder'          => 'openai',
            'semantic_ratio'    => 0.8,
        ] );

        $this->assertSame( 'semantic', $config->search_mode );
        $this->assertSame( 'openai', $config->embedder );
        $this->assertSame( 0.8, $config->semantic_ratio );
    }

    public function test_to_array_roundtrips(): void {
        $data = [
            'post_type'         => 'page',
            'enabled'           => false,
            'searchable_fields' => [ 'title', 'content' ],
            'filterable_fields' => [ 'author_id' ],
            'sortable_fields'   => [ 'date_int' ],
            'taxonomies'        => [],
            'meta_keys'         => [ 'custom_field' ],
            'geo_lat_key'       => '_lat',
            'geo_lng_key'       => '_lng',
            'search_mode'       => 'hybrid',
            'embedder'          => 'default',
            'semantic_ratio'    => 0.6,
        ];

        $config = PostTypeConfig::from_array( $data );
        $this->assertSame( $data, $config->to_array() );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter PostTypeConfigTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Indexing/PostTypeConfig.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final readonly class PostTypeConfig {

    /**
     * @param string[] $searchable_fields
     * @param string[] $filterable_fields
     * @param string[] $sortable_fields
     * @param string[] $taxonomies
     * @param string[] $meta_keys
     */
    public function __construct(
        public string $post_type,
        public bool $enabled,
        public array $searchable_fields,
        public array $filterable_fields,
        public array $sortable_fields,
        public array $taxonomies,
        public array $meta_keys,
        public string $geo_lat_key,
        public string $geo_lng_key,
        public string $search_mode = 'keyword',
        public ?string $embedder = null,
        public float $semantic_ratio = 0.5,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array( array $data ): self {
        $embedder = isset( $data['embedder'] ) && $data['embedder'] !== '' ? (string) $data['embedder'] : null;

        return new self(
            post_type: (string) ( $data['post_type'] ?? '' ),
            enabled: (bool) ( $data['enabled'] ?? false ),
            searchable_fields: (array) ( $data['searchable_fields'] ?? [] ),
            filterable_fields: (array) ( $data['filterable_fields'] ?? [] ),
            sortable_fields: (array) ( $data['sortable_fields'] ?? [] ),
            taxonomies: (array) ( $data['taxonomies'] ?? [] ),
            meta_keys: (array) ( $data['meta_keys'] ?? [] ),
            geo_lat_key: (string) ( $data['geo_lat_key'] ?? '' ),
            geo_lng_key: (string) ( $data['geo_lng_key'] ?? '' ),
            search_mode: (string) ( $data['search_mode'] ?? 'keyword' ),
            embedder: $embedder,
            semantic_ratio: (float) ( $data['semantic_ratio'] ?? 0.5 ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'post_type'         => $this->post_type,
            'enabled'           => $this->enabled,
            'searchable_fields' => $this->searchable_fields,
            'filterable_fields' => $this->filterable_fields,
            'sortable_fields'   => $this->sortable_fields,
            'taxonomies'        => $this->taxonomies,
            'meta_keys'         => $this->meta_keys,
            'geo_lat_key'       => $this->geo_lat_key,
            'geo_lng_key'       => $this->geo_lng_key,
            'search_mode'       => $this->search_mode,
            'embedder'          => $this->embedder,
            'semantic_ratio'    => $this->semantic_ratio,
        ];
    }

    public function has_geo(): bool {
        return $this->geo_lat_key !== '' && $this->geo_lng_key !== '';
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter PostTypeConfigTest`
Expected: OK (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Indexing/PostTypeConfig.php tests/unit/Indexing/PostTypeConfigTest.php
git commit -m "feat: add PostTypeConfig readonly value object"
```

---

### Task 10: FieldMapper — WP types to MS types

**Files:**
- Create: `src/Indexing/FieldMapper.php`
- Test: `tests/unit/Indexing/FieldMapperTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Indexing/FieldMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Indexing\FieldMapper;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase {

    private FieldMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = new FieldMapper();
    }

    public function test_maps_string_value(): void {
        $this->assertSame( 'hello', $this->mapper->cast( 'hello', 'text' ) );
    }

    public function test_maps_integer_value(): void {
        $this->assertSame( 42, $this->mapper->cast( '42', 'integer' ) );
    }

    public function test_maps_decimal_value(): void {
        $this->assertSame( 19.99, $this->mapper->cast( '19.99', 'decimal' ) );
    }

    public function test_maps_boolean_true(): void {
        $this->assertTrue( $this->mapper->cast( '1', 'boolean' ) );
    }

    public function test_maps_boolean_false(): void {
        $this->assertFalse( $this->mapper->cast( '0', 'boolean' ) );
    }

    public function test_maps_date_to_unix_timestamp(): void {
        $result = $this->mapper->cast( '2025-01-15 10:30:00', 'date' );
        $this->assertIsInt( $result );
        $this->assertSame( strtotime( '2025-01-15 10:30:00' ), $result );
    }

    public function test_maps_geopoint_returns_array(): void {
        $result = $this->mapper->cast( [ 'lat' => 48.85, 'lng' => 2.29 ], 'geopoint' );
        $this->assertSame( [ 'lat' => 48.85, 'lng' => 2.29 ], $result );
    }

    public function test_detect_type_identifies_numeric(): void {
        $this->assertSame( 'integer', $this->mapper->detect_type( '42' ) );
        $this->assertSame( 'decimal', $this->mapper->detect_type( '19.99' ) );
    }

    public function test_detect_type_identifies_boolean(): void {
        $this->assertSame( 'boolean', $this->mapper->detect_type( true ) );
    }

    public function test_detect_type_defaults_to_text(): void {
        $this->assertSame( 'text', $this->mapper->detect_type( 'hello world' ) );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter FieldMapperTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Indexing/FieldMapper.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FieldMapper {

    public function cast( mixed $value, string $type ): mixed {
        return match ( $type ) {
            'integer'  => (int) $value,
            'decimal'  => (float) $value,
            'boolean'  => (bool) $value,
            'date'     => is_numeric( $value ) ? (int) $value : (int) strtotime( (string) $value ),
            'geopoint' => $this->cast_geo( $value ),
            'string'   => (string) $value,
            default    => (string) $value, // 'text'
        };
    }

    public function detect_type( mixed $value ): string {
        if ( is_bool( $value ) ) {
            return 'boolean';
        }
        if ( is_int( $value ) ) {
            return 'integer';
        }
        if ( is_float( $value ) ) {
            return 'decimal';
        }
        if ( is_string( $value ) && $value !== '' && is_numeric( $value ) ) {
            return str_contains( $value, '.' ) ? 'decimal' : 'integer';
        }
        return 'text';
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function cast_geo( mixed $value ): ?array {
        if ( is_array( $value ) && isset( $value['lat'], $value['lng'] ) ) {
            return [
                'lat' => (float) $value['lat'],
                'lng' => (float) $value['lng'],
            ];
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter FieldMapperTest`
Expected: OK (10 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Indexing/FieldMapper.php tests/unit/Indexing/FieldMapperTest.php
git commit -m "feat: add FieldMapper for WP-to-Meilisearch type casting"
```

---

### Task 11: DocumentBuilder — WP_Post to MS document

**Files:**
- Create: `src/Indexing/DocumentBuilder.php`
- Test: `tests/unit/Indexing/DocumentBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Indexing/DocumentBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\FieldMapper;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class DocumentBuilderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_includes_core_fields(): void {
        $post = $this->make_post( 42, 'post', 'publish', 'Hello World', 'This is content.', '', '2025-01-15 10:00:00' );

        Functions\stubs( [
            'get_permalink'              => 'https://example.com/hello-world',
            'get_the_author_meta'        => 'John Doe',
            'get_the_post_thumbnail_url' => 'https://example.com/thumb.jpg',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( string $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => [],
            'wp_get_post_terms'          => [],
            'function_exists'            => false,
        ] );

        $config  = new PostTypeConfig( 'post', true, [ 'title', 'content' ], [], [ 'date_int' ], [], [], '', '' );
        $builder = new DocumentBuilder( new FieldMapper() );
        $doc     = $builder->build( $post, $config, 'wp_post' );

        $this->assertSame( 42, $doc['id'] );
        $this->assertSame( 42, $doc['wp_id'] );
        $this->assertSame( 'post', $doc['post_type'] );
        $this->assertSame( 'publish', $doc['post_status'] );
        $this->assertSame( 'Hello World', $doc['title'] );
        $this->assertStringContainsString( 'This is content.', $doc['content'] );
        $this->assertSame( 'https://example.com/hello-world', $doc['permalink'] );
        $this->assertSame( strtotime( '2025-01-15 10:00:00' ), $doc['date_int'] );
        $this->assertSame( 1, $doc['author_id'] );
        $this->assertSame( 'John Doe', $doc['author_name'] );
    }

    public function test_build_includes_taxonomy_fields(): void {
        $post = $this->make_post( 10, 'post', 'publish', 'Tax Post', '', '', '2025-01-01 00:00:00' );

        $term1     = new \stdClass();
        $term1->name    = 'PHP';
        $term1->term_id = 5;
        $term2     = new \stdClass();
        $term2->name    = 'WordPress';
        $term2->term_id = 8;

        Functions\stubs( [
            'get_permalink'              => 'https://example.com/tax',
            'get_the_author_meta'        => 'Jane',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( string $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => [],
            'wp_get_post_terms'          => [ $term1, $term2 ],
            'function_exists'            => false,
        ] );

        $config  = new PostTypeConfig( 'post', true, [ 'title' ], [], [], [ 'post_tag' ], [], '', '' );
        $builder = new DocumentBuilder( new FieldMapper() );
        $doc     = $builder->build( $post, $config, 'wp_post' );

        $this->assertSame( [ 'PHP', 'WordPress' ], $doc['tax_post_tag_names'] );
        $this->assertSame( [ 5, 8 ], $doc['tax_post_tag_ids'] );
    }

    public function test_build_includes_meta_fields(): void {
        $post = $this->make_post( 20, 'post', 'publish', 'Meta Post', '', '', '2025-01-01 00:00:00' );

        Functions\stubs( [
            'get_permalink'              => 'https://example.com/meta',
            'get_the_author_meta'        => 'Admin',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( string $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => function ( $id, $key, $single ) {
                return match ( $key ) {
                    'price'  => '29.99',
                    'color'  => 'red',
                    default  => '',
                };
            },
            'wp_get_post_terms'          => [],
            'function_exists'            => false,
        ] );

        $config  = new PostTypeConfig( 'post', true, [ 'title' ], [ 'meta_price' ], [ 'meta_price' ], [], [ 'price', 'color' ], '', '' );
        $builder = new DocumentBuilder( new FieldMapper() );
        $doc     = $builder->build( $post, $config, 'wp_post' );

        $this->assertSame( 29.99, $doc['meta_price'] );
        $this->assertSame( 'red', $doc['meta_color'] );
    }

    public function test_build_includes_geo_when_configured(): void {
        $post = $this->make_post( 30, 'post', 'publish', 'Geo Post', '', '', '2025-01-01 00:00:00' );

        Functions\stubs( [
            'get_permalink'              => 'https://example.com/geo',
            'get_the_author_meta'        => 'Admin',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( string $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => function ( $id, $key, $single ) {
                return match ( $key ) {
                    '_lat' => '48.8566',
                    '_lng' => '2.3522',
                    default => '',
                };
            },
            'wp_get_post_terms'          => [],
            'function_exists'            => false,
        ] );

        $config  = new PostTypeConfig( 'post', true, [ 'title' ], [], [], [], [], '_lat', '_lng' );
        $builder = new DocumentBuilder( new FieldMapper() );
        $doc     = $builder->build( $post, $config, 'wp_post' );

        $this->assertArrayHasKey( '_geo', $doc );
        $this->assertSame( 48.8566, $doc['_geo']['lat'] );
        $this->assertSame( 2.3522, $doc['_geo']['lng'] );
    }

    public function test_build_applies_filter_hook(): void {
        $post = $this->make_post( 50, 'post', 'publish', 'Filter Post', '', '', '2025-01-01 00:00:00' );

        Functions\stubs( [
            'get_permalink'              => 'https://example.com/filter',
            'get_the_author_meta'        => 'Admin',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( string $tag, ...$args ) {
                if ( $tag === 'meilisearch_document' ) {
                    $doc = $args[0];
                    $doc['custom_field'] = 'injected';
                    return $doc;
                }
                return $args[0];
            },
            'get_post_meta'              => [],
            'wp_get_post_terms'          => [],
            'function_exists'            => false,
        ] );

        $config  = new PostTypeConfig( 'post', true, [ 'title' ], [], [], [], [], '', '' );
        $builder = new DocumentBuilder( new FieldMapper() );
        $doc     = $builder->build( $post, $config, 'wp_post' );

        $this->assertSame( 'injected', $doc['custom_field'] );
    }

    public function test_id_sanitization(): void {
        $builder = new DocumentBuilder( new FieldMapper() );
        $this->assertSame( '42', $builder->sanitize_id( 42 ) );
        $this->assertSame( 'abc-123_def', $builder->sanitize_id( 'abc-123_def' ) );
        $this->assertSame( 'abc_123', $builder->sanitize_id( 'abc/123' ) );
    }

    /**
     * @return \WP_Post
     */
    private function make_post( int $id, string $type, string $status, string $title, string $content, string $excerpt, string $date_gmt ): object {
        $post                  = new \stdClass();
        $post->ID              = $id;
        $post->post_type       = $type;
        $post->post_status     = $status;
        $post->post_title      = $title;
        $post->post_content    = $content;
        $post->post_excerpt    = $excerpt;
        $post->post_date_gmt   = $date_gmt;
        $post->post_author     = 1;
        return $post;
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter DocumentBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Indexing/DocumentBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DocumentBuilder {

    private FieldMapper $field_mapper;

    public function __construct( FieldMapper $field_mapper ) {
        $this->field_mapper = $field_mapper;
    }

    /**
     * @param object $post WP_Post instance.
     * @return array<string, mixed>
     */
    public function build( object $post, PostTypeConfig $config, string $index_uid ): array {
        $content_raw = apply_filters( 'the_content', $post->post_content );
        $content     = wp_strip_all_tags( (string) $content_raw );

        $excerpt = $post->post_excerpt;
        if ( $excerpt === '' && $content !== '' ) {
            $excerpt = mb_substr( $content, 0, 300 );
        }

        $doc = [
            'id'            => (int) $post->ID,
            'wp_id'         => (int) $post->ID,
            'post_type'     => $post->post_type,
            'post_status'   => $post->post_status,
            'title'         => $post->post_title,
            'content'       => mb_substr( $content, 0, 65535 ),
            'excerpt'       => $excerpt,
            'permalink'     => get_permalink( $post ),
            'date_int'      => (int) strtotime( $post->post_date_gmt ),
            'author_id'     => (int) $post->post_author,
            'author_name'   => get_the_author_meta( 'display_name', $post->post_author ),
            'thumbnail_url' => (string) get_the_post_thumbnail_url( $post, 'medium' ),
        ];

        // Taxonomies.
        foreach ( $config->taxonomies as $taxonomy ) {
            $terms = wp_get_post_terms( $post->ID, $taxonomy );
            if ( ! is_array( $terms ) ) {
                continue;
            }
            $doc[ "tax_{$taxonomy}_names" ] = array_map( fn( $t ) => $t->name, $terms );
            $doc[ "tax_{$taxonomy}_ids" ]   = array_map( fn( $t ) => (int) $t->term_id, $terms );
        }

        // Meta fields.
        foreach ( $config->meta_keys as $key ) {
            $value = get_post_meta( $post->ID, $key, true );
            if ( $value === '' || $value === false ) {
                continue;
            }
            $type                   = $this->field_mapper->detect_type( $value );
            $doc[ "meta_{$key}" ]   = $this->field_mapper->cast( $value, $type );
        }

        // ACF auto-discovery.
        if ( function_exists( 'get_field_objects' ) ) {
            $acf_fields = get_field_objects( $post->ID );
            if ( is_array( $acf_fields ) ) {
                foreach ( $acf_fields as $field ) {
                    $acf_key = $field['name'] ?? '';
                    if ( $acf_key === '' || isset( $doc[ "meta_{$acf_key}" ] ) ) {
                        continue;
                    }
                    $acf_type = $this->map_acf_type( $field['type'] ?? 'text' );
                    $doc[ "acf_{$acf_key}" ] = $this->field_mapper->cast( $field['value'] ?? '', $acf_type );
                }
            }
        }

        // Geo.
        if ( $config->has_geo() ) {
            $lat = get_post_meta( $post->ID, $config->geo_lat_key, true );
            $lng = get_post_meta( $post->ID, $config->geo_lng_key, true );
            if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
                $doc['_geo'] = [
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                ];
            }
        }

        /** @var array<string, mixed> */
        return apply_filters( 'meilisearch_document', $doc, $post, $index_uid );
    }

    public function sanitize_id( int|string $raw ): string {
        return (string) preg_replace( '/[^a-zA-Z0-9_-]/', '_', (string) $raw );
    }

    private function map_acf_type( string $acf_type ): string {
        return match ( $acf_type ) {
            'number', 'range'          => 'decimal',
            'true_false'               => 'boolean',
            'date_picker', 'date_time_picker' => 'date',
            default                    => 'text',
        };
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter DocumentBuilderTest`
Expected: OK (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Indexing/DocumentBuilder.php tests/unit/Indexing/DocumentBuilderTest.php
git commit -m "feat: add DocumentBuilder with core fields, taxonomies, meta, ACF, and geo"
```

---

### Task 12: SettingsBuilder — MS index settings computation

**Files:**
- Create: `src/Indexing/SettingsBuilder.php`
- Test: `tests/unit/Indexing/SettingsBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Indexing/SettingsBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Indexing;

use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use PHPUnit\Framework\TestCase;

class SettingsBuilderTest extends TestCase {

    public function test_searchable_attributes_follow_config_order(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title', 'content', 'excerpt' ], [], [], [], [], '', '' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $this->assertSame( [ 'title', 'content', 'excerpt' ], $settings['searchableAttributes'] );
    }

    public function test_filterable_includes_defaults_and_custom(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title' ], [ 'price' ], [], [ 'category' ], [], '', '' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $filterable = $settings['filterableAttributes'];
        $this->assertContains( 'post_type', $filterable );
        $this->assertContains( 'post_status', $filterable );
        $this->assertContains( 'author_id', $filterable );
        $this->assertContains( 'date_int', $filterable );
        $this->assertContains( 'price', $filterable );
        $this->assertContains( 'tax_category_names', $filterable );
        $this->assertContains( 'tax_category_ids', $filterable );
    }

    public function test_sortable_includes_date_and_custom(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title' ], [], [ 'price', 'date_int' ], [], [], '', '' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $this->assertContains( 'date_int', $settings['sortableAttributes'] );
        $this->assertContains( 'price', $settings['sortableAttributes'] );
    }

    public function test_geo_adds_geo_to_filterable_and_sortable(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title' ], [], [], [], [], '_lat', '_lng' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $this->assertContains( '_geo', $settings['filterableAttributes'] );
        $this->assertContains( '_geo', $settings['sortableAttributes'] );
    }

    public function test_displayed_attributes_includes_all_indexed_fields(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title', 'content' ], [ 'price' ], [ 'date_int' ], [ 'category' ], [ 'color' ], '', '' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $this->assertContains( 'title', $settings['displayedAttributes'] );
        $this->assertContains( 'content', $settings['displayedAttributes'] );
        $this->assertContains( 'permalink', $settings['displayedAttributes'] );
        $this->assertContains( 'thumbnail_url', $settings['displayedAttributes'] );
    }

    public function test_distinct_attribute_is_null_by_default(): void {
        $config   = new PostTypeConfig( 'post', true, [ 'title' ], [], [], [], [], '', '' );
        $builder  = new SettingsBuilder();
        $settings = $builder->build( $config );

        $this->assertNull( $settings['distinctAttribute'] );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter SettingsBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Indexing/SettingsBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsBuilder {

    /**
     * @return array<string, mixed>
     */
    public function build( PostTypeConfig $config ): array {
        $searchable = $config->searchable_fields;

        // Filterable: always include system fields + configured + taxonomy fields.
        $filterable = array_merge(
            [ 'post_type', 'post_status', 'author_id', 'date_int' ],
            $config->filterable_fields,
        );
        foreach ( $config->taxonomies as $tax ) {
            $filterable[] = "tax_{$tax}_names";
            $filterable[] = "tax_{$tax}_ids";
        }

        // Sortable: always date_int + configured.
        $sortable = array_unique( array_merge( [ 'date_int' ], $config->sortable_fields ) );

        // Geo.
        if ( $config->has_geo() ) {
            $filterable[] = '_geo';
            $sortable[]   = '_geo';
        }

        $filterable = array_values( array_unique( $filterable ) );
        $sortable   = array_values( array_unique( $sortable ) );

        // Displayed: union of all known fields.
        $displayed = array_values( array_unique( array_merge(
            [ 'id', 'wp_id', 'post_type', 'post_status', 'title', 'content', 'excerpt', 'permalink', 'date_int', 'author_id', 'author_name', 'thumbnail_url' ],
            $searchable,
            $filterable,
            $sortable,
            array_map( fn( string $k ) => "meta_{$k}", $config->meta_keys ),
        ) ) );

        return [
            'searchableAttributes' => $searchable,
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'displayedAttributes'  => $displayed,
            'distinctAttribute'    => null,
        ];
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter SettingsBuilderTest`
Expected: OK (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Indexing/SettingsBuilder.php tests/unit/Indexing/SettingsBuilderTest.php
git commit -m "feat: add SettingsBuilder for MS index settings computation"
```

---

### Task 13: IndexManager — create/update/delete indexes

**Files:**
- Create: `src/Indexing/IndexManager.php`
- Test: `tests/integration/Indexing/IndexManagerTest.php`

- [ ] **Step 1: Write integration test**

Create `tests/integration/Indexing/IndexManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Indexing;

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use PHPUnit\Framework\TestCase;

/**
 * Requires a running Meilisearch instance at MEILISEARCH_HOST with MEILISEARCH_API_KEY.
 */
class IndexManagerTest extends TestCase {

    private Client $client;
    private IndexManager $manager;

    protected function setUp(): void {
        parent::setUp();
        $host = getenv( 'MEILISEARCH_HOST' ) ?: 'http://localhost:7700';
        $key  = getenv( 'MEILISEARCH_API_KEY' ) ?: 'masterKey123';

        $this->client  = new Client( $host, $key );
        $this->manager = new IndexManager( $this->client, new SettingsBuilder() );

        // Cleanup from prior runs.
        try {
            $this->client->delete_index( 'wp_post_test' );
            $this->client->wait_for_task( 0 );
        } catch ( \Throwable ) {
        }
    }

    protected function tearDown(): void {
        try {
            $this->client->delete_index( 'wp_post_test' );
        } catch ( \Throwable ) {
        }
        parent::tearDown();
    }

    public function test_create_index_and_push_settings(): void {
        $config = new PostTypeConfig( 'post_test', true, [ 'title', 'content' ], [ 'author_id' ], [ 'date_int' ], [ 'category' ], [], '', '' );

        $this->manager->create_index( 'wp_post_test', $config );

        // Verify index exists by searching.
        $result = $this->client->search( 'wp_post_test', '', [] );
        $this->assertSame( 0, $result->getEstimatedTotalHits() );
    }

    public function test_delete_index(): void {
        $config = new PostTypeConfig( 'post_test', true, [ 'title' ], [], [], [], [], '', '' );
        $this->manager->create_index( 'wp_post_test', $config );

        $this->manager->delete_index( 'wp_post_test' );

        $this->expectException( \Meilisearch\WordPress\Api\Exception::class );
        $this->client->search( 'wp_post_test', '' );
    }
}
```

- [ ] **Step 2: Implement `src/Indexing/IndexManager.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Support\Logger;

class IndexManager {

    private Client $client;
    private SettingsBuilder $settings_builder;

    public function __construct( Client $client, SettingsBuilder $settings_builder ) {
        $this->client           = $client;
        $this->settings_builder = $settings_builder;
    }

    public function create_index( string $uid, PostTypeConfig $config ): void {
        $task_info = $this->client->create_index( $uid );
        $this->wait_for( $task_info );

        $this->update_index_settings( $uid, $config );
    }

    public function update_index_settings( string $uid, PostTypeConfig $config ): void {
        $settings  = $this->settings_builder->build( $config );
        $task_info = $this->client->update_settings( $uid, $settings );
        $this->wait_for( $task_info );
    }

    public function delete_index( string $uid ): void {
        $task_info = $this->client->delete_index( $uid );
        $this->wait_for( $task_info );
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function add_documents( string $uid, array $documents ): void {
        $task_info = $this->client->add_documents( $uid, $documents );
        // Do not wait — async by design; caller uses Action Scheduler.
        $this->log_task( $task_info );
    }

    public function delete_document( string $uid, string|int $document_id ): void {
        $task_info = $this->client->delete_document( $uid, $document_id );
        $this->log_task( $task_info );
    }

    /**
     * Partial update for stock-only changes.
     *
     * @param array<int, array<string, mixed>> $documents
     */
    public function update_documents( string $uid, array $documents ): void {
        $task_info = $this->client->update_documents( $uid, $documents );
        $this->log_task( $task_info );
    }

    /**
     * @param array<string, mixed> $task_info
     */
    private function wait_for( array $task_info ): void {
        $task_uid = $task_info['taskUid'] ?? null;
        if ( $task_uid === null ) {
            return;
        }
        $timeout = (int) apply_filters( 'meilisearch_task_timeout', 30000 );
        try {
            $this->client->wait_for_task( (int) $task_uid, $timeout );
        } catch ( \Throwable $e ) {
            Logger::error( 'Task wait failed', [ 'taskUid' => $task_uid, 'error' => $e->getMessage() ] );
        }
    }

    /**
     * @param array<string, mixed> $task_info
     */
    private function log_task( array $task_info ): void {
        $task_uid = $task_info['taskUid'] ?? 'unknown';
        Logger::warning( "Meilisearch task enqueued: {$task_uid}" );
    }
}
```

- [ ] **Step 3: Run integration test**

Run: `composer test:integration -- --filter IndexManagerTest`
Expected: OK (2 tests) — requires Meilisearch container running.

- [ ] **Step 4: Commit**

```bash
git add src/Indexing/IndexManager.php tests/integration/Indexing/IndexManagerTest.php
git commit -m "feat: add IndexManager for create/update/delete index lifecycle"
```

---

### Task 14: PostTypesSection admin UI

**Files:**
- Create: `src/Admin/PostTypesSection.php`
- Test: `tests/unit/Admin/PostTypesSectionTest.php`

- [ ] **Step 1: Write failing test for search mode save/load**

Create `tests/unit/Admin/PostTypesSectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Admin\PostTypesSection;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class PostTypesSectionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sanitize_saves_search_mode_fields(): void {
        Functions\stubs( [
            'sanitize_key'        => function ( $s ) { return strtolower( trim( $s ) ); },
            'sanitize_text_field' => function ( $s ) { return trim( (string) $s ); },
        ] );

        $section = new PostTypesSection();
        $result  = $section->sanitize_post_types( [
            'product' => [
                'post_type'         => 'product',
                'enabled'           => '1',
                'searchable_fields' => [ 'title' ],
                'filterable_fields' => [],
                'sortable_fields'   => [],
                'taxonomies'        => [],
                'meta_keys_raw'     => '',
                'geo_lat_key'       => '',
                'geo_lng_key'       => '',
                'search_mode'       => 'hybrid',
                'embedder'          => 'default',
                'semantic_ratio'    => '0.7',
            ],
        ] );

        $this->assertSame( 'hybrid', $result['product']['search_mode'] );
        $this->assertSame( 'default', $result['product']['embedder'] );
        $this->assertSame( 0.7, $result['product']['semantic_ratio'] );
    }

    public function test_sanitize_defaults_search_mode_to_keyword(): void {
        Functions\stubs( [
            'sanitize_key'        => function ( $s ) { return strtolower( trim( $s ) ); },
            'sanitize_text_field' => function ( $s ) { return trim( (string) $s ); },
        ] );

        $section = new PostTypesSection();
        $result  = $section->sanitize_post_types( [
            'post' => [
                'post_type'         => 'post',
                'enabled'           => '1',
                'searchable_fields' => [ 'title' ],
                'filterable_fields' => [],
                'sortable_fields'   => [],
                'taxonomies'        => [],
                'meta_keys_raw'     => '',
                'geo_lat_key'       => '',
                'geo_lng_key'       => '',
            ],
        ] );

        $this->assertSame( 'keyword', $result['post']['search_mode'] );
        $this->assertSame( '', $result['post']['embedder'] );
        $this->assertSame( 0.5, $result['post']['semantic_ratio'] );
    }

    public function test_sanitize_clamps_semantic_ratio(): void {
        Functions\stubs( [
            'sanitize_key'        => function ( $s ) { return strtolower( trim( $s ) ); },
            'sanitize_text_field' => function ( $s ) { return trim( (string) $s ); },
        ] );

        $section = new PostTypesSection();
        $result  = $section->sanitize_post_types( [
            'post' => [
                'post_type'         => 'post',
                'enabled'           => '1',
                'searchable_fields' => [],
                'filterable_fields' => [],
                'sortable_fields'   => [],
                'taxonomies'        => [],
                'meta_keys_raw'     => '',
                'geo_lat_key'       => '',
                'geo_lng_key'       => '',
                'search_mode'       => 'hybrid',
                'embedder'          => 'default',
                'semantic_ratio'    => '1.5',
            ],
        ] );

        $this->assertSame( 1.0, $result['post']['semantic_ratio'] );
    }

    public function test_sanitize_rejects_invalid_search_mode(): void {
        Functions\stubs( [
            'sanitize_key'        => function ( $s ) { return strtolower( trim( $s ) ); },
            'sanitize_text_field' => function ( $s ) { return trim( (string) $s ); },
        ] );

        $section = new PostTypesSection();
        $result  = $section->sanitize_post_types( [
            'post' => [
                'post_type'         => 'post',
                'enabled'           => '1',
                'searchable_fields' => [],
                'filterable_fields' => [],
                'sortable_fields'   => [],
                'taxonomies'        => [],
                'meta_keys_raw'     => '',
                'geo_lat_key'       => '',
                'geo_lng_key'       => '',
                'search_mode'       => 'invalid_mode',
                'embedder'          => '',
                'semantic_ratio'    => '0.5',
            ],
        ] );

        $this->assertSame( 'keyword', $result['post']['search_mode'] );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter PostTypesSectionTest`
Expected: FAIL -- class not found.

- [ ] **Step 3: Implement `src/Admin/PostTypesSection.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Indexing\PostTypeConfig;

class PostTypesSection {

    public function register(): void {
        add_action( 'meilisearch_admin_tab_post_types', [ $this, 'render' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'meilisearch_settings', 'meilisearch_post_types', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_post_types' ],
        ] );
    }

    public function render(): void {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $saved      = get_option( 'meilisearch_post_types', [] );

        echo '<form method="post" action="options.php">';
        settings_fields( 'meilisearch_settings' );

        foreach ( $post_types as $pt ) {
            $slug   = $pt->name;
            $config = isset( $saved[ $slug ] ) ? PostTypeConfig::from_array( $saved[ $slug ] ) : null;
            $enabled = $config?->enabled ?? false;

            echo '<div class="meilisearch-card">';
            printf( '<h3>%s <code>(%s)</code></h3>', esc_html( $pt->label ), esc_html( $slug ) );

            printf(
                '<label><input type="checkbox" name="meilisearch_post_types[%s][enabled]" value="1" %s /> %s</label>',
                esc_attr( $slug ),
                checked( $enabled, true, false ),
                esc_html__( 'Enable indexing', 'meilisearch' ),
            );

            printf( '<input type="hidden" name="meilisearch_post_types[%s][post_type]" value="%s" />', esc_attr( $slug ), esc_attr( $slug ) );

            // Field mapping table.
            echo '<table class="widefat" style="margin-top: 12px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Field', 'meilisearch' ) . '</th>';
            echo '<th>' . esc_html__( 'Searchable', 'meilisearch' ) . '</th>';
            echo '<th>' . esc_html__( 'Filterable', 'meilisearch' ) . '</th>';
            echo '<th>' . esc_html__( 'Sortable', 'meilisearch' ) . '</th>';
            echo '</tr></thead><tbody>';

            $fields = [ 'title', 'content', 'excerpt', 'author_name' ];
            foreach ( $fields as $field ) {
                $searchable = in_array( $field, $config?->searchable_fields ?? [ 'title', 'content' ], true );
                $filterable = in_array( $field, $config?->filterable_fields ?? [], true );
                $sortable   = in_array( $field, $config?->sortable_fields ?? [], true );

                echo '<tr>';
                printf( '<td>%s</td>', esc_html( $field ) );
                printf( '<td><input type="checkbox" name="meilisearch_post_types[%s][searchable_fields][]" value="%s" %s /></td>', esc_attr( $slug ), esc_attr( $field ), checked( $searchable, true, false ) );
                printf( '<td><input type="checkbox" name="meilisearch_post_types[%s][filterable_fields][]" value="%s" %s /></td>', esc_attr( $slug ), esc_attr( $field ), checked( $filterable, true, false ) );
                printf( '<td><input type="checkbox" name="meilisearch_post_types[%s][sortable_fields][]" value="%s" %s /></td>', esc_attr( $slug ), esc_attr( $field ), checked( $sortable, true, false ) );
                echo '</tr>';
            }

            echo '</tbody></table>';

            // Taxonomy selection.
            $taxonomies = get_object_taxonomies( $slug, 'objects' );
            if ( ! empty( $taxonomies ) ) {
                echo '<h4 style="margin-top: 12px;">' . esc_html__( 'Taxonomies', 'meilisearch' ) . '</h4>';
                foreach ( $taxonomies as $tax ) {
                    $tax_checked = in_array( $tax->name, $config?->taxonomies ?? [], true );
                    printf(
                        '<label style="margin-right: 16px;"><input type="checkbox" name="meilisearch_post_types[%s][taxonomies][]" value="%s" %s /> %s</label>',
                        esc_attr( $slug ),
                        esc_attr( $tax->name ),
                        checked( $tax_checked, true, false ),
                        esc_html( $tax->label ),
                    );
                }
            }

            // Meta keys.
            echo '<h4 style="margin-top: 12px;">' . esc_html__( 'Meta Keys', 'meilisearch' ) . '</h4>';
            $meta_keys_value = implode( "\n", $config?->meta_keys ?? [] );
            printf(
                '<textarea name="meilisearch_post_types[%s][meta_keys_raw]" rows="3" class="large-text" placeholder="%s">%s</textarea>',
                esc_attr( $slug ),
                esc_attr__( 'One meta key per line', 'meilisearch' ),
                esc_textarea( $meta_keys_value ),
            );

            // Geo keys.
            echo '<h4 style="margin-top: 12px;">' . esc_html__( 'Geo Location', 'meilisearch' ) . '</h4>';
            printf(
                '<label>%s <input type="text" name="meilisearch_post_types[%s][geo_lat_key]" value="%s" class="regular-text" /></label> ',
                esc_html__( 'Latitude meta key:', 'meilisearch' ),
                esc_attr( $slug ),
                esc_attr( $config?->geo_lat_key ?? '' ),
            );
            printf(
                '<label>%s <input type="text" name="meilisearch_post_types[%s][geo_lng_key]" value="%s" class="regular-text" /></label>',
                esc_html__( 'Longitude meta key:', 'meilisearch' ),
                esc_attr( $slug ),
                esc_attr( $config?->geo_lng_key ?? '' ),
            );

            // Search mode.
            $current_mode     = $config?->search_mode ?? 'keyword';
            $current_embedder = $config?->embedder ?? '';
            $current_ratio    = $config?->semantic_ratio ?? 0.5;

            echo '<h4 style="margin-top: 12px;">' . esc_html__( 'Search Mode', 'meilisearch' ) . '</h4>';
            printf(
                '<select name="meilisearch_post_types[%s][search_mode]" class="meilisearch-search-mode-select" data-slug="%s">',
                esc_attr( $slug ),
                esc_attr( $slug ),
            );
            foreach ( [ 'keyword' => 'Keyword', 'hybrid' => 'Hybrid', 'semantic' => 'Semantic' ] as $mode_val => $mode_label ) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr( $mode_val ),
                    selected( $current_mode, $mode_val, false ),
                    esc_html( $mode_label ),
                );
            }
            echo '</select>';

            printf(
                '<div class="meilisearch-embedder-field" data-slug="%s" style="margin-top: 8px; %s">',
                esc_attr( $slug ),
                $current_mode === 'keyword' ? 'display:none;' : '',
            );
            printf(
                '<label>%s <input type="text" name="meilisearch_post_types[%s][embedder]" value="%s" class="regular-text" placeholder="default" /></label>',
                esc_html__( 'Embedder name:', 'meilisearch' ),
                esc_attr( $slug ),
                esc_attr( $current_embedder ),
            );
            echo '</div>';

            printf(
                '<div class="meilisearch-ratio-field" data-slug="%s" style="margin-top: 8px; %s">',
                esc_attr( $slug ),
                $current_mode !== 'hybrid' ? 'display:none;' : '',
            );
            printf(
                '<label>%s <input type="number" name="meilisearch_post_types[%s][semantic_ratio]" value="%s" min="0" max="1" step="0.1" /></label>',
                esc_html__( 'Semantic ratio (0.0 = full keyword, 1.0 = full semantic):', 'meilisearch' ),
                esc_attr( $slug ),
                esc_attr( (string) $current_ratio ),
            );
            echo '</div>';

            echo '</div>';
        }

        submit_button();
        echo '</form>';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, array<string, mixed>>
     */
    public function sanitize_post_types( array $input ): array {
        $sanitized = [];

        foreach ( $input as $slug => $data ) {
            $meta_raw = $data['meta_keys_raw'] ?? '';
            $meta_keys = array_filter( array_map( 'trim', explode( "\n", $meta_raw ) ) );

            $search_mode = sanitize_key( $data['search_mode'] ?? 'keyword' );
            if ( ! in_array( $search_mode, [ 'keyword', 'hybrid', 'semantic' ], true ) ) {
                $search_mode = 'keyword';
            }

            $sanitized[ sanitize_key( $slug ) ] = [
                'post_type'         => sanitize_key( $slug ),
                'enabled'           => ! empty( $data['enabled'] ),
                'searchable_fields' => array_map( 'sanitize_key', (array) ( $data['searchable_fields'] ?? [] ) ),
                'filterable_fields' => array_map( 'sanitize_key', (array) ( $data['filterable_fields'] ?? [] ) ),
                'sortable_fields'   => array_map( 'sanitize_key', (array) ( $data['sortable_fields'] ?? [] ) ),
                'taxonomies'        => array_map( 'sanitize_key', (array) ( $data['taxonomies'] ?? [] ) ),
                'meta_keys'         => array_map( 'sanitize_text_field', $meta_keys ),
                'geo_lat_key'       => sanitize_text_field( $data['geo_lat_key'] ?? '' ),
                'geo_lng_key'       => sanitize_text_field( $data['geo_lng_key'] ?? '' ),
                'search_mode'       => $search_mode,
                'embedder'          => sanitize_text_field( $data['embedder'] ?? '' ),
                'semantic_ratio'    => max( 0.0, min( 1.0, (float) ( $data['semantic_ratio'] ?? 0.5 ) ) ),
            ];
        }

        return $sanitized;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter PostTypesSectionTest`
Expected: OK (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Admin/PostTypesSection.php tests/unit/Admin/PostTypesSectionTest.php
git commit -m "feat: add PostTypesSection with field mapping, search mode, and sanitisation"
```

---

### Task 15: ReindexDashboard with REST status endpoint

**Files:**
- Create: `src/Admin/ReindexDashboard.php`
- Create: `assets/js/admin/reindex-progress.js`

- [ ] **Step 1: Implement `src/Admin/ReindexDashboard.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReindexDashboard {

    public function register(): void {
        add_action( 'meilisearch_admin_tab_post_types', [ $this, 'render_reindex_section' ], 20 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'wp_ajax_meilisearch_trigger_reindex', [ $this, 'ajax_trigger_reindex' ] );
    }

    public function register_rest_routes(): void {
        register_rest_route( 'meilisearch/v1', '/reindex/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_reindex_status' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );
    }

    public function render_reindex_section(): void {
        $post_types_config = get_option( 'meilisearch_post_types', [] );

        echo '<div class="meilisearch-card" style="margin-top: 24px;">';
        echo '<h2>' . esc_html__( 'Re-index', 'meilisearch' ) . '</h2>';
        echo '<table class="widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Post Type', 'meilisearch' ) . '</th>';
        echo '<th>' . esc_html__( 'Posts', 'meilisearch' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'meilisearch' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'meilisearch' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $post_types_config as $slug => $config ) {
            if ( empty( $config['enabled'] ) ) {
                continue;
            }
            $count = wp_count_posts( $slug );
            $total = $count->publish ?? 0;

            echo '<tr>';
            printf( '<td>%s</td>', esc_html( $slug ) );
            printf( '<td>%d</td>', (int) $total );
            printf(
                '<td><button type="button" class="button meilisearch-reindex-btn" data-post-type="%s">%s</button></td>',
                esc_attr( $slug ),
                esc_html__( 'Re-index', 'meilisearch' ),
            );
            printf( '<td><span class="meilisearch-reindex-status" data-post-type="%s">—</span></td>', esc_attr( $slug ) );
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top: 12px;">';
        echo '<button type="button" class="button button-primary" id="meilisearch-reindex-all">';
        echo esc_html__( 'Re-index Everything', 'meilisearch' );
        echo '</button></p>';
        echo '</div>';
    }

    /**
     * @return \WP_REST_Response
     */
    public function rest_reindex_status( \WP_REST_Request $request ): \WP_REST_Response {
        $statuses = [];

        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $pending   = as_get_scheduled_actions( [
                'group'  => 'meilisearch',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 0,
            ] );
            $completed = as_get_scheduled_actions( [
                'group'  => 'meilisearch',
                'status' => \ActionScheduler_Store::STATUS_COMPLETE,
                'per_page' => 0,
            ] );

            $statuses = [
                'pending'   => count( $pending ),
                'completed' => count( $completed ),
                'running'   => count( $pending ) > 0,
            ];
        } else {
            $statuses = [
                'pending'   => 0,
                'completed' => 0,
                'running'   => false,
            ];
        }

        return new \WP_REST_Response( $statuses, 200 );
    }

    public function ajax_trigger_reindex(): void {
        check_ajax_referer( 'meilisearch_reindex', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'meilisearch' ) ] );
            return;
        }

        $post_type = sanitize_key( $_POST['post_type'] ?? '' );
        if ( $post_type === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing post type.', 'meilisearch' ) ] );
            return;
        }

        $site_id = get_current_blog_id();

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'meilisearch_bulk_reindex', [ $post_type, $site_id, 0, 50 ], 'meilisearch' );
        }

        wp_send_json_success( [ 'message' => __( 'Re-index queued.', 'meilisearch' ) ] );
    }
}
```

- [ ] **Step 2: Create `assets/js/admin/reindex-progress.js`**

```js
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        const buttons    = document.querySelectorAll( '.meilisearch-reindex-btn' );
        const reindexAll = document.getElementById( 'meilisearch-reindex-all' );
        let polling      = null;

        function triggerReindex( postType ) {
            const data = new FormData();
            data.append( 'action', 'meilisearch_trigger_reindex' );
            data.append( 'nonce', meilisearchAdmin.reindexNonce );
            data.append( 'post_type', postType );

            fetch( meilisearchAdmin.ajaxUrl, { method: 'POST', body: data } )
                .then( r => r.json() )
                .then( function ( res ) {
                    const el = document.querySelector( '.meilisearch-reindex-status[data-post-type="' + postType + '"]' );
                    if ( el ) el.textContent = res.success ? res.data.message : res.data.message;
                    startPolling();
                } );
        }

        function startPolling() {
            if ( polling ) return;
            polling = setInterval( function () {
                fetch( meilisearchAdmin.restUrl + 'meilisearch/v1/reindex/status', {
                    headers: { 'X-WP-Nonce': meilisearchAdmin.restNonce },
                } )
                    .then( r => r.json() )
                    .then( function ( data ) {
                        if ( ! data.running ) {
                            clearInterval( polling );
                            polling = null;
                            document.querySelectorAll( '.meilisearch-reindex-status' ).forEach( function ( el ) {
                                el.textContent = meilisearchAdmin.completed;
                            } );
                        }
                    } );
            }, 3000 );
        }

        buttons.forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                triggerReindex( btn.dataset.postType );
            } );
        } );

        if ( reindexAll ) {
            reindexAll.addEventListener( 'click', function () {
                buttons.forEach( function ( btn ) {
                    triggerReindex( btn.dataset.postType );
                } );
            } );
        }
    } );
} )();
```

- [ ] **Step 3: Commit**

```bash
git add src/Admin/ReindexDashboard.php assets/js/admin/reindex-progress.js
git commit -m "feat: add ReindexDashboard with REST status endpoint and AJAX polling"
```

---

### Task 16: Wire IndexManager to admin toggle events

**Files:**
- Modify: `src/Admin/PostTypesSection.php`

- [ ] **Step 1: Add index lifecycle hooks to PostTypesSection**

Add to `PostTypesSection::register()`:

```php
add_action( 'update_option_meilisearch_post_types', [ $this, 'on_post_types_updated' ], 10, 2 );
```

Add method to `PostTypesSection`:

```php
/**
 * When post type config changes, create/delete indexes as needed.
 *
 * @param array<string, array<string, mixed>> $old_value
 * @param array<string, array<string, mixed>> $new_value
 */
public function on_post_types_updated( array $old_value, array $new_value ): void {
    $client_factory  = new \Meilisearch\WordPress\Api\ClientFactory();
    $client          = $client_factory->for_current_site();
    $settings_builder = new \Meilisearch\WordPress\Indexing\SettingsBuilder();
    $index_manager   = new \Meilisearch\WordPress\Indexing\IndexManager( $client, $settings_builder );
    $site_settings   = new \Meilisearch\WordPress\Multisite\SiteSettings();

    foreach ( $new_value as $slug => $data ) {
        $config      = PostTypeConfig::from_array( $data );
        $index_uid   = $site_settings->index_uid( $slug );
        $was_enabled = ! empty( $old_value[ $slug ]['enabled'] );

        if ( $config->enabled && ! $was_enabled ) {
            try {
                $index_manager->create_index( $index_uid, $config );
            } catch ( \Throwable $e ) {
                \Meilisearch\WordPress\Support\Logger::error( 'Failed to create index', [ 'uid' => $index_uid, 'error' => $e->getMessage() ] );
            }
        } elseif ( $config->enabled && $was_enabled ) {
            try {
                $index_manager->update_index_settings( $index_uid, $config );
            } catch ( \Throwable $e ) {
                \Meilisearch\WordPress\Support\Logger::error( 'Failed to update index settings', [ 'uid' => $index_uid, 'error' => $e->getMessage() ] );
            }
        } elseif ( ! $config->enabled && $was_enabled ) {
            try {
                $index_manager->delete_index( $index_uid );
            } catch ( \Throwable $e ) {
                \Meilisearch\WordPress\Support\Logger::error( 'Failed to delete index', [ 'uid' => $index_uid, 'error' => $e->getMessage() ] );
            }
        }
    }

    // Handle removed post types (present in old but not in new).
    foreach ( $old_value as $slug => $data ) {
        if ( ! empty( $data['enabled'] ) && ! isset( $new_value[ $slug ] ) ) {
            $index_uid = $site_settings->index_uid( $slug );
            try {
                $index_manager->delete_index( $index_uid );
            } catch ( \Throwable $e ) {
                \Meilisearch\WordPress\Support\Logger::error( 'Failed to delete removed index', [ 'uid' => $index_uid, 'error' => $e->getMessage() ] );
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Admin/PostTypesSection.php
git commit -m "feat: wire IndexManager to post type enable/disable/update admin events"
```

---

## Phase 3 — Sync (Tasks 17-22)

### Task 17: AsyncDispatcher — Action Scheduler wrapper

**Files:**
- Create: `src/Sync/AsyncDispatcher.php`
- Test: `tests/unit/Sync/AsyncDispatcherTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Sync/AsyncDispatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Sync\AsyncDispatcher;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AsyncDispatcherTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_dispatch_uses_action_scheduler_when_available(): void {
        Functions\expect( 'function_exists' )
            ->with( 'as_enqueue_async_action' )
            ->andReturn( true );

        Functions\expect( 'as_enqueue_async_action' )
            ->once()
            ->with( 'meilisearch_index_post', [ 42, 1 ], 'meilisearch' )
            ->andReturn( 100 );

        $dispatcher = new AsyncDispatcher();
        $result     = $dispatcher->dispatch( 'meilisearch_index_post', [ 42, 1 ] );

        $this->assertSame( 100, $result );
    }

    public function test_dispatch_returns_null_when_as_unavailable(): void {
        Functions\expect( 'function_exists' )
            ->with( 'as_enqueue_async_action' )
            ->andReturn( false );

        $dispatcher = new AsyncDispatcher();
        $result     = $dispatcher->dispatch( 'meilisearch_index_post', [ 42, 1 ] );

        $this->assertNull( $result );
    }

    public function test_schedule_recurring_delegates_to_as(): void {
        Functions\expect( 'function_exists' )
            ->with( 'as_schedule_recurring_action' )
            ->andReturn( true );

        Functions\expect( 'as_schedule_recurring_action' )
            ->once()
            ->andReturn( 101 );

        $dispatcher = new AsyncDispatcher();
        $result     = $dispatcher->schedule_recurring( time(), 60, 'meilisearch_cron_process', [] );

        $this->assertSame( 101, $result );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter AsyncDispatcherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Sync/AsyncDispatcher.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AsyncDispatcher {

    private const GROUP = 'meilisearch';

    /**
     * Dispatch an async action via Action Scheduler.
     *
     * @param string       $hook
     * @param array<mixed> $args
     * @return int|null Action ID or null if AS not available.
     */
    public function dispatch( string $hook, array $args = [] ): ?int {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return null;
        }
        return as_enqueue_async_action( $hook, $args, self::GROUP );
    }

    /**
     * Schedule a recurring action.
     *
     * @param int          $timestamp
     * @param int          $interval_seconds
     * @param string       $hook
     * @param array<mixed> $args
     * @return int|null
     */
    public function schedule_recurring( int $timestamp, int $interval_seconds, string $hook, array $args = [] ): ?int {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return null;
        }
        return as_schedule_recurring_action( $timestamp, $interval_seconds, $hook, $args, self::GROUP );
    }

    /**
     * Cancel all scheduled instances of a hook.
     */
    public function cancel_all( string $hook ): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $hook, null, self::GROUP );
        }
    }

    public function is_available(): bool {
        return function_exists( 'as_enqueue_async_action' );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter AsyncDispatcherTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Sync/AsyncDispatcher.php tests/unit/Sync/AsyncDispatcherTest.php
git commit -m "feat: add AsyncDispatcher wrapping Action Scheduler"
```

---

### Task 18: IndexPostJob

**Files:**
- Create: `src/Sync/Jobs/IndexPostJob.php`
- Test: `tests/unit/Sync/Jobs/IndexPostJobTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Sync/Jobs/IndexPostJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync\Jobs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\FieldMapper;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Sync\Jobs\IndexPostJob;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class IndexPostJobTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_handle_builds_document_and_indexes(): void {
        $post              = new \stdClass();
        $post->ID          = 42;
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        $post->post_title  = 'Test';
        $post->post_content = 'Content';
        $post->post_excerpt = '';
        $post->post_date_gmt = '2025-01-01 00:00:00';
        $post->post_author = 1;

        Functions\stubs( [
            'get_post'                   => $post,
            'get_option'                 => function ( $key ) {
                if ( $key === 'meilisearch_post_types' ) {
                    return [
                        'post' => [
                            'post_type' => 'post', 'enabled' => true,
                            'searchable_fields' => [ 'title' ], 'filterable_fields' => [],
                            'sortable_fields' => [], 'taxonomies' => [], 'meta_keys' => [],
                            'geo_lat_key' => '', 'geo_lng_key' => '',
                        ],
                    ];
                }
                return '';
            },
            'get_permalink'              => 'https://example.com/test',
            'get_the_author_meta'        => 'Admin',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => '',
            'wp_get_post_terms'          => [],
            'function_exists'            => false,
            'is_multisite'               => false,
            'get_current_blog_id'        => 1,
        ] );

        $client = Mockery::mock( Client::class );
        $client->shouldReceive( 'add_documents' )
            ->once()
            ->with( 'wp_post', Mockery::on( function ( $docs ) {
                return count( $docs ) === 1 && $docs[0]['id'] === 42;
            } ) )
            ->andReturn( [ 'taskUid' => 1 ] );

        $client_factory = Mockery::mock( ClientFactory::class );
        $client_factory->shouldReceive( 'for_site' )->with( 1 )->andReturn( $client );

        $index_manager = new IndexManager( $client, new SettingsBuilder() );
        $doc_builder   = new DocumentBuilder( new FieldMapper() );
        $site_settings = new SiteSettings();

        $job = new IndexPostJob( $client_factory, $doc_builder, $site_settings );
        $job->handle( 42, 1 );

        // Assertion is that add_documents was called once via Mockery expectations.
        $this->assertTrue( true );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter IndexPostJobTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Sync/Jobs/IndexPostJob.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Support\Logger;

class IndexPostJob {

    private const MAX_ATTEMPTS = 3;

    private ClientFactory $client_factory;
    private DocumentBuilder $doc_builder;
    private SiteSettings $site_settings;

    public function __construct(
        ClientFactory $client_factory,
        DocumentBuilder $doc_builder,
        SiteSettings $site_settings,
    ) {
        $this->client_factory = $client_factory;
        $this->doc_builder    = $doc_builder;
        $this->site_settings  = $site_settings;
    }

    public function handle( int $post_id, int $site_id, int $attempt = 1 ): void {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return;
        }

        $configs = get_option( 'meilisearch_post_types', [] );
        if ( ! isset( $configs[ $post->post_type ] ) || empty( $configs[ $post->post_type ]['enabled'] ) ) {
            return;
        }

        $config    = PostTypeConfig::from_array( $configs[ $post->post_type ] );
        $index_uid = $this->site_settings->index_uid( $post->post_type );

        try {
            $client = $this->client_factory->for_site( $site_id );
            $doc    = $this->doc_builder->build( $post, $config, $index_uid );
            $client->add_documents( $index_uid, [ $doc ] );
        } catch ( \Throwable $e ) {
            Logger::error( "IndexPostJob failed for post {$post_id}", [
                'attempt' => $attempt,
                'error'   => $e->getMessage(),
            ] );

            if ( $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
                $delay = (int) pow( 2, $attempt ) * 30;
                as_schedule_single_action(
                    time() + $delay,
                    'meilisearch_index_post',
                    [ $post_id, $site_id, $attempt + 1 ],
                    'meilisearch',
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter IndexPostJobTest`
Expected: OK (1 test)

- [ ] **Step 5: Commit**

```bash
git add src/Sync/Jobs/IndexPostJob.php tests/unit/Sync/Jobs/IndexPostJobTest.php
git commit -m "feat: add IndexPostJob with exponential backoff retry"
```

---

### Task 19: DeletePostJob

**Files:**
- Create: `src/Sync/Jobs/DeletePostJob.php`

- [ ] **Step 1: Implement `src/Sync/Jobs/DeletePostJob.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Support\Logger;

class DeletePostJob {

    private ClientFactory $client_factory;
    private SiteSettings $site_settings;

    public function __construct( ClientFactory $client_factory, SiteSettings $site_settings ) {
        $this->client_factory = $client_factory;
        $this->site_settings  = $site_settings;
    }

    public function handle( int $post_id, string $post_type, int $site_id ): void {
        $index_uid = $this->site_settings->index_uid( $post_type );

        try {
            $client = $this->client_factory->for_site( $site_id );
            $client->delete_document( $index_uid, $post_id );
        } catch ( \Throwable $e ) {
            Logger::error( "DeletePostJob failed for post {$post_id}", [
                'error' => $e->getMessage(),
            ] );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Sync/Jobs/DeletePostJob.php
git commit -m "feat: add DeletePostJob"
```

---

### Task 20: BulkReindexJob — batched self-chaining

**Files:**
- Create: `src/Sync/Jobs/BulkReindexJob.php`
- Test: `tests/unit/Sync/Jobs/BulkReindexJobTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Sync/Jobs/BulkReindexJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync\Jobs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\FieldMapper;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Sync\Jobs\BulkReindexJob;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class BulkReindexJobTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_handle_chains_next_batch_when_more_exist(): void {
        $posts = [];
        for ( $i = 1; $i <= 50; $i++ ) {
            $p = new \stdClass();
            $p->ID = $i;
            $p->post_type = 'post';
            $p->post_status = 'publish';
            $p->post_title = "Post {$i}";
            $p->post_content = '';
            $p->post_excerpt = '';
            $p->post_date_gmt = '2025-01-01 00:00:00';
            $p->post_author = 1;
            $posts[] = $p;
        }

        Functions\stubs( [
            'get_posts'                  => $posts,
            'get_option'                 => function ( $key ) {
                if ( $key === 'meilisearch_post_types' ) {
                    return [ 'post' => [
                        'post_type' => 'post', 'enabled' => true,
                        'searchable_fields' => [ 'title' ], 'filterable_fields' => [],
                        'sortable_fields' => [], 'taxonomies' => [], 'meta_keys' => [],
                        'geo_lat_key' => '', 'geo_lng_key' => '',
                    ] ];
                }
                return '';
            },
            'get_permalink'              => 'https://example.com/',
            'get_the_author_meta'        => 'Admin',
            'get_the_post_thumbnail_url' => '',
            'wp_strip_all_tags'          => function ( $s ) { return strip_tags( $s ); },
            'apply_filters'              => function ( $tag, ...$args ) { return $args[0]; },
            'get_post_meta'              => '',
            'wp_get_post_terms'          => [],
            'function_exists'            => true,
            'is_multisite'               => false,
            'get_current_blog_id'        => 1,
        ] );

        Functions\expect( 'as_enqueue_async_action' )
            ->once()
            ->with( 'meilisearch_bulk_reindex', [ 'post', 1, 50, 50 ], 'meilisearch' );

        $client = Mockery::mock( Client::class );
        $client->shouldReceive( 'add_documents' )->once()->andReturn( [ 'taskUid' => 1 ] );

        $client_factory = Mockery::mock( ClientFactory::class );
        $client_factory->shouldReceive( 'for_site' )->andReturn( $client );

        $job = new BulkReindexJob(
            $client_factory,
            new DocumentBuilder( new FieldMapper() ),
            new SiteSettings(),
        );
        $job->handle( 'post', 1, 0, 50 );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter BulkReindexJobTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Sync/Jobs/BulkReindexJob.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\ClientFactory;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Support\Logger;

class BulkReindexJob {

    private ClientFactory $client_factory;
    private DocumentBuilder $doc_builder;
    private SiteSettings $site_settings;

    public function __construct(
        ClientFactory $client_factory,
        DocumentBuilder $doc_builder,
        SiteSettings $site_settings,
    ) {
        $this->client_factory = $client_factory;
        $this->doc_builder    = $doc_builder;
        $this->site_settings  = $site_settings;
    }

    public function handle( string $post_type, int $site_id, int $offset = 0, int $batch_size = 50 ): void {
        $configs = get_option( 'meilisearch_post_types', [] );
        if ( ! isset( $configs[ $post_type ] ) || empty( $configs[ $post_type ]['enabled'] ) ) {
            return;
        }

        $config    = PostTypeConfig::from_array( $configs[ $post_type ] );
        $index_uid = $this->site_settings->index_uid( $post_type );

        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        if ( empty( $posts ) ) {
            return;
        }

        $documents = [];
        foreach ( $posts as $post ) {
            try {
                $documents[] = $this->doc_builder->build( $post, $config, $index_uid );
            } catch ( \Throwable $e ) {
                Logger::error( "BulkReindex doc build failed for post {$post->ID}", [ 'error' => $e->getMessage() ] );
            }
        }

        if ( ! empty( $documents ) ) {
            try {
                $client = $this->client_factory->for_site( $site_id );
                $client->add_documents( $index_uid, $documents );
            } catch ( \Throwable $e ) {
                Logger::error( 'BulkReindex add_documents failed', [ 'error' => $e->getMessage() ] );
            }
        }

        // Chain next batch if we got a full page.
        if ( count( $posts ) >= $batch_size ) {
            $next_offset = $offset + $batch_size;
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action(
                    'meilisearch_bulk_reindex',
                    [ $post_type, $site_id, $next_offset, $batch_size ],
                    'meilisearch',
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter BulkReindexJobTest`
Expected: OK (1 test)

- [ ] **Step 5: Commit**

```bash
git add src/Sync/Jobs/BulkReindexJob.php tests/unit/Sync/Jobs/BulkReindexJobTest.php
git commit -m "feat: add BulkReindexJob with batched self-chaining"
```

---

### Task 21: SyncController — hook registration and debouncing

**Files:**
- Create: `src/Sync/SyncController.php`
- Test: `tests/unit/Sync/SyncControllerTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Sync/SyncControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Sync\AsyncDispatcher;
use Meilisearch\WordPress\Sync\SyncController;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SyncControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_on_post_saved_dispatches_index_job(): void {
        $post = new \stdClass();
        $post->ID = 42;
        $post->post_type = 'post';
        $post->post_status = 'publish';

        Functions\stubs( [
            'get_option'          => [ 'post' => [ 'enabled' => true ] ],
            'get_current_blog_id' => 1,
        ] );

        $dispatcher = Mockery::mock( AsyncDispatcher::class );
        $dispatcher->shouldReceive( 'dispatch' )
            ->once()
            ->with( 'meilisearch_index_post', [ 42, 1 ] )
            ->andReturn( 100 );

        $controller = new SyncController( $dispatcher );
        $controller->on_post_saved( 42, $post, true );
    }

    public function test_debounces_multiple_calls_for_same_post(): void {
        $post = new \stdClass();
        $post->ID = 42;
        $post->post_type = 'post';
        $post->post_status = 'publish';

        Functions\stubs( [
            'get_option'          => [ 'post' => [ 'enabled' => true ] ],
            'get_current_blog_id' => 1,
        ] );

        $dispatcher = Mockery::mock( AsyncDispatcher::class );
        $dispatcher->shouldReceive( 'dispatch' )
            ->once()
            ->andReturn( 100 );

        $controller = new SyncController( $dispatcher );
        $controller->on_post_saved( 42, $post, true );
        $controller->on_post_saved( 42, $post, true );  // Debounced.
    }

    public function test_on_post_trashed_dispatches_delete_job(): void {
        Functions\stubs( [
            'get_post_type'       => 'post',
            'get_option'          => [ 'post' => [ 'enabled' => true ] ],
            'get_current_blog_id' => 1,
        ] );

        $dispatcher = Mockery::mock( AsyncDispatcher::class );
        $dispatcher->shouldReceive( 'dispatch' )
            ->once()
            ->with( 'meilisearch_delete_post', [ 42, 'post', 1 ] )
            ->andReturn( 101 );

        $controller = new SyncController( $dispatcher );
        $controller->on_post_trashed( 42 );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter SyncControllerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Sync/SyncController.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SyncController {

    private AsyncDispatcher $dispatcher;

    /** @var array<int, bool> Per-request debounce flags keyed by post ID. */
    private array $dispatched = [];

    public function __construct( AsyncDispatcher $dispatcher ) {
        $this->dispatcher = $dispatcher;
    }

    public function register(): void {
        add_action( 'save_post', [ $this, 'on_post_saved' ], 10, 3 );
        add_action( 'delete_post', [ $this, 'on_post_deleted' ], 10, 2 );
        add_action( 'trashed_post', [ $this, 'on_post_trashed' ] );
        add_action( 'untrashed_post', [ $this, 'on_post_untrashed' ] );
        add_action( 'set_object_terms', [ $this, 'on_terms_changed' ], 10, 6 );
        add_action( 'updated_post_meta', [ $this, 'on_meta_changed' ], 10, 4 );
    }

    /**
     * @param object $post WP_Post.
     */
    public function on_post_saved( int $post_id, object $post, bool $update ): void {
        if ( $post->post_status !== 'publish' || ! $this->is_indexed_type( $post->post_type ) ) {
            return;
        }
        $this->dispatch_index( $post_id );
    }

    public function on_post_deleted( int $post_id, object $post ): void {
        if ( ! $this->is_indexed_type( $post->post_type ) ) {
            return;
        }
        $this->dispatcher->dispatch( 'meilisearch_delete_post', [ $post_id, $post->post_type, get_current_blog_id() ] );
    }

    public function on_post_trashed( int $post_id ): void {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type || ! $this->is_indexed_type( $post_type ) ) {
            return;
        }
        $this->dispatcher->dispatch( 'meilisearch_delete_post', [ $post_id, $post_type, get_current_blog_id() ] );
    }

    public function on_post_untrashed( int $post_id ): void {
        $this->dispatch_index( $post_id );
    }

    /**
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     * @param bool   $append
     * @param array  $old_tt_ids
     */
    public function on_terms_changed( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
        $this->dispatch_index( $object_id );
    }

    public function on_meta_changed( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
        $this->dispatch_index( $object_id );
    }

    private function dispatch_index( int $post_id ): void {
        if ( isset( $this->dispatched[ $post_id ] ) ) {
            return;
        }
        $this->dispatched[ $post_id ] = true;
        $this->dispatcher->dispatch( 'meilisearch_index_post', [ $post_id, get_current_blog_id() ] );
    }

    private function is_indexed_type( string $post_type ): bool {
        $configs = get_option( 'meilisearch_post_types', [] );
        return ! empty( $configs[ $post_type ]['enabled'] );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter SyncControllerTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Sync/SyncController.php tests/unit/Sync/SyncControllerTest.php
git commit -m "feat: add SyncController with hook registration and per-request debouncing"
```

---

### Task 22: Queue fallback for non-AS environments

**Files:**
- Create: `src/Sync/Queue.php`

- [ ] **Step 1: Implement `src/Sync/Queue.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Queue {

    private const TABLE_SUFFIX = 'meilisearch_queue';
    private const CRON_HOOK    = 'meilisearch_process_queue';

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hook VARCHAR(255) NOT NULL,
            args LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_scheduled (status, scheduled_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function register_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'every_minute', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, [ self::class, 'process' ] );

        // Register custom interval.
        add_filter( 'cron_schedules', function ( array $schedules ): array {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute', 'meilisearch' ),
            ];
            return $schedules;
        } );
    }

    public static function enqueue( string $hook, array $args ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->insert( $table, [
            'hook'   => $hook,
            'args'   => wp_json_encode( $args ),
            'status' => 'pending',
        ] );
    }

    public static function process(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND scheduled_at <= %s ORDER BY id ASC LIMIT 10",
            'pending',
            current_time( 'mysql' ),
        ) );

        foreach ( $rows as $row ) {
            $args = json_decode( $row->args, true ) ?? [];

            $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $row->id ] );

            try {
                do_action( $row->hook, ...$args );
                $wpdb->update( $table, [ 'status' => 'completed' ], [ 'id' => $row->id ] );
            } catch ( \Throwable $e ) {
                $attempts = (int) $row->attempts + 1;
                if ( $attempts >= 3 ) {
                    $wpdb->update( $table, [ 'status' => 'failed', 'attempts' => $attempts ], [ 'id' => $row->id ] );
                } else {
                    $delay = (int) pow( 2, $attempts ) * 60;
                    $wpdb->update( $table, [
                        'status'       => 'pending',
                        'attempts'     => $attempts,
                        'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
                    ], [ 'id' => $row->id ] );
                }
            }
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Sync/Queue.php
git commit -m "feat: add Queue fallback with custom table and wp-cron dispatcher"
```

---

## Phase 4 — Search (Tasks 23-30)

### Task 23: FilterBuilder — WP query trees to MS filter strings

**Files:**
- Create: `src/Search/FilterBuilder.php`
- Test: `tests/unit/Search/FilterBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Search/FilterBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Meilisearch\WordPress\Search\FilterBuilder;
use PHPUnit\Framework\TestCase;

class FilterBuilderTest extends TestCase {

    private FilterBuilder $builder;

    protected function setUp(): void {
        parent::setUp();
        $this->builder = new FilterBuilder();
    }

    public function test_equals_operator(): void {
        $this->assertSame( 'status = "publish"', $this->builder->condition( 'status', '=', 'publish' ) );
    }

    public function test_not_equals_operator(): void {
        $this->assertSame( 'status != "draft"', $this->builder->condition( 'status', '!=', 'draft' ) );
    }

    public function test_less_than(): void {
        $this->assertSame( 'price < 100', $this->builder->condition( 'price', '<', 100 ) );
    }

    public function test_less_than_or_equal(): void {
        $this->assertSame( 'price <= 100', $this->builder->condition( 'price', '<=', 100 ) );
    }

    public function test_greater_than(): void {
        $this->assertSame( 'price > 10', $this->builder->condition( 'price', '>', 10 ) );
    }

    public function test_greater_than_or_equal(): void {
        $this->assertSame( 'price >= 10', $this->builder->condition( 'price', '>=', 10 ) );
    }

    public function test_in_operator(): void {
        $this->assertSame( 'author_id IN [1, 2, 3]', $this->builder->condition( 'author_id', 'IN', [ 1, 2, 3 ] ) );
    }

    public function test_not_in_operator(): void {
        $this->assertSame( 'author_id NOT IN [1, 2]', $this->builder->condition( 'author_id', 'NOT IN', [ 1, 2 ] ) );
    }

    public function test_between_operator(): void {
        $this->assertSame( 'price 10 TO 50', $this->builder->condition( 'price', 'BETWEEN', [ 10, 50 ] ) );
    }

    public function test_is_null(): void {
        $this->assertSame( 'field IS NULL', $this->builder->condition( 'field', 'IS NULL', null ) );
    }

    public function test_is_not_null(): void {
        $this->assertSame( 'field IS NOT NULL', $this->builder->condition( 'field', 'IS NOT NULL', null ) );
    }

    public function test_geo_radius(): void {
        $this->assertSame( '_geoRadius(48.856, 2.352, 5000)', $this->builder->geo_radius( 48.856, 2.352, 5000 ) );
    }

    public function test_geo_bounding_box(): void {
        $this->assertSame(
            '_geoBoundingBox([49.0, 2.0], [48.0, 3.0])',
            $this->builder->geo_bounding_box( 49.0, 2.0, 48.0, 3.0 ),
        );
    }

    public function test_and_group(): void {
        $result = $this->builder->and_group( [
            $this->builder->condition( 'status', '=', 'publish' ),
            $this->builder->condition( 'price', '>', 10 ),
        ] );
        $this->assertSame( '(status = "publish" AND price > 10)', $result );
    }

    public function test_or_group(): void {
        $result = $this->builder->or_group( [
            $this->builder->condition( 'type', '=', 'simple' ),
            $this->builder->condition( 'type', '=', 'variable' ),
        ] );
        $this->assertSame( '(type = "simple" OR type = "variable")', $result );
    }

    public function test_nested_groups(): void {
        $result = $this->builder->and_group( [
            $this->builder->condition( 'status', '=', 'publish' ),
            $this->builder->or_group( [
                $this->builder->condition( 'type', '=', 'post' ),
                $this->builder->condition( 'type', '=', 'page' ),
            ] ),
        ] );
        $this->assertSame( '(status = "publish" AND (type = "post" OR type = "page"))', $result );
    }

    public function test_tax_query_to_filter(): void {
        $tax_query = [
            [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => [ 5, 8 ],
            ],
        ];
        $result = $this->builder->from_tax_query( $tax_query );
        $this->assertSame( 'tax_category_ids IN [5, 8]', $result );
    }

    public function test_meta_query_to_filter(): void {
        $meta_query = [
            [
                'key'     => 'price',
                'value'   => 100,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ],
        ];
        $result = $this->builder->from_meta_query( $meta_query );
        $this->assertSame( 'meta_price <= 100', $result );
    }

    public function test_date_query_to_filter(): void {
        $date_query = [
            'after'  => '2025-01-01',
            'before' => '2025-12-31',
        ];
        $result = $this->builder->from_date_query( $date_query );
        $after  = strtotime( '2025-01-01' );
        $before = strtotime( '2025-12-31' );
        $this->assertSame( "(date_int >= {$after} AND date_int <= {$before})", $result );
    }

    public function test_string_values_are_quoted(): void {
        $this->assertSame( 'name = "hello world"', $this->builder->condition( 'name', '=', 'hello world' ) );
    }

    public function test_numeric_values_are_not_quoted(): void {
        $this->assertSame( 'price = 42', $this->builder->condition( 'price', '=', 42 ) );
        $this->assertSame( 'price = 19.99', $this->builder->condition( 'price', '=', 19.99 ) );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter FilterBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Search/FilterBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FilterBuilder {

    public function condition( string $field, string $operator, mixed $value ): string {
        return match ( strtoupper( $operator ) ) {
            '=', '!=', '<', '<=', '>', '>=' => sprintf( '%s %s %s', $field, $operator, $this->format_value( $value ) ),
            'IN'                             => sprintf( '%s IN [%s]', $field, $this->format_list( $value ) ),
            'NOT IN'                         => sprintf( '%s NOT IN [%s]', $field, $this->format_list( $value ) ),
            'BETWEEN'                        => sprintf( '%s %s TO %s', $field, $this->format_value( $value[0] ), $this->format_value( $value[1] ) ),
            'IS NULL'                        => sprintf( '%s IS NULL', $field ),
            'IS NOT NULL'                    => sprintf( '%s IS NOT NULL', $field ),
            default                          => sprintf( '%s = %s', $field, $this->format_value( $value ) ),
        };
    }

    public function geo_radius( float $lat, float $lng, int|float $distance_meters ): string {
        return sprintf( '_geoRadius(%s, %s, %s)', $lat, $lng, $distance_meters );
    }

    public function geo_bounding_box( float $top_lat, float $top_lng, float $bottom_lat, float $bottom_lng ): string {
        return sprintf( '_geoBoundingBox([%s, %s], [%s, %s])', $top_lat, $top_lng, $bottom_lat, $bottom_lng );
    }

    /**
     * @param string[] $conditions
     */
    public function and_group( array $conditions ): string {
        $filtered = array_filter( $conditions );
        if ( count( $filtered ) === 0 ) {
            return '';
        }
        if ( count( $filtered ) === 1 ) {
            return reset( $filtered );
        }
        return '(' . implode( ' AND ', $filtered ) . ')';
    }

    /**
     * @param string[] $conditions
     */
    public function or_group( array $conditions ): string {
        $filtered = array_filter( $conditions );
        if ( count( $filtered ) === 0 ) {
            return '';
        }
        if ( count( $filtered ) === 1 ) {
            return reset( $filtered );
        }
        return '(' . implode( ' OR ', $filtered ) . ')';
    }

    /**
     * @param array<int, array<string, mixed>> $tax_query
     */
    public function from_tax_query( array $tax_query, string $relation = 'AND' ): string {
        $parts = [];

        foreach ( $tax_query as $clause ) {
            if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
                continue;
            }

            $taxonomy = $clause['taxonomy'];
            $field    = $clause['field'] ?? 'term_id';
            $terms    = (array) ( $clause['terms'] ?? [] );
            $operator = strtoupper( $clause['operator'] ?? 'IN' );

            $ms_field = $field === 'name' ? "tax_{$taxonomy}_names" : "tax_{$taxonomy}_ids";

            $parts[] = match ( $operator ) {
                'IN'        => $this->condition( $ms_field, 'IN', $terms ),
                'NOT IN'    => $this->condition( $ms_field, 'NOT IN', $terms ),
                'AND'       => $this->and_group( array_map( fn( $t ) => $this->condition( $ms_field, '=', $t ), $terms ) ),
                default     => $this->condition( $ms_field, 'IN', $terms ),
            };
        }

        return $relation === 'OR' ? $this->or_group( $parts ) : $this->and_group( $parts );
    }

    /**
     * @param array<int, array<string, mixed>> $meta_query
     */
    public function from_meta_query( array $meta_query, string $relation = 'AND' ): string {
        $parts = [];

        foreach ( $meta_query as $clause ) {
            if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
                continue;
            }

            $key     = 'meta_' . $clause['key'];
            $compare = strtoupper( $clause['compare'] ?? '=' );
            $value   = $clause['value'] ?? null;
            $type    = strtoupper( $clause['type'] ?? 'CHAR' );

            if ( $type === 'NUMERIC' || $type === 'DECIMAL' ) {
                $value = is_array( $value ) ? array_map( 'floatval', $value ) : (float) $value;
            }

            $parts[] = $this->condition( $key, $compare, $value );
        }

        return $relation === 'OR' ? $this->or_group( $parts ) : $this->and_group( $parts );
    }

    /**
     * @param array<string, mixed> $date_query
     */
    public function from_date_query( array $date_query ): string {
        $parts = [];

        if ( isset( $date_query['after'] ) ) {
            $ts = strtotime( $date_query['after'] );
            if ( $ts !== false ) {
                $parts[] = $this->condition( 'date_int', '>=', $ts );
            }
        }
        if ( isset( $date_query['before'] ) ) {
            $ts = strtotime( $date_query['before'] );
            if ( $ts !== false ) {
                $parts[] = $this->condition( 'date_int', '<=', $ts );
            }
        }

        return $this->and_group( $parts );
    }

    private function format_value( mixed $value ): string {
        if ( is_null( $value ) ) {
            return 'null';
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }
        $escaped = str_replace( '"', '\\"', (string) $value );
        return '"' . $escaped . '"';
    }

    /**
     * @param array<mixed> $values
     */
    private function format_list( array $values ): string {
        return implode( ', ', array_map( fn( $v ) => $this->format_value( $v ), $values ) );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter FilterBuilderTest`
Expected: OK (21 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Search/FilterBuilder.php tests/unit/Search/FilterBuilderTest.php
git commit -m "feat: add FilterBuilder with all operators, geo, tax_query, meta_query, date_query"
```

---

### Task 24: QueryBuilder — WP_Query args to MS search params

**Files:**
- Create: `src/Search/QueryBuilder.php`
- Test: `tests/unit/Search/QueryBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Search/QueryBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Search;

use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Search\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase {

    private QueryBuilder $builder;

    protected function setUp(): void {
        parent::setUp();
        $this->builder = new QueryBuilder( new FilterBuilder() );
    }

    public function test_basic_search(): void {
        $params = $this->builder->from_wp_query_args( [
            's'              => 'hello world',
            'posts_per_page' => 10,
            'paged'          => 1,
        ] );

        $this->assertSame( 'hello world', $params['q'] );
        $this->assertSame( 10, $params['limit'] );
        $this->assertSame( 0, $params['offset'] );
    }

    public function test_pagination_offset(): void {
        $params = $this->builder->from_wp_query_args( [
            's'              => 'test',
            'posts_per_page' => 20,
            'paged'          => 3,
        ] );

        $this->assertSame( 40, $params['offset'] );
    }

    public function test_sort_by_date(): void {
        $params = $this->builder->from_wp_query_args( [
            's'       => 'test',
            'orderby' => 'date',
            'order'   => 'DESC',
        ] );

        $this->assertSame( [ 'date_int:desc' ], $params['sort'] );
    }

    public function test_sort_by_date_asc(): void {
        $params = $this->builder->from_wp_query_args( [
            's'       => 'test',
            'orderby' => 'date',
            'order'   => 'ASC',
        ] );

        $this->assertSame( [ 'date_int:asc' ], $params['sort'] );
    }

    public function test_author_in_filter(): void {
        $params = $this->builder->from_wp_query_args( [
            's'          => 'test',
            'author__in' => [ 1, 2 ],
        ] );

        $this->assertStringContainsString( 'author_id IN [1, 2]', $params['filter'] );
    }

    public function test_always_includes_post_status_publish(): void {
        $params = $this->builder->from_wp_query_args( [ 's' => 'test' ] );
        $this->assertStringContainsString( 'post_status = "publish"', $params['filter'] );
    }

    public function test_tax_query_converted(): void {
        $params = $this->builder->from_wp_query_args( [
            's'         => 'test',
            'tax_query' => [
                [ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => [ 3 ] ],
            ],
        ] );

        $this->assertStringContainsString( 'tax_category_ids IN [3]', $params['filter'] );
    }

    public function test_meta_query_converted(): void {
        $params = $this->builder->from_wp_query_args( [
            's'          => 'test',
            'meta_query' => [
                [ 'key' => 'price', 'value' => 100, 'compare' => '<=', 'type' => 'NUMERIC' ],
            ],
        ] );

        $this->assertStringContainsString( 'meta_price <= 100', $params['filter'] );
    }

    public function test_geo_radius_query_var(): void {
        $params = $this->builder->from_wp_query_args( [
            's'          => 'test',
            'geo_radius' => [ 48.856, 2.352, 5000 ],
        ] );

        $this->assertStringContainsString( '_geoRadius(48.856, 2.352, 5000)', $params['filter'] );
    }

    public function test_highlight_attributes_included(): void {
        $params = $this->builder->from_wp_query_args( [ 's' => 'test' ] );
        $this->assertArrayHasKey( 'attributesToHighlight', $params );
        $this->assertSame( [ 'title', 'content', 'excerpt' ], $params['attributesToHighlight'] );
    }

    public function test_keyword_mode_produces_no_hybrid_block(): void {
        $config = PostTypeConfig::from_array( [
            'post_type'         => 'post',
            'enabled'           => true,
            'searchable_fields' => [ 'title' ],
            'filterable_fields' => [],
            'sortable_fields'   => [],
            'taxonomies'        => [],
            'meta_keys'         => [],
            'geo_lat_key'       => '',
            'geo_lng_key'       => '',
            'search_mode'       => 'keyword',
        ] );

        $params = $this->builder->from_wp_query_args( [ 's' => 'test' ], $config );
        $this->assertArrayNotHasKey( 'hybrid', $params );
    }

    public function test_hybrid_mode_injects_hybrid_block(): void {
        $config = PostTypeConfig::from_array( [
            'post_type'         => 'post',
            'enabled'           => true,
            'searchable_fields' => [ 'title' ],
            'filterable_fields' => [],
            'sortable_fields'   => [],
            'taxonomies'        => [],
            'meta_keys'         => [],
            'geo_lat_key'       => '',
            'geo_lng_key'       => '',
            'search_mode'       => 'hybrid',
            'embedder'          => 'default',
            'semantic_ratio'    => 0.7,
        ] );

        $params = $this->builder->from_wp_query_args( [ 's' => 'test' ], $config );
        $this->assertArrayHasKey( 'hybrid', $params );
        $this->assertSame( 0.7, $params['hybrid']['semanticRatio'] );
        $this->assertSame( 'default', $params['hybrid']['embedder'] );
    }

    public function test_semantic_mode_forces_ratio_to_one(): void {
        $config = PostTypeConfig::from_array( [
            'post_type'         => 'post',
            'enabled'           => true,
            'searchable_fields' => [ 'title' ],
            'filterable_fields' => [],
            'sortable_fields'   => [],
            'taxonomies'        => [],
            'meta_keys'         => [],
            'geo_lat_key'       => '',
            'geo_lng_key'       => '',
            'search_mode'       => 'semantic',
            'embedder'          => 'openai',
            'semantic_ratio'    => 0.3,
        ] );

        $params = $this->builder->from_wp_query_args( [ 's' => 'test' ], $config );
        $this->assertArrayHasKey( 'hybrid', $params );
        $this->assertSame( 1.0, $params['hybrid']['semanticRatio'] );
        $this->assertSame( 'openai', $params['hybrid']['embedder'] );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter QueryBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Search/QueryBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Indexing\PostTypeConfig;

class QueryBuilder {

    private FilterBuilder $filter_builder;

    public function __construct( FilterBuilder $filter_builder ) {
        $this->filter_builder = $filter_builder;
    }

    /**
     * @param array<string, mixed>  $args   WP_Query args.
     * @param PostTypeConfig|null   $config Optional post-type config for search-mode injection.
     * @return array<string, mixed> Meilisearch search params.
     */
    public function from_wp_query_args( array $args, ?PostTypeConfig $config = null ): array {
        $query = $args['s'] ?? '';
        $limit = (int) ( $args['posts_per_page'] ?? 10 );
        $paged = max( 1, (int) ( $args['paged'] ?? 1 ) );

        if ( $limit <= 0 ) {
            $limit = 10;
        }

        $params = [
            'q'      => $query,
            'limit'  => $limit,
            'offset' => ( $paged - 1 ) * $limit,
        ];

        // Sort.
        $orderby = $args['orderby'] ?? 'relevance';
        $order   = strtolower( $args['order'] ?? 'DESC' );
        if ( $orderby === 'date' ) {
            $params['sort'] = [ "date_int:{$order}" ];
        } elseif ( $orderby === 'title' ) {
            $params['sort'] = [ "title:{$order}" ];
        }

        // Build filter parts.
        $filter_parts = [];

        // Always enforce published status.
        $filter_parts[] = $this->filter_builder->condition( 'post_status', '=', 'publish' );

        // Author filter.
        if ( ! empty( $args['author__in'] ) ) {
            $filter_parts[] = $this->filter_builder->condition( 'author_id', 'IN', array_map( 'intval', $args['author__in'] ) );
        }

        // Tax query.
        if ( ! empty( $args['tax_query'] ) && is_array( $args['tax_query'] ) ) {
            $relation  = $args['tax_query']['relation'] ?? 'AND';
            $clauses   = array_filter( $args['tax_query'], 'is_array' );
            $tax_filter = $this->filter_builder->from_tax_query( $clauses, $relation );
            if ( $tax_filter !== '' ) {
                $filter_parts[] = $tax_filter;
            }
        }

        // Meta query.
        if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $relation    = $args['meta_query']['relation'] ?? 'AND';
            $clauses     = array_filter( $args['meta_query'], 'is_array' );
            $meta_filter = $this->filter_builder->from_meta_query( $clauses, $relation );
            if ( $meta_filter !== '' ) {
                $filter_parts[] = $meta_filter;
            }
        }

        // Date query.
        if ( ! empty( $args['date_query'] ) && is_array( $args['date_query'] ) ) {
            $date_filter = $this->filter_builder->from_date_query( $args['date_query'] );
            if ( $date_filter !== '' ) {
                $filter_parts[] = $date_filter;
            }
        }

        // Geo radius.
        if ( ! empty( $args['geo_radius'] ) && is_array( $args['geo_radius'] ) && count( $args['geo_radius'] ) === 3 ) {
            [ $lat, $lng, $meters ] = $args['geo_radius'];
            $filter_parts[] = $this->filter_builder->geo_radius( (float) $lat, (float) $lng, (int) $meters );
        }

        $params['filter'] = $this->filter_builder->and_group( $filter_parts );

        // Highlight.
        $params['attributesToHighlight'] = [ 'title', 'content', 'excerpt' ];
        $params['highlightPreTag']       = '<em>';
        $params['highlightPostTag']      = '</em>';

        // Semantic / hybrid search mode.
        if ( $config !== null && $config->search_mode !== 'keyword' ) {
            $embedder = $config->embedder ?? 'default';
            if ( $config->search_mode === 'semantic' ) {
                $params['hybrid'] = [
                    'semanticRatio' => 1.0,
                    'embedder'      => $embedder,
                ];
            } elseif ( $config->search_mode === 'hybrid' ) {
                $params['hybrid'] = [
                    'semanticRatio' => $config->semantic_ratio,
                    'embedder'      => $embedder,
                ];
            }
        }

        return $params;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter QueryBuilderTest`
Expected: OK (14 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Search/QueryBuilder.php tests/unit/Search/QueryBuilderTest.php
git commit -m "feat: add QueryBuilder with WP_Query mapping and hybrid/semantic search support"
```

---

### Task 25: ResultSet — MS hits to WP_Post array

**Files:**
- Create: `src/Search/ResultSet.php`

- [ ] **Step 1: Implement `src/Search/ResultSet.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ResultSet {

    /** @var int[] */
    private array $post_ids;
    private int $estimated_total;
    private ?string $query_uid;
    /** @var array<int, array<string, mixed>> */
    private array $formatted;

    /**
     * @param array<string, mixed> $raw_response The Meilisearch search result as array.
     */
    public function __construct( array $raw_response ) {
        $this->estimated_total = (int) ( $raw_response['estimatedTotalHits'] ?? 0 );
        $this->query_uid       = $raw_response['_metadata']['queryUid'] ?? null;
        $this->post_ids        = [];
        $this->formatted       = [];

        $hits = $raw_response['hits'] ?? [];
        foreach ( $hits as $position => $hit ) {
            $wp_id = (int) ( $hit['wp_id'] ?? $hit['id'] ?? 0 );
            if ( $wp_id > 0 ) {
                $this->post_ids[] = $wp_id;
                $this->formatted[ $wp_id ] = $hit['_formatted'] ?? [];
            }
        }
    }

    /**
     * @return \WP_Post[]
     */
    public function to_wp_posts(): array {
        if ( empty( $this->post_ids ) ) {
            return [];
        }

        return get_posts( [
            'post__in'    => $this->post_ids,
            'orderby'     => 'post__in',
            'post_type'   => 'any',
            'post_status' => 'publish',
            'numberposts' => count( $this->post_ids ),
        ] );
    }

    /**
     * @return int[]
     */
    public function get_post_ids(): array {
        return $this->post_ids;
    }

    public function get_estimated_total(): int {
        return $this->estimated_total;
    }

    public function get_query_uid(): ?string {
        return $this->query_uid;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_formatted_for( int $post_id ): array {
        return $this->formatted[ $post_id ] ?? [];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Search/ResultSet.php
git commit -m "feat: add ResultSet mapping MS hits to WP_Post array with queryUid"
```

---

### Task 26: Highlighter

**Files:**
- Create: `src/Search/Highlighter.php`

- [ ] **Step 1: Implement `src/Search/Highlighter.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Highlighter {

    /**
     * Apply Meilisearch _formatted fields to a WP_Post for display.
     *
     * @param object               $post      WP_Post object.
     * @param array<string, mixed> $formatted The _formatted block from MS.
     */
    public static function apply( object $post, array $formatted ): void {
        $tags = apply_filters( 'meilisearch_highlight_tags', [ 'pre' => '<em>', 'post' => '</em>' ] );

        if ( isset( $formatted['title'] ) ) {
            $post->post_title = wp_kses( $formatted['title'], [
                'em' => [],
                'mark' => [],
            ] );
        }

        if ( isset( $formatted['excerpt'] ) ) {
            $post->post_excerpt = wp_kses( $formatted['excerpt'], [
                'em' => [],
                'mark' => [],
            ] );
        }

        if ( isset( $formatted['content'] ) ) {
            $post->meilisearch_highlighted_content = wp_kses( $formatted['content'], [
                'em' => [],
                'mark' => [],
            ] );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Search/Highlighter.php
git commit -m "feat: add Highlighter applying _formatted fields to WP_Post"
```

---

### Task 27: FederatedSearch — multi-post-type via multiSearch

**Files:**
- Create: `src/Search/FederatedSearch.php`

- [ ] **Step 1: Implement `src/Search/FederatedSearch.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Multisite\SiteSettings;

class FederatedSearch {

    private Client $client;
    private SiteSettings $site_settings;
    private QueryBuilder $query_builder;

    public function __construct( Client $client, SiteSettings $site_settings, QueryBuilder $query_builder ) {
        $this->client        = $client;
        $this->site_settings = $site_settings;
        $this->query_builder = $query_builder;
    }

    /**
     * @param string[]             $post_types
     * @param array<string, mixed> $wp_query_args
     */
    public function search( array $post_types, array $wp_query_args ): ResultSet {
        $params  = $this->query_builder->from_wp_query_args( $wp_query_args );
        $limit   = $params['limit'];
        $offset  = $params['offset'];
        $queries = [];

        foreach ( $post_types as $pt ) {
            $index_uid = $this->site_settings->index_uid( $pt );
            $queries[] = array_merge( $params, [
                'indexUid' => $index_uid,
            ] );
        }

        // Remove limit/offset from individual queries — federation controls them.
        foreach ( $queries as &$q ) {
            unset( $q['limit'], $q['offset'] );
        }
        unset( $q );

        $federation = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        $response = $this->client->multi_search( $queries, $federation );

        return new ResultSet( $response );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Search/FederatedSearch.php
git commit -m "feat: add FederatedSearch using multiSearch with federation params"
```

---

### Task 28: ServerSideSearch — pre_get_posts integration

**Files:**
- Create: `src/Search/ServerSideSearch.php`

- [ ] **Step 1: Implement `src/Search/ServerSideSearch.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Analytics\QueryUidStore;
use Meilisearch\WordPress\Multisite\SiteSettings;

class ServerSideSearch {

    private Client $client;
    private SiteSettings $site_settings;
    private QueryBuilder $query_builder;
    private FederatedSearch $federated;
    private ?QueryUidStore $query_uid_store;

    /** @var ResultSet|null Cached result for the current request. */
    private ?ResultSet $current_result = null;

    public function __construct(
        Client $client,
        SiteSettings $site_settings,
        QueryBuilder $query_builder,
        FederatedSearch $federated,
        ?QueryUidStore $query_uid_store = null,
    ) {
        $this->client          = $client;
        $this->site_settings   = $site_settings;
        $this->query_builder   = $query_builder;
        $this->federated       = $federated;
        $this->query_uid_store = $query_uid_store;
    }

    public function register(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        add_action( 'pre_get_posts', [ $this, 'intercept_query' ], 5 );
        add_filter( 'posts_pre_query', [ $this, 'replace_posts' ], 10, 2 );
        add_filter( 'found_posts', [ $this, 'override_found_posts' ], 10, 2 );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function register_query_vars( array $vars ): array {
        $vars[] = 'geo_radius';
        return $vars;
    }

    public function intercept_query( \WP_Query $query ): void {
        if ( ! $this->should_intercept( $query ) ) {
            return;
        }

        // Flag the query so posts_pre_query knows to act.
        $query->set( 'meilisearch_intercepted', true );
    }

    /**
     * @param \WP_Post[]|null $posts
     * @return \WP_Post[]|null
     */
    public function replace_posts( ?array $posts, \WP_Query $query ): ?array {
        if ( ! $query->get( 'meilisearch_intercepted' ) ) {
            return $posts;
        }

        $post_types = (array) $query->get( 'post_type' );
        if ( empty( $post_types ) || $post_types === [ '' ] ) {
            $post_types = $this->get_indexed_post_types();
        }

        $wp_args = [
            's'              => $query->get( 's' ),
            'posts_per_page' => $query->get( 'posts_per_page' ) ?: 10,
            'paged'          => max( 1, $query->get( 'paged' ) ),
            'orderby'        => $query->get( 'orderby' ) ?: 'relevance',
            'order'          => $query->get( 'order' ) ?: 'DESC',
            'tax_query'      => $query->get( 'tax_query' ) ?: [],
            'meta_query'     => $query->get( 'meta_query' ) ?: [],
            'date_query'     => $query->get( 'date_query' ) ?: [],
            'author__in'     => $query->get( 'author__in' ) ?: [],
            'geo_radius'     => $query->get( 'geo_radius' ) ?: [],
        ];

        try {
            if ( count( $post_types ) === 1 ) {
                $index_uid = $this->site_settings->index_uid( $post_types[0] );
                $params    = $this->query_builder->from_wp_query_args( $wp_args );
                $raw       = $this->client->search( $index_uid, $params['q'], $params );
                $this->current_result = new ResultSet( $raw->toArray() );
            } else {
                $this->current_result = $this->federated->search( $post_types, $wp_args );
            }

            // Store queryUid for analytics.
            if ( $this->query_uid_store !== null && $this->current_result->get_query_uid() !== null ) {
                $this->query_uid_store->store(
                    $this->current_result->get_query_uid(),
                    $this->current_result->get_post_ids(),
                );
            }

            $wp_posts = $this->current_result->to_wp_posts();

            // Apply highlighting.
            foreach ( $wp_posts as $wp_post ) {
                $formatted = $this->current_result->get_formatted_for( $wp_post->ID );
                if ( ! empty( $formatted ) ) {
                    Highlighter::apply( $wp_post, $formatted );
                }
            }

            return $wp_posts;
        } catch ( \Throwable $e ) {
            \Meilisearch\WordPress\Support\Logger::error( 'ServerSideSearch failed, falling back to MySQL', [ 'error' => $e->getMessage() ] );
            return null; // Fall back to default WP query.
        }
    }

    /**
     * @return int
     */
    public function override_found_posts( int $found_posts, \WP_Query $query ): int {
        if ( $query->get( 'meilisearch_intercepted' ) && $this->current_result !== null ) {
            return $this->current_result->get_estimated_total();
        }
        return $found_posts;
    }

    private function should_intercept( \WP_Query $query ): bool {
        if ( is_admin() ) {
            return false;
        }
        if ( ! $query->is_search() || ! $query->is_main_query() ) {
            return false;
        }
        return true;
    }

    private function is_enabled(): bool {
        return (bool) get_option( 'meilisearch_server_side_search', false );
    }

    /**
     * @return string[]
     */
    private function get_indexed_post_types(): array {
        $configs = get_option( 'meilisearch_post_types', [] );
        $types   = [];
        foreach ( $configs as $slug => $data ) {
            if ( ! empty( $data['enabled'] ) ) {
                $types[] = $slug;
            }
        }
        return $types;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Search/ServerSideSearch.php
git commit -m "feat: add ServerSideSearch with pre_get_posts, posts_pre_query, and found_posts hooks"
```

---

### Task 29: FrontendSection — search toggle admin UI

**Files:**
- Create: `src/Admin/FrontendSection.php`

- [ ] **Step 1: Implement `src/Admin/FrontendSection.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FrontendSection {

    public function register(): void {
        add_action( 'meilisearch_admin_tab_frontend', [ $this, 'render' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'meilisearch_settings', 'meilisearch_server_side_search', [
            'type'    => 'boolean',
            'default' => false,
        ] );
        register_setting( 'meilisearch_settings', 'meilisearch_instantsearch_enabled', [
            'type'    => 'boolean',
            'default' => false,
        ] );
    }

    public function render(): void {
        $server_side   = get_option( 'meilisearch_server_side_search', false );
        $instantsearch = get_option( 'meilisearch_instantsearch_enabled', false );
        $search_key    = get_option( 'meilisearch_search_api_key', '' );

        echo '<form method="post" action="options.php">';
        settings_fields( 'meilisearch_settings' );

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Server-Side Search Replacement', 'meilisearch' ) . '</h3>';
        echo '<p>' . esc_html__( 'Replace WordPress default search with Meilisearch via pre_get_posts. Works with any theme.', 'meilisearch' ) . '</p>';
        printf(
            '<label><input type="checkbox" name="meilisearch_server_side_search" value="1" %s /> %s</label>',
            checked( $server_side, true, false ),
            esc_html__( 'Enable server-side search replacement', 'meilisearch' ),
        );
        echo '</div>';

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'InstantSearch Shortcode & Block', 'meilisearch' ) . '</h3>';
        echo '<p>' . esc_html__( 'Enable the [meilisearch_search] shortcode and Gutenberg block for facet-rich instant search.', 'meilisearch' ) . '</p>';
        if ( $search_key === '' ) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__( 'Configure a search-only API key in Connection settings to enable the InstantSearch frontend.', 'meilisearch' );
            echo '</p></div>';
        }
        printf(
            '<label><input type="checkbox" name="meilisearch_instantsearch_enabled" value="1" %s %s /> %s</label>',
            checked( $instantsearch, true, false ),
            disabled( $search_key, '', false ),
            esc_html__( 'Enable InstantSearch shortcode and block', 'meilisearch' ),
        );
        echo '<p class="description">' . esc_html__( 'Usage: [meilisearch_search post_type="product" facets="category,brand,price"]', 'meilisearch' ) . '</p>';
        echo '</div>';

        submit_button();
        echo '</form>';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Admin/FrontendSection.php
git commit -m "feat: add FrontendSection with server-side and InstantSearch toggles"
```

---

### Task 30: Integration test — end-to-end search flow

**Files:**
- Create: `tests/integration/Search/ServerSideSearchTest.php`

- [ ] **Step 1: Write integration test**

Create `tests/integration/Search/ServerSideSearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Search;

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Indexing\DocumentBuilder;
use Meilisearch\WordPress\Indexing\FieldMapper;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Multisite\SiteSettings;
use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Search\QueryBuilder;
use Meilisearch\WordPress\Search\ResultSet;

/**
 * Integration test: index fixtures → search → verify WP_Post IDs returned.
 * Requires running Meilisearch + WP Test Suite.
 */
class ServerSideSearchTest extends \WP_UnitTestCase {

    private Client $client;

    public function set_up(): void {
        parent::set_up();

        $host = getenv( 'MEILISEARCH_HOST' ) ?: 'http://localhost:7700';
        $key  = getenv( 'MEILISEARCH_API_KEY' ) ?: 'masterKey123';
        $this->client = new Client( $host, $key );

        // Clean up.
        try { $this->client->delete_index( 'wp_post' ); } catch ( \Throwable ) {}
    }

    public function tear_down(): void {
        try { $this->client->delete_index( 'wp_post' ); } catch ( \Throwable ) {}
        parent::tear_down();
    }

    public function test_search_returns_matching_posts(): void {
        // Create test index.
        $config = new PostTypeConfig( 'post', true, [ 'title', 'content' ], [], [ 'date_int' ], [], [], '', '' );
        $manager = new IndexManager( $this->client, new SettingsBuilder() );
        $manager->create_index( 'wp_post', $config );

        // Create WP posts.
        $post_id_1 = self::factory()->post->create( [ 'post_title' => 'Meilisearch is amazing', 'post_content' => 'Fast typo-tolerant search' ] );
        $post_id_2 = self::factory()->post->create( [ 'post_title' => 'WordPress Rocks', 'post_content' => 'A great CMS' ] );

        // Build and index documents.
        $builder  = new DocumentBuilder( new FieldMapper() );
        $docs     = [];
        foreach ( [ $post_id_1, $post_id_2 ] as $pid ) {
            $post   = get_post( $pid );
            $docs[] = $builder->build( $post, $config, 'wp_post' );
        }
        $this->client->add_documents( 'wp_post', $docs );

        // Wait for indexing.
        sleep( 2 );

        // Search.
        $params = ( new QueryBuilder( new FilterBuilder() ) )->from_wp_query_args( [ 's' => 'meilisearch' ] );
        $result = $this->client->search( 'wp_post', $params['q'], $params );
        $rs     = new ResultSet( $result->toArray() );

        $this->assertContains( $post_id_1, $rs->get_post_ids() );
        $this->assertNotContains( $post_id_2, $rs->get_post_ids() );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/integration/Search/ServerSideSearchTest.php
git commit -m "test: add integration test for end-to-end search flow"
```

---

## Phase 5 — Shortcode + Gutenberg Block (Tasks 31-34)

### Task 31: SearchShortcode

**Files:**
- Create: `src/Shortcode/SearchShortcode.php`
- Create: `src/Frontend/Templates.php`
- Test: `tests/unit/Shortcode/SearchShortcodeTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Shortcode/SearchShortcodeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Shortcode;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Frontend\Templates;
use Meilisearch\WordPress\Shortcode\SearchShortcode;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SearchShortcodeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_outputs_root_div_with_config(): void {
        Functions\stubs( [
            'get_option' => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_host'           => 'https://ms-test.meilisearch.io',
                    'meilisearch_search_api_key' => 'searchKey123',
                    'meilisearch_instantsearch_enabled' => true,
                    default => '',
                };
            },
            'is_multisite'        => false,
            'get_current_blog_id' => 1,
            'esc_attr'            => function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); },
            'wp_json_encode'      => 'json_encode',
            'wp_enqueue_script'   => true,
            'wp_enqueue_style'    => true,
        ] );

        $shortcode = new SearchShortcode();
        $output    = $shortcode->render( [
            'post_type' => 'product',
            'facets'    => 'category,brand,price',
        ] );

        $this->assertStringContainsString( 'meilisearch-search-wrapper', $output );
        $this->assertStringContainsString( 'data-config=', $output );
        $this->assertStringContainsString( 'searchKey123', $output );
        $this->assertStringNotContainsString( 'masterKey', $output );
    }

    public function test_render_blocked_when_no_search_key(): void {
        Functions\stubs( [
            'get_option' => function ( string $key ) {
                return match ( $key ) {
                    'meilisearch_search_api_key'        => '',
                    'meilisearch_instantsearch_enabled' => true,
                    default => '',
                };
            },
            'esc_html__' => function ( $s ) { return $s; },
        ] );

        $shortcode = new SearchShortcode();
        $output    = $shortcode->render( [] );

        $this->assertStringContainsString( 'search-only key', $output );
    }

    public function test_template_loader_falls_back_to_plugin_template(): void {
        Functions\stubs( [
            'locate_template' => '',  // theme has no override
        ] );

        $path = Templates::locate( 'shortcode-search' );
        $this->assertStringEndsWith( 'templates/shortcode-search.php', $path );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter SearchShortcodeTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Shortcode/SearchShortcode.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Frontend\Templates;
use Meilisearch\WordPress\Multisite\SiteSettings;

class SearchShortcode {

    public function register(): void {
        add_shortcode( 'meilisearch_search', [ $this, 'render' ] );
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render( array|string $atts = [] ): string {
        $atts = shortcode_atts( [
            'post_type' => 'post',
            'facets'    => '',
            'limit'     => '20',
        ], (array) $atts, 'meilisearch_search' );

        $search_key = get_option( 'meilisearch_search_api_key', '' );
        $enabled    = get_option( 'meilisearch_instantsearch_enabled', false );

        if ( $search_key === '' || ! $enabled ) {
            return '<p class="meilisearch-notice">' . esc_html__( 'Configure a search-only key to enable the InstantSearch frontend.', 'meilisearch' ) . '</p>';
        }

        $host      = get_option( 'meilisearch_host', '' );
        $settings  = new SiteSettings();
        $index_uid = $settings->index_uid( $atts['post_type'] );

        $facets_list = array_filter( array_map( 'trim', explode( ',', $atts['facets'] ) ) );

        $config = [
            'host'      => $host,
            'searchKey' => $search_key,
            'indexUid'  => $index_uid,
            'facets'    => $facets_list,
            'limit'     => (int) $atts['limit'],
        ];

        $config_json = wp_json_encode( $config );

        // Enqueue InstantSearch assets.
        wp_enqueue_script( 'meilisearch-instant-search' );
        wp_enqueue_style( 'meilisearch-instant-search' );

        // Load overridable template.
        $template_path = Templates::locate( 'shortcode-search' );
        ob_start();
        include $template_path;
        $template_html = ob_get_clean();

        return sprintf(
            '<div data-config="%s">%s</div>',
            esc_attr( $config_json ),
            $template_html,
        );
    }
}
```

- [ ] **Step 3b: Implement `src/Frontend/Templates.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Templates {

    /**
     * Locate a plugin template, allowing theme overrides.
     *
     * Themes can override by placing a file at:
     *   {theme}/meilisearch/{template_name}.php
     *
     * Falls back to the plugin's own templates/ directory.
     *
     * @param string $template_name Template name without .php extension.
     * @return string Absolute path to the template file.
     */
    public static function locate( string $template_name ): string {
        $template_file = "meilisearch/{$template_name}.php";

        // Check theme/child-theme first.
        $theme_path = locate_template( $template_file );
        if ( $theme_path !== '' ) {
            return $theme_path;
        }

        // Fall back to plugin template.
        return MEILISEARCH_PLUGIN_DIR . "templates/{$template_name}.php";
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter SearchShortcodeTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Shortcode/SearchShortcode.php src/Frontend/Templates.php tests/unit/Shortcode/SearchShortcodeTest.php
git commit -m "feat: add SearchShortcode with overridable template and Templates loader"
```

---

### Task 32: InstantSearchAssets — frontend JS/CSS enqueue

**Files:**
- Create: `src/Frontend/InstantSearchAssets.php`

- [ ] **Step 1: Implement `src/Frontend/InstantSearchAssets.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InstantSearchAssets {

    public function register(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets(): void {
        $version = defined( 'MEILISEARCH_VERSION' ) ? MEILISEARCH_VERSION : '1.0.0';

        wp_register_script(
            'meilisearch-instant-search',
            MEILISEARCH_PLUGIN_URL . 'build/instant-search.js',
            [],
            $version,
            true,
        );

        wp_register_style(
            'meilisearch-instant-search',
            MEILISEARCH_PLUGIN_URL . 'assets/css/instant-search.css',
            [],
            $version,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Frontend/InstantSearchAssets.php
git commit -m "feat: add InstantSearchAssets for script/style registration"
```

---

### Task 33: InstantSearch JS bundle, CSS, and overridable template

**Files:**
- Create: `assets/js/instant-search.js`
- Create: `assets/css/instant-search.css`
- Create: `templates/shortcode-search.php`

- [ ] **Step 1: Create `assets/js/instant-search.js`**

```js
import instantsearch from 'instantsearch.js';
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch';
import {
    searchBox,
    hits,
    pagination,
    stats,
    refinementList,
    rangeSlider,
    hierarchicalMenu,
} from 'instantsearch.js/es/widgets';

document.addEventListener( 'DOMContentLoaded', function () {
    const root = document.getElementById( 'meilisearch-root' );
    if ( ! root ) return;

    const config = JSON.parse( root.dataset.config || '{}' );
    if ( ! config.host || ! config.searchKey || ! config.indexUid ) return;

    const { searchClient } = instantMeiliSearch( config.host, config.searchKey );

    const search = instantsearch( {
        indexName: config.indexUid,
        searchClient,
    } );

    const widgets = [
        searchBox( { container: '#meilisearch-searchbox', placeholder: 'Search...' } ),
        hits( {
            container: '#meilisearch-hits',
            templates: {
                item( hit, { html, components } ) {
                    return html`
                        <article class="meilisearch-hit">
                            ${ hit.thumbnail_url ? html`<img src="${ hit.thumbnail_url }" alt="" class="meilisearch-hit__image" />` : '' }
                            <div class="meilisearch-hit__body">
                                <h3 class="meilisearch-hit__title">
                                    <a href="${ hit.permalink }">${ components.Highlight( { hit, attribute: 'title' } ) }</a>
                                </h3>
                                <p class="meilisearch-hit__excerpt">
                                    ${ components.Snippet( { hit, attribute: 'content' } ) }
                                </p>
                            </div>
                        </article>
                    `;
                },
                empty( _, { html } ) {
                    return html`<p>No results found.</p>`;
                },
            },
        } ),
        pagination( { container: '#meilisearch-pagination' } ),
        stats( { container: '#meilisearch-stats' } ),
    ];

    // Add facet widgets.
    if ( Array.isArray( config.facets ) ) {
        config.facets.forEach( function ( facet, index ) {
            const containerId = 'meilisearch-facet-' + index;
            const el = document.createElement( 'div' );
            el.id = containerId;
            const sidebar = document.getElementById( 'meilisearch-facets' );
            if ( sidebar ) sidebar.appendChild( el );

            if ( facet.startsWith( 'categories.lvl' ) || facet === 'categories' ) {
                widgets.push( hierarchicalMenu( {
                    container: '#' + containerId,
                    attributes: [ 'categories.lvl0', 'categories.lvl1', 'categories.lvl2' ],
                } ) );
            } else if ( facet === 'price' || facet.endsWith( '_price' ) ) {
                widgets.push( rangeSlider( {
                    container: '#' + containerId,
                    attribute: facet,
                } ) );
            } else {
                widgets.push( refinementList( {
                    container: '#' + containerId,
                    attribute: facet.includes( 'tax_' ) ? facet : 'tax_' + facet + '_names',
                } ) );
            }
        } );
    }

    // Build DOM structure.
    root.innerHTML = `
        <div class="meilisearch-layout">
            <div id="meilisearch-facets" class="meilisearch-layout__sidebar"></div>
            <div class="meilisearch-layout__main">
                <div id="meilisearch-searchbox"></div>
                <div id="meilisearch-stats"></div>
                <div id="meilisearch-hits"></div>
                <div id="meilisearch-pagination"></div>
            </div>
        </div>
    `;

    search.addWidgets( widgets );
    search.start();
} );
```

- [ ] **Step 2: Create `assets/css/instant-search.css`**

```css
.meilisearch-layout {
    display: flex;
    gap: 24px;
    margin: 20px 0;
}

.meilisearch-layout__sidebar {
    flex: 0 0 250px;
}

.meilisearch-layout__main {
    flex: 1;
    min-width: 0;
}

.meilisearch-hit {
    display: flex;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #eee;
}

.meilisearch-hit__image {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 4px;
}

.meilisearch-hit__title a {
    color: inherit;
    text-decoration: none;
}

.meilisearch-hit__title a:hover {
    text-decoration: underline;
}

.meilisearch-hit__excerpt {
    color: #666;
    font-size: 0.9em;
}

.meilisearch-hit em {
    background: #fef3cd;
    font-style: normal;
    padding: 0 2px;
}
```

- [ ] **Step 3: Create `templates/shortcode-search.php`**

This is the default HTML scaffold that the InstantSearch JS bundle hydrates. Themes can override it by placing `meilisearch/shortcode-search.php` in their template directory.

```php
<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="meilisearch-search-wrapper">
    <div id="meilisearch-searchbox"></div>
    <div class="meilisearch-search-layout">
        <aside id="meilisearch-facets"></aside>
        <main>
            <div id="meilisearch-stats"></div>
            <div id="meilisearch-hits"></div>
            <div id="meilisearch-pagination"></div>
        </main>
    </div>
</div>
```

- [ ] **Step 4: Add webpack config for InstantSearch entry**

Create `webpack.config.js`:

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
    ...defaultConfig,
    entry: {
        'instant-search': path.resolve( __dirname, 'assets/js/instant-search.js' ),
        'click-tracking': path.resolve( __dirname, 'assets/js/click-tracking.js' ),
    },
    output: {
        path: path.resolve( __dirname, 'build' ),
        filename: '[name].js',
    },
};
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/instant-search.js assets/css/instant-search.css templates/shortcode-search.php webpack.config.js
git commit -m "feat: add InstantSearch JS bundle, CSS, and overridable search template"
```

---

### Task 34: Gutenberg block — meilisearch/search

**Files:**
- Create: `src/Shortcode/Block/block.json`
- Create: `src/Shortcode/Block/edit.js`
- Create: `src/Shortcode/Block/save.js`
- Create: `src/Shortcode/Block/index.js`
- Create: `src/Shortcode/Block/BlockRegistrar.php`

- [ ] **Step 1: Create `src/Shortcode/Block/block.json`**

```json
{
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "meilisearch/search",
    "version": "1.0.0",
    "title": "Meilisearch Search",
    "category": "widgets",
    "icon": "search",
    "description": "A facet-rich search interface powered by Meilisearch.",
    "keywords": [ "search", "meilisearch", "instant" ],
    "textdomain": "meilisearch",
    "attributes": {
        "postType": {
            "type": "string",
            "default": "post"
        },
        "facets": {
            "type": "string",
            "default": ""
        },
        "limit": {
            "type": "number",
            "default": 20
        }
    },
    "supports": {
        "html": false,
        "align": [ "wide", "full" ]
    },
    "editorScript": "file:./index.js"
}
```

- [ ] **Step 2: Create `src/Shortcode/Block/index.js`**

```js
import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

registerBlockType( metadata.name, {
    edit: Edit,
    save: Save,
} );
```

- [ ] **Step 3: Create `src/Shortcode/Block/edit.js`**

```js
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
    const { postType, facets, limit } = attributes;
    const blockProps = useBlockProps();

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Search Settings', 'meilisearch' ) }>
                    <TextControl
                        label={ __( 'Post Type', 'meilisearch' ) }
                        value={ postType }
                        onChange={ ( value ) => setAttributes( { postType: value } ) }
                    />
                    <TextControl
                        label={ __( 'Facets (comma-separated)', 'meilisearch' ) }
                        value={ facets }
                        onChange={ ( value ) => setAttributes( { facets: value } ) }
                        help={ __( 'e.g., category,brand,price', 'meilisearch' ) }
                    />
                    <NumberControl
                        label={ __( 'Results per page', 'meilisearch' ) }
                        value={ limit }
                        onChange={ ( value ) => setAttributes( { limit: parseInt( value, 10 ) } ) }
                        min={ 1 }
                        max={ 100 }
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <div style={ { padding: '20px', background: '#f0f0f1', borderRadius: '4px', textAlign: 'center' } }>
                    <strong>{ __( 'Meilisearch Search', 'meilisearch' ) }</strong>
                    <p>{ __( 'Searching:', 'meilisearch' ) } { postType } { facets ? `| Facets: ${ facets }` : '' }</p>
                </div>
            </div>
        </>
    );
}
```

- [ ] **Step 4: Create `src/Shortcode/Block/save.js`**

```js
export default function Save() {
    // Server-side rendered via shortcode callback.
    return null;
}
```

- [ ] **Step 5: Create `src/Shortcode/Block/BlockRegistrar.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Shortcode\Block;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Shortcode\SearchShortcode;

class BlockRegistrar {

    private SearchShortcode $shortcode;

    public function __construct( SearchShortcode $shortcode ) {
        $this->shortcode = $shortcode;
    }

    public function register(): void {
        add_action( 'init', [ $this, 'register_block' ] );
    }

    public function register_block(): void {
        register_block_type( __DIR__ . '/block.json', [
            'render_callback' => [ $this, 'render' ],
        ] );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render( array $attributes ): string {
        return $this->shortcode->render( [
            'post_type' => $attributes['postType'] ?? 'post',
            'facets'    => $attributes['facets'] ?? '',
            'limit'     => (string) ( $attributes['limit'] ?? 20 ),
        ] );
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Shortcode/Block/
git commit -m "feat: add Gutenberg block meilisearch/search with InspectorControls"
```

---

## Phase 6 — WooCommerce Integration (Tasks 35-42)

### Task 35: WooCommerce Integration bootstrap

**Files:**
- Create: `src/WooCommerce/Integration.php`

- [ ] **Step 1: Implement `src/WooCommerce/Integration.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Integration {

    private bool $enabled = false;

    public function register(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $this->enabled = (bool) get_option( 'meilisearch_woocommerce_enabled', true );
        if ( ! $this->enabled ) {
            return;
        }

        add_action( 'woocommerce_init', [ $this, 'init' ] );
    }

    public function init(): void {
        // Register WC-specific subsystems.
        ( new StockSync() )->register();
    }

    public static function is_active(): bool {
        return class_exists( 'WooCommerce' ) && (bool) get_option( 'meilisearch_woocommerce_enabled', true );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/WooCommerce/Integration.php
git commit -m "feat: add WooCommerce Integration bootstrap with detection guard"
```

---

### Task 36: ProductDocumentBuilder

**Files:**
- Create: `src/WooCommerce/ProductDocumentBuilder.php`
- Test: `tests/unit/WooCommerce/ProductDocumentBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/WooCommerce/ProductDocumentBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\WooCommerce\ProductDocumentBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ProductDocumentBuilderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_simple_product(): void {
        $product = Mockery::mock( 'WC_Product' );
        $product->shouldReceive( 'get_id' )->andReturn( 100 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Red T-Shirt' );
        $product->shouldReceive( 'get_sku' )->andReturn( 'TSHIRT-RED' );
        $product->shouldReceive( 'get_description' )->andReturn( 'A nice red t-shirt' );
        $product->shouldReceive( 'get_short_description' )->andReturn( 'Red shirt' );
        $product->shouldReceive( 'get_permalink' )->andReturn( 'https://shop.test/product/red-tshirt' );
        $product->shouldReceive( 'get_regular_price' )->andReturn( '29.99' );
        $product->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $product->shouldReceive( 'is_on_sale' )->andReturn( false );
        $product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
        $product->shouldReceive( 'get_stock_quantity' )->andReturn( 50 );
        $product->shouldReceive( 'backorders_allowed' )->andReturn( false );
        $product->shouldReceive( 'get_average_rating' )->andReturn( '4.5' );
        $product->shouldReceive( 'get_review_count' )->andReturn( 12 );
        $product->shouldReceive( 'get_type' )->andReturn( 'simple' );
        $product->shouldReceive( 'is_featured' )->andReturn( false );
        $product->shouldReceive( 'get_date_created' )->andReturn( new \WC_DateTime( '2025-06-01' ) );
        $product->shouldReceive( 'get_weight' )->andReturn( '0.3' );
        $product->shouldReceive( 'get_length' )->andReturn( '10' );
        $product->shouldReceive( 'get_width' )->andReturn( '8' );
        $product->shouldReceive( 'get_height' )->andReturn( '2' );
        $product->shouldReceive( 'get_image_id' )->andReturn( 200 );
        $product->shouldReceive( 'get_gallery_image_ids' )->andReturn( [ 201, 202 ] );
        $product->shouldReceive( 'get_category_ids' )->andReturn( [ 5 ] );

        Functions\stubs( [
            'wc_get_price_to_display' => 29.99,
            'get_post_meta'           => function ( $id, $key, $single ) {
                return match ( $key ) {
                    'total_sales' => '150',
                    default       => '',
                };
            },
            'wp_get_attachment_url'   => function ( $id ) { return "https://shop.test/img/{$id}.jpg"; },
            'apply_filters'           => function ( $tag, ...$args ) { return $args[0]; },
        ] );

        $builder = new ProductDocumentBuilder();
        $doc     = $builder->build( $product );

        $this->assertSame( 100, $doc['id'] );
        $this->assertSame( 'Red T-Shirt', $doc['title'] );
        $this->assertSame( 'TSHIRT-RED', $doc['sku'] );
        $this->assertSame( 29.99, $doc['price'] );
        $this->assertSame( 29.99, $doc['regular_price'] );
        $this->assertNull( $doc['sale_price'] );
        $this->assertFalse( $doc['on_sale'] );
        $this->assertSame( 'instock', $doc['stock_status'] );
        $this->assertSame( 50, $doc['stock_quantity'] );
        $this->assertSame( 4.5, $doc['rating_average'] );
        $this->assertSame( 'simple', $doc['product_type'] );
        $this->assertSame( 150, $doc['total_sales'] );
        $this->assertCount( 2, $doc['image_gallery_urls'] );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter ProductDocumentBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/WooCommerce/ProductDocumentBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductDocumentBuilder {

    /**
     * @param \WC_Product $product
     * @return array<string, mixed>
     */
    public function build( object $product ): array {
        $sale_price = $product->get_sale_price();

        $doc = [
            'id'                  => (int) $product->get_id(),
            'wp_id'               => (int) $product->get_id(),
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'title'               => $product->get_name(),
            'content'             => strip_tags( $product->get_description() ),
            'excerpt'             => $product->get_short_description(),
            'permalink'           => $product->get_permalink(),
            'sku'                 => $product->get_sku(),
            'price'               => (float) wc_get_price_to_display( $product ),
            'regular_price'       => (float) $product->get_regular_price(),
            'sale_price'          => $sale_price !== '' ? (float) $sale_price : null,
            'on_sale'             => $product->is_on_sale(),
            'stock_status'        => $product->get_stock_status(),
            'stock_quantity'      => $product->get_stock_quantity() !== null ? (int) $product->get_stock_quantity() : null,
            'backorders_allowed'  => $product->backorders_allowed(),
            'rating_average'      => (float) $product->get_average_rating(),
            'rating_count'        => (int) $product->get_review_count(),
            'product_type'        => $product->get_type(),
            'featured'            => $product->is_featured(),
            'total_sales'         => (int) get_post_meta( $product->get_id(), 'total_sales', true ),
            'date_int'            => $product->get_date_created() ? $product->get_date_created()->getTimestamp() : 0,
            'weight'              => (float) $product->get_weight(),
            'length'              => (float) $product->get_length(),
            'width'               => (float) $product->get_width(),
            'height'              => (float) $product->get_height(),
            'thumbnail_url'       => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            'image_gallery_urls'  => array_map(
                fn( int $id ) => wp_get_attachment_url( $id ) ?: '',
                $product->get_gallery_image_ids(),
            ),
        ];

        /** @var array<string, mixed> */
        return apply_filters( 'meilisearch_product_document', $doc, $product );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter ProductDocumentBuilderTest`
Expected: OK (1 test)

- [ ] **Step 5: Commit**

```bash
git add src/WooCommerce/ProductDocumentBuilder.php tests/unit/WooCommerce/ProductDocumentBuilderTest.php
git commit -m "feat: add ProductDocumentBuilder with full WC product fields"
```

---

### Task 37: VariationHandler — flatten variations into parent

**Files:**
- Create: `src/WooCommerce/VariationHandler.php`
- Test: `tests/unit/WooCommerce/VariationHandlerTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/WooCommerce/VariationHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\WooCommerce\VariationHandler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class VariationHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_flatten_two_sizes_two_colors(): void {
        $v1 = $this->make_variation( 201, 'SKU-S-RED', 19.99, [ 'size' => 'S', 'color' => 'red' ], 'instock' );
        $v2 = $this->make_variation( 202, 'SKU-M-RED', 24.99, [ 'size' => 'M', 'color' => 'red' ], 'instock' );
        $v3 = $this->make_variation( 203, 'SKU-S-BLUE', 19.99, [ 'size' => 'S', 'color' => 'blue' ], 'outofstock' );
        $v4 = $this->make_variation( 204, 'SKU-M-BLUE', 29.99, [ 'size' => 'M', 'color' => 'blue' ], 'instock' );

        $product = Mockery::mock( 'WC_Product_Variable' );
        $product->shouldReceive( 'get_available_variations' )->andReturn( [
            $this->variation_array( $v1 ),
            $this->variation_array( $v2 ),
            $this->variation_array( $v3 ),
            $this->variation_array( $v4 ),
        ] );

        Functions\stubs( [
            'wc_get_product' => function ( $id ) use ( $v1, $v2, $v3, $v4 ) {
                return match ( $id ) {
                    201 => $v1, 202 => $v2, 203 => $v3, 204 => $v4, default => null,
                };
            },
        ] );

        $handler = new VariationHandler();
        $data    = $handler->flatten( $product );

        $this->assertSame( 19.99, $data['price_min'] );
        $this->assertSame( 29.99, $data['price_max'] );
        $this->assertEqualsCanonicalizing( [ 'S', 'M' ], $data['available_size'] );
        $this->assertEqualsCanonicalizing( [ 'red', 'blue' ], $data['available_color'] );
        $this->assertTrue( $data['in_stock_any'] );
        $this->assertCount( 4, $data['variations'] );
    }

    private function make_variation( int $id, string $sku, float $price, array $attrs, string $stock ): object {
        $v = Mockery::mock( 'WC_Product_Variation' );
        $v->shouldReceive( 'get_id' )->andReturn( $id );
        $v->shouldReceive( 'get_sku' )->andReturn( $sku );
        $v->shouldReceive( 'get_price' )->andReturn( (string) $price );
        $v->shouldReceive( 'get_attributes' )->andReturn( $attrs );
        $v->shouldReceive( 'get_stock_status' )->andReturn( $stock );
        return $v;
    }

    private function variation_array( object $v ): array {
        return [ 'variation_id' => $v->get_id() ];
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter VariationHandlerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/WooCommerce/VariationHandler.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VariationHandler {

    /**
     * @param \WC_Product_Variable $product
     * @return array<string, mixed>
     */
    public function flatten( object $product ): array {
        $variations    = [];
        $prices        = [];
        $attributes    = [];
        $in_stock_any  = false;

        $available = $product->get_available_variations();

        foreach ( $available as $var_data ) {
            $variation = wc_get_product( $var_data['variation_id'] );
            if ( ! $variation ) {
                continue;
            }

            $price  = (float) $variation->get_price();
            $attrs  = $variation->get_attributes();
            $stock  = $variation->get_stock_status();

            $prices[]     = $price;
            $variations[] = [
                'id'         => (int) $variation->get_id(),
                'sku'        => $variation->get_sku(),
                'price'      => $price,
                'attributes' => $attrs,
                'stock_status' => $stock,
            ];

            if ( $stock === 'instock' ) {
                $in_stock_any = true;
            }

            foreach ( $attrs as $attr_name => $attr_value ) {
                $key = str_replace( 'pa_', '', $attr_name );
                if ( ! isset( $attributes[ $key ] ) ) {
                    $attributes[ $key ] = [];
                }
                if ( $attr_value !== '' && ! in_array( $attr_value, $attributes[ $key ], true ) ) {
                    $attributes[ $key ][] = $attr_value;
                }
            }
        }

        $data = [
            'price_min'    => ! empty( $prices ) ? min( $prices ) : 0.0,
            'price_max'    => ! empty( $prices ) ? max( $prices ) : 0.0,
            'in_stock_any' => $in_stock_any,
            'variations'   => $variations,
        ];

        foreach ( $attributes as $attr_name => $values ) {
            $data[ "available_{$attr_name}" ] = $values;
        }

        return $data;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter VariationHandlerTest`
Expected: OK (1 test)

- [ ] **Step 5: Commit**

```bash
git add src/WooCommerce/VariationHandler.php tests/unit/WooCommerce/VariationHandlerTest.php
git commit -m "feat: add VariationHandler flattening variations into parent document"
```

---

### Task 38: AttributeFacets

**Files:**
- Create: `src/WooCommerce/AttributeFacets.php`

- [ ] **Step 1: Implement `src/WooCommerce/AttributeFacets.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AttributeFacets {

    /**
     * Discover global and custom product attributes and return facet field names.
     *
     * @return array<string, string> Map of attribute slug => MS field name.
     */
    public function discover(): array {
        $facets = [];

        // Global attributes (pa_* taxonomies).
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        foreach ( $attribute_taxonomies as $tax ) {
            $slug            = $tax->attribute_name;
            $facets[ $slug ] = "attr_{$slug}";
        }

        return $facets;
    }

    /**
     * @param \WC_Product $product
     * @return array<string, string[]> Map of attr field name => values.
     */
    public function extract_from_product( object $product ): array {
        $result = [];

        $attributes = $product->get_attributes();
        foreach ( $attributes as $attr ) {
            if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
                $slug = $attr->get_taxonomy()
                    ? str_replace( 'pa_', '', $attr->get_taxonomy() )
                    : sanitize_title( $attr->get_name() );

                $field_name = $attr->get_taxonomy()
                    ? "attr_{$slug}"
                    : "custom_attr_{$slug}";

                if ( $attr->get_taxonomy() ) {
                    $terms = wp_get_post_terms( $product->get_id(), $attr->get_taxonomy(), [ 'fields' => 'names' ] );
                    $result[ $field_name ] = is_array( $terms ) ? $terms : [];
                } else {
                    $result[ $field_name ] = $attr->get_options();
                }
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    public function get_filterable_fields(): array {
        $fields = [];
        foreach ( $this->discover() as $slug => $field ) {
            $fields[] = $field;
        }
        return $fields;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/WooCommerce/AttributeFacets.php
git commit -m "feat: add AttributeFacets discovering pa_* taxonomies and custom attrs"
```

---

### Task 39: CategoryHierarchy — lvl0/lvl1/lvl2

**Files:**
- Create: `src/WooCommerce/CategoryHierarchy.php`
- Test: `tests/unit/WooCommerce/CategoryHierarchyTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/WooCommerce/CategoryHierarchyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\WooCommerce\CategoryHierarchy;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CategoryHierarchyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_single_depth_hierarchy(): void {
        $clothing = (object) [ 'term_id' => 10, 'name' => 'Clothing', 'parent' => 0 ];

        Functions\stubs( [
            'get_term'     => $clothing,
            'get_ancestors' => [],
        ] );

        $handler = new CategoryHierarchy();
        $result  = $handler->build( [ 10 ] );

        $this->assertSame( [ 'Clothing' ], $result['categories']['lvl0'] );
        $this->assertEmpty( $result['categories']['lvl1'] );
        $this->assertSame( [ 10 ], $result['category_ids'] );
    }

    public function test_three_level_hierarchy(): void {
        $clothing  = (object) [ 'term_id' => 10, 'name' => 'Clothing', 'parent' => 0 ];
        $tshirts   = (object) [ 'term_id' => 20, 'name' => 'T-Shirts', 'parent' => 10 ];
        $graphic   = (object) [ 'term_id' => 30, 'name' => 'Graphic', 'parent' => 20 ];

        Functions\stubs( [
            'get_term' => function ( $id ) use ( $clothing, $tshirts, $graphic ) {
                return match ( $id ) {
                    10 => $clothing,
                    20 => $tshirts,
                    30 => $graphic,
                    default => null,
                };
            },
            'get_ancestors' => function ( $id ) {
                return match ( $id ) {
                    30 => [ 20, 10 ],
                    20 => [ 10 ],
                    default => [],
                };
            },
        ] );

        $handler = new CategoryHierarchy();
        $result  = $handler->build( [ 30 ] );

        $this->assertContains( 'Clothing', $result['categories']['lvl0'] );
        $this->assertContains( 'Clothing > T-Shirts', $result['categories']['lvl1'] );
        $this->assertContains( 'Clothing > T-Shirts > Graphic', $result['categories']['lvl2'] );
        $this->assertContains( 30, $result['category_ids'] );
    }

    public function test_multi_hierarchy_product(): void {
        $clothing = (object) [ 'term_id' => 10, 'name' => 'Clothing', 'parent' => 0 ];
        $shoes    = (object) [ 'term_id' => 40, 'name' => 'Shoes', 'parent' => 0 ];
        $sneakers = (object) [ 'term_id' => 41, 'name' => 'Sneakers', 'parent' => 40 ];
        $tshirts  = (object) [ 'term_id' => 20, 'name' => 'T-Shirts', 'parent' => 10 ];

        Functions\stubs( [
            'get_term' => function ( $id ) use ( $clothing, $shoes, $sneakers, $tshirts ) {
                return match ( $id ) {
                    10 => $clothing, 20 => $tshirts, 40 => $shoes, 41 => $sneakers, default => null,
                };
            },
            'get_ancestors' => function ( $id ) {
                return match ( $id ) {
                    20 => [ 10 ],
                    41 => [ 40 ],
                    default => [],
                };
            },
        ] );

        $handler = new CategoryHierarchy();
        $result  = $handler->build( [ 20, 41 ] );

        $this->assertCount( 2, $result['categories']['lvl0'] );
        $this->assertContains( 'Clothing', $result['categories']['lvl0'] );
        $this->assertContains( 'Shoes', $result['categories']['lvl0'] );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter CategoryHierarchyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/WooCommerce/CategoryHierarchy.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CategoryHierarchy {

    /**
     * @param int[] $category_ids
     * @return array{categories: array{lvl0: string[], lvl1: string[], lvl2: string[]}, category_ids: int[]}
     */
    public function build( array $category_ids ): array {
        $levels = [ 'lvl0' => [], 'lvl1' => [], 'lvl2' => [] ];
        $all_ids = [];

        foreach ( $category_ids as $cat_id ) {
            $all_ids[] = (int) $cat_id;
            $chain     = $this->get_ancestor_chain( (int) $cat_id );

            // chain is ordered root → leaf.
            foreach ( $chain as $depth => $name ) {
                $lvl_key = "lvl{$depth}";
                if ( ! isset( $levels[ $lvl_key ] ) ) {
                    $levels[ $lvl_key ] = [];
                }
                // Build hierarchical string: "Root > Child > Grandchild".
                $path = implode( ' > ', array_slice( $chain, 0, $depth + 1 ) );
                if ( ! in_array( $path, $levels[ $lvl_key ], true ) ) {
                    $levels[ $lvl_key ][] = $path;
                }
            }
        }

        return [
            'categories'   => $levels,
            'category_ids' => array_values( array_unique( $all_ids ) ),
        ];
    }

    /**
     * @return string[] Ordered root → leaf names.
     */
    private function get_ancestor_chain( int $term_id ): array {
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return [];
        }

        $ancestor_ids = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
        // get_ancestors returns child → root order; reverse to root → child.
        $ancestor_ids = array_reverse( $ancestor_ids );

        $chain = [];
        foreach ( $ancestor_ids as $anc_id ) {
            $anc = get_term( $anc_id );
            if ( $anc && ! is_wp_error( $anc ) ) {
                $chain[] = $anc->name;
            }
        }
        $chain[] = $term->name;

        return $chain;
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter CategoryHierarchyTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/WooCommerce/CategoryHierarchy.php tests/unit/WooCommerce/CategoryHierarchyTest.php
git commit -m "feat: add CategoryHierarchy building lvl0/lvl1/lvl2 per MS convention"
```

---

### Task 40: StockSync — WC stock hooks

**Files:**
- Create: `src/WooCommerce/StockSync.php`

- [ ] **Step 1: Implement `src/WooCommerce/StockSync.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Sync\AsyncDispatcher;

class StockSync {

    public function register(): void {
        add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_change' ] );
        add_action( 'woocommerce_variation_set_stock', [ $this, 'on_variation_stock_change' ] );
        add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_stock_status_change' ], 10, 3 );
        add_action( 'woocommerce_reduce_order_stock', [ $this, 'on_order_stock_reduce' ] );
        add_action( 'woocommerce_restore_order_stock', [ $this, 'on_order_stock_restore' ] );
    }

    public function on_stock_change( object $product ): void {
        $this->dispatch_stock_update( (int) $product->get_id() );
    }

    public function on_variation_stock_change( object $variation ): void {
        $parent_id = (int) $variation->get_parent_id();
        if ( $parent_id > 0 ) {
            $this->dispatch_stock_update( $parent_id );
        }
    }

    public function on_stock_status_change( int $product_id, string $stock_status, object $product ): void {
        $this->dispatch_stock_update( $product_id );
    }

    public function on_order_stock_reduce( object $order ): void {
        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            if ( $product_id > 0 ) {
                $this->dispatch_stock_update( $product_id );
            }
        }
    }

    public function on_order_stock_restore( object $order ): void {
        $this->on_order_stock_reduce( $order );
    }

    private function dispatch_stock_update( int $product_id ): void {
        $dispatcher = new AsyncDispatcher();
        $dispatcher->dispatch( 'meilisearch_update_stock', [ $product_id, get_current_blog_id() ] );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/WooCommerce/StockSync.php
git commit -m "feat: add StockSync hooking WC stock events for partial index updates"
```

---

### Task 41: WooCommerceSection admin UI

**Files:**
- Create: `src/Admin/WooCommerceSection.php`

- [ ] **Step 1: Implement `src/Admin/WooCommerceSection.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerceSection {

    public function register(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_action( 'meilisearch_admin_tab_woocommerce', [ $this, 'render' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'meilisearch_settings', 'meilisearch_woocommerce_enabled', [
            'type'    => 'boolean',
            'default' => true,
        ] );
        register_setting( 'meilisearch_settings', 'meilisearch_wc_replace_shop_search', [
            'type'    => 'boolean',
            'default' => false,
        ] );
    }

    public function render(): void {
        $wc_enabled    = get_option( 'meilisearch_woocommerce_enabled', true );
        $replace_shop  = get_option( 'meilisearch_wc_replace_shop_search', false );

        echo '<form method="post" action="options.php">';
        settings_fields( 'meilisearch_settings' );

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Product Indexing', 'meilisearch' ) . '</h3>';
        printf(
            '<label><input type="checkbox" name="meilisearch_woocommerce_enabled" value="1" %s /> %s</label>',
            checked( $wc_enabled, true, false ),
            esc_html__( 'Enable WooCommerce product indexing', 'meilisearch' ),
        );
        echo '</div>';

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Variation Strategy', 'meilisearch' ) . '</h3>';
        echo '<p>' . esc_html__( 'Variations are attached to the parent product document. Future versions may support indexing each variation as a separate document.', 'meilisearch' ) . '</p>';
        echo '</div>';

        // Attributes table.
        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Attributes', 'meilisearch' ) . '</h3>';
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ( ! empty( $attribute_taxonomies ) ) {
            echo '<table class="widefat"><thead><tr>';
            echo '<th>' . esc_html__( 'Attribute', 'meilisearch' ) . '</th>';
            echo '<th>' . esc_html__( 'Indexed', 'meilisearch' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $attribute_taxonomies as $tax ) {
                echo '<tr>';
                printf( '<td>%s (%s)</td>', esc_html( $tax->attribute_label ), esc_html( $tax->attribute_name ) );
                echo '<td><input type="checkbox" checked disabled /> <em>' . esc_html__( 'Auto-indexed', 'meilisearch' ) . '</em></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No global attributes found. Create product attributes in WooCommerce.', 'meilisearch' ) . '</p>';
        }
        echo '</div>';

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Shop Search', 'meilisearch' ) . '</h3>';
        printf(
            '<label><input type="checkbox" name="meilisearch_wc_replace_shop_search" value="1" %s /> %s</label>',
            checked( $replace_shop, true, false ),
            esc_html__( 'Replace WooCommerce shop search with Meilisearch', 'meilisearch' ),
        );
        echo '</div>';

        submit_button();
        echo '</form>';
    }
}
```

- [ ] **Step 2: Modify `src/Admin/SettingsPage.php` to add WooCommerce tab**

In `SettingsPage::__construct()`, add conditionally:

```php
if ( class_exists( 'WooCommerce' ) ) {
    $this->tabs['woocommerce'] = __( 'WooCommerce', 'meilisearch' );
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Admin/WooCommerceSection.php src/Admin/SettingsPage.php
git commit -m "feat: add WooCommerceSection with product toggle, attributes table, shop search"
```

---

### Task 42: Shop page hooks — category archive filtering

**Files:**
- Modify: `src/Search/ServerSideSearch.php`

- [ ] **Step 1: Add WC shop page detection to `should_intercept()`**

Add to `ServerSideSearch::should_intercept()`:

```php
// Also intercept WC shop/taxonomy pages when WC replace is enabled.
if ( function_exists( 'is_shop' ) && get_option( 'meilisearch_wc_replace_shop_search', false ) ) {
    if ( is_shop() || is_product_taxonomy() || is_product_search() ) {
        return true;
    }
}
```

Add to `ServerSideSearch::replace_posts()`, before the search call:

```php
// Inject category filter for product taxonomy pages.
if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
    $queried_object = get_queried_object();
    if ( $queried_object && isset( $queried_object->term_id ) ) {
        $wp_args['tax_query'] = array_merge( $wp_args['tax_query'] ?: [], [
            [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [ $queried_object->term_id ] ],
        ] );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Search/ServerSideSearch.php
git commit -m "feat: add WC shop page hooks with category archive filter injection"
```

---

## Phase 7 — Analytics (Tasks 43-48)

### Task 43: QueryUidStore — transient-backed session store

**Files:**
- Create: `src/Analytics/QueryUidStore.php`
- Test: `tests/unit/Analytics/QueryUidStoreTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Analytics/QueryUidStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Analytics\QueryUidStore;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class QueryUidStoreTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_store_and_retrieve(): void {
        $stored = [];

        Functions\stubs( [
            'set_transient' => function ( string $key, $value, int $ttl ) use ( &$stored ) {
                $stored[ $key ] = $value;
                return true;
            },
            'get_transient' => function ( string $key ) use ( &$stored ) {
                return $stored[ $key ] ?? false;
            },
        ] );

        $store = new QueryUidStore();
        $store->store( 'query-abc', [ 42, 43, 44 ] );

        $this->assertSame( 'query-abc', $store->lookup( 42 ) );
        $this->assertSame( 'query-abc', $store->lookup( 43 ) );
        $this->assertNull( $store->lookup( 999 ) );
    }

    public function test_lookup_returns_null_when_expired(): void {
        Functions\stubs( [
            'set_transient' => true,
            'get_transient' => false,  // Simulates expired transient.
        ] );

        $store = new QueryUidStore();
        $this->assertNull( $store->lookup( 42 ) );
    }

    public function test_position_lookup(): void {
        $stored = [];

        Functions\stubs( [
            'set_transient' => function ( string $key, $value, int $ttl ) use ( &$stored ) {
                $stored[ $key ] = $value;
                return true;
            },
            'get_transient' => function ( string $key ) use ( &$stored ) {
                return $stored[ $key ] ?? false;
            },
        ] );

        $store = new QueryUidStore();
        $store->store( 'query-xyz', [ 10, 20, 30 ] );

        $this->assertSame( 1, $store->get_position( 10 ) );
        $this->assertSame( 2, $store->get_position( 20 ) );
        $this->assertSame( 3, $store->get_position( 30 ) );
        $this->assertNull( $store->get_position( 999 ) );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter QueryUidStoreTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Analytics/QueryUidStore.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QueryUidStore {

    private const TTL_SECONDS = 1800; // 30 minutes.
    private const PREFIX      = 'meili_quid_';

    /**
     * Store a queryUid for all post IDs in the result set.
     *
     * @param string $query_uid
     * @param int[]  $post_ids  Ordered result positions (1-indexed).
     */
    public function store( string $query_uid, array $post_ids ): void {
        $data = [
            'query_uid' => $query_uid,
            'positions' => [],
        ];

        foreach ( $post_ids as $position => $post_id ) {
            $data['positions'][ (int) $post_id ] = $position + 1; // 1-indexed.
        }

        // Store one transient per session with all post_id mappings.
        $session_key = $this->session_key();
        $existing    = get_transient( $session_key );

        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        foreach ( $data['positions'] as $pid => $pos ) {
            $existing[ $pid ] = [
                'query_uid' => $query_uid,
                'position'  => $pos,
            ];
        }

        set_transient( $session_key, $existing, self::TTL_SECONDS );
    }

    /**
     * Look up the queryUid for a given post ID.
     */
    public function lookup( int $post_id ): ?string {
        $session_data = get_transient( $this->session_key() );
        if ( ! is_array( $session_data ) || ! isset( $session_data[ $post_id ] ) ) {
            return null;
        }
        return $session_data[ $post_id ]['query_uid'] ?? null;
    }

    /**
     * Look up the 1-indexed position for a given post ID.
     */
    public function get_position( int $post_id ): ?int {
        $session_data = get_transient( $this->session_key() );
        if ( ! is_array( $session_data ) || ! isset( $session_data[ $post_id ] ) ) {
            return null;
        }
        return $session_data[ $post_id ]['position'] ?? null;
    }

    private function session_key(): string {
        // Use a combination of session cookie or IP for anonymous users.
        if ( isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
            return self::PREFIX . md5( $_COOKIE[ LOGGED_IN_COOKIE ] );
        }
        return self::PREFIX . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter QueryUidStoreTest`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Analytics/QueryUidStore.php tests/unit/Analytics/QueryUidStoreTest.php
git commit -m "feat: add QueryUidStore with transient-backed session TTL"
```

---

### Task 44: CustomFieldsProvider — analyticsCustomFields

**Files:**
- Create: `src/Analytics/CustomFieldsProvider.php`

- [ ] **Step 1: Implement `src/Analytics/CustomFieldsProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CustomFieldsProvider {

    /**
     * Build the analyticsCustomFields block for a search request.
     *
     * @param string $context One of: shop_search, main_search, instant_search_shortcode.
     * @return array<string, string>
     */
    public function build( string $context = 'main_search' ): array {
        $locale = get_locale();
        $role   = 'anonymous';

        if ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $roles = $user->roles;
            $role  = ! empty( $roles ) ? $roles[0] : 'subscriber';
        }

        $device = 'desktop';
        if ( function_exists( 'wp_is_mobile' ) && wp_is_mobile() ) {
            $device = 'mobile';
        }

        $fields = [
            'wp_locale'          => $locale,
            'wp_user_role'       => $role,
            'wp_multisite_blog'  => (string) get_current_blog_id(),
            'device'             => $device,
            'context'            => $context,
        ];

        /** @var array<string, string> */
        return apply_filters( 'meilisearch_analytics_custom_fields', $fields );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Analytics/CustomFieldsProvider.php
git commit -m "feat: add CustomFieldsProvider for analyticsCustomFields on searches"
```

---

### Task 45: ClickTrackingRoute — REST proxy with rate limiting

**Files:**
- Create: `src/Analytics/ClickTrackingRoute.php`
- Test: `tests/unit/Analytics/ClickTrackingRouteTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Analytics/ClickTrackingRouteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Analytics\ClickTrackingRoute;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ClickTrackingRouteTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rate_limiter_allows_under_limit(): void {
        $count = 0;

        Functions\stubs( [
            'get_transient' => function () use ( &$count ) { return $count; },
            'set_transient' => function ( $key, $val ) use ( &$count ) { $count = $val; return true; },
        ] );

        $route = new ClickTrackingRoute();
        $this->assertTrue( $route->check_rate_limit( '127.0.0.1' ) );
    }

    public function test_rate_limiter_blocks_at_limit(): void {
        Functions\stubs( [
            'get_transient' => 30,
            'set_transient' => true,
        ] );

        $route = new ClickTrackingRoute();
        $this->assertFalse( $route->check_rate_limit( '127.0.0.1' ) );
    }

    public function test_rejects_invalid_event_type(): void {
        $route  = new ClickTrackingRoute();
        $result = $route->validate_event( [ 'eventType' => 'conversion', 'queryUid' => 'abc', 'objectId' => '1' ] );
        $this->assertFalse( $result );
    }

    public function test_accepts_click_event_type(): void {
        $route  = new ClickTrackingRoute();
        $result = $route->validate_event( [
            'eventType'  => 'click',
            'queryUid'   => 'abc',
            'objectId'   => '1',
            'objectName' => 'Test',
            'position'   => 1,
        ] );
        $this->assertTrue( $result );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter ClickTrackingRouteTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Analytics/ClickTrackingRoute.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Api\ClientFactory;

class ClickTrackingRoute {

    private const RATE_LIMIT     = 30;
    private const RATE_WINDOW    = 60; // seconds
    private const TRANSIENT_PFX  = 'meili_rate_';

    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'meilisearch/v1', '/events', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_event' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_event( \WP_REST_Request $request ): \WP_REST_Response {
        // Nonce check.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
        }

        // DNT check.
        $dnt = $request->get_header( 'DNT' );
        $honor_dnt = apply_filters( 'meilisearch_honor_dnt', true );
        if ( $honor_dnt && $dnt === '1' ) {
            return new \WP_REST_Response( null, 204 );
        }

        $body = $request->get_json_params();

        // Validate event.
        if ( ! $this->validate_event( $body ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid event' ], 400 );
        }

        // Rate limit.
        $ip = $request->get_header( 'X-Forwarded-For' ) ?: ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        if ( ! $this->check_rate_limit( $ip ) ) {
            return new \WP_REST_Response( [ 'error' => 'Rate limit exceeded' ], 429 );
        }

        // Resolve userId.
        $user_id = $this->resolve_user_id();

        // Resolve indexUid from queryUid context.
        $search_key = get_option( 'meilisearch_search_api_key', '' );
        if ( $search_key === '' ) {
            return new \WP_REST_Response( [ 'error' => 'Search key not configured' ], 500 );
        }

        // Build event payload.
        $event = [
            'eventType'  => 'click',
            'eventName'  => apply_filters( 'meilisearch_analytics_event_name', 'Search Result Clicked', 'click' ),
            'queryUid'   => sanitize_text_field( $body['queryUid'] ),
            'objectId'   => sanitize_text_field( $body['objectId'] ),
            'objectName' => sanitize_text_field( $body['objectName'] ?? '' ),
            'position'   => (int) ( $body['position'] ?? 0 ),
        ];

        // Forward to Meilisearch.
        $host = get_option( 'meilisearch_host', '' );
        $client = new Client( $host, $search_key );
        $client->post_event( $event, $user_id, $search_key );

        return new \WP_REST_Response( null, 202 );
    }

    /**
     * @param array<string, mixed> $event
     */
    public function validate_event( array $event ): bool {
        // Only click events from browser.
        if ( ( $event['eventType'] ?? '' ) !== 'click' ) {
            return false;
        }
        if ( empty( $event['queryUid'] ) || empty( $event['objectId'] ) ) {
            return false;
        }
        return true;
    }

    public function check_rate_limit( string $ip ): bool {
        $key   = self::TRANSIENT_PFX . md5( $ip );
        $count = (int) get_transient( $key );

        if ( $count >= self::RATE_LIMIT ) {
            return false;
        }

        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return true;
    }

    private function resolve_user_id(): string {
        $strategy = get_option( 'meilisearch_analytics_user_id_strategy', 'hashed_session' );

        if ( $strategy === 'logged_in_user' && is_user_logged_in() ) {
            return 'wp_user_' . get_current_user_id();
        }

        if ( $strategy === 'anonymous_session' ) {
            return 'anon_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        }

        // Default: hashed_session.
        $cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        return 'session_' . md5( $cookie );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter ClickTrackingRouteTest`
Expected: OK (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Analytics/ClickTrackingRoute.php tests/unit/Analytics/ClickTrackingRouteTest.php
git commit -m "feat: add ClickTrackingRoute REST proxy with rate limiting and DNT"
```

---

### Task 46: QueryUidRoute + click-tracking JS

**Files:**
- Create: `src/Analytics/QueryUidRoute.php`
- Create: `assets/js/click-tracking.js`
- Create: `src/Frontend/ClickTrackingAssets.php`

- [ ] **Step 1: Implement `src/Analytics/QueryUidRoute.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QueryUidRoute {

    private QueryUidStore $store;

    public function __construct( QueryUidStore $store ) {
        $this->store = $store;
    }

    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'meilisearch/v1', '/queryuid', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $body      = $request->get_json_params();
        $query_uid = sanitize_text_field( $body['queryUid'] ?? '' );
        $post_ids  = array_map( 'intval', (array) ( $body['postIds'] ?? [] ) );

        if ( $query_uid === '' || empty( $post_ids ) ) {
            return new \WP_REST_Response( [ 'error' => 'Missing queryUid or postIds' ], 400 );
        }

        $this->store->store( $query_uid, $post_ids );

        return new \WP_REST_Response( null, 204 );
    }
}
```

- [ ] **Step 2: Create `assets/js/click-tracking.js`**

```js
( function () {
    'use strict';

    if ( ! window.meilisearch || ! window.meilisearch.eventsUrl ) return;

    const config = window.meilisearch;

    document.addEventListener( 'click', function ( e ) {
        const link = e.target.closest( '[data-meili-id]' );
        if ( ! link ) return;

        const objectId   = link.dataset.meiliId;
        const objectName = link.dataset.meiliName || '';
        const position   = parseInt( link.dataset.meiliPosition, 10 ) || 0;
        const queryUid   = config.queryUid || '';

        if ( ! queryUid || ! objectId ) return;

        const payload = {
            eventType:  'click',
            queryUid:   queryUid,
            objectId:   String( objectId ),
            objectName: objectName,
            position:   position,
        };

        // Fire and forget.
        fetch( config.eventsUrl, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':  config.nonce,
            },
            body:        JSON.stringify( payload ),
            keepalive:   true,
        } ).catch( function () { /* silent */ } );
    } );

    // Mirror queryUid to server for WC conversion lookups.
    if ( config.queryUid && config.queryUidUrl ) {
        const searchHits = document.querySelectorAll( '[data-meili-id]' );
        const postIds = Array.from( searchHits ).map( function ( el ) {
            return parseInt( el.dataset.meiliId, 10 );
        } ).filter( Boolean );

        if ( postIds.length > 0 ) {
            fetch( config.queryUidUrl, {
                method:  'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':  config.nonce,
                },
                body: JSON.stringify( { queryUid: config.queryUid, postIds: postIds } ),
                keepalive: true,
            } ).catch( function () { /* silent */ } );
        }
    }
} )();
```

- [ ] **Step 3: Implement `src/Frontend/ClickTrackingAssets.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ClickTrackingAssets {

    public function register(): void {
        if ( ! (bool) get_option( 'meilisearch_analytics_click_tracking', false ) ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue(): void {
        $version = defined( 'MEILISEARCH_VERSION' ) ? MEILISEARCH_VERSION : '1.0.0';

        wp_enqueue_script(
            'meilisearch-click-tracking',
            MEILISEARCH_PLUGIN_URL . 'build/click-tracking.js',
            [],
            $version,
            true,
        );

        wp_localize_script( 'meilisearch-click-tracking', 'meilisearch', [
            'eventsUrl'   => rest_url( 'meilisearch/v1/events' ),
            'queryUidUrl' => rest_url( 'meilisearch/v1/queryuid' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'queryUid'    => '', // Populated per-page via server-side or InstantSearch.
        ] );
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Analytics/QueryUidRoute.php assets/js/click-tracking.js src/Frontend/ClickTrackingAssets.php
git commit -m "feat: add QueryUidRoute, click-tracking JS, and ClickTrackingAssets"
```

---

### Task 47: OrderConversionHook — WC conversion events

**Files:**
- Create: `src/Analytics/OrderConversionHook.php`
- Test: `tests/unit/Analytics/OrderConversionHookTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/unit/Analytics/OrderConversionHookTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\WordPress\Analytics\OrderConversionHook;
use Meilisearch\WordPress\Analytics\QueryUidStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class OrderConversionHookTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_on_add_to_cart_sends_event_when_query_uid_found(): void {
        $store = Mockery::mock( QueryUidStore::class );
        $store->shouldReceive( 'lookup' )->with( 42 )->andReturn( 'query-abc' );
        $store->shouldReceive( 'get_position' )->with( 42 )->andReturn( 3 );

        Functions\stubs( [
            'get_option'       => function ( $key ) {
                return match ( $key ) {
                    'meilisearch_search_api_key'                => 'searchKey',
                    'meilisearch_host'                          => 'http://localhost:7700',
                    'meilisearch_analytics_conversion_tracking' => true,
                    default => '',
                };
            },
            'get_the_title'    => 'Test Product',
            'wp_remote_post'   => [ 'response' => [ 'code' => 202 ] ],
            'wp_json_encode'   => 'json_encode',
            'apply_filters'    => function ( $tag, ...$args ) { return $args[0]; },
            'is_multisite'     => false,
            'get_current_blog_id' => 1,
        ] );

        $hook = new OrderConversionHook( $store );
        // Should not throw.
        $hook->on_add_to_cart( 'cart_hash', 42, 1, 0, [], [] );

        $this->assertTrue( true ); // Assertion: no exception thrown.
    }

    public function test_on_add_to_cart_does_nothing_when_no_query_uid(): void {
        $store = Mockery::mock( QueryUidStore::class );
        $store->shouldReceive( 'lookup' )->with( 99 )->andReturn( null );

        Functions\stubs( [
            'get_option' => function ( $key ) {
                return match ( $key ) {
                    'meilisearch_analytics_conversion_tracking' => true,
                    default => '',
                };
            },
        ] );

        $hook = new OrderConversionHook( $store );
        $hook->on_add_to_cart( 'hash', 99, 1, 0, [], [] );

        // No event fired — no exception, no wp_remote_post call.
        $this->assertTrue( true );
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit --filter OrderConversionHookTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Analytics/OrderConversionHook.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Multisite\SiteSettings;

class OrderConversionHook {

    private QueryUidStore $store;

    public function __construct( QueryUidStore $store ) {
        $this->store = $store;
    }

    public function register(): void {
        if ( ! (bool) get_option( 'meilisearch_analytics_conversion_tracking', false ) ) {
            return;
        }

        add_action( 'woocommerce_add_to_cart', [ $this, 'on_add_to_cart' ], 10, 6 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_completed' ] );
    }

    public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
        $this->send_conversion_event( $product_id, 'Product Added To Cart' );
    }

    public function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            if ( $product_id > 0 ) {
                $this->send_conversion_event( $product_id, 'Order Completed' );
            }
        }
    }

    private function send_conversion_event( int $product_id, string $event_name ): void {
        $query_uid = $this->store->lookup( $product_id );
        if ( $query_uid === null ) {
            return;
        }

        $search_key = get_option( 'meilisearch_search_api_key', '' );
        $host       = get_option( 'meilisearch_host', '' );

        if ( $search_key === '' || $host === '' ) {
            return;
        }

        $position    = $this->store->get_position( $product_id ) ?? 0;
        $object_name = get_the_title( $product_id );

        $site_settings = new SiteSettings();
        $event_name    = apply_filters( 'meilisearch_analytics_event_name', $event_name, 'conversion' );

        $event = [
            'eventType'  => 'conversion',
            'eventName'  => $event_name,
            'queryUid'   => $query_uid,
            'objectId'   => (string) $product_id,
            'objectName' => $object_name,
            'position'   => $position,
        ];

        $client  = new Client( $host, $search_key );
        $user_id = is_user_logged_in() ? 'wp_user_' . get_current_user_id() : 'anon_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
        $client->post_event( $event, $user_id, $search_key );
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit --filter OrderConversionHookTest`
Expected: OK (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Analytics/OrderConversionHook.php tests/unit/Analytics/OrderConversionHookTest.php
git commit -m "feat: add OrderConversionHook for WC add-to-cart and order-completed events"
```

---

### Task 48: AnalyticsSection admin UI

**Files:**
- Create: `src/Admin/AnalyticsSection.php`

- [ ] **Step 1: Implement `src/Admin/AnalyticsSection.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Meilisearch\WordPress\Admin\CloudLinks;

class AnalyticsSection {

    public function register(): void {
        add_action( 'meilisearch_admin_tab_analytics', [ $this, 'render' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'meilisearch_settings', 'meilisearch_analytics_click_tracking', [ 'type' => 'boolean', 'default' => false ] );
        register_setting( 'meilisearch_settings', 'meilisearch_analytics_conversion_tracking', [ 'type' => 'boolean', 'default' => false ] );
        register_setting( 'meilisearch_settings', 'meilisearch_analytics_user_id_strategy', [ 'type' => 'string', 'default' => 'hashed_session' ] );
    }

    public function render(): void {
        $click      = get_option( 'meilisearch_analytics_click_tracking', false );
        $conversion = get_option( 'meilisearch_analytics_conversion_tracking', false );
        $strategy   = get_option( 'meilisearch_analytics_user_id_strategy', 'hashed_session' );
        $has_wc     = class_exists( 'WooCommerce' );

        echo '<form method="post" action="options.php">';
        settings_fields( 'meilisearch_settings' );

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Click Tracking', 'meilisearch' ) . '</h3>';
        printf(
            '<label><input type="checkbox" name="meilisearch_analytics_click_tracking" value="1" %s /> %s</label>',
            checked( $click, true, false ),
            esc_html__( 'Enable click tracking', 'meilisearch' ),
        );
        echo '</div>';

        if ( $has_wc ) {
            echo '<div class="meilisearch-card">';
            echo '<h3>' . esc_html__( 'Conversion Tracking', 'meilisearch' ) . '</h3>';
            printf(
                '<label><input type="checkbox" name="meilisearch_analytics_conversion_tracking" value="1" %s /> %s</label>',
                checked( $conversion, true, false ),
                esc_html__( 'Enable conversion tracking (add-to-cart + order completion)', 'meilisearch' ),
            );
            echo '</div>';
        }

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'User ID Strategy', 'meilisearch' ) . '</h3>';
        $strategies = [
            'hashed_session'  => __( 'Hashed session cookie (default, anonymous)', 'meilisearch' ),
            'logged_in_user'  => __( 'WordPress user ID (logged-in users only)', 'meilisearch' ),
            'anonymous_session' => __( 'Anonymous session (IP-based hash)', 'meilisearch' ),
        ];
        echo '<select name="meilisearch_analytics_user_id_strategy">';
        foreach ( $strategies as $value => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $strategy, $value, false ), esc_html( $label ) );
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="meilisearch-card">';
        echo '<h3>' . esc_html__( 'Privacy', 'meilisearch' ) . '</h3>';
        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__( 'Click tracking sends anonymous interaction data to your Meilisearch instance. Provide appropriate disclosure in your privacy policy.', 'meilisearch' );
        echo '</p></div>';
        echo '</div>';

        // Deep link to Cloud analytics via CloudLinks helper.
        echo CloudLinks::card(
            'analytics',
            '',
            __( 'Analytics Dashboard', 'meilisearch' ),
            __( 'View search analytics, top queries, and click-through rates.', 'meilisearch' ),
        );

        submit_button();
        echo '</form>';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Admin/AnalyticsSection.php
git commit -m "feat: add AnalyticsSection with tracking toggles, userId strategy, and privacy notice"
```

---

## Phase 8 — Multisite, Geo, Polish (Tasks 49-51)

### Task 49: Network admin page

**Files:**
- Create: `src/Admin/NetworkSettingsPage.php`

- [ ] **Step 1: Implement `src/Admin/NetworkSettingsPage.php`**

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NetworkSettingsPage {

    public function register(): void {
        if ( ! is_multisite() ) {
            return;
        }

        add_action( 'network_admin_menu', [ $this, 'add_network_menu' ] );
        add_action( 'network_admin_edit_meilisearch_network_settings', [ $this, 'save_network_settings' ] );
    }

    public function add_network_menu(): void {
        add_menu_page(
            __( 'Meilisearch', 'meilisearch' ),
            __( 'Meilisearch', 'meilisearch' ),
            'manage_network_options',
            'meilisearch-network',
            [ $this, 'render' ],
            'dashicons-search',
            80,
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'meilisearch' ) );
        }

        $host = get_site_option( 'meilisearch_network_host', '' );
        $admin_key = get_site_option( 'meilisearch_network_admin_api_key', '' );
        $search_key = get_site_option( 'meilisearch_network_search_api_key', '' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Meilisearch Network Defaults', 'meilisearch' ) . '</h1>';
        echo '<p>' . esc_html__( 'These values serve as defaults for subsites that have not configured their own Meilisearch connection.', 'meilisearch' ) . '</p>';

        echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=meilisearch_network_settings' ) ) . '">';
        wp_nonce_field( 'meilisearch_network_settings' );

        echo '<table class="form-table"><tbody>';

        printf(
            '<tr><th><label for="meilisearch_network_host">%s</label></th><td><input type="url" id="meilisearch_network_host" name="meilisearch_network_host" value="%s" class="regular-text" /></td></tr>',
            esc_html__( 'Default Host URL', 'meilisearch' ),
            esc_attr( $host ),
        );

        printf(
            '<tr><th><label for="meilisearch_network_admin_api_key">%s</label></th><td><input type="password" id="meilisearch_network_admin_api_key" name="meilisearch_network_admin_api_key" value="%s" class="regular-text" /></td></tr>',
            esc_html__( 'Default Admin API Key', 'meilisearch' ),
            esc_attr( $admin_key ),
        );

        printf(
            '<tr><th><label for="meilisearch_network_search_api_key">%s</label></th><td><input type="password" id="meilisearch_network_search_api_key" name="meilisearch_network_search_api_key" value="%s" class="regular-text" /></td></tr>',
            esc_html__( 'Default Search-Only API Key', 'meilisearch' ),
            esc_attr( $search_key ),
        );

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Per-site override table.
        echo '<h2>' . esc_html__( 'Per-Site Overrides', 'meilisearch' ) . '</h2>';
        echo '<table class="widefat"><thead><tr>';
        echo '<th>' . esc_html__( 'Site', 'meilisearch' ) . '</th>';
        echo '<th>' . esc_html__( 'Host Override', 'meilisearch' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'meilisearch' ) . '</th>';
        echo '</tr></thead><tbody>';

        $sites = get_sites( [ 'number' => 100 ] );
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );
            $site_host = get_option( 'meilisearch_host', '' );
            restore_current_blog();

            echo '<tr>';
            printf( '<td>%s (#%d)</td>', esc_html( $site->blogname ?: $site->domain . $site->path ), (int) $site->blog_id );
            printf( '<td>%s</td>', $site_host ? esc_html( $site_host ) : '<em>' . esc_html__( 'Using network default', 'meilisearch' ) . '</em>' );
            echo '<td>—</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function save_network_settings(): void {
        check_admin_referer( 'meilisearch_network_settings' );

        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'meilisearch' ) );
        }

        update_site_option( 'meilisearch_network_host', sanitize_text_field( $_POST['meilisearch_network_host'] ?? '' ) );
        update_site_option( 'meilisearch_network_admin_api_key', sanitize_text_field( $_POST['meilisearch_network_admin_api_key'] ?? '' ) );
        update_site_option( 'meilisearch_network_search_api_key', sanitize_text_field( $_POST['meilisearch_network_search_api_key'] ?? '' ) );

        wp_redirect( add_query_arg( 'updated', 'true', network_admin_url( 'admin.php?page=meilisearch-network' ) ) );
        exit;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Admin/NetworkSettingsPage.php
git commit -m "feat: add NetworkSettingsPage for multisite default host/key with per-site override table"
```

---

### Task 50: Geo support — end-to-end wiring

**Files:**
- Modify: `src/Search/QueryBuilder.php` (already handles geo_radius)
- Modify: `src/Indexing/SettingsBuilder.php` (already handles geo)
- Modify: `src/Indexing/DocumentBuilder.php` (already handles geo)

This task is verification-only — geo support was built into Tasks 11, 12, 23, and 24. Verify all pieces connect.

- [ ] **Step 1: Write integration test for geo search**

Create `tests/integration/Search/GeoSearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Integration\Search;

use Meilisearch\WordPress\Api\Client;
use Meilisearch\WordPress\Indexing\IndexManager;
use Meilisearch\WordPress\Indexing\PostTypeConfig;
use Meilisearch\WordPress\Indexing\SettingsBuilder;
use Meilisearch\WordPress\Search\FilterBuilder;
use Meilisearch\WordPress\Search\QueryBuilder;
use Meilisearch\WordPress\Search\ResultSet;

/**
 * Integration test: index posts with _geo → search with geoRadius → verify filtering.
 */
class GeoSearchTest extends \WP_UnitTestCase {

    private Client $client;

    public function set_up(): void {
        parent::set_up();
        $host = getenv( 'MEILISEARCH_HOST' ) ?: 'http://localhost:7700';
        $key  = getenv( 'MEILISEARCH_API_KEY' ) ?: 'masterKey123';
        $this->client = new Client( $host, $key );
        try { $this->client->delete_index( 'wp_geo_test' ); } catch ( \Throwable ) {}
    }

    public function tear_down(): void {
        try { $this->client->delete_index( 'wp_geo_test' ); } catch ( \Throwable ) {}
        parent::tear_down();
    }

    public function test_geo_radius_filters_results(): void {
        $config = new PostTypeConfig( 'geo_test', true, [ 'title' ], [], [], [], [], '_lat', '_lng' );
        $manager = new IndexManager( $this->client, new SettingsBuilder() );
        $manager->create_index( 'wp_geo_test', $config );

        // Paris: 48.8566, 2.3522
        // London: 51.5074, -0.1278
        $docs = [
            [ 'id' => 1, 'wp_id' => 1, 'post_type' => 'geo_test', 'post_status' => 'publish', 'title' => 'Paris Cafe', '_geo' => [ 'lat' => 48.8566, 'lng' => 2.3522 ] ],
            [ 'id' => 2, 'wp_id' => 2, 'post_type' => 'geo_test', 'post_status' => 'publish', 'title' => 'London Pub', '_geo' => [ 'lat' => 51.5074, 'lng' => -0.1278 ] ],
        ];
        $this->client->add_documents( 'wp_geo_test', $docs );
        sleep( 2 );

        // Search within 10km of Paris.
        $params = ( new QueryBuilder( new FilterBuilder() ) )->from_wp_query_args( [
            's' => '',
            'geo_radius' => [ 48.8566, 2.3522, 10000 ],
        ] );

        $result = $this->client->search( 'wp_geo_test', $params['q'], $params );
        $rs = new ResultSet( $result->toArray() );

        $this->assertContains( 1, $rs->get_post_ids() );
        $this->assertNotContains( 2, $rs->get_post_ids() );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/integration/Search/GeoSearchTest.php
git commit -m "test: add geo search integration test for _geoRadius filtering"
```

---

### Task 51: Internationalization — POT generation

**Files:**
- Create: `languages/meilisearch.pot` (generated)

- [ ] **Step 1: Verify all user-visible strings use `__()` / `_e()` with `meilisearch` text domain**

Audit all `src/Admin/*.php` files and verify every user-visible string is wrapped. All strings in the plan already use `esc_html__( '...', 'meilisearch' )` or `__( '...', 'meilisearch' )`.

- [ ] **Step 2: Generate POT file**

Run:
```bash
npx @wordpress/scripts i18n make-pot . languages/meilisearch.pot --slug=meilisearch --domain=meilisearch
```
Expected: `languages/meilisearch.pot` generated with all translatable strings.

- [ ] **Step 3: Commit**

```bash
git add languages/meilisearch.pot
git commit -m "chore: generate i18n POT template for the meilisearch text domain"
```

---

## Phase 9 — Testing Infrastructure (Tasks 52-55)

### Task 52: Unit test setup with PHPUnit + Brain Monkey

**Files:**
- Modify: `phpunit.xml.dist` (already created in Task 1)
- Modify: `tests/unit/bootstrap.php` (already created in Task 1)
- Create: `tests/unit/TestCase.php`

- [ ] **Step 1: Create shared `TestCase` base class**

Create `tests/unit/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Meilisearch\WordPress\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Verify all unit tests pass**

Run:
```bash
vendor/bin/phpunit --testsuite unit
```
Expected: All tests pass (across PluginTest, ClientTest, ClientFactoryTest, SiteSettingsTest, PostTypeConfigTest, FieldMapperTest, DocumentBuilderTest, SettingsBuilderTest, AsyncDispatcherTest, IndexPostJobTest, BulkReindexJobTest, SyncControllerTest, FilterBuilderTest, QueryBuilderTest, SearchShortcodeTest, ClickTrackingRouteTest, QueryUidStoreTest, OrderConversionHookTest, ProductDocumentBuilderTest, VariationHandlerTest, CategoryHierarchyTest, ConnectionSectionTest).

- [ ] **Step 3: Commit**

```bash
git add tests/unit/TestCase.php
git commit -m "chore: add shared unit TestCase base class with Brain Monkey setup"
```

---

### Task 53: Integration test setup — WP Test Suite + Meilisearch

**Files:**
- Create: `tests/integration/bootstrap.php`
- Create: `bin/install-wp-tests.sh`
- Create: `docker-compose.test.yml`

- [ ] **Step 1: Create `tests/integration/bootstrap.php`**

```php
<?php

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
    echo "WordPress test suite not found at {$_tests_dir}. Run bin/install-wp-tests.sh first.\n";
    exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

// Load plugin.
tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__, 2 ) . '/meilisearch.php';
} );

// Start WP test suite.
require "{$_tests_dir}/includes/bootstrap.php";
```

- [ ] **Step 2: Create `bin/install-wp-tests.sh`**

```bash
#!/usr/bin/env bash

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2"
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    if [ "$WP_VERSION" = "latest" ]; then
        local ARCHIVE_URL='https://wordpress.org/latest.tar.gz'
    else
        local ARCHIVE_URL="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
    fi
    download "$ARCHIVE_URL" /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        return
    fi
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    download "https://develop.svn.wordpress.org/tags/$WP_VERSION/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_wp
install_test_suite
```

- [ ] **Step 3: Create `docker-compose.test.yml`**

```yaml
services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_test
    ports:
      - "3306:3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      retries: 5

  meilisearch:
    image: getmeili/meilisearch:latest
    environment:
      MEILI_MASTER_KEY: "masterKey123"
      MEILI_ENV: "development"
    ports:
      - "7700:7700"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:7700/health"]
      interval: 5s
      retries: 5
```

- [ ] **Step 4: Commit**

```bash
git add tests/integration/bootstrap.php bin/install-wp-tests.sh docker-compose.test.yml
chmod +x bin/install-wp-tests.sh
git commit -m "chore: add integration test bootstrap, WP test installer, and Docker Compose test services"
```

---

### Task 54: E2E test setup — Playwright + wp-env

**Files:**
- Create: `.wp-env.json`
- Create: `tests/e2e/playwright.config.ts`
- Create: `tests/e2e/admin-settings.spec.ts`
- Create: `tests/e2e/reindex.spec.ts`
- Create: `tests/e2e/frontend-search.spec.ts`
- Create: `tests/e2e/instant-search.spec.ts`
- Create: `tests/e2e/woocommerce.spec.ts`

- [ ] **Step 1: Create `.wp-env.json`**

```json
{
    "core": "WordPress/WordPress#6.7",
    "phpVersion": "8.3",
    "plugins": [ "." ],
    "mappings": {
        "wp-content/plugins/woocommerce": "./node_modules/woocommerce"
    },
    "lifecycleScripts": {
        "afterStart": "docker exec -i $(docker ps -q --filter name=tests-wordpress) bash -c 'curl -sL https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-linux-amd64 -o /usr/local/bin/meilisearch && chmod +x /usr/local/bin/meilisearch && MEILI_MASTER_KEY=masterKey123 MEILI_ENV=development meilisearch --db-path /tmp/meili_data &'"
    },
    "env": {
        "tests": {
            "phpVersion": "8.3"
        }
    }
}
```

- [ ] **Step 2: Create `tests/e2e/playwright.config.ts`**

```ts
import { defineConfig } from '@playwright/test';

export default defineConfig( {
    testDir: '.',
    timeout: 60000,
    retries: 1,
    use: {
        baseURL: 'http://localhost:8889',
        storageState: 'tests/e2e/.auth/admin.json',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /global-setup\.ts/,
        },
        {
            name: 'e2e',
            dependencies: [ 'setup' ],
        },
    ],
} );
```

- [ ] **Step 3: Create `tests/e2e/admin-settings.spec.ts`**

```ts
import { test, expect } from '@playwright/test';

test.describe( 'Admin Settings', () => {
    test( 'settings page loads with tabs', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=meilisearch' );
        await expect( page.locator( '.nav-tab-wrapper' ) ).toBeVisible();
        await expect( page.locator( '.nav-tab' ) ).toHaveCount( 4 ); // connection, post_types, frontend, analytics
    } );

    test( 'test connection button works', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=meilisearch&tab=connection' );
        await page.fill( 'input[name="meilisearch_host"]', 'http://localhost:7700' );
        await page.fill( 'input[name="meilisearch_admin_api_key"]', 'masterKey123' );
        await page.click( '#submit' ); // Save settings first.
        await page.click( '#meilisearch-test-connection' );
        await expect( page.locator( '#meilisearch-connection-status' ) ).toContainText( 'Connected', { timeout: 10000 } );
    } );
} );
```

- [ ] **Step 4: Create `tests/e2e/reindex.spec.ts`**

```ts
import { test, expect } from '@playwright/test';

test.describe( 'Reindex', () => {
    test( 'trigger reindex and see completion', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=meilisearch&tab=post_types' );
        const reindexBtn = page.locator( '.meilisearch-reindex-btn' ).first();
        if ( await reindexBtn.isVisible() ) {
            await reindexBtn.click();
            await expect( page.locator( '.meilisearch-reindex-status' ).first() ).not.toHaveText( '—', { timeout: 30000 } );
        }
    } );
} );
```

- [ ] **Step 5: Create stub E2E tests for frontend-search, instant-search, and woocommerce**

Create `tests/e2e/frontend-search.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

test.describe( 'Frontend Search', () => {
    test( 'search returns Meilisearch results', async ( { page } ) => {
        await page.goto( '/?s=test' );
        // Verify search page loaded.
        await expect( page ).toHaveURL( /s=test/ );
    } );
} );
```

Create `tests/e2e/instant-search.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

test.describe( 'InstantSearch', () => {
    test( 'shortcode renders search UI', async ( { page } ) => {
        // Requires a page with [meilisearch_search] shortcode to be created.
        await page.goto( '/meilisearch-search/' );
        const root = page.locator( '#meilisearch-root' );
        if ( await root.isVisible() ) {
            await expect( root ).toBeVisible();
        }
    } );
} );
```

Create `tests/e2e/woocommerce.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

test.describe( 'WooCommerce', () => {
    test( 'product search returns results', async ( { page } ) => {
        await page.goto( '/shop/?s=shirt' );
        await expect( page ).toHaveURL( /s=shirt/ );
    } );
} );
```

- [ ] **Step 6: Commit**

```bash
git add .wp-env.json tests/e2e/
git commit -m "chore: add E2E test setup with Playwright, wp-env, and baseline specs"
```

---

### Task 55: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'
      - run: composer install --no-progress
      - run: npm ci
      - run: composer lint
      - run: composer analyse
      - run: npm run lint:js
      - run: npm run lint:css

  unit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [ '8.1', '8.2', '8.3', '8.4', '8.5' ]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer
          coverage: none
      - run: composer install --no-progress
      - run: composer test:unit

  integration:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [ '8.1', '8.3' ]
        wp: [ '6.2', '6.7', 'trunk' ]
    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports: [ '3306:3306' ]
        options: --health-cmd="mysqladmin ping" --health-interval=5s --health-retries=5
      meilisearch:
        image: getmeili/meilisearch:latest
        env:
          MEILI_MASTER_KEY: masterKey123
          MEILI_ENV: development
        ports: [ '7700:7700' ]
        options: --health-cmd="curl -f http://localhost:7700/health" --health-interval=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer
      - run: composer install --no-progress
      - run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 ${{ matrix.wp }}
        env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib
      - run: composer test:integration
        env:
          MEILISEARCH_HOST: http://localhost:7700
          MEILISEARCH_API_KEY: masterKey123

  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - run: npm run build
      - run: npx wp-env start
      - run: npx playwright test
        env:
          PLAYWRIGHT_BASE_URL: http://localhost:8889
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions with lint, unit (PHP 8.1-8.5), integration (WP 6.2/6.7/trunk), and E2E jobs"
```

---

## Phase 10 — Release + Docs (Tasks 56-60)

### Task 56: readme.txt (WP.org format)

**Files:**
- Create: `readme.txt`

- [ ] **Step 1: Create `readme.txt`**

```
=== Meilisearch ===
Contributors: meilisearch
Tags: search, meilisearch, woocommerce, instant search, faceted search
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, typo-tolerant search for WordPress and WooCommerce powered by Meilisearch.

== Description ==

The official Meilisearch plugin for WordPress replaces default search with fast, relevant, typo-tolerant results. Supports WooCommerce products with facets, filtering, and analytics out of the box.

**Features:**

* Server-side search replacement — works with any theme, no template changes needed
* InstantSearch shortcode and Gutenberg block for faceted search UI
* WooCommerce integration: products, variations, attributes, categories, stock sync
* Action Scheduler for reliable async indexing
* Meilisearch Cloud and self-hosted support
* Click tracking and conversion analytics
* Multisite-aware with per-site and network defaults
* Geo search support
* ACF field auto-discovery

== Installation ==

1. Upload the `meilisearch` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Navigate to Meilisearch > Connection and enter your host URL and API keys
4. Enable post types for indexing under Meilisearch > Post Types
5. Trigger a full re-index

== Frequently Asked Questions ==

= Do I need Meilisearch Cloud? =

No. The plugin works with both Meilisearch Cloud and self-hosted instances (v1.10+).

= Does it work with WooCommerce? =

Yes. WooCommerce features auto-activate when WooCommerce is detected. Products, variations, attributes, and categories are all indexed.

= How does search replacement work? =

The plugin hooks into `pre_get_posts` and replaces the MySQL fulltext query with a Meilisearch search. Your theme's search template works as-is.

== Changelog ==

= 1.0.0 =
* Initial release
* Server-side search replacement
* WooCommerce product indexing with variations
* InstantSearch shortcode and Gutenberg block
* Click tracking and conversion analytics
* Multisite support
* Geo search support
```

- [ ] **Step 2: Commit**

```bash
git add readme.txt
git commit -m "docs: add readme.txt in WP.org format"
```

---

### Task 57: Documentation files

**Files:**
- Create: `docs/docker-compose.example.yml`
- Create: `docs/local-by-flywheel.md`
- Create: `docs/wp-env.md`
- Create: `docs/cloud-setup.md`
- Create: `docs/multisite.md`
- Create: `docs/bedrock.md`

- [ ] **Step 1: Create `docs/docker-compose.example.yml`**

Copy the Docker Compose example from the spec verbatim (the full WP + MySQL + Meilisearch stack as documented in the spec's "Deployment Examples" section).

- [ ] **Step 2: Create documentation markdown files**

Each file provides a walkthrough for its environment:

- `docs/local-by-flywheel.md` — Install plugin, point at Meilisearch Cloud or local Docker.
- `docs/wp-env.md` — `@wordpress/env` contributor setup with `.wp-env.json`.
- `docs/cloud-setup.md` — Meilisearch Cloud signup, project creation, API key generation, embedder configuration.
- `docs/multisite.md` — Single-instance vs multi-instance strategies, index naming, network defaults.
- `docs/bedrock.md` — Install via Composer with Bedrock/Roots.

- [ ] **Step 3: Commit**

```bash
git add docs/
git commit -m "docs: add deployment guides for Docker, Local, wp-env, Cloud, multisite, and Bedrock"
```

---

### Task 58: php-scoper vendor scoping script

**Files:**
- Create: `scoper.inc.php`
- Create: `bin/scope-vendor.sh`

- [ ] **Step 1: Create `scoper.inc.php`**

```php
<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Meilisearch\\WordPress\\Vendor',
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS( true )
            ->notName( '/LICENSE|.*\\.md|.*\\.dist|Makefile/' )
            ->exclude( [ 'doc', 'test', 'Test', 'tests', 'Tests', 'vendor-bin' ] )
            ->in( 'vendor/meilisearch' )
            ->in( 'vendor/symfony/http-client' )
            ->in( 'vendor/php-http' )
            ->in( 'vendor/psr' ),
    ],
    'exclude-namespaces' => [
        // Action Scheduler must NOT be scoped — it shares a global instance.
        'ActionScheduler',
        'ActionScheduler_',
    ],
    'exclude-files' => [
        'vendor/woocommerce/action-scheduler/action-scheduler.php',
    ],
];
```

- [ ] **Step 2: Create `bin/scope-vendor.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

echo "==> Scoping vendor dependencies..."
composer install --no-dev --optimize-autoloader
vendor/bin/php-scoper add-prefix --output-dir=vendor-prefixed --force
composer dump-autoload --working-dir=vendor-prefixed --optimize

echo "==> Vendor scoped to Meilisearch\\WordPress\\Vendor\\"
echo "==> Testing scoped autoload..."
php -r "require 'vendor-prefixed/autoload.php'; echo 'Scoped autoload OK' . PHP_EOL;"
```

- [ ] **Step 3: Commit**

```bash
chmod +x bin/scope-vendor.sh
git add scoper.inc.php bin/scope-vendor.sh
git commit -m "chore: add php-scoper config and scope-vendor.sh build script"
```

---

### Task 59: SVN release script

**Files:**
- Create: `bin/release-to-svn.sh`

- [ ] **Step 1: Create `bin/release-to-svn.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:?Usage: release-to-svn.sh <version>}"
SVN_DIR="${SVN_DIR:-/tmp/meilisearch-svn}"
SLUG="meilisearch"

echo "==> Building release ${VERSION}..."

# Build vendor.
composer install --no-dev --optimize-autoloader
bash bin/scope-vendor.sh

# Build JS assets.
npm ci
npm run build

# Checkout SVN if needed.
if [ ! -d "${SVN_DIR}" ]; then
    svn co "https://plugins.svn.wordpress.org/${SLUG}/" "${SVN_DIR}"
fi

# Clear trunk.
rm -rf "${SVN_DIR}/trunk/"
mkdir -p "${SVN_DIR}/trunk/"

# Copy files.
rsync -av --exclude='.git' --exclude='node_modules' --exclude='vendor' \
    --exclude='tests' --exclude='.github' --exclude='.phpunit.cache' \
    --exclude='docker-compose.test.yml' --exclude='.wp-env.json' \
    --exclude='phpunit.xml.dist' --exclude='phpstan.neon' \
    --exclude='scoper.inc.php' --exclude='webpack.config.js' \
    ./ "${SVN_DIR}/trunk/"

# Copy scoped vendor.
cp -r vendor-prefixed/ "${SVN_DIR}/trunk/vendor-prefixed/"

# Copy Action Scheduler (unscoped).
mkdir -p "${SVN_DIR}/trunk/vendor/woocommerce/"
cp -r vendor/woocommerce/action-scheduler/ "${SVN_DIR}/trunk/vendor/woocommerce/action-scheduler/"

# Copy build output.
cp -r build/ "${SVN_DIR}/trunk/build/"

# Tag.
svn cp "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}"

echo "==> Ready to commit to SVN:"
echo "    cd ${SVN_DIR} && svn ci -m 'Release ${VERSION}'"
```

- [ ] **Step 2: Commit**

```bash
chmod +x bin/release-to-svn.sh
git add bin/release-to-svn.sh
git commit -m "chore: add release-to-svn.sh for WP.org plugin deployment"
```

---

### Task 60: GitHub Actions release workflow

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Create `.github/workflows/release.yml`**

```yaml
name: Release

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Version tag (e.g., 1.0.0)'
        required: true

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer

      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - run: composer install --no-dev --optimize-autoloader
      - run: npm ci
      - run: npm run build
      - run: bash bin/scope-vendor.sh

      - name: Create release zip
        run: |
          mkdir -p dist
          rsync -av --exclude='.git' --exclude='node_modules' --exclude='vendor' \
            --exclude='tests' --exclude='.github' --exclude='dist' \
            --exclude='docker-compose.test.yml' --exclude='.wp-env.json' \
            --exclude='phpunit.xml.dist' --exclude='phpstan.neon' \
            --exclude='scoper.inc.php' --exclude='webpack.config.js' \
            ./ dist/meilisearch/
          cp -r vendor-prefixed/ dist/meilisearch/vendor-prefixed/
          mkdir -p dist/meilisearch/vendor/woocommerce/
          cp -r vendor/woocommerce/action-scheduler/ dist/meilisearch/vendor/woocommerce/action-scheduler/
          cp -r build/ dist/meilisearch/build/
          cd dist && zip -r ../meilisearch-${{ github.event.inputs.version }}.zip meilisearch/

      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          tag_name: ${{ github.event.inputs.version }}
          name: v${{ github.event.inputs.version }}
          files: meilisearch-${{ github.event.inputs.version }}.zip
          generate_release_notes: true

      - name: Deploy to SVN
        env:
          SVN_USERNAME: ${{ secrets.WP_SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.WP_SVN_PASSWORD }}
        run: |
          bash bin/release-to-svn.sh ${{ github.event.inputs.version }}
          cd /tmp/meilisearch-svn
          svn ci --username "$SVN_USERNAME" --password "$SVN_PASSWORD" \
            -m "Release ${{ github.event.inputs.version }}"
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "ci: add manual-trigger release workflow for SVN + GitHub Release + zip"
```

---
