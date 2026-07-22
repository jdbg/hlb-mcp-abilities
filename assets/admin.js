/**
 * HLB MCP Abilities — settings screen behavior.
 *
 * Progressive enhancement only: every ability checkbox is rendered visible and
 * enabled server-side, so the page is fully usable with this script disabled.
 * Loaded only on this plugin's own admin pages (see Admin::enqueue_assets()).
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function initTabs( root ) {
		var tabs = root.querySelectorAll( '.hlb-mcp-tab' );
		if ( ! tabs.length ) {
			return;
		}

		function activate( tab, focus ) {
			tabs.forEach( function ( candidate ) {
				var selected = candidate === tab;
				candidate.classList.toggle( 'nav-tab-active', selected );
				candidate.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				candidate.setAttribute( 'tabindex', selected ? '0' : '-1' );

				var panel = document.getElementById( candidate.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.hidden = ! selected;
				}
			} );

			if ( focus ) {
				tab.focus();
			}

			if ( window.history && window.history.replaceState ) {
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', tab.getAttribute( 'data-category' ) );
				window.history.replaceState( null, '', url );
			}
		}

		var params = new URLSearchParams( window.location.search );
		var wanted = params.get( 'tab' );
		var initial = null;

		if ( wanted ) {
			tabs.forEach( function ( candidate ) {
				if ( candidate.getAttribute( 'data-category' ) === wanted ) {
					initial = candidate;
				}
			} );
		}

		activate( initial || tabs[ 0 ], false );

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				activate( tab, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var target = null;

				if ( 'ArrowRight' === event.key ) {
					target = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === event.key ) {
					target = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === event.key ) {
					target = tabs[ 0 ];
				} else if ( 'End' === event.key ) {
					target = tabs[ tabs.length - 1 ];
				}

				if ( target ) {
					event.preventDefault();
					activate( target, true );
				}
			} );
		} );
	}

	function initSearch( root ) {
		var input = root.querySelector( '#hlb-mcp-search-input' );
		var status = root.querySelector( '.hlb-mcp-search-status' );
		var rows = root.querySelectorAll( '.hlb-mcp-row' );
		var tabs = root.querySelectorAll( '.hlb-mcp-tab' );

		if ( ! input || ! rows.length ) {
			return;
		}

		var l10n = window.hlbMcpAdminL10n || {};

		function visibleCount( category ) {
			var panel = document.getElementById( 'hlb-panel-' + category );
			return panel ? panel.querySelectorAll( '.hlb-mcp-row:not([hidden])' ).length : 0;
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim().toLowerCase();
			var visible = 0;

			rows.forEach( function ( row ) {
				var haystack = row.getAttribute( 'data-hlb-search' ) || '';
				var match = '' === term || -1 !== haystack.indexOf( term );
				row.hidden = ! match;
				if ( match ) {
					visible++;
				}
			} );

			tabs.forEach( function ( tab ) {
				var category = tab.getAttribute( 'data-category' );
				var count = visibleCount( category );
				var badge = tab.querySelector( '.hlb-mcp-tab-count' );

				if ( badge ) {
					badge.textContent = String( count );
				}
				tab.classList.toggle( 'hlb-mcp-tab-dimmed', '' !== term && 0 === count );
			} );

			if ( ! status ) {
				return;
			}

			if ( '' === term ) {
				status.textContent = l10n.searchCleared || '';
				return;
			}

			var template = l10n.searchStatus || '%1$d of %2$d abilities match "%3$s".';
			status.textContent = template
				.replace( '%1$d', String( visible ) )
				.replace( '%2$d', String( rows.length ) )
				.replace( '%3$s', term );
		} );
	}

	function initOverrideToggle() {
		var toggle = document.getElementById( 'hlb-mcp-override' );
		var fields = document.getElementById( 'hlb-mcp-fields' );

		if ( ! toggle || ! fields ) {
			return;
		}

		function sync() {
			var boxes = fields.querySelectorAll( 'input[type="checkbox"]' );
			boxes.forEach( function ( box ) {
				box.disabled = ! toggle.checked;
			} );
			fields.style.opacity = toggle.checked ? '1' : '0.55';
		}

		toggle.addEventListener( 'change', sync );
		sync();
	}

	ready( function () {
		document.querySelectorAll( '.hlb-mcp-tabs' ).forEach( function ( root ) {
			initTabs( root );
			initSearch( root );
		} );
		initOverrideToggle();
	} );
} )();
