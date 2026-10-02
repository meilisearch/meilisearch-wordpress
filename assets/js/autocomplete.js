/*!
 * Meilisearch for WordPress — search-as-you-type suggestions.
 * License: GPL-2.0-or-later. Readable source of autocomplete.min.js.
 *
 * Configuration comes from wp_localize_script() as window.meilisearchAutocomplete
 * (see src/Frontend/Autocomplete.php). Results are rendered with createElement /
 * textContent only; highlight markers (U+0002 / U+0003) become <mark> elements,
 * so no HTML from the index is ever inserted into the page.
 */
( function () {
	'use strict';

	const config = window.meilisearchAutocomplete;
	if ( ! config || ! config.host || ! config.key || ! config.indexes || ! window.fetch || ! window.AbortController ) {
		return;
	}

	const PRE_TAG = '\u0002';
	const POST_TAG = '\u0003';
	const CONTENT_FIELDS = [ 'id', 'title', 'permalink', 'post_type', 'thumbnail_url' ];
	const PRODUCT_FIELDS = CONTENT_FIELDS.concat( [ 'price' ] );
	const limits = Object.assign( { content: 5, products: 5, minChars: 2, debounce: 150 }, config.limits || {} );
	const subtitles = Object.assign( { content: null, products: null }, config.subtitles || {} );
	const i18n = Object.assign(
		{
			products: 'Products',
			posts: 'Posts',
			listLabel: 'Search suggestions',
			noResults: 'No suggestions found.',
			oneResult: '1 suggestion available.',
			results: '%d suggestions available.',
			seeAll: 'See all results for “%s”',
			seeAllCount: 'See all %1$d results for “%2$s”',
		},
		config.i18n || {}
	);
	const endpoint = String( config.host ).replace( /\/+$/, '' ) + '/multi-search';
	let instances = 0;
	let priceFormatter = null;

	function element( tag, attributes ) {
		const node = document.createElement( tag );
		Object.keys( attributes || {} ).forEach( function ( name ) {
			node.setAttribute( name, attributes[ name ] );
		} );
		return node;
	}

	function safeUrl( value ) {
		if ( typeof value !== 'string' || value === '' ) {
			return null;
		}
		try {
			const url = new URL( value, window.location.href );
			return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
		} catch ( error ) {
			return null;
		}
	}

	function appendHighlighted( parent, text ) {
		const parts = String( text ).split( PRE_TAG );
		parent.appendChild( document.createTextNode( parts[ 0 ].split( POST_TAG ).join( '' ) ) );
		for ( let i = 1; i < parts.length; i++ ) {
			const end = parts[ i ].indexOf( POST_TAG );
			const mark = document.createElement( 'mark' );
			mark.textContent = end === -1 ? parts[ i ] : parts[ i ].slice( 0, end );
			parent.appendChild( mark );
			if ( end !== -1 ) {
				parent.appendChild( document.createTextNode( parts[ i ].slice( end + 1 ).split( POST_TAG ).join( '' ) ) );
			}
		}
	}

	function formatPrice( value ) {
		if ( typeof value !== 'number' || ! isFinite( value ) ) {
			return '';
		}
		if ( config.currency ) {
			try {
				priceFormatter = priceFormatter || new Intl.NumberFormat( config.locale || undefined, { style: 'currency', currency: config.currency } );
				return priceFormatter.format( value );
			} catch ( error ) {
				priceFormatter = null;
			}
		}
		return String( value );
	}

	function fieldsFor( key, base ) {
		return subtitles[ key ] ? base.concat( [ subtitles[ key ] ] ) : base;
	}

	function buildQuery( indexUid, q, limit, fields ) {
		return {
			indexUid: indexUid,
			q: q,
			limit: limit,
			attributesToRetrieve: fields,
			attributesToHighlight: [ 'title' ],
			highlightPreTag: PRE_TAG,
			highlightPostTag: POST_TAG,
		};
	}

	function attach( input ) {
		instances += 1;
		const prefix = 'meilisearch-ac-' + instances;
		const listId = prefix + '-listbox';
		const panel = element( 'div', { class: 'meilisearch-ac' } );
		const listbox = element( 'div', { id: listId, role: 'listbox', 'aria-label': i18n.listLabel } );
		const status = element( 'div', { id: prefix + '-status', class: 'meilisearch-ac__status', role: 'status', 'aria-live': 'polite' } );
		let options = [];
		let active = -1;
		let timer = null;
		let controller = null;
		let lastQuery = '';

		panel.hidden = true;
		panel.appendChild( listbox );
		input.insertAdjacentElement( 'afterend', panel );
		panel.insertAdjacentElement( 'afterend', status );

		input.setAttribute( 'data-meilisearch-autocomplete', '1' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );
		input.setAttribute( 'autocomplete', 'off' );

		function announce( text ) {
			status.textContent = text;
		}

		function position() {
			panel.style.top = input.offsetTop + input.offsetHeight + 'px';
			panel.style.left = input.offsetLeft + 'px';
			panel.style.minWidth = input.offsetWidth + 'px';
		}

		function setActive( index ) {
			options.forEach( function ( option, i ) {
				const selected = i === index;
				option.element.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				option.element.classList.toggle( 'is-active', selected );
			} );
			active = index;
			if ( index < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
				return;
			}
			input.setAttribute( 'aria-activedescendant', options[ index ].element.id );
			if ( typeof options[ index ].element.scrollIntoView === 'function' ) {
				options[ index ].element.scrollIntoView( { block: 'nearest' } );
			}
		}

		function open() {
			position();
			panel.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function close() {
			setActive( -1 );
			panel.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
		}

		function abort() {
			if ( controller ) {
				controller.abort();
				controller = null;
			}
		}

		function go( url ) {
			close();
			window.location.assign( url );
		}

		function submitSearch() {
			close();
			if ( input.form ) {
				if ( typeof input.form.requestSubmit === 'function' ) {
					input.form.requestSubmit();
				} else {
					input.form.submit();
				}
				return;
			}
			window.location.assign( '/?s=' + encodeURIComponent( input.value ) );
		}

		function render( groups, total ) {
			listbox.textContent = '';
			options = [];
			groups.forEach( function ( group, groupIndex ) {
				const labelId = prefix + '-group-' + groupIndex;
				const groupNode = element( 'div', { class: 'meilisearch-ac__group', role: 'group', 'aria-labelledby': labelId, 'data-index': group.key } );
				const label = element( 'div', { id: labelId, class: 'meilisearch-ac__group-label', role: 'presentation' } );
				label.textContent = group.label;
				groupNode.appendChild( label );

				group.hits.forEach( function ( hit ) {
					const url = safeUrl( hit.permalink );
					if ( ! url ) {
						return;
					}
					const option = element( 'div', {
						id: prefix + '-option-' + options.length,
						class: 'meilisearch-ac__option',
						role: 'option',
						'aria-selected': 'false',
						'data-url': url,
					} );
					const thumbnail = safeUrl( hit.thumbnail_url );
					if ( thumbnail ) {
						const image = element( 'img', { class: 'meilisearch-ac__thumb', src: thumbnail, alt: '', loading: 'lazy', width: '32', height: '32' } );
						option.appendChild( image );
					}
					const title = element( 'span', { class: 'meilisearch-ac__title' } );
					const formatted = hit._formatted && typeof hit._formatted.title === 'string' ? hit._formatted.title : hit.title;
					appendHighlighted( title, formatted || '' );
					option.appendChild( title );
					const subtitleField = subtitles[ group.key ];
					const subtitleValue = subtitleField ? hit[ subtitleField ] : null;
					if ( typeof subtitleValue === 'string' && subtitleValue !== '' ) {
						const subtitle = element( 'span', { class: 'meilisearch-ac__subtitle' } );
						subtitle.textContent = subtitleValue;
						title.appendChild( document.createElement( 'br' ) );
						title.appendChild( subtitle );
					}
					if ( group.key === 'products' ) {
						const price = formatPrice( hit.price );
						if ( price !== '' ) {
							const priceNode = element( 'span', { class: 'meilisearch-ac__price' } );
							priceNode.textContent = price;
							option.appendChild( priceNode );
						}
					}
					option.addEventListener( 'mousedown', function ( event ) {
						event.preventDefault(); // Keep focus in the input.
					} );
					option.addEventListener( 'click', function () {
						go( url );
					} );
					options.push( { element: option, url: url } );
					groupNode.appendChild( option );
				} );

				if ( groupNode.querySelector( '[role="option"]' ) ) {
					listbox.appendChild( groupNode );
				}
			} );

			const suggestions = options.filter( function ( option ) {
				return option.url !== null;
			} ).length;
			if ( suggestions > 0 ) {
				const footer = element( 'div', {
					id: prefix + '-footer',
					class: 'meilisearch-ac__footer',
					role: 'option',
					'aria-selected': 'false',
					'data-submit': '1',
				} );
				footer.textContent = typeof total === 'number' && total > 0
					? i18n.seeAllCount.replace( '%1$d', String( total ) ).replace( '%2$s', lastQuery )
					: i18n.seeAll.replace( '%s', lastQuery );
				footer.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
				} );
				footer.addEventListener( 'click', submitSearch );
				options.push( { element: footer, url: null } );
				listbox.appendChild( footer );
			}

			setActive( -1 );
			if ( suggestions === 0 ) {
				close();
				announce( i18n.noResults );
				return;
			}
			open();
			announce( suggestions === 1 ? i18n.oneResult : i18n.results.replace( '%d', String( suggestions ) ) );
		}

		function groupsFrom( data ) {
			const hitsByIndex = {};
			const results = data && Array.isArray( data.results ) ? data.results : [];
			let total = results.length > 0 ? 0 : null;
			results.forEach( function ( result ) {
				hitsByIndex[ result.indexUid ] = Array.isArray( result.hits ) ? result.hits : [];
				if ( total !== null ) {
					total = typeof result.estimatedTotalHits === 'number' ? total + result.estimatedTotalHits : null;
				}
			} );
			const groups = [];
			if ( config.indexes.products ) {
				groups.push( { key: 'products', label: i18n.products, hits: hitsByIndex[ config.indexes.products ] || [] } );
			}
			groups.push( { key: 'content', label: i18n.posts, hits: hitsByIndex[ config.indexes.content ] || [] } );
			return {
				groups: groups.filter( function ( group ) {
					return group.hits.length > 0;
				} ),
				total: total,
			};
		}

		async function search( q ) {
			abort();
			lastQuery = q;
			if ( q.length < limits.minChars ) {
				close();
				return;
			}
			const queries = [];
			if ( config.indexes.products ) {
				queries.push( buildQuery( config.indexes.products, q, limits.products, fieldsFor( 'products', PRODUCT_FIELDS ) ) );
			}
			queries.push( buildQuery( config.indexes.content, q, limits.content, fieldsFor( 'content', CONTENT_FIELDS ) ) );

			const current = new AbortController();
			controller = current;
			try {
				const response = await window.fetch( endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + config.key },
					body: JSON.stringify( { queries: queries } ),
					signal: current.signal,
					credentials: 'omit',
				} );
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				const data = await response.json();
				if ( current.signal.aborted || q !== lastQuery ) {
					return;
				}
				const result = groupsFrom( data );
				render( result.groups, result.total );
			} catch ( error ) {
				if ( ! current.signal.aborted ) {
					close(); // Network or API error: hide silently, the form keeps working.
				}
			} finally {
				if ( controller === current ) {
					controller = null;
				}
			}
		}

		input.addEventListener( 'input', function () {
			const q = input.value.trim();
			window.clearTimeout( timer );
			if ( q.length < limits.minChars ) {
				abort();
				lastQuery = q;
				close();
				return;
			}
			timer = window.setTimeout( function () {
				search( q );
			}, limits.debounce );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.isComposing ) {
				return;
			}
			const expanded = ! panel.hidden;
			switch ( event.key ) {
				case 'ArrowDown':
					if ( options.length === 0 ) {
						return;
					}
					event.preventDefault();
					if ( ! expanded ) {
						open();
					}
					setActive( active < 0 || active >= options.length - 1 ? 0 : active + 1 );
					break;
				case 'ArrowUp':
					if ( options.length === 0 ) {
						return;
					}
					event.preventDefault();
					if ( ! expanded ) {
						open();
					}
					setActive( active <= 0 ? options.length - 1 : active - 1 );
					break;
				case 'Escape':
					if ( expanded ) {
						event.preventDefault();
						close();
					}
					break;
				case 'Enter':
					if ( expanded && active >= 0 ) {
						event.preventDefault();
						if ( options[ active ].url === null ) {
							submitSearch();
						} else {
							go( options[ active ].url );
						}
					} else {
						close(); // Normal form submission: the theme results page is canonical.
					}
					break;
				case 'Tab':
					close();
					break;
			}
		} );

		input.addEventListener( 'blur', close );

		document.addEventListener( 'mousedown', function ( event ) {
			if ( event.target !== input && ! panel.contains( event.target ) ) {
				close();
			}
		} );
	}

	function init() {
		let inputs;
		try {
			inputs = document.querySelectorAll( config.selector || 'form[role=search] input[name=s], input[name=s]' );
		} catch ( error ) {
			return; // Invalid selector from the meilisearch_autocomplete_selector filter.
		}
		Array.prototype.forEach.call( inputs, function ( input ) {
			if ( input.tagName !== 'INPUT' || input.hasAttribute( 'data-meilisearch-autocomplete' ) ) {
				return;
			}
			attach( input );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
