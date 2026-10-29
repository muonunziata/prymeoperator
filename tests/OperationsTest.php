<?php
use PrymeOperator\Operator\Trash;
use PrymeOperator\Operator\Vault;
use PrymeOperator\Operator\Versions;
use PrymeOperator\Security\Jail;
use PrymeOperator\Security\JailException;
use PrymeOperator\Security\Policy;
use PrymeOperator\Server\Operations;
use PrymeOperator\Server\OperationException;

$base = sys_get_temp_dir() . '/po-ops-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/root/docs', 0777, true );
file_put_contents( $base . '/root/docs/a.txt', 'one' );

$blocked = [ 'php', 'phtml', 'htaccess' ];
$vault   = new Vault( $base . '/root/.vault' );
$jail    = new Jail( $base . '/root' );
$make    = function ( Policy $policy ) use ( $jail, $vault ) {
	return new Operations( $jail, $policy, $vault, new Trash( $vault ), new Versions( $vault, 3 ), 1 );
};
$admin   = $make( new Policy( [], $blocked, true, 0, true ) );
$editor  = $make( new Policy( [ 'upload', 'create', 'rename' ], $blocked, false, 10 ) );
$root    = realpath( $base . '/root' );

check( 'policy blocks double extensions', function () use ( $blocked ) {
	$p = new Policy( [], $blocked );
	assert_true( $p->is_blocked( 'shell.php' ) && $p->is_blocked( 'shell.PHP.jpg' ) && $p->is_blocked( '.htaccess' ) );
	assert_true( ! $p->is_blocked( 'photo.jpg' ) && ! $p->is_blocked( 'php-guide.pdf' ) );
} );

check( 'mkdir and create file', function () use ( $admin, $root ) {
	assert_true( 'docs/new' === $admin->mkdir( 'docs', 'new' ) && is_dir( $root . '/docs/new' ) );
	assert_true( 'docs/new/n.txt' === $admin->create_file( 'docs/new', 'n.txt' ) );
} );
check( 'existing name conflicts', fn () => assert_throws( fn () => $admin->mkdir( 'docs', 'new' ), OperationException::class ) );
check( 'names with slashes rejected', fn () => assert_throws( fn () => $admin->mkdir( 'docs', '../x' ), OperationException::class ) );
check( 'rename', fn () => assert_true( 'docs/b.txt' === $admin->rename( 'docs/a.txt', 'b.txt' ) ) );
check( 'restricted user cannot rename to php', fn () => assert_throws( fn () => $editor->rename( 'docs/b.txt', 'b.php' ), OperationException::class ) );
check( 'restricted user cannot delete without permission', fn () => assert_throws( fn () => $editor->delete( [ 'docs/b.txt' ] ), OperationException::class ) );
check( 'copy gets unique name', function () use ( $admin ) {
	$out = $admin->copy( [ 'docs/b.txt' ], 'docs' );
	assert_true( [ 'docs/b (2).txt' ] === $out, json_encode( $out ) );
} );
check( 'folder cannot be copied into itself', fn () => assert_throws( fn () => $admin->copy( [ 'docs' ], 'docs/new' ), OperationException::class ) );
check( 'move', fn () => assert_true( [ 'docs/new/b (2).txt' ] === $admin->move( [ 'docs/b (2).txt' ], 'docs/new' ) ) );
check( 'root cannot be deleted', fn () => assert_throws( fn () => $admin->delete( [ '' ] ), OperationException::class ) );
check( 'vault is protected', fn () => assert_throws( fn () => $admin->delete( [ '.vault' ] ), JailException::class ) );
check( 'delete goes to trash and restores', function () use ( $admin, $root ) {
	$entries = $admin->delete( [ 'docs/b.txt' ] );
	assert_true( ! file_exists( $root . '/docs/b.txt' ) );
	assert_true( 'docs/b.txt' === $admin->restore( $entries[0]['id'] ) && 'one' === file_get_contents( $root . '/docs/b.txt' ) );
} );
check( 'write keeps versions (pruned to 3)', function () use ( $admin ) {
	foreach ( [ 'two', 'three', 'four', 'five' ] as $text ) {
		$admin->write( 'docs/b.txt', $text );
	}
	$versions = $admin->versions( 'docs/b.txt' );
	assert_true( 3 === count( $versions ), (string) count( $versions ) );
	assert_true( 'four' === $admin->version_content( 'docs/b.txt', $versions[0]['id'] ) );
} );
check( 'php lint finds errors', function () {
	assert_true( null === Operations::lint_php( '<?php echo 1;' ) );
	assert_true( str_starts_with( (string) Operations::lint_php( "<?php\necho ;" ), 'Line 2' ) );
} );
check( 'chmod admin only', function () use ( $admin, $editor, $root ) {
	$admin->chmod( 'docs/b.txt', '0640' );
	assert_true( '0640' === substr( sprintf( '%o', fileperms( $root . '/docs/b.txt' ) ), -4 ) );
	assert_throws( fn () => $editor->chmod( 'docs/b.txt', '0777' ), OperationException::class );
} );
check( 'search by name and content', function () use ( $admin ) {
	assert_true( 1 === count( $admin->search( '', 'b.txt' ) ) );
	assert_true( 1 === count( $admin->search( '', 'five', true ) ) );
	assert_true( 0 === count( $admin->search( '', 'Silence' , true ) ), 'vault must not be searched' );
} );
check( 'zip and unzip round trip', function () use ( $admin, $root ) {
	$zip = $admin->compress( [ 'docs/new' ], 'docs', 'pack' );
	assert_true( 'docs/pack.zip' === $zip );
	$out = $admin->extract( $zip );
	assert_true( 'docs/pack' === $out && is_file( $root . '/docs/pack/new/n.txt' ), $out );
} );

$evil = function ( string $entry, string $body = 'x' ) use ( $root ) {
	$file = $root . '/docs/evil-' . bin2hex( random_bytes( 3 ) ) . '.zip';
	$zip  = new ZipArchive();
	$zip->open( $file, ZipArchive::CREATE );
	$zip->addFromString( $entry, $body );
	$zip->close();
	return 'docs/' . basename( $file );
};
check( 'zip slip rejected', fn () => assert_throws( fn () => $admin->extract( $evil( '../../escape.txt' ) ), OperationException::class ) );
check( 'absolute zip entry rejected', fn () => assert_throws( fn () => $admin->extract( $evil( '/tmp/escape.txt' ) ), OperationException::class ) );
check( 'restricted user cannot unzip php', function () use ( $make, $blocked, $evil ) {
	$archiver = $make( new Policy( [ 'archive' ], $blocked ) );
	assert_throws( fn () => $archiver->extract( $evil( 'inner/shell.php' ) ), OperationException::class );
} );
check( 'zip bomb rejected', fn () => assert_throws( fn () => $admin->extract( $evil( 'big.txt', str_repeat( '0', 20 * 1024 * 1024 ) ) ), OperationException::class ) );

check( 'chunked upload assembles in order', function () use ( $admin, $base, $root ) {
	$id = bin2hex( random_bytes( 8 ) );
	foreach ( [ 'hel', 'lo' ] as $i => $part ) {
		file_put_contents( $base . '/chunk', $part );
		$res = $admin->upload_chunk( 'docs', 'up.txt', $id, $i, 2, $base . '/chunk' );
	}
	assert_true( $res['done'] && 'hello' === file_get_contents( $root . '/' . $res['path'] ) );
} );
check( 'upload rejects out-of-order chunk', function () use ( $admin, $base ) {
	file_put_contents( $base . '/chunk', 'x' );
	assert_throws( fn () => $admin->upload_chunk( 'docs', 'z.txt', bin2hex( random_bytes( 8 ) ), 1, 2, $base . '/chunk' ), OperationException::class );
} );
check( 'upload enforces blocked types and size', function () use ( $editor, $base ) {
	file_put_contents( $base . '/chunk', str_repeat( 'x', 20 ) );
	assert_throws( fn () => $editor->upload_chunk( 'docs', 'x.php', bin2hex( random_bytes( 8 ) ), 0, 1, $base . '/chunk' ), OperationException::class );
	assert_throws( fn () => $editor->upload_chunk( 'docs', 'big.txt', bin2hex( random_bytes( 8 ) ), 0, 1, $base . '/chunk' ), OperationException::class );
} );

exec( 'rm -rf ' . escapeshellarg( $base ) );
