import { expect, test } from '@playwright/test';
import { wp } from './utils';

// Real WP-CLI in the E2E container (the integration suite only has a shim).
test.describe( 'wp meilisearch', () => {
	test( 'status prints a table of the indexes', () => {
		const output = wp( 'meilisearch', 'status' );

		expect( output ).toMatch( /Meilisearch \d+\.\d+\.\d+, index prefix "e2e"/ );
		expect( output ).toMatch( /content\s+e2e_content/ );
		expect( output ).toMatch( /products\s+e2e_products/ );
	} );

	test( 'status --format=json writes only JSON to stdout', () => {
		const rows = JSON.parse( wp( 'meilisearch', 'status', '--format=json' ) ) as { index: string; uid: string }[];

		expect( rows.map( ( row ) => row.uid ) ).toEqual( [ 'e2e_content', 'e2e_products' ] );
	} );

	test( 'check passes against the real Meilisearch', () => {
		// wp() throws on a non-zero exit code.
		expect( wp( 'meilisearch', 'check' ) ).not.toBe( '' );
	} );

	test( 'reindex runs to completion', () => {
		expect( wp( 'meilisearch', 'reindex' ) ).toMatch( /Reindexed e2e_content: \d+ document\(s\) sent/ );
	} );
} );
