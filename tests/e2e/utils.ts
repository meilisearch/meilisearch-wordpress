import { execFileSync } from 'node:child_process';
import path from 'node:path';

/** Admin session saved by global-setup.ts (paths are relative to the repository root, where npm runs). */
export const STORAGE_STATE = path.resolve( 'tests/e2e/.auth/admin.json' );

export const MEILI_URL = process.env.E2E_MEILI_URL ?? 'http://localhost:7700';
export const MEILI_KEY = process.env.E2E_MEILI_KEY ?? 'masterKey';
export const CONTENT_INDEX = 'e2e_content';

const WP_CLI = ( process.env.E2E_WP_CLI ?? 'docker compose exec -T -u www-data wordpress wp' ).split( ' ' );

/** Runs WP-CLI in the E2E WordPress container and returns trimmed stdout. */
export function wp( ...args: string[] ): string {
	return execFileSync( WP_CLI[ 0 ], [ ...WP_CLI.slice( 1 ), ...args ], { encoding: 'utf8' } ).trim();
}

/** Absolute admin URL of a plugin tab, computed by the plugin itself. */
export function tabUrl( tab: string ): string {
	return wp( 'eval', `echo \\Meilisearch\\WordPress\\Admin\\Menu::url( '${ tab }' );` );
}

/** Reindex state of the content index: null (idle) or the stored state's status. */
export function contentReindexStatus(): string | null {
	const raw = wp( 'eval', 'echo wp_json_encode( \\Meilisearch\\WordPress\\Plugin::instance()->get( "reindexer" )->status( "content" ) );' );
	const state = JSON.parse( raw ) as { status?: string } | null;
	return state?.status ?? null;
}

/**
 * Runs every due background action. Not `--group=meilisearch`: with Action Scheduler's hybrid store (WooCommerce
 * active, data migration pending) WP-CLI rejects an existing group as "does not exist".
 */
export function runActions(): void {
	wp( 'action-scheduler', 'run', '--force', '--batch-size=100' );
}

/**
 * Runs due background actions until the content index returns `expected` hits for `query`
 * (a post change is synced by an action scheduled a couple of seconds ahead).
 */
export async function syncUntilHits( query: string, expected: number ): Promise< void > {
	const deadline = Date.now() + 30_000;
	let hits = -1;
	while ( Date.now() < deadline ) {
		runActions();
		const response = await fetch( `${ MEILI_URL }/indexes/${ CONTENT_INDEX }/search`, {
			method: 'POST',
			headers: { Authorization: `Bearer ${ MEILI_KEY }`, 'Content-Type': 'application/json' },
			body: JSON.stringify( { q: query } ),
		} );
		hits = ( ( await response.json() ) as { hits: unknown[] } ).hits.length;
		if ( hits === expected ) {
			return;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
	throw new Error( `Expected ${ expected } hits for "${ query }", got ${ hits }.` );
}
