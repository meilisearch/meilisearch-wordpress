import { expect, test } from '@playwright/test';
import { wp } from './utils';

test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'theme search', () => {
	test( 'a typo query that MySQL cannot match is answered by Meilisearch', async ( { page } ) => {
		const result = page.locator( 'main' ).getByRole( 'link', { name: 'Mountain Photography Tips' } );

		await page.goto( '/?s=mountian+photografy' );
		await expect( page.locator( 'body' ) ).toHaveClass( /(^|\s)search-results(\s|$)/ );
		await expect( result.first() ).toBeVisible();

		// Proof that Meilisearch served it: with replacement off, WordPress' LIKE search finds nothing.
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'false', '--format=json' );
		try {
			await page.goto( '/?s=mountian+photografy' );
			await expect( page.locator( 'body' ) ).toHaveClass( /(^|\s)search-no-results(\s|$)/ );
			await expect( result ).toHaveCount( 0 );
		} finally {
			wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
		}
	} );

	test( 'a page past the end behaves exactly like WordPress without Meilisearch', async ( { page } ) => {
		// WP::handle_404() answers 404 for a paged query without posts, including searches.
		const response = await page.goto( '/?s=mountian&paged=999' );

		expect( response?.status() ).toBe( 404 );
		await expect( page.getByRole( 'link', { name: 'Mountain Photography Tips' } ) ).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toContainText( /Fatal error|Warning:|Notice:/ );
	} );
} );
