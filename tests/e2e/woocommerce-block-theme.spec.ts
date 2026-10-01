import { expect, test, type Page } from '@playwright/test';
import { wp } from './utils';

test.use( { storageState: { cookies: [], origins: [] } } );

// On a block theme the product list is a Product Collection block, but it renders the main query
// (inherit), so the Interceptor answers it. Whatever answers, the list must equal WordPress' own (MySQL) answer.
const BLOCK_THEME = 'twentytwentyfive';
const CLASSIC_THEME = 'storefront';

async function productTitles( page: Page, query: string ): Promise< string[] > {
	await page.goto( `/?post_type=product&s=meili+mug${ query }` );
	const titles = page.locator( 'main' ).getByRole( 'heading', { name: /^Meili Mug (Small|Large|Deluxe)$/ } );
	await expect( titles.first() ).toBeVisible();
	return ( await titles.allTextContents() ).map( ( t ) => t.trim() );
}

test.describe( 'WooCommerce product search on a block theme', () => {
	test.beforeAll( () => {
		wp( 'theme', 'activate', BLOCK_THEME );
	} );

	test.afterAll( () => {
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'replace', 'true', '--format=json' );
		wp( 'theme', 'activate', CLASSIC_THEME );
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
