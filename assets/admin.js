/* global wp, PrymeOperator */
( function () {
	'use strict';

	const { createElement: h, Fragment, useState, useEffect, useRef, useCallback, useMemo, createRoot, render } = wp.element;
	const { Modal, Button, Spinner, TextControl, ToggleControl, CheckboxControl, SelectControl, TextareaControl } = wp.components;
	const { __, sprintf, _n } = wp.i18n;
	const { addQueryArgs } = wp.url;
	const apiFetch = wp.apiFetch;
	const CFG = window.PrymeOperator || {};

	// ── API ────────────────────────────────────────────────────────────────

	const api = {
		get: ( path, params ) => apiFetch( { path: addQueryArgs( '/prymeoperator/v1/' + path, params || {} ) } ),
		post: ( path, data ) => apiFetch( { path: '/prymeoperator/v1/' + path, method: 'POST', data } ),
		rawUrl: ( path, download ) => addQueryArgs( CFG.rest + 'server/raw', { path, download: download ? 1 : 0, _wpnonce: CFG.nonce } ),
	};

	// ── File helpers ───────────────────────────────────────────────────────

	const IMAGE = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico' ];
	const VIDEO = [ 'mp4', 'webm', 'ogv', 'mov', 'm4v' ];
	const AUDIO = [ 'mp3', 'wav', 'ogg', 'oga', 'm4a', 'flac' ];
	const ARCHIVE = [ 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2' ];
	const MODES = {
		php: 'application/x-httpd-php', phtml: 'application/x-httpd-php', inc: 'application/x-httpd-php',
		js: 'text/javascript', mjs: 'text/javascript', jsx: 'text/jsx', ts: 'text/typescript', tsx: 'text/typescript-jsx', json: 'application/json',
		css: 'text/css', scss: 'text/x-scss', less: 'text/x-less', sass: 'text/x-sass',
		html: 'text/html', htm: 'text/html', twig: 'text/html', tpl: 'text/html', vue: 'text/html', svg: 'application/xml', xml: 'application/xml',
		md: 'text/x-gfm', markdown: 'text/x-gfm', sql: 'text/x-sql', sh: 'text/x-sh', yml: 'text/x-yaml', yaml: 'text/x-yaml',
		ini: 'text/x-ini', htaccess: 'text/plain', conf: 'text/x-nginx-conf', txt: 'text/plain', log: 'text/plain', csv: 'text/plain',
		env: 'text/plain', po: 'text/plain', pot: 'text/plain', diff: 'text/x-diff',
	};

	const extOf = ( e ) => ( e.ext || ( e.name.startsWith( '.' ) ? e.name.slice( 1 ) : '' ) ).toLowerCase();
	const isEditable = ( e ) => e.type === 'file' && Object.prototype.hasOwnProperty.call( MODES, extOf( e ) );
	const isImage = ( e ) => IMAGE.includes( extOf( e ) );
	const isPreviewable = ( e ) => isImage( e ) || VIDEO.includes( extOf( e ) ) || AUDIO.includes( extOf( e ) ) || extOf( e ) === 'pdf';

	function iconFor( e ) {
		if ( e.type === 'dir' ) return 'dashicons-category';
		const x = extOf( e );
		if ( IMAGE.includes( x ) || x === 'svg' ) return 'dashicons-format-image';
		if ( VIDEO.includes( x ) ) return 'dashicons-format-video';
		if ( AUDIO.includes( x ) ) return 'dashicons-format-audio';
		if ( ARCHIVE.includes( x ) ) return 'dashicons-media-archive';
		if ( x === 'pdf' ) return 'dashicons-pdf';
		if ( [ 'xls', 'xlsx', 'ods', 'csv' ].includes( x ) ) return 'dashicons-media-spreadsheet';
		if ( [ 'doc', 'docx', 'odt', 'rtf' ].includes( x ) ) return 'dashicons-media-document';
		if ( [ 'php', 'js', 'css', 'scss', 'html', 'htm', 'json', 'xml', 'sql', 'sh', 'ts', 'jsx', 'tsx', 'vue', 'twig' ].includes( x ) ) return 'dashicons-editor-code';
		if ( [ 'txt', 'md', 'log', 'ini', 'yml', 'yaml', 'htaccess', 'env' ].includes( x ) ) return 'dashicons-media-text';
		return 'dashicons-media-default';
	}

	function formatSize( bytes ) {
		if ( ! bytes ) return '0 B';
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i ? bytes.toFixed( 1 ) : bytes ) + ' ' + units[ i ];
	}

	const formatDate = ( ts ) => ( ts ? new Date( ts * 1000 ).toLocaleString() : '' );
	const parentOf = ( p ) => ( p.includes( '/' ) ? p.slice( 0, p.lastIndexOf( '/' ) ) : '' );
	const join = ( dir, name ) => ( dir ? dir + '/' + name : name );
	const errMsg = ( e ) => ( e && e.message ) || __( 'Something went wrong.', 'prymeoperator' );

	// ── Toasts ─────────────────────────────────────────────────────────────

	let pushToast = () => {};
	function Toasts() {
		const [ items, setItems ] = useState( [] );
		useEffect( () => {
			pushToast = ( message, status, action ) => {
				const id = Math.random().toString( 36 ).slice( 2 );
				// Keep the stack short: only the three newest messages stay on screen.
				setItems( ( list ) => [ ...list, { id, message, status: status || 'success', action } ].slice( -3 ) );
				setTimeout( () => setItems( ( list ) => list.filter( ( t ) => t.id !== id ) ), status === 'error' ? 8000 : 5000 );
			};
		}, [] );
		return h( 'div', { className: 'po-toasts', role: 'status', 'aria-live': 'polite' },
			items.map( ( t ) => h( 'div', { key: t.id, className: 'po-toast is-' + t.status },
				h( 'span', null, t.message ),
				t.action && h( 'button', { type: 'button', className: 'po-toast__action', onClick: () => { t.action.onClick(); setItems( ( l ) => l.filter( ( x ) => x.id !== t.id ) ); } }, t.action.label )
			) )
		);
	}
	const toast = ( m, s, a ) => pushToast( m, s, a );

	// ── Generic dialogs ────────────────────────────────────────────────────

	function PromptDialog( { title, label, initial, confirm, onSubmit, onClose } ) {
		const [ value, setValue ] = useState( initial || '' );
		const ref = useRef();
		useEffect( () => {
			const input = ref.current && ref.current.querySelector( 'input' );
			if ( input ) {
				input.focus();
				const dot = value.lastIndexOf( '.' );
				input.setSelectionRange( 0, dot > 0 ? dot : value.length );
			}
		}, [] ); // eslint-disable-line react-hooks/exhaustive-deps
		return h( Modal, { title, onRequestClose: onClose, className: 'po-modal po-modal--small' },
			h( 'form', { ref, onSubmit: ( e ) => { e.preventDefault(); if ( value.trim() ) onSubmit( value.trim() ); } },
				h( TextControl, { label, value, onChange: setValue, __nextHasNoMarginBottom: true } ),
				h( 'div', { className: 'po-modal__actions' },
					h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'prymeoperator' ) ),
					h( Button, { variant: 'primary', type: 'submit' }, confirm || __( 'OK', 'prymeoperator' ) )
				)
			)
		);
	}

	function ConfirmDialog( { title, message, confirm, destructive, onConfirm, onClose } ) {
		return h( Modal, { title, onRequestClose: onClose, className: 'po-modal po-modal--small' },
			h( 'p', null, message ),
			h( 'div', { className: 'po-modal__actions' },
				h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'prymeoperator' ) ),
				h( Button, { variant: 'primary', isDestructive: destructive, onClick: onConfirm }, confirm )
			)
		);
	}

	function ChmodDialog( { entry, onSubmit, onClose } ) {
		const [ mode, setMode ] = useState( entry.perms.slice( -3 ) );
		const digits = mode.split( '' ).map( Number );
		const toggle = ( who, bit ) => {
			const next = digits.slice();
			next[ who ] ^= bit;
			setMode( next.join( '' ) );
		};
		const rows = [ __( 'Owner', 'prymeoperator' ), __( 'Group', 'prymeoperator' ), __( 'Public', 'prymeoperator' ) ];
		const bits = [ [ 4, __( 'Read', 'prymeoperator' ) ], [ 2, __( 'Write', 'prymeoperator' ) ], [ 1, __( 'Execute', 'prymeoperator' ) ] ];
		return h( Modal, { title: sprintf( __( 'Permissions: %s', 'prymeoperator' ), entry.name ), onRequestClose: onClose, className: 'po-modal po-modal--small' },
			h( 'table', { className: 'po-chmod' },
				h( 'thead', null, h( 'tr', null, h( 'th' ), bits.map( ( [ , l ] ) => h( 'th', { key: l }, l ) ) ) ),
				h( 'tbody', null, rows.map( ( row, who ) => h( 'tr', { key: row },
					h( 'th', null, row ),
					bits.map( ( [ bit ] ) => h( 'td', { key: bit }, h( 'input', { type: 'checkbox', checked: !! ( digits[ who ] & bit ), onChange: () => toggle( who, bit ) } ) ) )
				) ) )
			),
			h( TextControl, { label: __( 'Numeric value', 'prymeoperator' ), value: mode, onChange: ( v ) => /^[0-7]{0,3}$/.test( v ) && setMode( v ), __nextHasNoMarginBottom: true } ),
			h( 'div', { className: 'po-modal__actions' },
				h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'prymeoperator' ) ),
				h( Button, { variant: 'primary', disabled: mode.length !== 3, onClick: () => onSubmit( '0' + mode ) }, __( 'Apply', 'prymeoperator' ) )
			)
		);
	}

	function PropertiesDialog( { entry, onClose } ) {
		const [ size, setSize ] = useState( entry.type === 'file' ? entry.size : null );
		useEffect( () => {
			if ( entry.type === 'dir' ) api.get( 'server/size', { path: entry.path } ).then( ( r ) => setSize( r.size ), () => setSize( -1 ) );
		}, [ entry.path ] ); // eslint-disable-line react-hooks/exhaustive-deps
		const rows = [
			[ __( 'Name', 'prymeoperator' ), entry.name ],
			[ __( 'Location', 'prymeoperator' ), '/' + parentOf( entry.path ) ],
			[ __( 'Type', 'prymeoperator' ), entry.type === 'dir' ? __( 'Folder', 'prymeoperator' ) : ( extOf( entry ).toUpperCase() || __( 'File', 'prymeoperator' ) ) ],
			[ __( 'Size', 'prymeoperator' ), size === null ? h( Spinner ) : size < 0 ? '—' : formatSize( size ) + ' (' + size.toLocaleString() + ' bytes)' ],
			[ __( 'Modified', 'prymeoperator' ), formatDate( entry.modified ) ],
			[ __( 'Permissions', 'prymeoperator' ), h( 'code', null, entry.perms ) ],
			[ __( 'Writable', 'prymeoperator' ), entry.writable ? __( 'Yes', 'prymeoperator' ) : __( 'No', 'prymeoperator' ) ],
		];
		return h( Modal, { title: __( 'Properties', 'prymeoperator' ), onRequestClose: onClose, className: 'po-modal po-modal--small' },
			h( 'dl', { className: 'po-props' }, rows.map( ( [ k, v ] ) => h( Fragment, { key: k }, h( 'dt', null, k ), h( 'dd', null, v ) ) ) )
		);
	}

	// ── Preview & editor ───────────────────────────────────────────────────

	function PreviewDialog( { entry, entries, onNavigate, onClose, onEdit } ) {
		const x = extOf( entry );
		const url = api.rawUrl( entry.path );
		const media = entries.filter( isPreviewable );
		const idx = media.findIndex( ( e ) => e.path === entry.path );
		useEffect( () => {
			const key = ( ev ) => {
				if ( ev.key === 'ArrowRight' && idx < media.length - 1 ) onNavigate( media[ idx + 1 ] );
				if ( ev.key === 'ArrowLeft' && idx > 0 ) onNavigate( media[ idx - 1 ] );
			};
			window.addEventListener( 'keydown', key );
			return () => window.removeEventListener( 'keydown', key );
		} );
		let body;
		if ( IMAGE.includes( x ) ) body = h( 'img', { src: url, alt: entry.name } );
		else if ( VIDEO.includes( x ) ) body = h( 'video', { src: url, controls: true, autoPlay: true } );
		else if ( AUDIO.includes( x ) ) body = h( 'audio', { src: url, controls: true, autoPlay: true } );
		else if ( x === 'pdf' ) body = h( 'iframe', { src: url, title: entry.name } );
		return h( Modal, { title: entry.name, onRequestClose: onClose, className: 'po-modal po-modal--preview', isFullScreen: true },
			h( 'div', { className: 'po-preview' },
				idx > 0 && h( Button, { className: 'po-preview__nav is-prev', icon: 'arrow-left-alt2', label: __( 'Previous', 'prymeoperator' ), onClick: () => onNavigate( media[ idx - 1 ] ) } ),
				body,
				idx < media.length - 1 && h( Button, { className: 'po-preview__nav is-next', icon: 'arrow-right-alt2', label: __( 'Next', 'prymeoperator' ), onClick: () => onNavigate( media[ idx + 1 ] ) } )
			),
			h( 'div', { className: 'po-preview__bar' },
				h( 'span', null, formatSize( entry.size ) + ' · ' + formatDate( entry.modified ) ),
				h( 'span', null,
					onEdit && isEditable( entry ) && h( Button, { variant: 'secondary', onClick: onEdit }, __( 'Edit', 'prymeoperator' ) ),
					' ',
					h( Button, { variant: 'primary', href: api.rawUrl( entry.path, true ) }, __( 'Download', 'prymeoperator' ) )
				)
			)
		);
	}

	function EditorDialog( { entry, canEdit, onClose, onSaved } ) {
		const [ file, setFile ] = useState( null );
		const [ error, setError ] = useState( null );
		const [ dirty, setDirty ] = useState( false );
		const [ saving, setSaving ] = useState( false );
		const [ versions, setVersions ] = useState( [] );
		const host = useRef();
		const cm = useRef();

		const loadVersions = () => api.get( 'server/versions', { path: entry.path } ).then( setVersions, () => {} );

		useEffect( () => {
			api.get( 'server/file', { path: entry.path } ).then( setFile, ( e ) => setError( errMsg( e ) ) );
			loadVersions();
		}, [ entry.path ] ); // eslint-disable-line react-hooks/exhaustive-deps

		useEffect( () => {
			if ( ! file || file.content === null || ! host.current ) return;
			const textarea = host.current;
			textarea.value = file.content;
			if ( wp.codeEditor && CFG.editor ) {
				const settings = Object.assign( {}, CFG.editor );
				settings.codemirror = Object.assign( {}, settings.codemirror, {
					mode: MODES[ extOf( entry ) ] || 'text/plain',
					readOnly: ! canEdit,
					lineNumbers: true,
					lineWrapping: false,
				} );
				const instance = wp.codeEditor.initialize( textarea, settings ).codemirror;
				instance.on( 'change', () => setDirty( true ) );
				instance.setSize( null, '100%' );
				cm.current = instance;
				setTimeout( () => instance.refresh(), 50 );
			}
		}, [ file ] ); // eslint-disable-line react-hooks/exhaustive-deps

		const value = () => ( cm.current ? cm.current.getValue() : host.current.value );
		const saveRef = useRef();

		// Ctrl/Cmd+S anywhere in the editor window, not only inside CodeMirror.
		useEffect( () => {
			const onKey = ( ev ) => {
				if ( ( ev.ctrlKey || ev.metaKey ) && ev.key.toLowerCase() === 's' ) {
					ev.preventDefault();
					if ( saveRef.current ) saveRef.current();
				}
			};
			window.addEventListener( 'keydown', onKey, true );
			return () => window.removeEventListener( 'keydown', onKey, true );
		}, [] );

		function save() {
			if ( ! canEdit || saving ) return;
			setSaving( true );
			setError( null );
			api.post( 'server/write', { path: entry.path, content: value() } ).then( ( r ) => {
				setSaving( false );
				setDirty( false );
				loadVersions();
				toast( r.warning || sprintf( __( '%s saved.', 'prymeoperator' ), entry.name ), r.warning ? 'warning' : 'success' );
				onSaved();
			}, ( e ) => {
				setSaving( false );
				setError( errMsg( e ) );
			} );
		}

		saveRef.current = save;

		const restore = ( id ) => api.get( 'server/version', { path: entry.path, id } ).then( ( r ) => {
			if ( cm.current ) cm.current.setValue( r.content );
			else host.current.value = r.content;
			setDirty( true );
			toast( __( 'Version loaded. Save to keep it.', 'prymeoperator' ), 'info' );
		}, ( e ) => setError( errMsg( e ) ) );

		const close = () => {
			if ( dirty && ! window.confirm( __( 'Discard unsaved changes?', 'prymeoperator' ) ) ) return; // eslint-disable-line no-alert
			onClose();
		};

		let body;
		if ( ! file && ! error ) body = h( 'div', { className: 'po-center' }, h( Spinner ) );
		else if ( file && file.content === null ) body = h( 'p', { className: 'po-center' }, file.reason === 'too_large' ? __( 'This file is too large to edit here.', 'prymeoperator' ) : __( 'Binary file: it cannot be edited as text.', 'prymeoperator' ) );
		else if ( file ) body = h( 'textarea', { ref: host, className: 'po-editor__textarea', spellCheck: false, readOnly: ! canEdit } );

		return h( Modal, { title: entry.path, onRequestClose: close, className: 'po-modal po-modal--editor', isFullScreen: true, shouldCloseOnClickOutside: false },
			error && h( 'div', { className: 'po-banner is-error' }, error ),
			h( 'div', { className: 'po-editor' }, body ),
			h( 'div', { className: 'po-editor__bar' },
				h( 'span', { className: 'po-editor__status' },
					! canEdit ? __( 'Read only', 'prymeoperator' ) : dirty ? __( 'Unsaved changes', 'prymeoperator' ) : __( 'All changes saved', 'prymeoperator' ),
					' · ', ( MODES[ extOf( entry ) ] || 'text/plain' )
				),
				h( 'span', { className: 'po-editor__actions' },
					versions.length > 0 && h( SelectControl, {
						className: 'po-editor__versions',
						value: '',
						__nextHasNoMarginBottom: true,
						options: [ { value: '', label: sprintf( _n( '%d earlier version', '%d earlier versions', versions.length, 'prymeoperator' ), versions.length ) } ]
							.concat( versions.map( ( v ) => ( { value: v.id, label: formatDate( v.created_at ) + ' · ' + formatSize( v.size || 0 ) } ) ) ),
						onChange: ( id ) => id && restore( id ),
					} ),
					h( Button, { variant: 'tertiary', onClick: close }, __( 'Close', 'prymeoperator' ) ),
					canEdit && h( Button, { variant: 'primary', isBusy: saving, disabled: saving || ! file || file.content === null, onClick: save }, __( 'Save', 'prymeoperator' ) + ' (Ctrl+S)' )
				)
			)
		);
	}

	// ── Uploads ────────────────────────────────────────────────────────────

	let uploadsApi = { add: () => {} };

	function Uploads( { onDone } ) {
		const [ items, setItems ] = useState( [] );
		const queue = useRef( [] );
		const busy = useRef( false );

		const update = ( id, patch ) => setItems( ( list ) => list.map( ( it ) => ( it.id === id ? Object.assign( {}, it, patch ) : it ) ) );

		async function sendOne( job ) {
			const size = CFG.chunkSize || 1048576;
			const total = Math.max( 1, Math.ceil( job.file.size / size ) );
			const uploadId = Array.from( crypto.getRandomValues( new Uint8Array( 16 ) ) ).map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
			for ( let index = 0; index < total; index++ ) {
				const form = new FormData();
				form.append( 'chunk', job.file.slice( index * size, ( index + 1 ) * size ), job.file.name );
				form.append( 'path', job.dir );
				form.append( 'name', job.name );
				form.append( 'uploadId', uploadId );
				form.append( 'index', String( index ) );
				form.append( 'total', String( total ) );
				const res = await apiFetch( { path: '/prymeoperator/v1/server/upload', method: 'POST', body: form } );
				update( job.id, { progress: Math.round( ( ( index + 1 ) / total ) * 100 ) } );
				if ( res.done ) return res.path;
			}
			return null;
		}

		async function pump() {
			if ( busy.current ) return;
			busy.current = true;
			while ( queue.current.length ) {
				const job = queue.current.shift();
				update( job.id, { state: 'uploading' } );
				try {
					await sendOne( job );
					update( job.id, { state: 'done', progress: 100 } );
				} catch ( e ) {
					update( job.id, { state: 'error', error: errMsg( e ) } );
				}
				if ( ! queue.current.length ) onDone();
			}
			busy.current = false;
		}

		useEffect( () => {
			uploadsApi = {
				add( files, dir ) {
					const jobs = Array.from( files ).map( ( file ) => ( {
						id: Math.random().toString( 36 ).slice( 2 ),
						file,
						dir,
						name: file.name,
						progress: 0,
						state: 'queued',
					} ) );
					setItems( ( list ) => list.filter( ( i ) => i.state !== 'done' ).concat( jobs ) );
					queue.current.push( ...jobs );
					pump();
				},
			};
		} );

		if ( ! items.length ) return null;
		const active = items.filter( ( i ) => i.state === 'queued' || i.state === 'uploading' ).length;
		return h( 'div', { className: 'po-uploads' },
			h( 'div', { className: 'po-uploads__head' },
				h( 'strong', null, active ? sprintf( _n( 'Uploading %d file', 'Uploading %d files', active, 'prymeoperator' ), active ) : __( 'Uploads finished', 'prymeoperator' ) ),
				! active && h( Button, { icon: 'no-alt', label: __( 'Close', 'prymeoperator' ), size: 'small', onClick: () => setItems( [] ) } )
			),
			h( 'ul', null, items.map( ( it ) => h( 'li', { key: it.id, className: 'is-' + it.state },
				h( 'span', { className: 'po-uploads__name', title: it.name }, it.name ),
				it.state === 'error' ? h( 'span', { className: 'po-uploads__error' }, it.error ) : h( 'span', { className: 'po-progress' }, h( 'span', { style: { width: it.progress + '%' } } ) )
			) ) )
		);
	}

	// ── Context menu ───────────────────────────────────────────────────────

	function ContextMenu( { x, y, items, onClose } ) {
		const ref = useRef();
		const [ pos, setPos ] = useState( { left: x, top: y } );
		useEffect( () => {
			const el = ref.current;
			if ( el ) {
				const r = el.getBoundingClientRect();
				setPos( { left: Math.min( x, window.innerWidth - r.width - 8 ), top: Math.min( y, window.innerHeight - r.height - 8 ) } );
				const first = el.querySelector( 'button:not([disabled])' );
				if ( first ) first.focus();
			}
			const close = ( e ) => { if ( ! el || ! el.contains( e.target ) ) onClose(); };
			const esc = ( e ) => e.key === 'Escape' && onClose();
			document.addEventListener( 'mousedown', close );
			document.addEventListener( 'keydown', esc );
			window.addEventListener( 'blur', onClose );
			return () => {
				document.removeEventListener( 'mousedown', close );
				document.removeEventListener( 'keydown', esc );
				window.removeEventListener( 'blur', onClose );
			};
		}, [] ); // eslint-disable-line react-hooks/exhaustive-deps
		return h( 'div', { ref, className: 'po-context', style: pos, role: 'menu' },
			items.filter( Boolean ).map( ( it, i ) => it === '-'
				? h( 'hr', { key: 'sep' + i } )
				: h( 'button', { key: it.label, type: 'button', role: 'menuitem', disabled: it.disabled, className: it.danger ? 'is-danger' : '', onClick: () => { onClose(); it.onClick(); } },
					h( 'span', { className: 'dashicons dashicons-' + it.icon } ),
					h( 'span', { className: 'po-context__label' }, it.label ),
					it.shortcut && h( 'kbd', null, it.shortcut )
				) )
		);
	}

	// ── Folder tree ────────────────────────────────────────────────────────

	function TreeNode( { path, name, current, onOpen, depth, refreshKey } ) {
		const isAncestor = current === path || current.startsWith( path ? path + '/' : '' );
		const [ open, setOpen ] = useState( depth === 0 || isAncestor );
		const [ children, setChildren ] = useState( null );

		useEffect( () => { if ( isAncestor ) setOpen( true ); }, [ current ] ); // eslint-disable-line react-hooks/exhaustive-deps
		useEffect( () => {
			if ( ! open ) return;
			api.get( 'server/list', { path } ).then( ( r ) => setChildren( r.entries.filter( ( e ) => e.type === 'dir' ) ), () => setChildren( [] ) );
		}, [ open, path, refreshKey ] );

		return h( 'li', { role: 'treeitem', 'aria-expanded': open },
			h( 'div', { className: 'po-tree__row' + ( current === path ? ' is-current' : '' ), style: { paddingLeft: 6 + depth * 14 } },
				h( 'button', { type: 'button', className: 'po-tree__toggle', 'aria-label': open ? __( 'Collapse', 'prymeoperator' ) : __( 'Expand', 'prymeoperator' ), onClick: () => setOpen( ! open ) },
					h( 'span', { className: 'dashicons dashicons-arrow-' + ( open ? 'down' : 'right' ) + '-alt2' } ) ),
				h( 'button', { type: 'button', className: 'po-tree__name', onClick: () => onOpen( path ), title: name },
					h( 'span', { className: 'dashicons ' + ( depth === 0 ? 'dashicons-admin-home' : 'dashicons-category' ) } ), name )
			),
			open && children && children.length > 0 && h( 'ul', { role: 'group' }, children.map( ( c ) => h( TreeNode, { key: c.path, path: c.path, name: c.name, current, onOpen, depth: depth + 1, refreshKey } ) ) )
		);
	}

	// ── File browser ───────────────────────────────────────────────────────

	function Browser() {
		const [ path, setPath ] = useState( () => {
			try { return window.localStorage.getItem( 'po-path' ) || ''; } catch ( e ) { return ''; }
		} );
		const [ data, setData ] = useState( null );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( null );
		const [ selected, setSelected ] = useState( [] );
		const [ anchor, setAnchor ] = useState( null );
		const [ view, setView ] = useState( () => {
			try { return window.localStorage.getItem( 'po-view' ) || CFG.view || 'list'; } catch ( e ) { return CFG.view || 'list'; }
		} );
		const [ sort, setSort ] = useState( { key: 'name', dir: 1 } );
		const [ clipboard, setClipboard ] = useState( null );
		const [ dialog, setDialog ] = useState( null );
		const [ menu, setMenu ] = useState( null );
		const [ query, setQuery ] = useState( '' );
		const [ deep, setDeep ] = useState( false );
		const [ results, setResults ] = useState( null );
		const [ dropping, setDropping ] = useState( false );
		const [ history, setHistory ] = useState( [] );
		const [ treeKey, setTreeKey ] = useState( 0 );
		const fileInput = useRef();
		const paneRef = useRef();
		const searchRef = useRef();

		const perms = ( data && data.policy ) || {};

		const load = useCallback( ( p ) => {
			setLoading( true );
			setError( null );
			return api.get( 'server/list', { path: p } ).then( ( r ) => {
				setData( r );
				setLoading( false );
			}, ( e ) => {
				setLoading( false );
				if ( p !== '' && e && e.data && e.data.status === 404 ) {
					setPath( '' );
				} else {
					setError( errMsg( e ) );
				}
			} );
		}, [] );

		useEffect( () => {
			load( path );
			setSelected( [] );
			setResults( null );
			try { window.localStorage.setItem( 'po-path', path ); } catch ( e ) {}
		}, [ path, load ] );

		const refresh = () => { load( path ); setTreeKey( ( k ) => k + 1 ); };
		const navigate = ( p ) => { if ( p !== path ) { setHistory( ( hs ) => hs.concat( path ).slice( -50 ) ); setPath( p ); } };
		const back = () => { if ( history.length ) { setPath( history[ history.length - 1 ] ); setHistory( history.slice( 0, -1 ) ); } };
		const changeView = ( v ) => { setView( v ); try { window.localStorage.setItem( 'po-view', v ); } catch ( e ) {} };

		const entries = useMemo( () => {
			const list = results || ( data ? data.entries : [] );
			return list.slice().sort( ( a, b ) => {
				if ( a.type !== b.type ) return a.type === 'dir' ? -1 : 1;
				const k = sort.key;
				const va = k === 'name' ? a.name.toLowerCase() : k === 'ext' ? extOf( a ) : a[ k ] || 0;
				const vb = k === 'name' ? b.name.toLowerCase() : k === 'ext' ? extOf( b ) : b[ k ] || 0;
				return ( va < vb ? -1 : va > vb ? 1 : 0 ) * sort.dir;
			} );
		}, [ data, results, sort ] );

		const selection = entries.filter( ( e ) => selected.includes( e.path ) );
		const single = selection.length === 1 ? selection[ 0 ] : null;

		// ── Actions

		const run = ( promise, success, after ) => promise.then( ( r ) => {
			if ( success ) toast( typeof success === 'function' ? success( r ) : success );
			refresh();
			if ( after ) after( r );
			return r;
		}, ( e ) => { toast( errMsg( e ), 'error' ); } );

		const actions = {
			open( e ) {
				if ( e.type === 'dir' ) navigate( e.path );
				else if ( isPreviewable( e ) ) setDialog( { type: 'preview', entry: e } );
				else if ( isEditable( e ) ) setDialog( { type: 'edit', entry: e } );
				else if ( perms.download ) window.location.href = api.rawUrl( e.path, true );
			},
			newFolder() {
				setDialog( { type: 'prompt', title: __( 'New folder', 'prymeoperator' ), label: __( 'Folder name', 'prymeoperator' ), initial: __( 'New folder', 'prymeoperator' ), confirm: __( 'Create', 'prymeoperator' ),
					onSubmit: ( name ) => { setDialog( null ); run( api.post( 'server/mkdir', { path, name } ), __( 'Folder created.', 'prymeoperator' ), ( r ) => setSelected( [ r.path ] ) ); } } );
			},
			newFile() {
				setDialog( { type: 'prompt', title: __( 'New file', 'prymeoperator' ), label: __( 'File name', 'prymeoperator' ), initial: 'untitled.txt', confirm: __( 'Create', 'prymeoperator' ),
					onSubmit: ( name ) => { setDialog( null ); run( api.post( 'server/touch', { path, name } ), __( 'File created.', 'prymeoperator' ), ( r ) => setSelected( [ r.path ] ) ); } } );
			},
			rename( e ) {
				setDialog( { type: 'prompt', title: __( 'Rename', 'prymeoperator' ), label: __( 'New name', 'prymeoperator' ), initial: e.name, confirm: __( 'Rename', 'prymeoperator' ),
					onSubmit: ( name ) => { setDialog( null ); if ( name !== e.name ) run( api.post( 'server/rename', { path: e.path, name } ), __( 'Renamed.', 'prymeoperator' ), ( r ) => setSelected( [ r.path ] ) ); } } );
			},
			copy( items ) { setClipboard( { mode: 'copy', paths: items.map( ( e ) => e.path ) } ); toast( sprintf( _n( '%d item copied.', '%d items copied.', items.length, 'prymeoperator' ), items.length ), 'info' ); },
			cut( items ) { setClipboard( { mode: 'cut', paths: items.map( ( e ) => e.path ) } ); toast( sprintf( _n( '%d item cut.', '%d items cut.', items.length, 'prymeoperator' ), items.length ), 'info' ); },
			paste( dest ) {
				if ( ! clipboard ) return;
				const target = dest === undefined ? path : dest;
				const op = clipboard.mode === 'copy' ? 'server/copy' : 'server/move';
				run( api.post( op, { paths: clipboard.paths, dest: target } ), clipboard.mode === 'copy' ? __( 'Pasted.', 'prymeoperator' ) : __( 'Moved.', 'prymeoperator' ), ( r ) => {
					if ( clipboard.mode === 'cut' ) setClipboard( null );
					setSelected( r.paths );
				} );
			},
			move( paths, dest ) { run( api.post( 'server/move', { paths, dest } ), __( 'Moved.', 'prymeoperator' ) ); },
			duplicate( items ) { run( api.post( 'server/copy', { paths: items.map( ( e ) => e.path ), dest: path } ), __( 'Duplicated.', 'prymeoperator' ) ); },
			remove( items ) {
				setDialog( { type: 'confirm', title: __( 'Move to trash', 'prymeoperator' ), destructive: true, confirm: __( 'Move to trash', 'prymeoperator' ),
					message: items.length === 1 ? sprintf( __( '"%s" will be moved to the trash. You can restore it later.', 'prymeoperator' ), items[ 0 ].name ) : sprintf( __( '%d items will be moved to the trash. You can restore them later.', 'prymeoperator' ), items.length ),
					onConfirm: () => {
						setDialog( null );
						api.post( 'server/delete', { paths: items.map( ( e ) => e.path ) } ).then( ( r ) => {
							refresh();
							toast( sprintf( _n( '%d item moved to the trash.', '%d items moved to the trash.', r.trash.length, 'prymeoperator' ), r.trash.length ), 'success', {
								label: __( 'Undo', 'prymeoperator' ),
								onClick: () => Promise.all( r.trash.map( ( t ) => api.post( 'trash/restore', { id: t.id } ) ) ).then( () => { refresh(); toast( __( 'Restored.', 'prymeoperator' ) ); }, ( e ) => toast( errMsg( e ), 'error' ) ),
							} );
						}, ( e ) => toast( errMsg( e ), 'error' ) );
					} } );
			},
			download( items ) {
				if ( items.length === 1 ) {
					window.location.href = api.rawUrl( items[ 0 ].path, true );
				} else {
					run( api.post( 'server/compress', { paths: items.map( ( e ) => e.path ), dest: path, name: 'download' } ), null, ( r ) => { window.location.href = api.rawUrl( r.path, true ); } );
				}
			},
			compress( items ) {
				const initial = items.length === 1 ? items[ 0 ].name.replace( /\.[^.]+$/, '' ) : 'archive';
				setDialog( { type: 'prompt', title: __( 'Compress to ZIP', 'prymeoperator' ), label: __( 'Archive name', 'prymeoperator' ), initial: initial + '.zip', confirm: __( 'Compress', 'prymeoperator' ),
					onSubmit: ( name ) => { setDialog( null ); toast( __( 'Compressing…', 'prymeoperator' ), 'info' ); run( api.post( 'server/compress', { paths: items.map( ( e ) => e.path ), dest: path, name } ), __( 'Archive created.', 'prymeoperator' ), ( r ) => setSelected( [ r.path ] ) ); } } );
			},
			extract( e ) { toast( __( 'Extracting…', 'prymeoperator' ), 'info' ); run( api.post( 'server/extract', { path: e.path } ), __( 'Archive extracted.', 'prymeoperator' ), ( r ) => setSelected( [ r.path ] ) ); },
			chmod( e ) { setDialog( { type: 'chmod', entry: e, onSubmit: ( mode ) => { setDialog( null ); run( api.post( 'server/chmod', { path: e.path, mode } ), __( 'Permissions changed.', 'prymeoperator' ) ); } } ); },
			copyPath( e ) { if ( navigator.clipboard ) navigator.clipboard.writeText( '/' + e.path ).then( () => toast( __( 'Path copied.', 'prymeoperator' ), 'info' ) ); },
			upload() { if ( fileInput.current ) fileInput.current.click(); },
		};

		const search = ( ev ) => {
			if ( ev ) ev.preventDefault();
			if ( query.trim().length < 2 ) { setResults( null ); return; }
			setLoading( true );
			api.get( 'server/search', { path, q: query, content: deep } ).then( ( r ) => { setResults( r ); setLoading( false ); setSelected( [] ); }, ( e ) => { setLoading( false ); toast( errMsg( e ), 'error' ); } );
		};

		// ── Selection

		const clickEntry = ( ev, e, index ) => {
			if ( ev.shiftKey && anchor !== null ) {
				const [ a, b ] = [ Math.min( anchor, index ), Math.max( anchor, index ) ];
				setSelected( entries.slice( a, b + 1 ).map( ( x ) => x.path ) );
			} else if ( ev.metaKey || ev.ctrlKey ) {
				setSelected( selected.includes( e.path ) ? selected.filter( ( p ) => p !== e.path ) : selected.concat( e.path ) );
				setAnchor( index );
			} else {
				setSelected( [ e.path ] );
				setAnchor( index );
			}
		};

		// ── Keyboard shortcuts

		useEffect( () => {
			const onKey = ( ev ) => {
				if ( dialog || menu ) return;
				const tag = ( ev.target.tagName || '' ).toLowerCase();
				if ( tag === 'input' || tag === 'textarea' || tag === 'select' || ev.target.isContentEditable ) return;
				if ( ! paneRef.current || ! document.body.contains( paneRef.current ) ) return;
				const mod = ev.ctrlKey || ev.metaKey;
				const k = ev.key;
				if ( mod && k.toLowerCase() === 'a' ) { ev.preventDefault(); setSelected( entries.map( ( e ) => e.path ) ); }
				else if ( mod && k.toLowerCase() === 'c' && selection.length && perms.copy ) { ev.preventDefault(); actions.copy( selection ); }
				else if ( mod && k.toLowerCase() === 'x' && selection.length && perms.move ) { ev.preventDefault(); actions.cut( selection ); }
				else if ( mod && k.toLowerCase() === 'v' && clipboard ) { ev.preventDefault(); actions.paste(); }
				else if ( mod && k.toLowerCase() === 'f' ) { ev.preventDefault(); if ( searchRef.current ) searchRef.current.focus(); }
				else if ( ( k === 'Delete' || ( k === 'Backspace' && mod ) ) && selection.length && perms.delete ) { ev.preventDefault(); actions.remove( selection ); }
				else if ( k === 'F2' && single && perms.rename ) { ev.preventDefault(); actions.rename( single ); }
				else if ( k === 'Enter' && single ) { ev.preventDefault(); actions.open( single ); }
				else if ( k === 'Backspace' && ! mod && path ) { ev.preventDefault(); navigate( parentOf( path ) ); }
				else if ( k === 'Escape' ) { setSelected( [] ); }
				else if ( k === 'F5' ) { ev.preventDefault(); refresh(); }
				else if ( ( k === 'ArrowDown' || k === 'ArrowUp' ) && entries.length ) {
					ev.preventDefault();
					const cur = single ? entries.findIndex( ( e ) => e.path === single.path ) : -1;
					const next = Math.max( 0, Math.min( entries.length - 1, cur + ( k === 'ArrowDown' ? 1 : -1 ) ) );
					setSelected( [ entries[ next ].path ] );
					setAnchor( next );
				}
			};
			window.addEventListener( 'keydown', onKey );
			return () => window.removeEventListener( 'keydown', onKey );
		} );

		// ── Drag & drop

		const onDragStart = ( ev, e ) => {
			const paths = selected.includes( e.path ) ? selected : [ e.path ];
			if ( ! selected.includes( e.path ) ) setSelected( [ e.path ] );
			ev.dataTransfer.setData( 'application/x-prymeoperator', JSON.stringify( paths ) );
			ev.dataTransfer.effectAllowed = 'move';
		};
		const internalPaths = ( ev ) => {
			try { return JSON.parse( ev.dataTransfer.getData( 'application/x-prymeoperator' ) || 'null' ); } catch ( x ) { return null; }
		};
		const isExternal = ( ev ) => Array.from( ev.dataTransfer.types || [] ).includes( 'Files' );
		const onFolderDrop = ( ev, dest ) => {
			ev.preventDefault();
			ev.stopPropagation();
			setDropping( false );
			const paths = internalPaths( ev );
			if ( paths && perms.move ) {
				const valid = paths.filter( ( p ) => p !== dest && ! dest.startsWith( p + '/' ) );
				if ( valid.length ) actions.move( valid, dest );
			} else if ( ev.dataTransfer.files && ev.dataTransfer.files.length && perms.upload ) {
				uploadsApi.add( ev.dataTransfer.files, dest );
			}
		};

		// ── Context menu

		const openMenu = ( ev, e ) => {
			ev.preventDefault();
			ev.stopPropagation();
			let items;
			if ( e ) {
				const targets = selected.includes( e.path ) ? selection : [ e ];
				if ( ! selected.includes( e.path ) ) setSelected( [ e.path ] );
				const one = targets.length === 1 ? targets[ 0 ] : null;
				const x = extOf( e );
				items = [
					one && { icon: e.type === 'dir' ? 'portfolio' : 'visibility', label: e.type === 'dir' ? __( 'Open', 'prymeoperator' ) : __( 'Open / preview', 'prymeoperator' ), shortcut: 'Enter', onClick: () => actions.open( one ) },
					one && isEditable( one ) && { icon: 'edit', label: perms.edit ? __( 'Edit', 'prymeoperator' ) : __( 'View code', 'prymeoperator' ), onClick: () => setDialog( { type: 'edit', entry: one } ) },
					perms.download && { icon: 'download', label: __( 'Download', 'prymeoperator' ), onClick: () => actions.download( targets ) },
					'-',
					perms.copy && { icon: 'admin-page', label: __( 'Copy', 'prymeoperator' ), shortcut: 'Ctrl+C', onClick: () => actions.copy( targets ) },
					perms.move && { icon: 'move', label: __( 'Cut', 'prymeoperator' ), shortcut: 'Ctrl+X', onClick: () => actions.cut( targets ) },
					one && one.type === 'dir' && clipboard && { icon: 'clipboard', label: __( 'Paste into folder', 'prymeoperator' ), onClick: () => actions.paste( one.path ) },
					perms.copy && { icon: 'images-alt2', label: __( 'Duplicate', 'prymeoperator' ), onClick: () => actions.duplicate( targets ) },
					one && perms.rename && { icon: 'edit-large', label: __( 'Rename', 'prymeoperator' ), shortcut: 'F2', onClick: () => actions.rename( one ) },
					'-',
					perms.archive && { icon: 'media-archive', label: __( 'Compress to ZIP', 'prymeoperator' ), onClick: () => actions.compress( targets ) },
					one && x === 'zip' && perms.archive && { icon: 'unlock', label: __( 'Extract here', 'prymeoperator' ), onClick: () => actions.extract( one ) },
					one && perms.chmod && { icon: 'lock', label: __( 'Permissions…', 'prymeoperator' ), onClick: () => actions.chmod( one ) },
					one && CFG.canSeePath && { icon: 'admin-links', label: __( 'Copy path', 'prymeoperator' ), onClick: () => actions.copyPath( one ) },
					one && { icon: 'info-outline', label: __( 'Properties', 'prymeoperator' ), onClick: () => setDialog( { type: 'props', entry: one } ) },
					perms.delete && '-',
					perms.delete && { icon: 'trash', label: __( 'Move to trash', 'prymeoperator' ), shortcut: 'Del', danger: true, onClick: () => actions.remove( targets ) },
				];
			} else {
				setSelected( [] );
				items = [
					perms.create && { icon: 'category', label: __( 'New folder', 'prymeoperator' ), onClick: actions.newFolder },
					perms.create && { icon: 'media-text', label: __( 'New file', 'prymeoperator' ), onClick: actions.newFile },
					perms.upload && { icon: 'upload', label: __( 'Upload files', 'prymeoperator' ), onClick: actions.upload },
					'-',
					clipboard && { icon: 'clipboard', label: __( 'Paste', 'prymeoperator' ), shortcut: 'Ctrl+V', onClick: () => actions.paste() },
					{ icon: 'update', label: __( 'Refresh', 'prymeoperator' ), shortcut: 'F5', onClick: refresh },
					{ icon: 'yes', label: __( 'Select all', 'prymeoperator' ), shortcut: 'Ctrl+A', onClick: () => setSelected( entries.map( ( e ) => e.path ) ) },
				];
			}
			// Drop leading/trailing/double separators.
			items = items.filter( Boolean ).filter( ( it, i, arr ) => it !== '-' || ( i > 0 && i < arr.length - 1 && arr[ i - 1 ] !== '-' ) );
			setMenu( { x: ev.clientX, y: ev.clientY, items } );
		};

		// ── Render pieces

		const crumbs = path ? path.split( '/' ) : [];
		const sortBy = ( key ) => setSort( { key, dir: sort.key === key ? -sort.dir : 1 } );
		const sortArrow = ( key ) => sort.key === key && h( 'span', { className: 'dashicons dashicons-arrow-' + ( sort.dir > 0 ? 'up' : 'down' ) } );

		const tool = ( icon, label, onClick, disabled, extra ) => h( Button, Object.assign( { icon, label, showTooltip: true, onClick, disabled: !! disabled, size: 'compact' }, extra || {} ) );

		const toolbar = h( 'div', { className: 'po-toolbar', role: 'toolbar', 'aria-label': __( 'File actions', 'prymeoperator' ) },
			h( 'div', { className: 'po-toolbar__group' },
				tool( 'arrow-left-alt', __( 'Back', 'prymeoperator' ), back, ! history.length ),
				tool( 'arrow-up-alt', __( 'Up one level', 'prymeoperator' ), () => navigate( parentOf( path ) ), ! path ),
				tool( 'update', __( 'Refresh (F5)', 'prymeoperator' ), refresh )
			),
			h( 'div', { className: 'po-toolbar__group' },
				perms.create && tool( 'category', __( 'New folder', 'prymeoperator' ), actions.newFolder ),
				perms.create && tool( 'media-text', __( 'New file', 'prymeoperator' ), actions.newFile ),
				perms.upload && tool( 'upload', __( 'Upload', 'prymeoperator' ), actions.upload )
			),
			h( 'div', { className: 'po-toolbar__group' },
				perms.copy && tool( 'admin-page', __( 'Copy (Ctrl+C)', 'prymeoperator' ), () => actions.copy( selection ), ! selection.length ),
				perms.move && tool( 'move', __( 'Cut (Ctrl+X)', 'prymeoperator' ), () => actions.cut( selection ), ! selection.length ),
				( perms.copy || perms.move ) && tool( 'clipboard', __( 'Paste (Ctrl+V)', 'prymeoperator' ), () => actions.paste(), ! clipboard ),
				perms.rename && tool( 'edit-large', __( 'Rename (F2)', 'prymeoperator' ), () => actions.rename( single ), ! single ),
				perms.delete && tool( 'trash', __( 'Move to trash (Del)', 'prymeoperator' ), () => actions.remove( selection ), ! selection.length, { isDestructive: true } )
			),
			h( 'div', { className: 'po-toolbar__group' },
				perms.download && tool( 'download', __( 'Download', 'prymeoperator' ), () => actions.download( selection ), ! selection.length ),
				perms.archive && tool( 'media-archive', __( 'Compress to ZIP', 'prymeoperator' ), () => actions.compress( selection ), ! selection.length ),
				perms.archive && tool( 'unlock', __( 'Extract ZIP', 'prymeoperator' ), () => actions.extract( single ), ! single || extOf( single ) !== 'zip' ),
				tool( 'info-outline', __( 'Properties', 'prymeoperator' ), () => setDialog( { type: 'props', entry: single } ), ! single )
			),
			h( 'form', { className: 'po-search', onSubmit: search, role: 'search' },
				h( 'span', { className: 'dashicons dashicons-search' } ),
				h( 'input', { ref: searchRef, type: 'search', value: query, placeholder: __( 'Search in this folder…', 'prymeoperator' ), onChange: ( e ) => { setQuery( e.target.value ); if ( ! e.target.value ) setResults( null ); } } ),
				h( 'label', { className: 'po-search__deep', title: __( 'Also search inside text files', 'prymeoperator' ) },
					h( 'input', { type: 'checkbox', checked: deep, onChange: ( e ) => setDeep( e.target.checked ) } ), __( 'Content', 'prymeoperator' ) )
			),
			h( 'div', { className: 'po-toolbar__group po-toolbar__views' },
				tool( 'list-view', __( 'List view', 'prymeoperator' ), () => changeView( 'list' ), false, { isPressed: view === 'list' } ),
				tool( 'grid-view', __( 'Grid view', 'prymeoperator' ), () => changeView( 'grid' ), false, { isPressed: view === 'grid' } )
			)
		);

		const breadcrumb = h( 'nav', { className: 'po-breadcrumb', 'aria-label': __( 'Location', 'prymeoperator' ) },
			h( 'button', { type: 'button', onClick: () => navigate( '' ), onDragOver: ( e ) => e.preventDefault(), onDrop: ( e ) => onFolderDrop( e, '' ) }, h( 'span', { className: 'dashicons dashicons-admin-home' } ), __( 'Root', 'prymeoperator' ) ),
			crumbs.map( ( part, i ) => {
				const target = crumbs.slice( 0, i + 1 ).join( '/' );
				return h( Fragment, { key: target },
					h( 'span', { className: 'po-breadcrumb__sep dashicons dashicons-arrow-right-alt2' } ),
					h( 'button', { type: 'button', onClick: () => navigate( target ), onDragOver: ( e ) => e.preventDefault(), onDrop: ( e ) => onFolderDrop( e, target ), 'aria-current': i === crumbs.length - 1 ? 'page' : undefined }, part )
				);
			} ),
			results && h( 'span', { className: 'po-breadcrumb__note' }, sprintf( _n( '%d search result', '%d search results', results.length, 'prymeoperator' ), results.length ),
				h( Button, { variant: 'link', onClick: () => { setResults( null ); setQuery( '' ); } }, __( 'Clear', 'prymeoperator' ) ) )
		);

		const rowProps = ( e, i ) => ( {
			key: e.path,
			className: ( selected.includes( e.path ) ? 'is-selected ' : '' ) + ( clipboard && clipboard.mode === 'cut' && clipboard.paths.includes( e.path ) ? 'is-cut ' : '' ),
			onClick: ( ev ) => clickEntry( ev, e, i ),
			onDoubleClick: () => actions.open( e ),
			onContextMenu: ( ev ) => openMenu( ev, e ),
			draggable: perms.move,
			onDragStart: ( ev ) => onDragStart( ev, e ),
			onDragOver: e.type === 'dir' ? ( ev ) => { ev.preventDefault(); ev.currentTarget.classList.add( 'is-drop' ); } : undefined,
			onDragLeave: e.type === 'dir' ? ( ev ) => ev.currentTarget.classList.remove( 'is-drop' ) : undefined,
			onDrop: e.type === 'dir' ? ( ev ) => { ev.currentTarget.classList.remove( 'is-drop' ); onFolderDrop( ev, e.path ); } : undefined,
			'aria-selected': selected.includes( e.path ),
			title: results ? '/' + e.path : undefined,
		} );

		let content;
		if ( error ) {
			content = h( 'div', { className: 'po-empty is-error' }, h( 'span', { className: 'dashicons dashicons-warning' } ), h( 'p', null, error ), h( Button, { variant: 'secondary', onClick: () => navigate( '' ) }, __( 'Go to root', 'prymeoperator' ) ) );
		} else if ( ! data ) {
			content = h( 'div', { className: 'po-empty' }, h( Spinner ) );
		} else if ( ! entries.length ) {
			content = h( 'div', { className: 'po-empty' },
				h( 'span', { className: 'dashicons dashicons-portfolio' } ),
				h( 'p', null, results ? __( 'Nothing matches your search.', 'prymeoperator' ) : __( 'This folder is empty.', 'prymeoperator' ) ),
				! results && perms.upload && h( 'p', { className: 'po-muted' }, __( 'Drop files here to upload them.', 'prymeoperator' ) )
			);
		} else if ( view === 'grid' ) {
			content = h( 'div', { className: 'po-grid', role: 'listbox', 'aria-multiselectable': true },
				entries.map( ( e, i ) => h( 'div', Object.assign( rowProps( e, i ), { className: 'po-tile ' + rowProps( e, i ).className, role: 'option' } ),
					h( 'div', { className: 'po-tile__thumb' },
						isImage( e ) && e.size < 15728640
							? h( 'img', { src: api.rawUrl( e.path ), alt: '', loading: 'lazy', draggable: false } )
							: h( 'span', { className: 'dashicons ' + iconFor( e ) + ( e.type === 'dir' ? ' is-folder' : '' ) } ) ),
					h( 'div', { className: 'po-tile__name' }, e.name ),
					h( 'div', { className: 'po-tile__meta' }, e.type === 'dir' ? __( 'Folder', 'prymeoperator' ) : formatSize( e.size ) )
				) )
			);
		} else {
			content = h( 'table', { className: 'po-list', role: 'grid', 'aria-multiselectable': true },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { className: 'po-col-name', onClick: () => sortBy( 'name' ) }, __( 'Name', 'prymeoperator' ), sortArrow( 'name' ) ),
					h( 'th', { className: 'po-col-size', onClick: () => sortBy( 'size' ) }, __( 'Size', 'prymeoperator' ), sortArrow( 'size' ) ),
					h( 'th', { className: 'po-col-type', onClick: () => sortBy( 'ext' ) }, __( 'Type', 'prymeoperator' ), sortArrow( 'ext' ) ),
					h( 'th', { className: 'po-col-date', onClick: () => sortBy( 'modified' ) }, __( 'Modified', 'prymeoperator' ), sortArrow( 'modified' ) ),
					h( 'th', { className: 'po-col-perms' }, __( 'Permissions', 'prymeoperator' ) )
				) ),
				h( 'tbody', null, entries.map( ( e, i ) => h( 'tr', rowProps( e, i ),
					h( 'td', { className: 'po-col-name' },
						h( 'span', { className: 'dashicons ' + iconFor( e ) + ( e.type === 'dir' ? ' is-folder' : '' ) } ),
						h( 'span', { className: 'po-name' }, results ? e.path : e.name ),
						e.link && h( 'span', { className: 'po-badge' }, __( 'link', 'prymeoperator' ) ),
						! e.writable && e.perms && h( 'span', { className: 'po-badge is-muted', title: __( 'Not writable', 'prymeoperator' ) }, h( 'span', { className: 'dashicons dashicons-lock' } ) )
					),
					h( 'td', { className: 'po-col-size' }, e.type === 'dir' ? '—' : formatSize( e.size ) ),
					h( 'td', { className: 'po-col-type' }, e.type === 'dir' ? __( 'Folder', 'prymeoperator' ) : ( extOf( e ).toUpperCase() || __( 'File', 'prymeoperator' ) ) ),
					h( 'td', { className: 'po-col-date' }, formatDate( e.modified ) ),
					h( 'td', { className: 'po-col-perms' }, e.perms ? h( 'code', { onClick: perms.chmod ? ( ev ) => { ev.stopPropagation(); actions.chmod( e ); } : undefined, className: perms.chmod ? 'is-clickable' : '' }, e.perms ) : '' )
				) ) )
			);
		}

		const selSize = selection.reduce( ( s, e ) => s + ( e.size || 0 ), 0 );
		const status = h( 'div', { className: 'po-status' },
			h( 'span', null, sprintf( _n( '%d item', '%d items', entries.length, 'prymeoperator' ), entries.length ) ),
			selection.length > 0 && h( 'span', null, sprintf( _n( '%d selected', '%d selected', selection.length, 'prymeoperator' ), selection.length ) + ( selSize ? ' · ' + formatSize( selSize ) : '' ) ),
			clipboard && h( 'span', null, h( 'span', { className: 'dashicons dashicons-clipboard' } ), sprintf( clipboard.mode === 'copy' ? __( '%d to copy', 'prymeoperator' ) : __( '%d to move', 'prymeoperator' ), clipboard.paths.length ),
				h( Button, { variant: 'link', onClick: () => setClipboard( null ) }, __( 'Clear', 'prymeoperator' ) ) ),
			loading && data && h( Spinner )
		);

		let modal = null;
		if ( dialog ) {
			const close = () => setDialog( null );
			if ( dialog.type === 'prompt' ) modal = h( PromptDialog, Object.assign( {}, dialog, { onClose: close } ) );
			if ( dialog.type === 'confirm' ) modal = h( ConfirmDialog, Object.assign( {}, dialog, { onClose: close } ) );
			if ( dialog.type === 'chmod' ) modal = h( ChmodDialog, Object.assign( {}, dialog, { onClose: close } ) );
			if ( dialog.type === 'props' ) modal = h( PropertiesDialog, { entry: dialog.entry, onClose: close } );
			if ( dialog.type === 'preview' ) modal = h( PreviewDialog, { entry: dialog.entry, entries, onClose: close, onNavigate: ( e ) => setDialog( { type: 'preview', entry: e } ), onEdit: () => setDialog( { type: 'edit', entry: dialog.entry } ) } );
			if ( dialog.type === 'edit' ) modal = h( EditorDialog, { entry: dialog.entry, canEdit: !! perms.edit && dialog.entry.writable, onClose: close, onSaved: () => load( path ) } );
		}

		return h( 'div', { className: 'po-browser' },
			toolbar,
			h( 'div', { className: 'po-browser__body' },
				h( 'aside', { className: 'po-tree', 'aria-label': __( 'Folders', 'prymeoperator' ) },
					h( 'ul', { role: 'tree' }, h( TreeNode, { path: '', name: __( 'Root', 'prymeoperator' ), current: path, onOpen: navigate, depth: 0, refreshKey: treeKey } ) )
				),
				h( 'section', {
					ref: paneRef,
					className: 'po-pane' + ( dropping ? ' is-dropping' : '' ),
					onContextMenu: ( ev ) => openMenu( ev, null ),
					onClick: ( ev ) => { if ( ev.target === ev.currentTarget || ev.target.classList.contains( 'po-grid' ) || ev.target.classList.contains( 'po-empty' ) ) setSelected( [] ); },
					onDragOver: ( ev ) => { if ( isExternal( ev ) && perms.upload ) { ev.preventDefault(); setDropping( true ); } },
					onDragLeave: ( ev ) => { if ( ev.currentTarget === ev.target ) setDropping( false ); },
					onDrop: ( ev ) => onFolderDrop( ev, path ),
				},
				breadcrumb,
				h( 'div', { className: 'po-pane__content' }, content ),
				dropping && h( 'div', { className: 'po-dropzone' }, h( 'span', { className: 'dashicons dashicons-upload' } ), __( 'Drop to upload to this folder', 'prymeoperator' ) )
				)
			),
			status,
			h( 'input', { ref: fileInput, type: 'file', multiple: true, hidden: true, onChange: ( e ) => { uploadsApi.add( e.target.files, path ); e.target.value = ''; } } ),
			h( Uploads, { onDone: refresh } ),
			menu && h( ContextMenu, Object.assign( {}, menu, { onClose: () => setMenu( null ) } ) ),
			modal
		);
	}

	// ── Dashboard ──────────────────────────────────────────────────────────

	function Dashboard( { goTo } ) {
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( null );
		useEffect( () => { api.get( 'dashboard' ).then( setData, ( e ) => setError( errMsg( e ) ) ); }, [] );
		if ( error ) return h( 'div', { className: 'po-banner is-error' }, error );
		if ( ! data ) return h( 'div', { className: 'po-center' }, h( Spinner ) );
		const used = data.disk.total ? data.disk.total - data.disk.free : 0;
		const pct = data.disk.total ? Math.round( ( used / data.disk.total ) * 100 ) : 0;
		const env = data.environment;
		const check = ( ok, label, hint ) => h( 'li', { className: ok ? 'is-ok' : 'is-warn' }, h( 'span', { className: 'dashicons dashicons-' + ( ok ? 'yes-alt' : 'warning' ) } ), h( 'span', { className: 'po-checks__text' }, label ), hint && h( 'small', null, hint ) );
		return h( 'div', { className: 'po-dash' },
			h( 'div', { className: 'po-cards' },
				h( 'div', { className: 'po-card' },
					h( 'h3', null, __( 'Disk space', 'prymeoperator' ) ),
					data.disk.total ? h( Fragment, null,
						h( 'div', { className: 'po-meter', role: 'meter', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100 }, h( 'span', { style: { width: pct + '%' }, className: pct > 90 ? 'is-high' : '' } ) ),
						h( 'p', { className: 'po-big' }, formatSize( data.disk.free ) ), h( 'p', { className: 'po-muted' }, sprintf( __( 'free of %1$s (%2$d%% used)', 'prymeoperator' ), formatSize( data.disk.total ), pct ) )
					) : h( 'p', { className: 'po-muted' }, __( 'The server does not report disk space.', 'prymeoperator' ) )
				),
				h( 'div', { className: 'po-card' },
					h( 'h3', null, __( 'Trash', 'prymeoperator' ) ),
					h( 'p', { className: 'po-big' }, String( data.trash.count ) ),
					h( 'p', { className: 'po-muted' }, sprintf( __( 'items · %s', 'prymeoperator' ), formatSize( data.trash.size ) ) ),
					h( Button, { variant: 'secondary', onClick: () => goTo( 'trash' ) }, __( 'Open trash', 'prymeoperator' ) )
				),
				h( 'div', { className: 'po-card' },
					h( 'h3', null, __( 'Uploads', 'prymeoperator' ) ),
					h( 'p', { className: 'po-big' }, data.limits.plugin ? formatSize( data.limits.plugin ) : __( 'No limit', 'prymeoperator' ) ),
					h( 'p', { className: 'po-muted' }, sprintf( __( 'Large files are sent in pieces, so the server limit of %s per request does not apply.', 'prymeoperator' ), formatSize( data.limits.upload ) ) )
				)
			),
			env && h( 'div', { className: 'po-cards' },
				h( 'div', { className: 'po-card po-card--wide' },
					h( 'h3', null, __( 'Health check', 'prymeoperator' ) ),
					h( 'ul', { className: 'po-checks' },
						check( true, sprintf( __( 'Root folder: %s', 'prymeoperator' ), data.root ) ),
						check( true, sprintf( 'PHP %s · WordPress %s', env.php, env.wordpress ) ),
						check( env.zip, env.zip ? __( 'ZIP support available', 'prymeoperator' ) : __( 'ZIP support missing', 'prymeoperator' ), ! env.zip && __( 'Install the PHP zip extension to compress and extract.', 'prymeoperator' ) ),
						check( ! env.disallow_file_mods, env.disallow_file_mods ? __( 'File changes are disabled (DISALLOW_FILE_MODS)', 'prymeoperator' ) : __( 'File changes allowed', 'prymeoperator' ) ),
						check( ! env.disallow_file_edit, env.disallow_file_edit ? __( 'Code editing is disabled (DISALLOW_FILE_EDIT)', 'prymeoperator' ) : __( 'Code editing allowed, with syntax check and automatic rollback', 'prymeoperator' ) )
					)
				),
				h( 'div', { className: 'po-card po-card--wide' },
					h( 'h3', null, __( 'Recent activity', 'prymeoperator' ) ),
					data.recent && data.recent.length ? h( 'ul', { className: 'po-recent' }, data.recent.map( ( r ) => h( 'li', { key: r.id },
						h( 'code', null, r.action ), ' ', h( 'span', { className: 'po-recent__target' }, r.target ),
						h( 'small', null, r.user + ' · ' + new Date( r.created_at ).toLocaleString() )
					) ) ) : h( 'p', { className: 'po-muted' }, __( 'No activity yet.', 'prymeoperator' ) ),
					h( Button, { variant: 'link', onClick: () => goTo( 'activity' ) }, __( 'See all activity', 'prymeoperator' ) )
				)
			)
		);
	}

	// ── Trash ──────────────────────────────────────────────────────────────

	function TrashView() {
		const [ items, setItems ] = useState( null );
		const [ confirm, setConfirm ] = useState( null );
		const load = () => api.get( 'trash' ).then( setItems, ( e ) => toast( errMsg( e ), 'error' ) );
		useEffect( () => { load(); }, [] );
		const act = ( p, msg ) => p.then( ( r ) => { toast( typeof msg === 'function' ? msg( r ) : msg ); load(); }, ( e ) => toast( errMsg( e ), 'error' ) );

		if ( ! items ) return h( 'div', { className: 'po-center' }, h( Spinner ) );
		return h( 'div', { className: 'po-trash' },
			h( 'div', { className: 'po-section-head' },
				h( 'p', { className: 'po-muted' }, __( 'Deleted files stay here until they expire or you empty the trash.', 'prymeoperator' ) ),
				CFG.isAdmin && items.length > 0 && h( Button, { variant: 'secondary', isDestructive: true, onClick: () => setConfirm( 'empty' ) }, __( 'Empty trash', 'prymeoperator' ) )
			),
			items.length === 0 ? h( 'div', { className: 'po-empty' }, h( 'span', { className: 'dashicons dashicons-trash' } ), h( 'p', null, __( 'The trash is empty.', 'prymeoperator' ) ) )
				: h( 'table', { className: 'po-list' },
					h( 'thead', null, h( 'tr', null,
						h( 'th', null, __( 'Name', 'prymeoperator' ) ), h( 'th', null, __( 'Original location', 'prymeoperator' ) ), h( 'th', null, __( 'Size', 'prymeoperator' ) ),
						h( 'th', null, __( 'Deleted', 'prymeoperator' ) ), h( 'th', null, __( 'Deleted by', 'prymeoperator' ) ), h( 'th' )
					) ),
					h( 'tbody', null, items.map( ( it ) => h( 'tr', { key: it.id },
						h( 'td', { className: 'po-col-name' }, h( 'span', { className: 'dashicons ' + iconFor( { type: it.type, name: it.name, ext: it.name.split( '.' ).pop() } ) + ( it.type === 'dir' ? ' is-folder' : '' ) } ), h( 'span', { className: 'po-name' }, it.name ) ),
						h( 'td', null, h( 'code', null, '/' + parentOf( it.original ) ) ),
						h( 'td', null, formatSize( it.size ) ),
						h( 'td', null, formatDate( it.deleted_at ) ),
						h( 'td', null, it.user ),
						h( 'td', { className: 'po-actions' },
							h( Button, { variant: 'secondary', size: 'compact', onClick: () => act( api.post( 'trash/restore', { id: it.id } ), ( r ) => sprintf( __( 'Restored to /%s', 'prymeoperator' ), r.path ) ) }, __( 'Restore', 'prymeoperator' ) ),
							CFG.isAdmin && h( Button, { variant: 'tertiary', size: 'compact', isDestructive: true, onClick: () => setConfirm( it ) }, __( 'Delete forever', 'prymeoperator' ) )
						)
					) ) )
				),
			confirm && h( ConfirmDialog, {
				title: confirm === 'empty' ? __( 'Empty trash', 'prymeoperator' ) : __( 'Delete forever', 'prymeoperator' ),
				message: confirm === 'empty' ? __( 'Everything in the trash will be deleted permanently. This cannot be undone.', 'prymeoperator' ) : sprintf( __( '"%s" will be deleted permanently. This cannot be undone.', 'prymeoperator' ), confirm.name ),
				confirm: __( 'Delete permanently', 'prymeoperator' ),
				destructive: true,
				onClose: () => setConfirm( null ),
				onConfirm: () => {
					const c = confirm;
					setConfirm( null );
					act( c === 'empty' ? api.post( 'trash/empty' ) : api.post( 'trash/purge', { id: c.id } ), __( 'Deleted permanently.', 'prymeoperator' ) );
				},
			} )
		);
	}

	// ── Activity ───────────────────────────────────────────────────────────

	function Activity() {
		const [ items, setItems ] = useState( null );
		const [ page, setPage ] = useState( 1 );
		const [ total, setTotal ] = useState( 0 );
		const [ filter, setFilter ] = useState( '' );
		const per = 50;
		useEffect( () => {
			setItems( null );
			apiFetch( { path: addQueryArgs( '/prymeoperator/v1/audit', { page, per_page: per, action: filter } ), parse: false } ).then( ( res ) => {
				setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
				return res.json();
			} ).then( setItems, ( e ) => { setItems( [] ); toast( errMsg( e ), 'error' ); } );
		}, [ page, filter ] );
		const pages = Math.max( 1, Math.ceil( total / per ) );
		const actions = [ '', 'server.upload', 'server.write', 'server.rollback', 'server.delete', 'server.rename', 'server.move', 'server.copy', 'server.mkdir', 'server.create', 'server.chmod', 'server.compress', 'server.extract', 'server.download', 'server.read', 'trash.restore', 'trash.purge', 'trash.empty', 'settings.update', 'security.jail_violation' ];
		return h( 'div', { className: 'po-activity' },
			h( 'div', { className: 'po-section-head' },
				h( SelectControl, { label: __( 'Filter', 'prymeoperator' ), hideLabelFromVision: true, value: filter, __nextHasNoMarginBottom: true, onChange: ( v ) => { setFilter( v ); setPage( 1 ); },
					options: actions.map( ( a ) => ( { value: a, label: a || __( 'All actions', 'prymeoperator' ) } ) ) } ),
				h( 'span', { className: 'po-muted' }, sprintf( _n( '%d event', '%d events', total, 'prymeoperator' ), total ) )
			),
			! items ? h( 'div', { className: 'po-center' }, h( Spinner ) )
				: items.length === 0 ? h( 'div', { className: 'po-empty' }, h( 'span', { className: 'dashicons dashicons-backup' } ), h( 'p', null, __( 'No activity yet.', 'prymeoperator' ) ) )
					: h( 'table', { className: 'po-list' },
						h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'When', 'prymeoperator' ) ), h( 'th', null, __( 'User', 'prymeoperator' ) ), h( 'th', null, __( 'Action', 'prymeoperator' ) ), h( 'th', null, __( 'Target', 'prymeoperator' ) ), h( 'th', null, 'IP' ) ) ),
						h( 'tbody', null, items.map( ( r ) => h( 'tr', { key: r.id, className: r.action.startsWith( 'security.' ) || r.action === 'server.rollback' ? 'is-alert' : '' },
							h( 'td', null, new Date( r.created_at ).toLocaleString() ), h( 'td', null, r.user ), h( 'td', null, h( 'code', null, r.action ) ), h( 'td', { className: 'po-break' }, r.target ), h( 'td', null, r.ip )
						) ) )
					),
			pages > 1 && h( 'div', { className: 'po-pager' },
				h( Button, { variant: 'secondary', disabled: page <= 1, onClick: () => setPage( page - 1 ) }, __( 'Previous', 'prymeoperator' ) ),
				h( 'span', null, sprintf( __( 'Page %1$d of %2$d', 'prymeoperator' ), page, pages ) ),
				h( Button, { variant: 'secondary', disabled: page >= pages, onClick: () => setPage( page + 1 ) }, __( 'Next', 'prymeoperator' ) )
			)
		);
	}

	// ── Settings ───────────────────────────────────────────────────────────

	function SettingsView( { onTheme } ) {
		const [ payload, setPayload ] = useState( null );
		const [ form, setForm ] = useState( null );
		const [ saving, setSaving ] = useState( false );
		useEffect( () => { api.get( 'settings' ).then( ( p ) => { setPayload( p ); setForm( p.settings ); }, ( e ) => toast( errMsg( e ), 'error' ) ); }, [] );
		if ( ! form ) return h( 'div', { className: 'po-center' }, h( Spinner ) );
		const set = ( k ) => ( v ) => setForm( Object.assign( {}, form, { [ k ]: v } ) );
		const toggleIn = ( k, value ) => ( on ) => set( k )( on ? form[ k ].concat( value ) : form[ k ].filter( ( x ) => x !== value ) );
		const save = () => {
			setSaving( true );
			api.post( 'settings', form ).then( ( p ) => { setPayload( p ); setForm( p.settings ); setSaving( false ); onTheme( p.settings.theme ); toast( __( 'Settings saved.', 'prymeoperator' ) ); },
				( e ) => { setSaving( false ); toast( errMsg( e ), 'error' ); } );
		};
		const permLabels = { upload: __( 'Upload', 'prymeoperator' ), download: __( 'Download', 'prymeoperator' ), create: __( 'Create files and folders', 'prymeoperator' ), rename: __( 'Rename', 'prymeoperator' ), copy: __( 'Copy', 'prymeoperator' ), move: __( 'Move', 'prymeoperator' ), delete: __( 'Delete (to trash)', 'prymeoperator' ), archive: __( 'Compress and extract', 'prymeoperator' ), edit: __( 'Edit text files', 'prymeoperator' ) };
		const section = ( title, desc, ...children ) => h( 'section', { className: 'po-settings__section' }, h( 'div', { className: 'po-settings__intro' }, h( 'h3', null, title ), desc && h( 'p', null, desc ) ), h( 'div', { className: 'po-settings__fields' }, ...children ) );
		const nm = { __nextHasNoMarginBottom: true };

		return h( 'div', { className: 'po-settings' },
			section( __( 'General', 'prymeoperator' ), __( 'Where PrymeOperator starts and how it looks.', 'prymeoperator' ),
				h( TextControl, Object.assign( { label: __( 'Root folder', 'prymeoperator' ), help: payload.root_locked ? __( 'Set by the PRYMEOPERATOR_ROOT constant in wp-config.php.', 'prymeoperator' ) : sprintf( __( 'Absolute path. Leave empty for the WordPress folder (%s).', 'prymeoperator' ), payload.default_root ), value: form.root_path, placeholder: payload.default_root, disabled: payload.root_locked, onChange: set( 'root_path' ) }, nm ) ),
				h( SelectControl, Object.assign( { label: __( 'Theme', 'prymeoperator' ), value: form.theme, onChange: set( 'theme' ), options: [ { value: 'auto', label: __( 'Follow system', 'prymeoperator' ) }, { value: 'light', label: __( 'Light', 'prymeoperator' ) }, { value: 'dark', label: __( 'Dark', 'prymeoperator' ) } ] }, nm ) ),
				h( SelectControl, Object.assign( { label: __( 'Default view', 'prymeoperator' ), value: form.default_view, onChange: set( 'default_view' ), options: [ { value: 'list', label: __( 'List', 'prymeoperator' ) }, { value: 'grid', label: __( 'Grid', 'prymeoperator' ) } ] }, nm ) ),
				h( ToggleControl, Object.assign( { label: __( 'Show hidden files (starting with a dot)', 'prymeoperator' ), checked: form.show_hidden, onChange: set( 'show_hidden' ) }, nm ) )
			),
			section( __( 'Access for other roles', 'prymeoperator' ), __( 'Administrators always have full access. Roles you enable here only see their folder and can only do what you allow. They can never upload blocked file types, edit code or change permissions.', 'prymeoperator' ),
				h( 'fieldset', null, h( 'legend', null, __( 'Roles with access', 'prymeoperator' ) ),
					payload.roles.map( ( r ) => h( CheckboxControl, Object.assign( { key: r.value, label: r.label, checked: form.role_access.includes( r.value ), onChange: toggleIn( 'role_access', r.value ) }, nm ) ) ) ),
				h( TextControl, Object.assign( { label: __( 'Their folder', 'prymeoperator' ), help: __( 'Relative to the root folder. It must exist.', 'prymeoperator' ), value: form.role_root, onChange: set( 'role_root' ) }, nm ) ),
				h( 'fieldset', null, h( 'legend', null, __( 'What they can do', 'prymeoperator' ) ),
					payload.permissions.map( ( p ) => h( CheckboxControl, Object.assign( { key: p, label: permLabels[ p ] || p, checked: form.role_permissions.includes( p ), onChange: toggleIn( 'role_permissions', p ) }, nm ) ) ) )
			),
			section( __( 'Uploads and file types', 'prymeoperator' ), null,
				h( TextControl, Object.assign( { type: 'number', min: 0, label: __( 'Maximum upload size (MB)', 'prymeoperator' ), help: __( '0 means no limit. Files are uploaded in pieces, so this can be larger than the server limit.', 'prymeoperator' ), value: String( form.max_upload_mb ), onChange: ( v ) => set( 'max_upload_mb' )( parseInt( v || '0', 10 ) ) }, nm ) ),
				h( TextareaControl, Object.assign( { label: __( 'Blocked file types', 'prymeoperator' ), help: __( 'Comma separated. A name is blocked if any of its extensions is listed, so "shell.php.jpg" counts as PHP.', 'prymeoperator' ), value: form.blocked_extensions, rows: 3, onChange: set( 'blocked_extensions' ) }, nm ) ),
				h( ToggleControl, Object.assign( { label: __( 'Administrators may upload and create blocked file types', 'prymeoperator' ), checked: form.admins_bypass_blocklist, onChange: set( 'admins_bypass_blocklist' ) }, nm ) )
			),
			section( __( 'Safety net', 'prymeoperator' ), __( 'How PrymeOperator protects you from mistakes.', 'prymeoperator' ),
				h( ToggleControl, Object.assign( { label: __( 'Check PHP syntax before saving', 'prymeoperator' ), help: __( 'Files with syntax errors are not saved.', 'prymeoperator' ), checked: form.php_lint, onChange: set( 'php_lint' ) }, nm ) ),
				h( ToggleControl, Object.assign( { label: __( 'Restore automatically if a PHP change breaks the site', 'prymeoperator' ), help: __( 'After saving, the site is loaded once; if it fails, the previous version is put back.', 'prymeoperator' ), checked: form.loopback_check, onChange: set( 'loopback_check' ) }, nm ) ),
				h( TextControl, Object.assign( { type: 'number', min: 0, label: __( 'Versions kept per file', 'prymeoperator' ), value: String( form.versions_keep ), onChange: ( v ) => set( 'versions_keep' )( parseInt( v || '0', 10 ) ) }, nm ) ),
				h( TextControl, Object.assign( { type: 'number', min: 0, label: __( 'Days to keep items in the trash', 'prymeoperator' ), help: __( '0 keeps them until you empty the trash.', 'prymeoperator' ), value: String( form.trash_days ), onChange: ( v ) => set( 'trash_days' )( parseInt( v || '0', 10 ) ) }, nm ) )
			),
			section( __( 'Notifications', 'prymeoperator' ), null,
				h( ToggleControl, Object.assign( { label: __( 'E-mail me when files are deleted', 'prymeoperator' ), checked: form.notify_on_delete, onChange: set( 'notify_on_delete' ) }, nm ) ),
				form.notify_on_delete && h( TextControl, Object.assign( { type: 'email', label: __( 'Send to', 'prymeoperator' ), help: __( 'Leave empty to use the site administrator e-mail.', 'prymeoperator' ), value: form.notify_email, onChange: set( 'notify_email' ) }, nm ) )
			),
			section( __( 'Uninstall', 'prymeoperator' ), null,
				h( ToggleControl, Object.assign( { label: __( 'Delete all PrymeOperator data when the plugin is deleted', 'prymeoperator' ), help: __( 'Activity log, trash, versions and settings. Your own files are never touched.', 'prymeoperator' ), checked: form.delete_data_on_uninstall, onChange: set( 'delete_data_on_uninstall' ) }, nm ) )
			),
			h( 'div', { className: 'po-settings__save' }, h( Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: save }, __( 'Save settings', 'prymeoperator' ) ) )
		);
	}

	// ── App shell ──────────────────────────────────────────────────────────

	function App() {
		const tabs = [
			{ name: 'files', title: __( 'Files', 'prymeoperator' ), icon: 'portfolio' },
			{ name: 'dashboard', title: __( 'Dashboard', 'prymeoperator' ), icon: 'chart-area' },
			{ name: 'trash', title: __( 'Trash', 'prymeoperator' ), icon: 'trash' },
			CFG.isAdmin && { name: 'activity', title: __( 'Activity', 'prymeoperator' ), icon: 'backup' },
			CFG.isAdmin && { name: 'settings', title: __( 'Settings', 'prymeoperator' ), icon: 'admin-generic' },
		].filter( Boolean );
		const initial = ( window.location.hash || '' ).replace( '#', '' );
		const [ tab, setTab ] = useState( tabs.some( ( t ) => t.name === initial ) ? initial : 'files' );
		const [ theme, setTheme ] = useState( CFG.theme || 'auto' );
		const goTo = ( name ) => { setTab( name ); try { window.history.replaceState( null, '', '#' + name ); } catch ( e ) {} };

		let body;
		if ( tab === 'files' ) body = h( Browser );
		if ( tab === 'dashboard' ) body = h( Dashboard, { goTo } );
		if ( tab === 'trash' ) body = h( TrashView );
		if ( tab === 'activity' ) body = h( Activity );
		if ( tab === 'settings' ) body = h( SettingsView, { onTheme: setTheme } );

		return h( 'div', { className: 'po-app', 'data-theme': theme },
			h( 'header', { className: 'po-header' },
				h( 'div', { className: 'po-brand' }, h( 'span', { className: 'po-logo', 'aria-hidden': true } ), h( 'span', null, 'PrymeOperator' ), h( 'small', null, 'v' + CFG.version ) ),
				h( 'nav', { className: 'po-tabs', role: 'tablist' }, tabs.map( ( t ) => h( 'button', {
					key: t.name, type: 'button', role: 'tab', 'aria-selected': tab === t.name, className: tab === t.name ? 'is-active' : '', onClick: () => goTo( t.name ),
				}, h( 'span', { className: 'dashicons dashicons-' + t.icon } ), t.title ) ) )
			),
			h( 'main', { className: 'po-main' }, body ),
			h( Toasts )
		);
	}

	const mount = document.getElementById( 'prymeoperator-app' );
	if ( mount ) {
		if ( createRoot ) createRoot( mount ).render( h( App ) );
		else render( h( App ), mount );
	}
}() );
