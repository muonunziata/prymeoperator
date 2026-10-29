/* global wp */
( function () {
	const { createElement: h, useState, useEffect, createRoot, render } = wp.element;
	const { TabPanel, Spinner, Notice, Modal, Button } = wp.components;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;

	const api = ( path, params ) =>
		apiFetch( { path: wp.url.addQueryArgs( path, params ) } );

	const formatSize = ( bytes ) => {
		if ( ! bytes ) return '—';
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return bytes.toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
	};

	const formatDate = ( ts ) => ( ts ? new Date( ts * 1000 ).toLocaleString() : '' );

	function Breadcrumb( { path, onNavigate } ) {
		const parts = path ? path.split( '/' ) : [];
		const crumbs = [ h( 'a', { key: 'root', href: '#', onClick: ( e ) => { e.preventDefault(); onNavigate( '' ); } }, __( 'Root', 'prymeoperator' ) ) ];
		parts.forEach( ( part, i ) => {
			const target = parts.slice( 0, i + 1 ).join( '/' );
			crumbs.push( h( 'span', { key: 's' + i, className: 'po-sep' }, '/' ) );
			crumbs.push( h( 'a', { key: target, href: '#', onClick: ( e ) => { e.preventDefault(); onNavigate( target ); } }, part ) );
		} );
		return h( 'nav', { className: 'po-breadcrumb' }, crumbs );
	}

	function Preview( { file, onClose } ) {
		let body;
		if ( file.content !== null ) {
			body = h( 'pre', { className: 'po-preview' }, file.content );
		} else if ( file.reason === 'too_large' ) {
			body = h( 'p', null, __( 'This file is too large to preview.', 'prymeoperator' ) );
		} else {
			body = h( 'p', null, __( 'Binary file: no text preview.', 'prymeoperator' ) );
		}
		return h( Modal, { title: file.path, onRequestClose: onClose, size: 'large' }, body );
	}

	function ServerBrowser() {
		const [ path, setPath ] = useState( '' );
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( null );
		const [ file, setFile ] = useState( null );

		useEffect( () => {
			setData( null );
			setError( null );
			api( '/prymeoperator/v1/server/list', { path } ).then( setData, ( e ) => setError( e.message ) );
		}, [ path ] );

		const open = ( entry ) => {
			if ( entry.type === 'dir' ) {
				setPath( entry.path );
				return;
			}
			api( '/prymeoperator/v1/server/file', { path: entry.path } ).then( setFile, ( e ) => setError( e.message ) );
		};

		const up = path ? path.split( '/' ).slice( 0, -1 ).join( '/' ) : null;

		return h( 'div', { className: 'po-server' },
			h( 'div', { className: 'po-toolbar' },
				h( Button, { variant: 'secondary', disabled: up === null, onClick: () => setPath( up ) }, __( 'Up', 'prymeoperator' ) ),
				h( Breadcrumb, { path, onNavigate: setPath } )
			),
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			! data && ! error && h( Spinner ),
			data && h( 'table', { className: 'widefat striped po-table' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', null, __( 'Name', 'prymeoperator' ) ),
					h( 'th', null, __( 'Size', 'prymeoperator' ) ),
					h( 'th', null, __( 'Modified', 'prymeoperator' ) ),
					h( 'th', null, __( 'Permissions', 'prymeoperator' ) )
				) ),
				h( 'tbody', null,
					data.entries.length === 0 && h( 'tr', null, h( 'td', { colSpan: 4 }, __( 'This folder is empty.', 'prymeoperator' ) ) ),
					data.entries.map( ( entry ) => h( 'tr', { key: entry.path },
						h( 'td', null,
							h( 'span', { className: 'dashicons ' + ( entry.type === 'dir' ? 'dashicons-category' : 'dashicons-media-default' ) } ),
							' ',
							h( 'a', { href: '#', onClick: ( e ) => { e.preventDefault(); open( entry ); } }, entry.name )
						),
						h( 'td', null, entry.type === 'dir' ? '—' : formatSize( entry.size ) ),
						h( 'td', null, formatDate( entry.modified ) ),
						h( 'td', null, h( 'code', null, entry.perms ) )
					) )
				)
			),
			file && h( Preview, { file, onClose: () => setFile( null ) } )
		);
	}

	function AuditLog() {
		const [ items, setItems ] = useState( null );
		const [ error, setError ] = useState( null );

		useEffect( () => {
			apiFetch( { path: '/prymeoperator/v1/audit?per_page=100' } ).then( setItems, ( e ) => setError( e.message ) );
		}, [] );

		if ( error ) return h( Notice, { status: 'error', isDismissible: false }, error );
		if ( ! items ) return h( Spinner );

		return h( 'table', { className: 'widefat striped po-table' },
			h( 'thead', null, h( 'tr', null,
				h( 'th', null, __( 'When', 'prymeoperator' ) ),
				h( 'th', null, __( 'User', 'prymeoperator' ) ),
				h( 'th', null, __( 'Action', 'prymeoperator' ) ),
				h( 'th', null, __( 'Target', 'prymeoperator' ) ),
				h( 'th', null, 'IP' )
			) ),
			h( 'tbody', null,
				items.length === 0 && h( 'tr', null, h( 'td', { colSpan: 5 }, __( 'No activity yet.', 'prymeoperator' ) ) ),
				items.map( ( row ) => h( 'tr', { key: row.id },
					h( 'td', null, new Date( row.created_at ).toLocaleString() ),
					h( 'td', null, row.user ),
					h( 'td', null, h( 'code', null, row.action ) ),
					h( 'td', null, row.target ),
					h( 'td', null, row.ip )
				) )
			)
		);
	}

	function App() {
		return h( 'div', { className: 'po-app' },
			h( 'h1', null, 'PrymeOperator' ),
			h( TabPanel, {
				tabs: [
					{ name: 'server', title: __( 'Server', 'prymeoperator' ) },
					{ name: 'audit', title: __( 'Activity', 'prymeoperator' ) },
				],
			}, ( tab ) => ( tab.name === 'server' ? h( ServerBrowser ) : h( AuditLog ) ) )
		);
	}

	const mount = document.getElementById( 'prymeoperator-app' );
	if ( mount ) {
		if ( createRoot ) {
			createRoot( mount ).render( h( App ) );
		} else {
			render( h( App ), mount );
		}
	}
}() );
