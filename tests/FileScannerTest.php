<?php
use PrymeOperator\Backup\BackupException;
use PrymeOperator\Backup\FileScanner;

$base = sys_get_temp_dir() . '/po-scan-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/site/wp-content/uploads/2024', 0777, true );
mkdir( $base . '/site/wp-content/cache/page', 0777, true );
mkdir( $base . '/site/wp-content/private', 0777, true );
mkdir( $base . '/outside', 0777, true );
file_put_contents( $base . '/site/index.php', '<?php' );
file_put_contents( $base . '/site/wp-content/uploads/2024/a.jpg', str_repeat( 'x', 100 ) );
file_put_contents( $base . '/site/wp-content/cache/page/c.html', 'cache' );
file_put_contents( $base . '/site/wp-content/private/secret.txt', 'vault' );
file_put_contents( $base . '/outside/o.txt', 'outside' );
symlink( $base . '/outside', $base . '/site/wp-content/link' );
$list = $base . '/list.txt';
$site = $base . '/site';

check( 'scanner lists files relative to the base', function () use ( $site, $list ) {
	$r     = FileScanner::scan( $site, $site, [], $list );
	$paths = file( $list, FILE_IGNORE_NEW_LINES );
	sort( $paths );
	assert_true( 4 === $r['files'] && in_array( 'index.php', $paths, true ) && in_array( 'wp-content/uploads/2024/a.jpg', $paths, true ) );
} );
check( 'scanner never follows symlinks', function () use ( $site, $list ) {
	FileScanner::scan( $site, $site, [], $list );
	assert_true( ! str_contains( (string) file_get_contents( $list ), 'o.txt' ) );
} );
check( 'scanner skips excluded folders', function () use ( $site, $list ) {
	$r = FileScanner::scan( $site, $site, [ $site . '/wp-content/cache', $site . '/wp-content/private/' ], $list );
	$all = (string) file_get_contents( $list );
	assert_true( 2 === $r['files'] && ! str_contains( $all, 'cache' ) && ! str_contains( $all, 'secret' ) );
} );
check( 'scanner can start below the base', function () use ( $site, $list ) {
	$r = FileScanner::scan( dirname( $site . '/wp-content' ), $site . '/wp-content', [], $list );
	assert_true( 3 === $r['files'] && str_starts_with( (string) file_get_contents( $list ), 'wp-content/' ) );
} );
check( 'scanner adds up sizes', fn () => assert_true( 105 === FileScanner::scan( $site, $site, [ $site . '/wp-content/cache', $site . '/wp-content/private' ], $list )['bytes'] ) );
check( 'scanner refuses a start outside the base', fn () => assert_throws( fn () => FileScanner::scan( $site, $GLOBALS['base'] . '/outside', [], $list ), BackupException::class ) );
check( 'exclusions do not match look-alike names', fn () => assert_true( ! FileScanner::excluded( '/a/cache-old/x', [ '/a/cache' ] ) && FileScanner::excluded( '/a/cache/x', [ '/a/cache' ] ) ) );
