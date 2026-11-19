import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { CONTENT_INDEX, MEILI_KEY, MEILI_URL, contentReindexStatus, runActions, tabUrl } from './utils';

/** Same rule set as the autocomplete scan: serious and critical violations fail. */
async function expectAccessible( page: Page ): Promise< void > {
	const axe = await new AxeBuilder( { page } ).include( '#wpbody-content' ).analyze();
	const serious = axe.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	expect( serious, JSON.stringify( serious, null, 2 ) ).toEqual( [] );
}

test.describe( 'admin', () => {
	test( 'the Connection and Status tabs have no serious accessibility violations', async ( { page } ) => {
		await page.goto( tabUrl( 'connection' ) );
		await expect( page.getByRole( 'button', { name: 'Save and connect' } ) ).toBeVisible();
		await expectAccessible( page );

		await page.goto( tabUrl( 'status' ) );
		await expect( page.locator( '.meilisearch-status-table' ) ).toBeVisible();
		await expectAccessible( page );
	} );

	test( 'saving the Connection tab connects, shows the Meilisearch version and never the admin key', async ( { page, request } ) => {
		const response = await request.get( `${ MEILI_URL }/version`, { headers: { Authorization: `Bearer ${ MEILI_KEY }` } } );
		expect( response.ok() ).toBeTruthy();
		const { pkgVersion } = ( await response.json() ) as { pkgVersion: string };

		await page.goto( tabUrl( 'connection' ) );
		expect( await page.content() ).not.toContain( MEILI_KEY );
		await page.getByRole( 'button', { name: 'Save and connect' } ).click();

		await expect( page.getByText( `Connected to Meilisearch ${ pkgVersion }. Indexes are ready.` ) ).toBeVisible();
		await expect( page.locator( '.meilisearch-status' ) ).toContainText( `Connected to Meilisearch ${ pkgVersion }.` );
		expect( await page.content() ).not.toContain( MEILI_KEY );
	} );

	test( 'a reindex started from the Status tab completes and re-enables its button', async ( { page, request } ) => {
		await page.goto( tabUrl( 'status' ) );
		const button = page.locator( '[data-meilisearch-action="reindex"][data-meilisearch-index="content"]' );
		await expect( button ).toBeEnabled();

		const started = page.waitForResponse(
			( r ) => r.url().includes( 'meilisearch/v1/reindex' ) && r.request().method() === 'POST'
		);
		await button.click();
		expect( ( await started ).ok() ).toBeTruthy();
		await expect( button ).toBeDisabled();

		await expect
			.poll(
				() => {
					runActions();
					return contentReindexStatus();
				},
				{ timeout: 90_000, intervals: [ 1_000 ] }
			)
			.toBe( 'done' );

		// The page polls the reindex status itself: the button comes back and the progress text reports the end.
		await expect( button ).toBeEnabled( { timeout: 15_000 } );
		await expect( page.locator( '[data-meilisearch-progress="content"] .meilisearch-progress-text' ) ).toContainText( /\d/ );

		const stats = await request.get( `${ MEILI_URL }/indexes/${ CONTENT_INDEX }/stats`, {
			headers: { Authorization: `Bearer ${ MEILI_KEY }` },
		} );
		expect( ( ( await stats.json() ) as { numberOfDocuments: number } ).numberOfDocuments ).toBeGreaterThan( 0 );
		// The in-place reindex leaves exactly one content index for this prefix.
		const indexes = await request.get( `${ MEILI_URL }/indexes?limit=1000`, { headers: { Authorization: `Bearer ${ MEILI_KEY }` } } );
		const uids = ( ( await indexes.json() ) as { results: { uid: string }[] } ).results.map( ( i ) => i.uid );
		expect( uids.filter( ( uid ) => uid.startsWith( CONTENT_INDEX ) ) ).toEqual( [ CONTENT_INDEX ] );
	} );

	test( 'the Status tab keeps following a reindex after a failed status request', async ( { page } ) => {
		// The REST answers are scripted, so no reindex really runs: start, one failed refresh, progress, end.
		const running = ( sent: number ) => ( { status: 'running', phase: 'upsert', sent, total: 10, deleted: 0 } );
		const statuses = [
			{ status: 504, body: '<html>Gateway Time-out</html>', contentType: 'text/html' },
			{ status: 200, body: JSON.stringify( { content: running( 5 ), products: null, abandoned: {} } ), contentType: 'application/json' },
			{ status: 200, body: JSON.stringify( { content: { status: 'done', phase: 'upsert', sent: 10, total: 10, deleted: 0 }, products: null, abandoned: {} } ), contentType: 'application/json' },
		];
		let started = false;
		let answered = 0;
		await page.route( /meilisearch\/v1\/reindex(\/status)?(\?|$)/, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				started = true;
				await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( running( 0 ) ) } );
			} else if ( ! started ) {
				await route.continue(); // The poll on page load: the real, idle state.
			} else {
				await route.fulfill( statuses[ Math.min( answered++, statuses.length - 1 ) ] );
			}
		} );

		const initial = page.waitForResponse( /meilisearch\/v1\/reindex\/status/ );
		await page.goto( tabUrl( 'status' ) );
		await initial;
		const button = page.locator( '[data-meilisearch-action="reindex"][data-meilisearch-index="content"]' );
		const text = page.locator( '[data-meilisearch-progress="content"] .meilisearch-progress-text' );
		await expect( button ).toBeEnabled();
		await button.click();

		// The failed request is reported where the admin looks, and the reindex is still considered running.
		await expect( text ).toHaveText( 'Could not refresh the progress. Retrying…' );
		await expect( button ).toBeDisabled();

		// The next attempts go through: progress resumes, then the end re-enables the button.
		await expect( text ).toHaveText( /5 of 10/, { timeout: 10_000 } );
		// Same status and phase as before the failure: screen readers still hear the "Retrying…" was resolved.
		await expect( page.locator( '#meilisearch-live' ) ).toHaveText( /5 of 10/ );
		await expect( button ).toBeEnabled( { timeout: 10_000 } );
		await expect( text ).toHaveText( /10/ );
		expect( answered ).toBe( 3 );
	} );
} );
