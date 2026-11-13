import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { MEILI_KEY, syncUntilHits, wp } from './utils';

test.use( { storageState: { cookies: [], origins: [] } } );

interface AutocompleteConfig {
	key: string;
	host: string;
}

/** The search form inside the page content (themes may also render one in the header). */
function searchInput( page: Page ) {
	return page.locator( 'main' ).getByRole( 'combobox' ).first();
}

test.describe( 'autocomplete', () => {
	test.beforeAll( () => {
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'autocomplete', 'true', '--format=json' );
	} );

	test.afterAll( () => {
		wp( 'option', 'patch', 'update', 'meilisearch_search', 'autocomplete', 'false', '--format=json' );
	} );

	test( 'the browser only receives the search-only key', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const config = await page.evaluate( () => ( window as unknown as { meilisearchAutocomplete: AutocompleteConfig } ).meilisearchAutocomplete );

		expect( config.key.length ).toBeGreaterThan( 0 );
		expect( config.key ).not.toBe( MEILI_KEY );
		expect( JSON.stringify( config ) ).not.toContain( MEILI_KEY );
		expect( await page.content() ).not.toContain( MEILI_KEY );
	} );

	test( 'suggestions are accessible and keyboard navigable', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		await expect( input ).toBeVisible();

		await input.pressSequentially( 'mountian', { delay: 50 } );
		const listbox = page.getByRole( 'listbox' );
		await expect( listbox ).toBeVisible();
		await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( listbox.getByRole( 'option', { name: /Mountain Photography Tips/ } ) ).toBeVisible();
		const listId = await input.getAttribute( 'aria-controls' );
		await expect( page.locator( `[id="${ listId?.replace( /-listbox$/, '-status' ) }"][role="status"]` ) ).toHaveText( /\d/ );

		const axe = await new AxeBuilder( { page } ).include( 'main' ).analyze();
		const serious = axe.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
		expect( serious, JSON.stringify( serious, null, 2 ) ).toEqual( [] );

		await input.press( 'ArrowDown' );
		const activeId = await input.getAttribute( 'aria-activedescendant' );
		expect( activeId ).toMatch( /^meilisearch-ac-\d+-option-\d+$/ );
		const active = page.locator( `[id="${ activeId }"]` );
		await expect( active ).toHaveAttribute( 'aria-selected', 'true' );
		const href = await active.getAttribute( 'data-url' );
		expect( href ).toBeTruthy();

		await input.press( 'Enter' );
		await page.waitForURL( href as string );
		await expect( page.getByRole( 'heading', { name: /Mountain Photography Tips/ } ).first() ).toBeVisible();
	} );

	test( 'product suggestions show their price and open the product', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );

		await input.pressSequentially( 'meili mug', { delay: 50 } );
		const options = page.getByRole( 'listbox' ).getByRole( 'option', { name: /Meili Mug/ } );
		await expect( options ).toHaveCount( 3 );
		await expect( page.getByRole( 'listbox' ).getByText( 'Products' ) ).toBeVisible();
		await expect( options.filter( { hasText: '15' } ) ).toHaveCount( 1 );

		const large = options.filter( { hasText: 'Meili Mug Large' } );
		const href = await large.getAttribute( 'data-url' );
		expect( href ).toMatch( /\/product\/meili-mug-large\/$/ );
		await large.click();
		await page.waitForURL( /\/product\/meili-mug-large\/$/ );
		await expect( page.getByRole( 'heading', { name: 'Meili Mug Large', level: 1 } ) ).toBeVisible();
	} );

	test( 'hit text is rendered as text, never as HTML', async ( { page } ) => {
		const id = wp(
			'post', 'create', '--post_type=post', '--post_status=publish', '--porcelain',
			// WordPress stores the entities; the indexed title is the literal text `<img src=x onerror=...>`.
			'--post_title=Quokkafinch &lt;img src=x onerror=window.__xss=1&gt;',
			'--post_content=Quokkafinch markup safety check.'
		);
		try {
			await syncUntilHits( 'quokkafinch', 1 );

			await page.goto( '/search-demo/' );
			const input = searchInput( page );
			await input.pressSequentially( 'quokkafinch', { delay: 50 } );

			const listbox = page.getByRole( 'listbox' );
			const option = listbox.getByRole( 'option', { name: /Quokkafinch/ } );
			await expect( option ).toBeVisible();
			await expect( option ).toContainText( '<img src=x onerror=window.__xss=1>' );
			await expect( listbox.locator( 'img:not(.meilisearch-ac__thumb), script' ) ).toHaveCount( 0 );
			expect( await page.evaluate( () => ( window as unknown as { __xss?: number } ).__xss ) ).toBeUndefined();
		} finally {
			wp( 'post', 'delete', id, '--force' );
			await syncUntilHits( 'quokkafinch', 0 );
		}
	} );

	test( 'Escape closes the list and Enter without a selection submits the search form', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );

		await input.pressSequentially( 'mountian', { delay: 50 } );
		await expect( page.getByRole( 'listbox' ) ).toBeVisible();
		await input.press( 'Escape' );
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );

		await input.press( 'Enter' );
		await page.waitForURL( /[?&]s=mountian/ );
	} );

	test( 'a response that arrives after the visitor left the field does not open the list', async ( { page } ) => {
		// Slow Meilisearch down so the visitor can leave while the request is in flight.
		await page.route( '**/multi-search', async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
			await route.continue().catch( () => undefined ); // Already aborted by the page.
		} );
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		const listbox = page.locator( `[id="${ await input.getAttribute( 'aria-controls' ) }"]` );

		// Tab away while the request is in flight: it is aborted and nothing opens.
		const sent = page.waitForRequest( '**/multi-search' );
		const aborted = page.waitForEvent( 'requestfailed', ( request ) => request.url().endsWith( '/multi-search' ) );
		await input.fill( 'mountian' );
		await sent;
		await input.press( 'Tab' );
		await aborted;
		await expect( input ).not.toBeFocused();
		await page.waitForTimeout( 1_000 ); // Longer than the delayed response.
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( listbox ).toBeHidden();

		// Tab away within the debounce delay: the search is never sent.
		let requests = 0;
		page.on( 'request', ( request ) => {
			requests += request.url().endsWith( '/multi-search' ) ? 1 : 0;
		} );
		await input.fill( 'mountain' );
		await input.press( 'Tab' );
		await page.waitForTimeout( 1_000 );
		expect( requests ).toBe( 0 );
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( listbox ).toBeHidden();
	} );

	test( 'the arrow keys only reopen suggestions that answer the current text', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		const listbox = page.locator( `[id="${ await input.getAttribute( 'aria-controls' ) }"]` );

		// Same text: Escape then ArrowDown reopens the list.
		await input.pressSequentially( 'mountian', { delay: 50 } );
		await expect( listbox ).toBeVisible();
		await input.press( 'Escape' );
		await expect( listbox ).toBeHidden();
		await input.press( 'ArrowDown' );
		await expect( listbox ).toBeVisible();
		await expect( input ).toHaveAttribute( 'aria-activedescendant', /-option-0$/ );

		// Shortened below the minimum length: the old suggestions are gone for good.
		await input.fill( 'm' );
		await expect( listbox ).toBeHidden();
		await input.press( 'ArrowDown' );
		await input.press( 'ArrowUp' );
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( listbox ).toBeHidden();
		await expect( listbox.getByRole( 'option', { includeHidden: true } ) ).toHaveCount( 0 );

		// A failed request also drops the suggestions of the previous query.
		await input.fill( 'mountian' );
		await expect( listbox ).toBeVisible();
		await page.route( '**/multi-search', ( route ) => route.fulfill( { status: 503, contentType: 'application/json', body: '{}' } ) );
		const failed = page.waitForResponse( '**/multi-search' );
		await input.fill( 'mountian tips' );
		await failed;
		await expect( listbox ).toBeHidden();
		await input.press( 'ArrowDown' );
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( listbox ).toBeHidden();
	} );

	test( 'a filtered subtitle field renders under the title', async ( { page } ) => {
		// wp-content/mu-plugins is root-owned, so the throwaway plugin is written as root.
		const muPlugin = '/var/www/html/wp-content/mu-plugins/e2e-subtitle.php';
		const filter = '<?php add_filter( "meilisearch_autocomplete_subtitle_field", fn( $f, $l ) => "content" === $l ? "excerpt" : $f, 10, 2 );';
		execFileSync( 'docker', [ 'compose', 'exec', '-T', '-u', 'root', 'wordpress', 'sh', '-c', `mkdir -p "$(dirname ${ muPlugin })" && printf '%s' '${ filter }' > ${ muPlugin }` ] );
		try {
			await page.goto( '/search-demo/' );
			const input = searchInput( page );
			await input.pressSequentially( 'mountian', { delay: 50 } );
			const option = page.getByRole( 'option', { name: /Mountain Photography Tips/ } );
			await expect( option.locator( '.meilisearch-ac__subtitle' ) ).toContainText( 'Golden hour' );
		} finally {
			execFileSync( 'docker', [ 'compose', 'exec', '-T', '-u', 'root', 'wordpress', 'rm', '-f', muPlugin ] );
		}
	} );

	test( 'the footer shows the total and submits the search form', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		await input.pressSequentially( 'mountian', { delay: 50 } );
		const footer = page.getByRole( 'option', { name: /See all \d+ results for “mountian”/ } );
		await expect( footer ).toBeVisible();

		// Keyboard: the footer is the last option.
		await input.press( 'ArrowUp' );
		await expect( footer ).toHaveAttribute( 'aria-selected', 'true' );
		await input.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]s=mountian/ );
	} );

	test( 'the footer shows the query verbatim, even with $ patterns', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		await input.pressSequentially( 'mountian $&', { delay: 50 } );
		await expect( page.getByRole( 'option', { name: /See all .*“mountian \$&”/ } ) ).toBeVisible();
	} );
} );
