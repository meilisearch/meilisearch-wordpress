import fs from 'node:fs';
import path from 'node:path';
import { chromium, type FullConfig } from '@playwright/test';
import { STORAGE_STATE } from './utils';

export default async function globalSetup( config: FullConfig ): Promise< void > {
	const baseURL = config.projects[ 0 ]?.use.baseURL ?? 'http://localhost:8080';
	fs.mkdirSync( path.dirname( STORAGE_STATE ), { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( process.env.E2E_ADMIN_USER ?? 'admin' );
	await page.locator( '#user_pass' ).fill( process.env.E2E_ADMIN_PASSWORD ?? 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\/?/ );
	await page.context().storageState( { path: STORAGE_STATE } );
	await browser.close();
}
