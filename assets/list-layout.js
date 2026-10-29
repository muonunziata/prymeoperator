/**
 * Keeps list screens (Posts, Pages, Media) tidy when a folders plugin floats
 * a sidebar to the left of the table.
 *
 * WordPress gives `.tablenav` `clear: both`, so the bulk-actions row and the
 * table drop below any left float and leave a gap as tall as the sidebar.
 * Once such a sidebar shows up we take it out of the float flow: it is pinned
 * to the left of #wpbody-content and the content gets matching padding.
 */
( function () {
	'use strict';

	var body = document.getElementById( 'wpbody-content' );
	if ( ! body ) {
		return;
	}

	// WordPress' own left floats that must never be treated as a sidebar.
	var CORE = '.subsubsub, .tablenav, .alignleft, .search-box, #screen-meta, #screen-meta-links, .notice, .wp-header-end, .clear';

	function isSidebar( el ) {
		if ( el.nodeType !== 1 || el.matches( CORE ) ) {
			return false;
		}
		var style = window.getComputedStyle( el );
		if ( style.float !== 'left' || style.display === 'none' ) {
			return false;
		}
		var w = el.offsetWidth;
		return w >= 150 && w <= 480 && el.offsetHeight >= 120;
	}

	function find() {
		var wrap = body.querySelector( ':scope > .wrap' );
		var pool = Array.prototype.slice.call( body.children );
		if ( wrap ) {
			pool = pool.concat( Array.prototype.slice.call( wrap.children ) );
		}
		for ( var i = 0; i < pool.length; i++ ) {
			if ( isSidebar( pool[ i ] ) ) {
				return pool[ i ];
			}
		}
		return null;
	}

	function apply( side ) {
		if ( side.parentNode !== body ) {
			body.insertBefore( side, body.firstChild );
		}
		body.classList.add( 'po-lf-host' );
		side.classList.add( 'po-lf-side' );

		var sync = function () {
			body.style.setProperty( '--po-lf-w', side.offsetWidth + 'px' );
			body.style.setProperty( '--po-lf-h', side.offsetHeight + 'px' );
		};
		sync();
		// The sidebar can be collapsed, expanded or grow with its folder tree.
		if ( window.ResizeObserver ) {
			new ResizeObserver( sync ).observe( side );
		}
	}

	var css = document.createElement( 'style' );
	css.id = 'prymeoperator-list-layout';
	css.textContent =
		'#wpbody-content.po-lf-host{position:relative;box-sizing:border-box;padding-left:calc(var(--po-lf-w) + 20px);min-height:calc(var(--po-lf-h) + 20px)}' +
		'#wpbody-content.po-lf-host>.po-lf-side{position:absolute!important;left:0;top:10px;float:none!important;margin:0!important}' +
		'@media (max-width:782px){#wpbody-content.po-lf-host{padding-left:0;min-height:0}#wpbody-content.po-lf-host>.po-lf-side{position:static!important;width:auto!important;margin:10px 10px 0 0!important}}';
	document.head.appendChild( css );

	function tryApply() {
		if ( body.classList.contains( 'po-lf-host' ) ) {
			return true;
		}
		var side = find();
		if ( side ) {
			apply( side );
			return true;
		}
		return false;
	}

	if ( tryApply() ) {
		return;
	}
	// Folder sidebars are usually injected by script after the page loads.
	var observer = new MutationObserver( function () {
		if ( tryApply() ) {
			observer.disconnect();
		}
	} );
	observer.observe( body, { childList: true, subtree: true } );
	setTimeout( function () {
		observer.disconnect();
	}, 10000 );
} )();
