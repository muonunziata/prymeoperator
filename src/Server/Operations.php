<?php
namespace PrymeOperator\Server;

use PrymeOperator\Operator\Trash;
use PrymeOperator\Operator\Vault;
use PrymeOperator\Operator\Versions;
use PrymeOperator\Security\Jail;
use PrymeOperator\Security\JailException;
use PrymeOperator\Security\Policy;

/**
 * Every change to the server filesystem goes through here: paths through the
 * Jail, permissions and file types through the Policy, deletes into the Trash
 * and overwrites into Versions. No WordPress dependency.
 */
final class Operations {

	public const ZIP_MAX_ENTRIES = 20000;
	public const ZIP_MAX_BYTES   = 2147483648; // 2 GB uncompressed.
	public const ZIP_MAX_RATIO   = 200;
	public const SEARCH_LIMIT    = 500;

	public function __construct(
		private Jail $jail,
		private Policy $policy,
		private Vault $vault,
		private Trash $trash,
		private Versions $versions,
		private int $user_id = 0,
	) {}

	public function mkdir( string $dir, string $name ): string {
		$this->need( 'create' );
		$parent = $this->existing_dir( $dir );
		$name   = Fs::clean_name( $name );
		$target = $this->free_target( $parent, $name );
		if ( ! mkdir( $target, 0755 ) ) {
			throw new OperationException( 'Could not create folder.', 500 );
		}
		return $this->jail->relative( $target );
	}

	public function create_file( string $dir, string $name ): string {
		$this->need( 'create' );
		$parent = $this->existing_dir( $dir );
		$name   = $this->allowed_name( $name );
		$target = $this->free_target( $parent, $name );
		if ( false === file_put_contents( $target, '' ) ) {
			throw new OperationException( 'Could not create file.', 500 );
		}
		return $this->jail->relative( $target );
	}

	public function rename( string $path, string $name ): string {
		$this->need( 'rename' );
		$from   = $this->existing( $path, false );
		$name   = is_dir( $from ) ? Fs::clean_name( $name ) : $this->allowed_name( $name );
		$target = $this->free_target( dirname( $from ), $name, $from );
		if ( $target !== $from && ! rename( $from, $target ) ) {
			throw new OperationException( 'Could not rename.', 500 );
		}
		return $this->jail->relative( $target );
	}

	/**
	 * @param string[] $paths
	 * @return string[] New relative paths.
	 */
	public function copy( array $paths, string $dest ): array {
		$this->need( 'copy' );
		$to  = $this->existing_dir( $dest );
		$out = [];
		foreach ( $paths as $path ) {
			$from = $this->existing( $path, false );
			$this->not_inside( $to, $from );
			$target = $to . '/' . Fs::unique( $to, basename( $from ) );
			Fs::copy( $from, $target );
			$out[] = $this->jail->relative( $target );
		}
		return $out;
	}

	/**
	 * @param string[] $paths
	 * @return string[] New relative paths.
	 */
	public function move( array $paths, string $dest ): array {
		$this->need( 'move' );
		$to  = $this->existing_dir( $dest );
		$out = [];
		foreach ( $paths as $path ) {
			$from = $this->existing( $path, false );
			if ( dirname( $from ) === $to ) {
				$out[] = $this->jail->relative( $from );
				continue;
			}
			$this->not_inside( $to, $from );
			$target = $to . '/' . Fs::unique( $to, basename( $from ) );
			Fs::move( $from, $target );
			$out[] = $this->jail->relative( $target );
		}
		return $out;
	}

	/**
	 * Moves items to the trash.
	 *
	 * @param string[] $paths
	 * @return array<int, array<string, mixed>> Trash entries.
	 */
	public function delete( array $paths ): array {
		$this->need( 'delete' );
		$out = [];
		foreach ( $paths as $path ) {
			$abs   = $this->existing( $path, false );
			$out[] = $this->trash->put( $abs, $this->jail->relative( $abs ), $this->user_id );
		}
		return $out;
	}

	public function restore( string $trash_id ): string {
		$this->need( 'delete' );
		return $this->trash->restore( $trash_id, $this->jail );
	}

	/**
	 * Overwrites a text file, keeping the previous content as a version.
	 *
	 * @return array<string, mixed>|null The version that was saved.
	 */
	public function write( string $path, string $content ): ?array {
		$this->need( 'edit' );
		$abs = $this->existing( $path, false );
		if ( ! is_file( $abs ) ) {
			throw new OperationException( 'Not a file.', 400 );
		}
		if ( Policy::is_code( $abs ) && ! $this->policy->is_admin() ) {
			throw new OperationException( 'Only administrators can edit code files.', 403 );
		}
		if ( ! is_writable( $abs ) ) {
			throw new OperationException( 'File is not writable.', 403 );
		}
		$version = $this->versions->snapshot( $abs, $this->jail->relative( $abs ), $this->user_id );
		if ( false === file_put_contents( $abs, $content, LOCK_EX ) ) {
			throw new OperationException( 'Could not save file.', 500 );
		}
		// Without this, OPcache may keep running the old code for a few seconds,
		// which would also hide a broken save from the health check.
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $abs, true );
		}
		clearstatcache( true, $abs );
		return $version;
	}

	public function versions( string $path ): array {
		$abs = $this->existing( $path, false );
		return $this->versions->list( $this->jail->relative( $abs ) );
	}

	public function version_content( string $path, string $id ): string {
		$abs = $this->existing( $path, false );
		return $this->versions->content( $this->jail->relative( $abs ), $id );
	}

	public function chmod( string $path, string $mode ): void {
		if ( ! $this->policy->is_admin() ) {
			throw new OperationException( 'Only administrators can change permissions.', 403 );
		}
		if ( ! preg_match( '/^0?[0-7]{3}$/', $mode ) ) {
			throw new OperationException( 'Invalid permissions.', 400 );
		}
		$abs = $this->existing( $path, false );
		if ( ! chmod( $abs, octdec( $mode ) ) ) {
			throw new OperationException( 'Could not change permissions.', 500 );
		}
	}

	/**
	 * @param string[] $paths
	 */
	public function compress( array $paths, string $dest, string $name ): string {
		$this->need( 'archive' );
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new OperationException( 'The PHP zip extension is not installed.', 501 );
		}
		$to   = $this->existing_dir( $dest );
		$name = Fs::clean_name( $name );
		if ( ! str_ends_with( strtolower( $name ), '.zip' ) ) {
			$name .= '.zip';
		}
		$target = $to . '/' . Fs::unique( $to, $name );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target, \ZipArchive::CREATE ) ) {
			throw new OperationException( 'Could not create archive.', 500 );
		}
		foreach ( $paths as $path ) {
			$abs = $this->existing( $path, false );
			$this->add_to_zip( $zip, $abs, basename( $abs ) );
		}
		$zip->close();
		return $this->jail->relative( $target );
	}

	/**
	 * Extracts into a new folder next to the archive, never over existing files.
	 * Rejects zip-slip entries, symlinks, blocked file types and zip bombs.
	 */
	public function extract( string $path ): string {
		$this->need( 'archive' );
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new OperationException( 'The PHP zip extension is not installed.', 501 );
		}
		$abs = $this->existing( $path, false );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $abs, \ZipArchive::RDONLY ) ) {
			throw new OperationException( 'Not a valid zip archive.', 400 );
		}

		if ( $zip->numFiles > self::ZIP_MAX_ENTRIES ) {
			throw new OperationException( 'The archive has too many files.', 413 );
		}

		$entries = [];
		$total   = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = str_replace( '\\', '/', (string) $stat['name'] );
			if ( str_starts_with( $name, '__MACOSX/' ) ) {
				continue;
			}
			$zip->getExternalAttributesIndex( $i, $os, $attr );
			if ( \ZipArchive::OPSYS_UNIX === $os && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
				throw new OperationException( 'The archive contains symbolic links.', 400 );
			}
			if ( str_starts_with( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '/^[a-zA-Z]:/', $name ) ) {
				throw new OperationException( 'The archive contains unsafe paths.', 400 );
			}
			$is_dir = str_ends_with( $name, '/' );
			if ( ! $is_dir && $this->policy->is_blocked( $name ) ) {
				throw new OperationException( sprintf( 'The archive contains a blocked file type: %s', basename( $name ) ), 415 );
			}
			$total += (int) $stat['size'];
			if ( $stat['comp_size'] > 0 && $stat['size'] > 10485760 && $stat['size'] / $stat['comp_size'] > self::ZIP_MAX_RATIO ) {
				throw new OperationException( 'The archive looks like a zip bomb.', 413 );
			}
			$entries[] = [ $i, $name, $is_dir ];
		}
		if ( $total > self::ZIP_MAX_BYTES ) {
			throw new OperationException( 'The archive is too large to extract.', 413 );
		}

		$parent = dirname( $abs );
		$folder = $parent . '/' . Fs::unique( $parent, preg_replace( '/\.zip$/i', '', basename( $abs ) ) ?: 'archive' );
		mkdir( $folder, 0755 );
		$base = $this->jail->relative( $folder );

		foreach ( $entries as [ $index, $name, $is_dir ] ) {
			$target = $this->jail->resolve( $base . '/' . $name );
			if ( $is_dir ) {
				if ( ! is_dir( $target ) ) {
					mkdir( $target, 0755, true );
				}
				continue;
			}
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0755, true );
			}
			$in  = $zip->getStream( $zip->getNameIndex( $index ) );
			$out = fopen( $target, 'wb' );
			if ( ! $in || ! $out ) {
				throw new OperationException( 'Could not extract a file.', 500 );
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in );
			fclose( $out );
		}
		$zip->close();
		return $base;
	}

	/**
	 * Finds files and folders by name, and optionally text files by content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $dir, string $query, bool $content = false ): array {
		$query = trim( $query );
		if ( mb_strlen( $query ) < 2 ) {
			throw new OperationException( 'Type at least two characters.', 400 );
		}
		$start   = $this->existing_dir( $dir );
		$found   = [];
		$started = microtime( true );
		$it      = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $start, \FilesystemIterator::SKIP_DOTS ),
				fn ( \SplFileInfo $f ) => ! $f->isLink() && ! $this->vault->contains( $f->getPathname() ) && ( $this->policy->show_hidden() || ! str_starts_with( $f->getFilename(), '.' ) )
			),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $it as $file ) {
			if ( count( $found ) >= self::SEARCH_LIMIT || microtime( true ) - $started > 10 ) {
				break;
			}
			$match = false !== mb_stripos( $file->getFilename(), $query );
			if ( ! $match && $content && $file->isFile() && $file->getSize() <= Explorer::PREVIEW_LIMIT ) {
				$data  = (string) @file_get_contents( $file->getPathname() );
				$match = ! str_contains( $data, "\0" ) && false !== mb_stripos( $data, $query );
			}
			if ( $match ) {
				$found[] = [
					'name' => $file->getFilename(),
					'path' => $this->jail->relative( $file->getPathname() ),
					'type' => $file->isDir() ? 'dir' : 'file',
					'size' => $file->isDir() ? 0 : $file->getSize(),
				];
			}
		}
		return $found;
	}

	public function size( string $path ): int {
		return Fs::size( $this->existing( $path, true ) );
	}

	/**
	 * Receives one piece of a chunked upload. Chunks must arrive in order.
	 *
	 * @return array{done: bool, path?: string}
	 */
	public function upload_chunk( string $dir, string $name, string $upload_id, int $index, int $total, string $chunk_file ): array {
		$this->need( 'upload' );
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $upload_id ) || $index < 0 || $total < 1 || $total > 100000 || $index >= $total ) {
			throw new OperationException( 'Invalid upload.', 400 );
		}
		$tmp   = $this->vault->dir( 'tmp' ) . '/' . $this->user_id . '-' . $upload_id;
		$state = is_file( $tmp . '.json' ) ? json_decode( (string) file_get_contents( $tmp . '.json' ), true ) : null;

		if ( 0 === $index ) {
			$this->existing_dir( $dir );
			$this->allowed_name( $name );
			$state = [ 'dir' => $dir, 'name' => $name, 'next' => 0, 'size' => 0, 'total' => $total ];
			@unlink( $tmp . '.part' );
		}
		if ( ! is_array( $state ) || $state['next'] !== $index || $state['total'] !== $total ) {
			throw new OperationException( 'Upload chunk out of order.', 409 );
		}

		$size = (int) filesize( $chunk_file );
		$max  = $this->policy->max_upload();
		if ( $max > 0 && $state['size'] + $size > $max ) {
			@unlink( $tmp . '.part' );
			@unlink( $tmp . '.json' );
			throw new OperationException( 'The file is larger than the upload limit.', 413 );
		}

		$in  = fopen( $chunk_file, 'rb' );
		$out = fopen( $tmp . '.part', 'ab' );
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );

		$state['next']++;
		$state['size'] += $size;

		if ( $state['next'] < $total ) {
			file_put_contents( $tmp . '.json', json_encode( $state ) );
			return [ 'done' => false ];
		}

		$parent = $this->existing_dir( $state['dir'] );
		$target = $parent . '/' . Fs::unique( $parent, $this->allowed_name( $state['name'] ) );
		Fs::move( $tmp . '.part', $target );
		@unlink( $tmp . '.json' );
		return [ 'done' => true, 'path' => $this->jail->relative( $target ) ];
	}

	/**
	 * Returns a parse error message for PHP code, or null when it is valid.
	 */
	public static function lint_php( string $code ): ?string {
		try {
			token_get_all( $code, TOKEN_PARSE );
			return null;
		} catch ( \ParseError $e ) {
			return sprintf( 'Line %d: %s', $e->getLine(), $e->getMessage() );
		}
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private function need( string $permission ): void {
		if ( ! $this->policy->allows( $permission ) ) {
			throw new OperationException( 'You do not have permission for this action.', 403 );
		}
	}

	/**
	 * Resolves a path that must exist. The root itself and the vault are off limits
	 * for destructive actions.
	 */
	private function existing( string $relative, bool $allow_root ): string {
		$abs = $this->jail->resolve( $relative );
		if ( $this->vault->contains( $abs ) ) {
			throw new JailException( 'Path is protected.' );
		}
		if ( ! file_exists( $abs ) && ! is_link( $abs ) ) {
			throw new OperationException( 'Not found.', 404 );
		}
		if ( ! $allow_root && $abs === $this->jail->root() ) {
			throw new OperationException( 'The root folder cannot be changed.', 403 );
		}
		return $abs;
	}

	private function existing_dir( string $relative ): string {
		$abs = $this->existing( $relative, true );
		if ( ! is_dir( $abs ) ) {
			throw new OperationException( 'Not a folder.', 400 );
		}
		return $abs;
	}

	private function allowed_name( string $name ): string {
		$name = Fs::clean_name( $name );
		if ( $this->policy->is_blocked( $name ) ) {
			throw new OperationException( 'This file type is not allowed.', 415 );
		}
		return $name;
	}

	private function free_target( string $parent, string $name, string $self = '' ): string {
		$target = $parent . '/' . $name;
		$this->jail->resolve( $this->jail->relative( $target ) );
		if ( $target !== $self && file_exists( $target ) && strtolower( $target ) !== strtolower( $self ) ) {
			throw new OperationException( 'An item with that name already exists.', 409 );
		}
		return $target;
	}

	private function not_inside( string $dest, string $source ): void {
		if ( is_dir( $source ) && ( $dest === $source || str_starts_with( $dest . '/', $source . '/' ) ) ) {
			throw new OperationException( 'A folder cannot be placed inside itself.', 400 );
		}
	}

	private function add_to_zip( \ZipArchive $zip, string $abs, string $local ): void {
		if ( is_link( $abs ) || $this->vault->contains( $abs ) ) {
			return;
		}
		if ( is_dir( $abs ) ) {
			$zip->addEmptyDir( $local );
			foreach ( new \DirectoryIterator( $abs ) as $item ) {
				if ( ! $item->isDot() ) {
					$this->add_to_zip( $zip, $item->getPathname(), $local . '/' . $item->getFilename() );
				}
			}
			return;
		}
		$zip->addFile( $abs, $local );
	}
}
