<?php
/**
 * Vault, Trash and Versions on their own: the pieces every undo relies on.
 */
use PrymeOperator\Operator\Trash;
use PrymeOperator\Operator\Vault;
use PrymeOperator\Operator\Versions;
use PrymeOperator\Security\Jail;
use PrymeOperator\Security\JailException;
use PrymeOperator\Server\OperationException;

$base = sys_get_temp_dir() . '/po-safety-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/root/site/sub', 0777, true );
mkdir( $base . '/outside', 0777, true );
$root  = realpath( $base . '/root' );
$jail  = new Jail( $root );
$vault = new Vault( $root . '/.vault/' );

// ── Vault ───────────────────────────────────────────────────────────────

check( 'vault base drops trailing slash', fn () => assert_true( $vault->base() === $root . '/.vault' ) );
check( 'vault creates its folder with web-deny files', function () use ( $vault ) {
	$dir = $vault->dir( 'trash' );
	assert_true( is_dir( $dir ) );
	foreach ( [ '.htaccess', 'index.php', 'web.config' ] as $guard ) {
		assert_true( is_file( $vault->base() . '/' . $guard ), "missing {$guard}" );
	}
	assert_true( str_contains( (string) file_get_contents( $vault->base() . '/.htaccess' ), 'Require all denied' ) );
} );
check( 'vault restores a deleted .htaccess', function () use ( $vault ) {
	unlink( $vault->base() . '/.htaccess' );
	$vault->ensure();
	assert_true( is_file( $vault->base() . '/.htaccess' ) );
} );
check( 'vault contains itself and its children', function () use ( $vault ) {
	assert_true( $vault->contains( $vault->base() ) );
	assert_true( $vault->contains( $vault->base() . '/trash/x' ) );
	assert_true( $vault->contains( str_replace( '/', '\\', $vault->base() . '/versions' ) ) );
} );
check( 'vault does not claim look-alike siblings', function () use ( $vault, $root ) {
	assert_true( ! $vault->contains( $root . '/.vault-old/file' ) );
	assert_true( ! $vault->contains( $root . '/site' ) );
} );
check( 'vault is matched through a symlinked base', function () use ( $vault, $base ) {
	symlink( $vault->base(), $base . '/vault-link' );
	$linked = new Vault( $base . '/vault-link' );
	assert_true( $linked->contains( $vault->base() . '/trash' ) );
} );

// ── Trash ───────────────────────────────────────────────────────────────

$trash = new Trash( $vault );

check( 'trash moves a file out and remembers it', function () use ( $trash, $root ) {
	file_put_contents( $root . '/site/a.txt', 'hello' );
	$meta = $trash->put( $root . '/site/a.txt', 'site/a.txt', 7 );
	assert_true( ! file_exists( $root . '/site/a.txt' ) );
	assert_true( 'a.txt' === $meta['name'] && 'file' === $meta['type'] && 5 === $meta['size'] && 7 === $meta['user_id'] );
	assert_true( 1 === count( $trash->list() ) );
} );
check( 'trash restores the file with its content', function () use ( $trash, $jail, $root ) {
	$id = $trash->list()[0]['id'];
	assert_true( 'site/a.txt' === $trash->restore( $id, $jail ) );
	assert_true( 'hello' === file_get_contents( $root . '/site/a.txt' ) );
	assert_true( [] === $trash->list() );
} );
check( 'trash restore never overwrites', function () use ( $trash, $jail, $root ) {
	$meta = $trash->put( $root . '/site/a.txt', 'site/a.txt' );
	file_put_contents( $root . '/site/a.txt', 'newer' );
	$back = $trash->restore( $meta['id'], $jail );
	assert_true( 'site/a.txt' !== $back );
	assert_true( 'newer' === file_get_contents( $root . '/site/a.txt' ) && 'hello' === file_get_contents( $root . '/' . $back ) );
} );
check( 'trash keeps whole folders', function () use ( $trash, $jail, $root ) {
	file_put_contents( $root . '/site/sub/b.txt', 'bb' );
	$meta = $trash->put( $root . '/site/sub', 'site/sub' );
	assert_true( 'dir' === $meta['type'] && ! is_dir( $root . '/site/sub' ) );
	$trash->restore( $meta['id'], $jail );
	assert_true( 'bb' === file_get_contents( $root . '/site/sub/b.txt' ) );
} );
check( 'trash recreates a missing parent folder', function () use ( $trash, $jail, $root ) {
	mkdir( $root . '/site/gone' );
	file_put_contents( $root . '/site/gone/c.txt', 'c' );
	$meta = $trash->put( $root . '/site/gone/c.txt', 'site/gone/c.txt' );
	rmdir( $root . '/site/gone' );
	assert_true( 'site/gone/c.txt' === $trash->restore( $meta['id'], $jail ) );
} );
check( 'trash lists newest first', function () use ( $trash, $root, $vault ) {
	file_put_contents( $root . '/site/old.txt', 'o' );
	file_put_contents( $root . '/site/new.txt', 'n' );
	$old = $trash->put( $root . '/site/old.txt', 'site/old.txt' );
	$meta = json_decode( file_get_contents( $vault->dir( 'trash' ) . '/' . $old['id'] . '/meta.json' ), true );
	$meta['deleted_at'] -= 100;
	file_put_contents( $vault->dir( 'trash' ) . '/' . $old['id'] . '/meta.json', json_encode( $meta ) );
	$trash->put( $root . '/site/new.txt', 'site/new.txt' );
	$names = array_column( $trash->list(), 'name' );
	assert_true( [ 'new.txt', 'old.txt' ] === $names );
} );
check( 'trash rejects forged ids', function () use ( $trash, $jail ) {
	foreach ( [ '../../etc', '20240101000000-zzzzzzzz', '', '20240101000000-0000000a/../x' ] as $bad ) {
		assert_throws( fn () => $trash->restore( $bad, $jail ), OperationException::class );
		assert_throws( fn () => $trash->purge( $bad ), OperationException::class );
	}
} );
check( 'trash restore stays inside the jail', function () use ( $trash, $jail, $base, $vault ) {
	file_put_contents( $base . '/outside/evil.txt', 'x' );
	$meta = $trash->put( $base . '/outside/evil.txt', 'evil.txt' );
	// A tampered entry pointing outside the root must not be restored there.
	assert_throws( fn () => $trash->restore( $meta['id'], $jail ), JailException::class );
	assert_true( ! is_file( $base . '/outside/evil.txt' ) );
	$trash->purge( $meta['id'] );
} );
check( 'trash purges only expired items', function () use ( $trash, $vault ) {
	// "old.txt" from the previous check becomes two days old; "new.txt" is fresh.
	$old  = array_values( array_filter( $trash->list(), fn ( $i ) => 'old.txt' === $i['name'] ) )[0];
	$file = $vault->dir( 'trash' ) . '/' . $old['id'] . '/meta.json';
	$meta = json_decode( file_get_contents( $file ), true );
	$meta['deleted_at'] = time() - 2 * 86400;
	file_put_contents( $file, json_encode( $meta ) );
	assert_true( 0 === $trash->purge_older_than( 0 ), '0 days means keep forever' );
	assert_true( 1 === $trash->purge_older_than( 1 ) );
	assert_true( [ 'new.txt' ] === array_column( $trash->list(), 'name' ) );
} );
check( 'trash empties everything', function () use ( $trash, $root ) {
	file_put_contents( $root . '/site/z.txt', 'z' );
	$trash->put( $root . '/site/z.txt', 'site/z.txt' );
	assert_true( $trash->empty() >= 1 && [] === $trash->list() );
} );

// ── Versions ────────────────────────────────────────────────────────────

$versions = new Versions( $vault, 3 );

check( 'no version for a file that does not exist yet', fn () => assert_true( null === $versions->snapshot( $root . '/site/none.php', 'site/none.php' ) ) );
check( 'versions keep every earlier content, newest first', function () use ( $versions, $root ) {
	$file = $root . '/site/v.php';
	foreach ( [ 'v1', 'v2' ] as $content ) {
		file_put_contents( $file, $content );
		$versions->snapshot( $file, 'site/v.php', 3 );
	}
	$list = $versions->list( 'site/v.php' );
	assert_true( 2 === count( $list ) );
	assert_true( 'v2' === $versions->content( 'site/v.php', $list[0]['id'] ) && 'v1' === $versions->content( 'site/v.php', $list[1]['id'] ) );
	assert_true( 3 === $list[0]['user_id'] && 2 === $list[0]['size'] );
} );
check( 'versions are pruned to the limit', function () use ( $versions, $root ) {
	$file = $root . '/site/v.php';
	foreach ( [ 'v3', 'v4', 'v5' ] as $content ) {
		file_put_contents( $file, $content );
		$versions->snapshot( $file, 'site/v.php' );
	}
	$list = $versions->list( 'site/v.php' );
	assert_true( 3 === count( $list ) );
	assert_true( 'v5' === $versions->content( 'site/v.php', $list[0]['id'] ) && 'v3' === $versions->content( 'site/v.php', $list[2]['id'] ) );
} );
check( 'versions of different files do not mix', fn () => assert_true( [] === $versions->list( 'site/other.php' ) ) );
check( 'version ids cannot be forged', function () use ( $versions ) {
	foreach ( [ '../../../etc/passwd', 'x', '20240101000000-000000abcd/../../x' ] as $bad ) {
		assert_throws( fn () => $versions->content( 'site/v.php', $bad ), OperationException::class );
	}
} );
check( 'a limit of 0 keeps all versions', function () use ( $vault, $root ) {
	$all  = new Versions( $vault, 0 );
	$file = $root . '/site/all.txt';
	for ( $i = 0; $i < 5; $i++ ) {
		file_put_contents( $file, (string) $i );
		$all->snapshot( $file, 'site/all.txt' );
	}
	assert_true( 5 === count( $all->list( 'site/all.txt' ) ) );
} );
