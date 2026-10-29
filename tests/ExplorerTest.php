<?php
use PrymeOperator\Security\Jail;
use PrymeOperator\Server\Explorer;
use PrymeOperator\Server\ExplorerException;

$base = sys_get_temp_dir() . '/po-explorer-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/root/Beta', 0777, true );
mkdir( $base . '/outside', 0777, true );
file_put_contents( $base . '/root/alpha.txt', "hola\n" );
file_put_contents( $base . '/root/bin.dat', "\x00\x01\x02" );
file_put_contents( $base . '/root/big.log', str_repeat( 'x', Explorer::PREVIEW_LIMIT + 1 ) );
symlink( $base . '/outside', $base . '/root/escape' );

$explorer = new Explorer( new Jail( $base . '/root' ) );

check( 'lists folders first, hides escaping symlinks', function () use ( $explorer ) {
	$names = array_column( $explorer->list( '' )['entries'], 'name' );
	assert_true( $names === [ 'Beta', 'alpha.txt', 'big.log', 'bin.dat' ], json_encode( $names ) );
} );
check( 'reads text file', fn () => assert_true( $explorer->read( 'alpha.txt' )['content'] === "hola\n" ) );
check( 'binary has no content', fn () => assert_true( $explorer->read( 'bin.dat' )['reason'] === 'binary' ) );
check( 'large file has no content', fn () => assert_true( $explorer->read( 'big.log' )['reason'] === 'too_large' ) );
check( 'listing a file fails', fn () => assert_throws( fn () => $explorer->list( 'alpha.txt' ), ExplorerException::class ) );
check( 'reading a folder fails', fn () => assert_throws( fn () => $explorer->read( 'Beta' ), ExplorerException::class ) );

exec( 'rm -rf ' . escapeshellarg( $base ) );
