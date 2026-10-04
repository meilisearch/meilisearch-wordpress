import { expect, test, type Page } from '@playwright/test';

test.use( { storageState: { cookies: [], origins: [] } } );

async function productTitles( page: Page, query: string, search = 'meili+mug' ): Promise< string[] > {
	await page.goto( `/?post_type=product&s=${ search }${ query }` );
	const titles = page.locator( 'main' ).getByRole( 'heading', { name: /^Meili Mug (Small|Large|Deluxe)$/ } );
	await expect( titles.first() ).toBeVisible();
	return ( await titles.allTextContents() ).map( ( t ) => t.trim() );
}

// WooCommerce's product query only runs with a theme that supports WooCommerce (bin/e2e-setup.sh activates Storefront).
test.describe( 'WooCommerce product search', () => {
	test( 'sorts by price ascending', async ( { page } ) => {
		expect( await productTitles( page, '&orderby=price' ) ).toEqual( [ 'Meili Mug Small', 'Meili Mug Large', 'Meili Mug Deluxe' ] );
	} );

	test( 'sorts by price descending', async ( { page } ) => {
		expect( await productTitles( page, '&orderby=price-desc' ) ).toEqual( [ 'Meili Mug Deluxe', 'Meili Mug Large', 'Meili Mug Small' ] );
	} );

	test( 'tolerates typos, which MySQL cannot', async ( { page } ) => {
		expect( await productTitles( page, '&orderby=price', 'meilli+mug' ) ).toEqual( [ 'Meili Mug Small', 'Meili Mug Large', 'Meili Mug Deluxe' ] );
	} );

	test( 'filters by price range', async ( { page } ) => {
		// A single match: WooCommerce redirects the search to the product itself.
		await page.goto( '/?post_type=product&s=meili+mug&min_price=10&max_price=20' );

		await expect( page ).toHaveURL( /\/product\/meili-mug-large\/$/ );
		await expect( page.getByRole( 'heading', { name: 'Meili Mug Large', level: 1 } ) ).toBeVisible();
	} );
} );
