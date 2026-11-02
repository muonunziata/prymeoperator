<?php
use PrymeOperator\Library\Tree;

$rows = [
	[ 'id' => 1, 'parent_id' => 0, 'name' => 'Fotos' ],
	[ 'id' => 2, 'parent_id' => 1, 'name' => '2024' ],
	[ 'id' => 3, 'parent_id' => 2, 'name' => 'Verano' ],
	[ 'id' => 4, 'parent_id' => 0, 'name' => 'Documentos' ],
	[ 'id' => 5, 'parent_id' => 1, 'name' => 'Logo (2)' ],
];

check( 'descendants include grandchildren', fn () => assert_true( [ 2, 3, 5 ] === sorted( Tree::descendants( $rows, 1 ) ) ) );
check( 'leaf has no descendants', fn () => assert_true( [] === Tree::descendants( $rows, 3 ) ) );
check( 'descendants survive a corrupt loop', function () {
	$loop = [ [ 'id' => 1, 'parent_id' => 2, 'name' => 'a' ], [ 'id' => 2, 'parent_id' => 1, 'name' => 'b' ] ];
	assert_true( [ 1, 2 ] === sorted( Tree::descendants( $loop, 1 ) ) );
} );

check( 'folder can move to top level', fn () => assert_true( Tree::can_move( $rows, 3, 0 ) ) );
check( 'folder can move to an unrelated folder', fn () => assert_true( Tree::can_move( $rows, 2, 4 ) ) );
check( 'folder cannot move into itself', fn () => assert_true( ! Tree::can_move( $rows, 2, 2 ) ) );
check( 'folder cannot move into its descendant', fn () => assert_true( ! Tree::can_move( $rows, 1, 3 ) ) );
check( 'folder cannot move into a missing folder', fn () => assert_true( ! Tree::can_move( $rows, 2, 99 ) ) );

check( 'free name is kept', fn () => assert_true( 'Música' === Tree::unique_name( $rows, 0, 'Música' ) ) );
check( 'taken name gets a number', fn () => assert_true( 'Fotos (2)' === Tree::unique_name( $rows, 0, 'Fotos' ) ) );
check( 'names compare without case', fn () => assert_true( 'fotos (2)' === Tree::unique_name( $rows, 0, 'fotos' ) ) );
check( 'same name is fine under another parent', fn () => assert_true( 'Fotos' === Tree::unique_name( $rows, 4, 'Fotos' ) ) );
check( 'a folder may keep its own name', fn () => assert_true( 'Fotos' === Tree::unique_name( $rows, 0, 'Fotos', 1 ) ) );
check( 'numbered names count up', function () use ( $rows ) {
	$more = array_merge( $rows, [ [ 'id' => 6, 'parent_id' => 1, 'name' => 'Logo' ] ] );
	assert_true( 'Logo (3)' === Tree::unique_name( $more, 1, 'Logo' ) );
} );

check( 'names are trimmed and collapsed', fn () => assert_true( 'Mis fotos' === Tree::clean_name( "  Mis \t fotos \n" ) ) );
check( 'tags and control characters are removed', fn () => assert_true( 'Hola' === Tree::clean_name( "<b>Ho\x00la</b><script></script>" ) ) );
check( 'names are capped', fn () => assert_true( Tree::MAX_NAME === mb_strlen( Tree::clean_name( str_repeat( 'ñ', 400 ) ) ) ) );
check( 'empty names stay empty', fn () => assert_true( '' === Tree::clean_name( '   ' ) ) );

check( 'path lists names from the top', fn () => assert_true( [ 'Fotos', '2024', 'Verano' ] === Tree::path( $rows, 3 ) ) );

function sorted( array $a ): array {
	sort( $a );
	return $a;
}
