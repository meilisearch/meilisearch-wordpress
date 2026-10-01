import { expect, test } from '@playwright/test';
import { CONTENT_INDEX, MEILI_KEY, MEILI_URL, contentReindexStatus, runActions, tabUrl } from './utils';

test.describe( 'admin', () => {
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
} );
