<?php
use PrymeOperator\Security\Jail;
use PrymeOperator\Security\JailException;

$base = sys_get_temp_dir() . '/po-jail-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/root/sub', 0777, true );
mkdir( $base . '/outside', 0777, true );
file_put_contents( $base . '/root/sub/a.txt', 'a' );
file_put_contents( $base . '/outside/secret.txt', 'secret' );
symlink( $base . '/outside', $base . '/root/escape' );
symlink( $base . '/root/sub', $base . '/root/inside-link' );

$jail = new Jail( $base . '/root' );
$root = realpath( $base . '/root' );

check( 'empty path resolves to root', fn () => assert_true( $jail->resolve( '' ) === $root ) );
check( 'normal file resolves', fn () => assert_true( $jail->resolve( 'sub/a.txt' ) === $root . '/sub/a.txt' ) );
check( 'leading slash is treated as relative', fn () => assert_true( $jail->resolve( '/sub/a.txt' ) === $root . '/sub/a.txt' ) );
check( 'backslashes are normalised', fn () => assert_true( $jail->resolve( 'sub\\a.txt' ) === $root . '/sub/a.txt' ) );
check( 'inner .. that stays inside is allowed', fn () => assert_true( $jail->resolve( 'sub/../sub/a.txt' ) === $root . '/sub/a.txt' ) );
check( 'non-existent target inside root is allowed', fn () => assert_true( $jail->resolve( 'sub/new/file.txt' ) === $root . '/sub/new/file.txt' ) );
check( 'symlink inside root is allowed', fn () => assert_true( $jail->resolve( 'inside-link/a.txt' ) === $root . '/inside-link/a.txt' ) );

$attacks = [
	'../outside/secret.txt',
	'sub/../../outside/secret.txt',
	'..\\outside\\secret.txt',
	'../../../../etc/passwd',
	"sub/a.txt\0.png",
	'escape/secret.txt',
	'escape',
	'escape/../../outside',
	'escape/new-file.php',
];
foreach ( $attacks as $attack ) {
	check( 'blocks ' . json_encode( $attack ), fn () => assert_throws( fn () => $jail->resolve( $attack ), JailException::class ) );
}

check( 'relative() round-trips', fn () => assert_true( $jail->relative( $root . '/sub/a.txt' ) === 'sub/a.txt' ) );
check( 'relative() rejects outside paths', fn () => assert_throws( fn () => $jail->relative( $base . '/outside' ), JailException::class ) );
check( 'relative() rejects sibling prefix', fn () => assert_throws( fn () => $jail->relative( $root . '-evil/x' ), JailException::class ) );
check( 'missing root throws', fn () => assert_throws( fn () => new Jail( $base . '/nope' ), JailException::class ) );

exec( 'rm -rf ' . escapeshellarg( $base ) );
