import { expect, test, type Page } from '@playwright/test';
import { wp } from './utils';

test.use( { storageState: { cookies: [], origins: [] } } );

// On a block theme the product list is a Product Collection block that renders the main query (inherit).
// Proves two things: (1) Meilisearch answers it: a typo query only Meilisearch can match returns the products
// while the MySQL baseline (replace off) returns none; (2) the constraints (price range, sorting) give the same
// products as WordPress' own MySQL answer.
const BLOCK_THEME = 'twentytwentyfive';
const CLASSIC_THEME = 'storefront';

function mugHeadings( page: Page ) {
	return page.locator( 'main' ).getByRole( 'heading', { name: /^Meili Mug (Small|Large|Deluxe)$/ } );
}

async function productTitles( page: Page, query: string ): Promise< string[] > {
	await page.goto( `/?post_type=product&s=meili+mug${ query }` );
	await expect( mugHeadings( page ).first() ).toBeVisible();
	return ( await mugHeadings( page ).allTextContents() ).map( ( t ) => t.trim() );
}

test.describe( 'WooCommerce product search on a block theme', () => {
	test.beforeAll( () => {
		// A crashed earlier run must not leave the suite in baseline mode.
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
		expect( wp( 'option', 'pluck', 'meilisearch_search', 'replace' ) ).toBe( '1' );
		wp( 'theme', 'activate', BLOCK_THEME );
	} );

	test.afterAll( () => {
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
		wp( 'theme', 'activate', CLASSIC_THEME );
	} );

	test( 'a typo query is answered by Meilisearch, not by MySQL', async ( { page } ) => {
		// "meilli" matches no product in MySQL (LIKE); only Meilisearch's typo tolerance finds "meili".
		const typoQuery = '/?post_type=product&s=meilli+mug&min_price=10';

		await page.goto( typoQuery );
		await expect( mugHeadings( page ) ).toHaveCount( 2 );
		expect( ( await mugHeadings( page ).allTextContents() ).map( ( t ) => t.trim() ).sort() ).toEqual( [ 'Meili Mug Deluxe', 'Meili Mug Large' ] );

		wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'false', '--format=json' );
		try {
			await page.goto( typoQuery );
			await expect( page.locator( 'body' ) ).toHaveClass( /search-no-results/ );
			await expect( mugHeadings( page ) ).toHaveCount( 0 );
		} finally {
			wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
		}
	} );

	for ( const [ name, query, ordered ] of [
		[ 'a minimum price', '&min_price=10', false ],
		[ 'a price range sorted by price', '&min_price=6&max_price=30&orderby=price', true ],
		[ 'price descending', '&orderby=price-desc', true ],
	] as const ) {
		test( `${ name } gives the same products as WordPress without Meilisearch`, async ( { page } ) => {
			const withMeilisearch = await productTitles( page, query );

			wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'false', '--format=json' );
			let baseline: string[];
			try {
				baseline = await productTitles( page, query );
			} finally {
				wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
			}

			expect( ordered ? withMeilisearch : [ ...withMeilisearch ].sort() ).toEqual( ordered ? baseline : [ ...baseline ].sort() );
		} );
	}
} );
