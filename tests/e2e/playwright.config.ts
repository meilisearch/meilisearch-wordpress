import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { STORAGE_STATE } from './utils';

export default defineConfig( {
	testDir: '.',
	testMatch: '**/*.spec.ts',
	globalSetup: './global-setup.ts',
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	outputDir: path.resolve( 'test-results' ),
	reporter: process.env.CI
		? [ [ 'list' ], [ 'html', { outputFolder: path.resolve( 'playwright-report' ), open: 'never' } ] ]
		: [ [ 'list' ] ],
	use: {
		// Alternative with OrbStack: http://wordpress.<project>.orb.local (install the site with the same E2E_SITE_URL).
		baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
		storageState: STORAGE_STATE,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				// The plugin is configured with http://meilisearch:7700 (container DNS); the browser reaches
				// the same instance through the port Compose publishes on the host.
				launchOptions: { args: [ '--host-resolver-rules=MAP meilisearch 127.0.0.1' ] },
			},
		},
	],
} );
