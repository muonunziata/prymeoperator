/**
 * PrymeOperator Library: folder sidebar for Media, Pages, Posts and other
 * post type lists. Plain DOM so it loads fast on every list screen.
 */
( function () {
	'use strict';

	var CFG = window.PrymeOperatorLibrary;
	var body = document.getElementById( 'wpbody-content' );
	if ( ! CFG || ! body || ! window.wp || ! wp.apiFetch ) {
		return;
	}

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var isGrid = CFG.mode === 'grid';
	var storeKey = 'prymeoperator-library-' + CFG.scope;

	var state = {
		folders: [],
		total: 0,
		unassigned: 0,
		current: CFG.current, // 'all', 'none' or a folder ID as string
		open: load( 'open', {} ),
		collapsed: load( 'collapsed', false ),
		filter: '',
		editing: null, // { id, parent } — id 0 means a new folder under parent
	};

	function load( key, fallback ) {
		try {
			var v = JSON.parse( window.localStorage.getItem( storeKey + '-' + key ) );
			return v === null ? fallback : v;
		} catch ( e ) {
			return fallback;
		}
	}
	function save( key, value ) {
		try {
			window.localStorage.setItem( storeKey + '-' + key, JSON.stringify( value ) );
		} catch ( e ) {}
	}

	function api( path, options ) {
		return wp.apiFetch( Object.assign( { path: '/prymeoperator/v1/library/' + path }, options || {} ) );
	}

	function el( tag, attrs ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) node.textContent = attrs[ k ];
			else if ( k === 'className' ) node.className = attrs[ k ];
			else if ( k.indexOf( 'on' ) === 0 ) node.addEventListener( k.slice( 2 ), attrs[ k ] );
			else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) node.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			if ( arguments[ i ] ) node.appendChild( typeof arguments[ i ] === 'string' ? document.createTextNode( arguments[ i ] ) : arguments[ i ] );
		}
		return node;
	}

	// ── Notices ────────────────────────────────────────────────────────────

	var toastTimer;
	function toast( message, isError ) {
		var box = document.getElementById( 'po-lib-toast' );
		if ( ! box ) {
			box = el( 'div', { id: 'po-lib-toast', role: 'status', 'aria-live': 'polite' } );
			document.body.appendChild( box );
		}
		box.textContent = message;
		box.className = 'is-visible' + ( isError ? ' is-error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			box.className = '';
		}, 3500 );
	}
	function fail( err ) {
		toast( ( err && err.message ) || __( 'Something went wrong.', 'prymeoperator' ), true );
	}

	// ── Data ───────────────────────────────────────────────────────────────

	function apply( data ) {
		state.folders = data.folders;
		state.total = data.total;
		state.unassigned = data.unassigned;
		if ( /^\d+$/.test( state.current ) && ! byId( +state.current ) ) {
			state.current = 'all';
		}
		render();
	}

	function refresh() {
		return api( 'folders?scope=' + encodeURIComponent( CFG.scope ) ).then( apply, fail );
	}

	function byId( id ) {
		for ( var i = 0; i < state.folders.length; i++ ) {
			if ( state.folders[ i ].id === id ) return state.folders[ i ];
		}
		return null;
	}

	function childrenOf( parent ) {
		return state.folders.filter( function ( f ) {
			return f.parent_id === parent;
		} );
	}

	function ancestors( id ) {
		var out = [];
		var f = byId( id );
		while ( f && f.parent_id && out.length < 100 ) {
			out.push( f.parent_id );
			f = byId( f.parent_id );
		}
		return out;
	}

	function isInside( id, maybeAncestor ) {
		return ancestors( id ).indexOf( maybeAncestor ) !== -1;
	}

	// ── Navigation ─────────────────────────────────────────────────────────

	function select( value ) {
		value = String( value );
		if ( isGrid ) {
			state.current = value;
			gridFilter();
			setUrl( value );
			render();
			return;
		}
		var url = new URL( window.location.href );
		url.searchParams.delete( 'paged' );
		if ( value === 'all' ) url.searchParams.delete( CFG.param );
		else url.searchParams.set( CFG.param, value );
		window.location.href = url.toString();
	}

	function setUrl( value ) {
		try {
			var url = new URL( window.location.href );
			if ( value === 'all' ) url.searchParams.delete( CFG.param );
			else url.searchParams.set( CFG.param, value );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
	}

	function gridLibrary() {
		var frame = wp.media && wp.media.frames && wp.media.frames.browse;
		var st = frame && frame.state && frame.state();
		return st && st.get ? st.get( 'library' ) : null;
	}

	function gridFilter( force ) {
		var lib = gridLibrary();
		if ( ! lib ) return;
		var props = {};
		props[ CFG.param ] = state.current === 'all' ? undefined : state.current;
		if ( force ) props.poRefresh = Date.now(); // Busts the cached query after moving items.
		lib.props.set( props );
		uploadTarget();
	}

	// New uploads go into the open folder.
	function uploadTarget() {
		var target = /^\d+$/.test( state.current ) ? state.current : '';
		try {
			if ( wp.Uploader && wp.Uploader.defaults ) {
				wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};
				wp.Uploader.defaults.multipart_params[ CFG.param ] = target;
			}
			var frame = wp.media && wp.media.frames && wp.media.frames.browse;
			var plup = frame && frame.uploader && frame.uploader.uploader && frame.uploader.uploader.uploader;
			if ( plup && plup.settings ) {
				var params = Object.assign( {}, plup.settings.multipart_params );
				params[ CFG.param ] = target;
				plup.setOption( 'multipart_params', params );
			}
		} catch ( e ) {}
	}

	// ── Folder actions ─────────────────────────────────────────────────────

	function startCreate( parent ) {
		if ( parent ) {
			state.open[ parent ] = true;
			save( 'open', state.open );
		}
		state.editing = { id: 0, parent: parent || 0 };
		render();
	}

	function commitEdit( value ) {
		var ed = state.editing;
		state.editing = null;
		value = ( value || '' ).trim();
		if ( ! ed || ! value ) {
			render();
			return;
		}
		if ( ed.id ) {
			var f = byId( ed.id );
			if ( f && f.name === value ) {
				render();
				return;
			}
			api( 'folders/' + ed.id, { method: 'POST', data: { name: value } } ).then( apply, function ( e ) {
				fail( e );
				render();
			} );
		} else {
			api( 'folders', { method: 'POST', data: { scope: CFG.scope, name: value, parent: ed.parent } } ).then( function ( data ) {
				apply( data );
				toast( sprintf( __( 'Folder "%s" created.', 'prymeoperator' ), ( byId( data.id ) || {} ).name || value ) );
			}, function ( e ) {
				fail( e );
				render();
			} );
		}
	}

	function remove( folder ) {
		var msg = sprintf( __( 'Delete the folder "%s"?', 'prymeoperator' ), folder.name ) + '\n\n' +
			__( 'Nothing inside is deleted: its items become unassigned and its subfolders move up one level.', 'prymeoperator' );
		if ( ! window.confirm( msg ) ) return;
		api( 'folders/' + folder.id, { method: 'DELETE' } ).then( function ( data ) {
			var wasCurrent = state.current === String( folder.id );
			apply( data );
			toast( sprintf( __( 'Folder "%s" deleted.', 'prymeoperator' ), folder.name ) );
			if ( wasCurrent ) select( 'all' );
			else afterItemsChanged();
		}, fail );
	}

	function moveFolder( id, parent ) {
		if ( id === parent || ( parent && isInside( parent, id ) ) ) {
			toast( __( 'A folder cannot be moved inside itself.', 'prymeoperator' ), true );
			return;
		}
		var f = byId( id );
		if ( ! f || f.parent_id === parent ) return;
		if ( parent ) {
			state.open[ parent ] = true;
			save( 'open', state.open );
		}
		api( 'folders/' + id, { method: 'POST', data: { parent: parent } } ).then( apply, fail );
	}

	function assign( ids, folder ) {
		api( 'assign', { method: 'POST', data: { scope: CFG.scope, ids: ids, folder: folder } } ).then( function ( data ) {
			apply( data );
			var target = folder ? ( byId( folder ) || {} ).name : __( 'Unassigned', 'prymeoperator' );
			if ( data.moved ) toast( sprintf( _n( '%1$d item moved to "%2$s".', '%1$d items moved to "%2$s".', data.moved, 'prymeoperator' ), data.moved, target ) );
			if ( data.skipped ) toast( sprintf( _n( '%d item could not be moved: you cannot edit it.', '%d items could not be moved: you cannot edit them.', data.skipped, 'prymeoperator' ), data.skipped ), true );
			var leaving = state.current !== 'all' && state.current !== String( folder );
			if ( leaving && data.moved ) removeRows( ids );
			afterItemsChanged();
		}, fail );
	}

	function afterItemsChanged() {
		if ( isGrid && state.current !== 'all' ) gridFilter( true );
	}

	function removeRows( ids ) {
		if ( isGrid ) return;
		ids.forEach( function ( id ) {
			var row = document.getElementById( 'post-' + id );
			if ( row ) {
				row.classList.add( 'po-lib-leaving' );
				setTimeout( function () {
					row.remove();
				}, 300 );
			}
		} );
	}

	function importMediaflow() {
		if ( ! window.confirm( __( 'Copy the folders from MediaFlow Pro into PrymeOperator? Your MediaFlow data is not changed.', 'prymeoperator' ) ) ) return;
		api( 'import-mediaflow', { method: 'POST' } ).then( function ( r ) {
			toast( sprintf( __( 'Imported %1$d folders and %2$d items.', 'prymeoperator' ), r.folders, r.items ) );
			CFG.mediaflow = false;
			refresh().then( afterItemsChanged );
		}, fail );
	}

	// ── Drag and drop ──────────────────────────────────────────────────────

	var drag = null; // { type: 'items', ids: [] } or { type: 'folder', id }

	function draggedItems( source ) {
		var id;
		if ( isGrid ) {
			id = +source.getAttribute( 'data-id' );
			var picked = Array.prototype.map.call( document.querySelectorAll( '.attachments li.attachment.selected[data-id]' ), function ( n ) {
				return +n.getAttribute( 'data-id' );
			} );
			return picked.indexOf( id ) !== -1 ? picked : [ id ];
		}
		id = +source.id.replace( 'post-', '' );
		var checked = Array.prototype.map.call( document.querySelectorAll( '#the-list input[type=checkbox][name="post[]"]:checked, #the-list input[type=checkbox][name="media[]"]:checked' ), function ( n ) {
			return +n.value;
		} );
		return checked.indexOf( id ) !== -1 ? checked : [ id ];
	}

	function ghost( count ) {
		var g = el( 'div', { id: 'po-lib-ghost' }, el( 'span', { className: 'dashicons dashicons-move' } ), sprintf( _n( '%d item', '%d items', count, 'prymeoperator' ), count ) );
		document.body.appendChild( g );
		setTimeout( function () {
			g.remove();
		}, 0 );
		return g;
	}

	document.addEventListener( 'dragstart', function ( e ) {
		var src = e.target && e.target.closest && e.target.closest( isGrid ? '.attachments li.attachment[data-id]' : '#the-list > tr[id^="post-"]' );
		if ( ! src ) return;
		var ids = draggedItems( src );
		drag = { type: 'items', ids: ids };
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', ids.join( ',' ) );
		if ( e.dataTransfer.setDragImage ) e.dataTransfer.setDragImage( ghost( ids.length ), 12, 12 );
		document.body.classList.add( 'po-lib-dragging' );
	} );
	document.addEventListener( 'dragend', function () {
		drag = null;
		document.body.classList.remove( 'po-lib-dragging' );
		Array.prototype.forEach.call( document.querySelectorAll( '.po-lib .is-drop' ), function ( n ) {
			n.classList.remove( 'is-drop' );
		} );
	} );

	// While one of our own drags is in progress, WordPress must not see it:
	// its uploader would cover the screen with "Drop files to upload".
	[ 'dragenter', 'dragover', 'dragleave', 'drop' ].forEach( function ( type ) {
		window.addEventListener( type, function ( e ) {
			if ( drag && ! ( e.target && e.target.closest && e.target.closest( '.po-lib' ) ) ) {
				e.stopPropagation();
			}
		}, true );
	} );

	// Rows and thumbnails are created after load (and re-rendered by the grid).
	function makeDraggable() {
		var sel = isGrid ? '.attachments li.attachment[data-id]:not([draggable])' : '#the-list > tr[id^="post-"]:not([draggable])';
		Array.prototype.forEach.call( document.querySelectorAll( sel ), function ( n ) {
			n.setAttribute( 'draggable', 'true' );
		} );
	}
	new MutationObserver( makeDraggable ).observe( body, { childList: true, subtree: true } );

	function dropTarget( node, folder ) {
		// folder: number (ID), 0 for "Unassigned" / top level.
		node.addEventListener( 'dragover', function ( e ) {
			if ( ! drag ) return;
			if ( drag.type === 'folder' && ( drag.id === folder || ( folder && isInside( folder, drag.id ) ) ) ) return;
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			node.classList.add( 'is-drop' );
		} );
		node.addEventListener( 'dragleave', function () {
			node.classList.remove( 'is-drop' );
		} );
		node.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			node.classList.remove( 'is-drop' );
			if ( ! drag ) return;
			if ( drag.type === 'items' ) assign( drag.ids, folder );
			else moveFolder( drag.id, folder );
			drag = null;
		} );
	}

	// ── Rendering ──────────────────────────────────────────────────────────

	var root = el( 'aside', { className: 'po-lib', id: 'po-library', 'aria-label': __( 'Folders', 'prymeoperator' ) } );
	var panel = el( 'div', { className: 'po-lib__panel' } );
	root.appendChild( panel );
	[ 'dragenter', 'dragover', 'dragleave', 'drop' ].forEach( function ( type ) {
		root.addEventListener( type, function ( e ) {
			if ( drag ) e.stopPropagation();
		} );
	} );
	body.insertBefore( root, body.firstChild );
	body.classList.add( 'po-lib-host' );

	function editor( value, depth ) {
		var input = el( 'input', { type: 'text', className: 'po-lib__input', value: value, maxlength: 190, 'aria-label': __( 'Folder name', 'prymeoperator' ), placeholder: __( 'Folder name', 'prymeoperator' ) } );
		var done = false;
		function finish( keep ) {
			if ( done ) return;
			done = true;
			if ( keep ) commitEdit( input.value );
			else {
				state.editing = null;
				render();
			}
		}
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				finish( true );
			}
			if ( e.key === 'Escape' ) finish( false );
		} );
		input.addEventListener( 'blur', function () {
			finish( true );
		} );
		setTimeout( function () {
			input.focus();
			input.select();
		}, 0 );
		return el( 'li', { className: 'po-lib__row po-lib__row--edit', style: '--depth:' + depth }, el( 'span', { className: 'dashicons dashicons-category' } ), input );
	}

	function matches( folder ) {
		if ( ! state.filter ) return true;
		var q = state.filter.toLowerCase();
		if ( folder.name.toLowerCase().indexOf( q ) !== -1 ) return true;
		return state.folders.some( function ( f ) {
			return f.name.toLowerCase().indexOf( q ) !== -1 && isInside( f.id, folder.id );
		} );
	}

	function branch( parent, depth, list ) {
		childrenOf( parent ).forEach( function ( f ) {
			if ( ! matches( f ) ) return;
			var kids = childrenOf( f.id );
			var open = !! state.open[ f.id ] || ( state.filter && kids.length );
			if ( state.editing && state.editing.id === f.id ) {
				list.appendChild( editor( f.name, depth ) );
			} else {
				list.appendChild( row( f, depth, kids.length > 0, open ) );
			}
			if ( open ) branch( f.id, depth + 1, list );
			if ( state.editing && state.editing.id === 0 && state.editing.parent === f.id && ! open ) {
				list.appendChild( editor( '', depth + 1 ) );
			}
		} );
		if ( state.editing && state.editing.id === 0 && state.editing.parent === parent && ( parent === 0 || state.open[ parent ] ) ) {
			list.appendChild( editor( '', depth ) );
		}
	}

	function iconButton( icon, label, onClick ) {
		return el( 'button', { type: 'button', className: 'po-lib__act', title: label, 'aria-label': label, onclick: function ( e ) {
			e.stopPropagation();
			onClick();
		} }, el( 'span', { className: 'dashicons dashicons-' + icon } ) );
	}

	function row( f, depth, hasKids, open ) {
		var active = state.current === String( f.id );
		var li = el( 'li', { className: 'po-lib__row' + ( active ? ' is-active' : '' ), style: '--depth:' + depth, draggable: CFG.organise ? 'true' : false },
			hasKids ? el( 'button', { type: 'button', className: 'po-lib__caret' + ( open ? ' is-open' : '' ), 'aria-expanded': open ? 'true' : 'false', 'aria-label': open ? __( 'Collapse', 'prymeoperator' ) : __( 'Expand', 'prymeoperator' ), onclick: function ( e ) {
				e.stopPropagation();
				state.open[ f.id ] = ! open;
				if ( open ) delete state.open[ f.id ];
				save( 'open', state.open );
				render();
			} }, el( 'span', { className: 'dashicons dashicons-arrow-right-alt2' } ) ) : el( 'span', { className: 'po-lib__caret-space' } ),
			el( 'button', { type: 'button', className: 'po-lib__name', 'aria-current': active ? 'true' : false, onclick: function () {
				select( f.id );
			}, ondblclick: function () {
				if ( ! CFG.organise ) return;
				state.editing = { id: f.id };
				render();
			} }, el( 'span', { className: 'dashicons dashicons-' + ( active ? 'open-folder' : 'category' ) } ), el( 'span', { className: 'po-lib__label', text: f.name } ) ),
			el( 'span', { className: 'po-lib__count', text: String( f.count ) } ),
			CFG.organise && el( 'span', { className: 'po-lib__acts' },
				iconButton( 'plus-alt2', __( 'New subfolder', 'prymeoperator' ), function () {
					startCreate( f.id );
				} ),
				iconButton( 'edit', __( 'Rename', 'prymeoperator' ), function () {
					state.editing = { id: f.id };
					render();
				} ),
				iconButton( 'trash', __( 'Delete', 'prymeoperator' ), function () {
					remove( f );
				} )
			)
		);
		li.addEventListener( 'dragstart', function ( e ) {
			e.stopPropagation();
			drag = { type: 'folder', id: f.id };
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', f.name );
		} );
		dropTarget( li, f.id );
		return li;
	}

	function special( value, icon, label, count, drop ) {
		var active = state.current === value;
		var li = el( 'li', { className: 'po-lib__row po-lib__row--special' + ( active ? ' is-active' : '' ), style: '--depth:0' },
			el( 'span', { className: 'po-lib__caret-space' } ),
			el( 'button', { type: 'button', className: 'po-lib__name', 'aria-current': active ? 'true' : false, onclick: function () {
				select( value );
			} }, el( 'span', { className: 'dashicons dashicons-' + icon } ), el( 'span', { className: 'po-lib__label', text: label } ) ),
			el( 'span', { className: 'po-lib__count', text: String( count ) } )
		);
		if ( drop ) dropTarget( li, 0 );
		return li;
	}

	function render() {
		var focusSearch = document.activeElement && document.activeElement.classList.contains( 'po-lib__search' );
		panel.innerHTML = '';
		root.classList.toggle( 'is-collapsed', !! state.collapsed );
		body.classList.toggle( 'po-lib-collapsed', !! state.collapsed );

		var toggle = el( 'button', { type: 'button', className: 'po-lib__toggle', 'aria-expanded': state.collapsed ? 'false' : 'true', title: state.collapsed ? __( 'Show folders', 'prymeoperator' ) : __( 'Hide folders', 'prymeoperator' ), 'aria-label': state.collapsed ? __( 'Show folders', 'prymeoperator' ) : __( 'Hide folders', 'prymeoperator' ), onclick: function () {
			state.collapsed = ! state.collapsed;
			save( 'collapsed', state.collapsed );
			render();
		} }, el( 'span', { className: 'dashicons dashicons-' + ( state.collapsed ? 'arrow-right-alt2' : 'arrow-left-alt2' ) } ) );

		if ( state.collapsed ) {
			panel.appendChild( toggle );
			return;
		}

		panel.appendChild( el( 'div', { className: 'po-lib__head' },
			el( 'h2', { className: 'po-lib__title' }, el( 'span', { className: 'dashicons dashicons-portfolio' } ), __( 'Folders', 'prymeoperator' ) ),
			CFG.organise && el( 'button', { type: 'button', className: 'button button-small po-lib__new', onclick: function () {
				startCreate( 0 );
			} }, el( 'span', { className: 'dashicons dashicons-plus-alt2' } ), __( 'New', 'prymeoperator' ) ),
			toggle
		) );

		var search = el( 'input', { type: 'search', className: 'po-lib__search', placeholder: __( 'Search folder…', 'prymeoperator' ), 'aria-label': __( 'Search folder…', 'prymeoperator' ), value: state.filter } );
		search.addEventListener( 'input', function () {
			state.filter = search.value.trim();
			render();
		} );
		panel.appendChild( search );
		if ( focusSearch ) {
			search.focus();
			search.setSelectionRange( search.value.length, search.value.length );
		}

		var list = el( 'ul', { className: 'po-lib__tree', role: 'tree' } );
		var all = special( 'all', 'admin-media', CFG.allLabel, state.total, false );
		// Dropping a folder on "All" moves it to the top level.
		all.addEventListener( 'dragover', function ( e ) {
			if ( drag && drag.type === 'folder' ) {
				e.preventDefault();
				all.classList.add( 'is-drop' );
			}
		} );
		all.addEventListener( 'dragleave', function () {
			all.classList.remove( 'is-drop' );
		} );
		all.addEventListener( 'drop', function ( e ) {
			all.classList.remove( 'is-drop' );
			if ( drag && drag.type === 'folder' ) {
				e.preventDefault();
				moveFolder( drag.id, 0 );
				drag = null;
			}
		} );
		list.appendChild( all );
		list.appendChild( special( 'none', 'marker', __( 'Unassigned', 'prymeoperator' ), state.unassigned, true ) );
		list.appendChild( el( 'li', { className: 'po-lib__sep', role: 'presentation' } ) );
		branch( 0, 0, list );
		panel.appendChild( list );

		if ( ! state.folders.length && ! state.editing ) {
			panel.appendChild( el( 'p', { className: 'po-lib__empty', text: CFG.organise ? __( 'Create a folder, then drag items onto it to organise them.', 'prymeoperator' ) : __( 'No folders yet. An editor or administrator can create them.', 'prymeoperator' ) } ) );
		} else if ( state.filter && ! state.folders.some( matches ) ) {
			panel.appendChild( el( 'p', { className: 'po-lib__empty', text: __( 'No folder matches your search.', 'prymeoperator' ) } ) );
		}

		if ( CFG.mediaflow ) {
			panel.appendChild( el( 'p', { className: 'po-lib__foot' }, el( 'button', { type: 'button', className: 'button-link', onclick: importMediaflow }, __( 'Import folders from MediaFlow Pro', 'prymeoperator' ) ) ) );
		}
		panel.appendChild( el( 'p', { className: 'po-lib__hint', text: isGrid ? __( 'Drag thumbnails onto a folder. Use "Bulk select" to move several at once.', 'prymeoperator' ) : __( 'Drag rows onto a folder. Tick several to move them together.', 'prymeoperator' ) } ) );
	}

	// Keep the folder when searching or filtering the list table.
	if ( ! isGrid && /^(\d+|none)$/.test( state.current ) ) {
		var form = document.getElementById( 'posts-filter' );
		if ( form && ! form.querySelector( 'input[name="' + CFG.param + '"]' ) ) {
			form.appendChild( el( 'input', { type: 'hidden', name: CFG.param, value: state.current } ) );
		}
	}

	render();
	refresh();
	makeDraggable();

	if ( isGrid ) {
		// The grid frame is built after this script runs.
		var tries = 0;
		( function waitForGrid() {
			if ( gridLibrary() ) {
				if ( state.current !== 'all' ) gridFilter();
				else uploadTarget();
				// WordPress only shows new uploads live in unfiltered views, so
				// reload the open folder once the upload queue is done.
				if ( wp.Uploader && wp.Uploader.queue ) {
					wp.Uploader.queue.on( 'reset', function () {
						afterItemsChanged();
						refresh();
					} );
				}
				gridLibrary().on( 'destroy', debounce( refresh, 600 ) );
				return;
			}
			if ( tries++ < 100 ) setTimeout( waitForGrid, 100 );
		} )();
	}

	function debounce( fn, ms ) {
		var t;
		return function () {
			clearTimeout( t );
			t = setTimeout( fn, ms );
		};
	}
}() );
