/**
 * Editor side of the PrymeOperator "Folder" block. The front end is rendered
 * by PHP (and previewed here with ServerSideRender).
 */
( function ( wp ) {
	'use strict';

	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var c = wp.components;
	var be = wp.blockEditor;
	var ServerSideRender = wp.serverSideRender;

	function FolderOptions() {
		var state = useState( null );
		var folders = state[ 0 ];
		var setFolders = state[ 1 ];
		useEffect( function () {
			wp.apiFetch( { path: '/prymeoperator/v1/library/folders?scope=attachment' } ).then( function ( data ) {
				var byParent = {};
				data.folders.forEach( function ( f ) {
					( byParent[ f.parent_id ] = byParent[ f.parent_id ] || [] ).push( f );
				} );
				var out = [];
				( function walk( parent, depth ) {
					( byParent[ parent ] || [] ).forEach( function ( f ) {
						out.push( { value: String( f.id ), label: new Array( depth + 1 ).join( '— ' ) + f.name + ' (' + f.count + ')' } );
						walk( f.id, depth + 1 );
					} );
				} )( 0, 0 );
				setFolders( out );
			}, function () {
				setFolders( [] );
			} );
		}, [] );
		return folders;
	}

	wp.blocks.registerBlockType( 'prymeoperator/folder', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( value ) {
					var next = {};
					next[ key ] = value;
					props.setAttributes( next );
				};
			};
			var folders = FolderOptions();
			var blockProps = be.useBlockProps();
			var nm = { __nextHasNoMarginBottom: true };

			var picker = folders === null
				? h( c.Spinner )
				: h( c.SelectControl, Object.assign( {
					label: __( 'Folder', 'prymeoperator' ),
					value: String( a.folder || '' ),
					options: [ { value: '', label: __( 'Choose a folder…', 'prymeoperator' ) } ].concat( folders ),
					onChange: function ( v ) {
						props.setAttributes( { folder: parseInt( v || '0', 10 ) } );
					},
				}, nm ) );

			return h( 'div', blockProps,
				h( be.InspectorControls, null,
					h( c.PanelBody, { title: __( 'Folder', 'prymeoperator' ) },
						picker,
						h( c.ToggleControl, Object.assign( { label: __( 'Include subfolders', 'prymeoperator' ), checked: a.subfolders, onChange: set( 'subfolders' ) }, nm ) )
					),
					h( c.PanelBody, { title: __( 'Display', 'prymeoperator' ) },
						h( c.SelectControl, Object.assign( { label: __( 'Show as', 'prymeoperator' ), value: a.view, onChange: set( 'view' ), options: [ { value: 'grid', label: __( 'Gallery', 'prymeoperator' ) }, { value: 'list', label: __( 'List of downloads', 'prymeoperator' ) } ] }, nm ) ),
						a.view === 'grid' && h( c.RangeControl, Object.assign( { label: __( 'Columns', 'prymeoperator' ), value: a.columns, min: 1, max: 8, onChange: set( 'columns' ) }, nm ) ),
						a.view === 'grid' && h( c.SelectControl, Object.assign( { label: __( 'Image size', 'prymeoperator' ), value: a.size, onChange: set( 'size' ), options: [ { value: 'thumbnail', label: __( 'Thumbnail', 'prymeoperator' ) }, { value: 'medium', label: __( 'Medium', 'prymeoperator' ) }, { value: 'large', label: __( 'Large', 'prymeoperator' ) }, { value: 'full', label: __( 'Full size', 'prymeoperator' ) } ] }, nm ) ),
						a.view === 'grid' && h( c.ToggleControl, Object.assign( { label: __( 'Show captions', 'prymeoperator' ), checked: a.captions, onChange: set( 'captions' ) }, nm ) ),
						h( c.SelectControl, Object.assign( { label: __( 'Order', 'prymeoperator' ), value: a.orderby, onChange: set( 'orderby' ), options: [ { value: 'date', label: __( 'Newest first', 'prymeoperator' ) }, { value: 'title', label: __( 'By name', 'prymeoperator' ) }, { value: 'rand', label: __( 'Random', 'prymeoperator' ) } ] }, nm ) ),
						h( c.RangeControl, Object.assign( { label: __( 'Maximum items', 'prymeoperator' ), value: a.limit, min: 1, max: 500, onChange: set( 'limit' ) }, nm ) )
					)
				),
				a.folder
					? h( ServerSideRender, { block: 'prymeoperator/folder', attributes: a } )
					: h( c.Placeholder, { icon: 'portfolio', label: __( 'Folder', 'prymeoperator' ), instructions: __( 'Shows the files of a Media Library folder as a gallery or a list of downloads.', 'prymeoperator' ) }, picker )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
