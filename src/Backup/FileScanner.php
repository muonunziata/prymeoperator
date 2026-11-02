<?php
namespace PrymeOperator\Backup;

/**
 * Lists the files a backup should contain. WordPress-free so it can be tested.
 */
final class FileScanner {

	/**
	 * Walks $start and writes one path per line, relative to $base, to
	 * $list_file. Returns the number of files and their total size. Symlinks
	 * are never followed and excluded folders (absolute paths) are skipped.
	 *
	 * @param string[] $exclude Absolute folders to leave out.
	 * @return array{files:int, bytes:int}
	 */
	public static function scan( string $base, string $start, array $exclude, string $list_file ): array {
		$root    = rtrim( str_replace( '\\', '/', $base ), '/' );
		$start   = rtrim( str_replace( '\\', '/', $start ), '/' );
		if ( $start !== $root && ! str_starts_with( $start, $root . '/' ) ) {
			throw new BackupException( 'The folder to back up must be inside the base folder.' );
		}
		$exclude = array_values( array_filter( array_map( static fn ( $p ) => rtrim( str_replace( '\\', '/', (string) $p ), '/' ), $exclude ) ) );
		$out     = fopen( $list_file, 'wb' );
		if ( ! $out ) {
			throw new BackupException( 'Could not write the backup file list.' );
		}

		$files = 0;
		$bytes = 0;
		$stack = [ $start ];
		while ( $stack ) {
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir );
			if ( false === $entries ) {
				continue; // Unreadable folder: back up what we can.
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_link( $path ) || self::excluded( $path, $exclude ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$stack[] = $path;
				} elseif ( is_file( $path ) && is_readable( $path ) ) {
					// Newlines in names would break the one-path-per-line list.
					if ( str_contains( $name, "\n" ) || str_contains( $name, "\r" ) ) {
						continue;
					}
					fwrite( $out, substr( $path, strlen( $root ) + 1 ) . "\n" );
					++$files;
					$bytes += (int) @filesize( $path );
				}
			}
		}
		fclose( $out );
		return [ 'files' => $files, 'bytes' => $bytes ];
	}

	/**
	 * @param string[] $exclude
	 */
	public static function excluded( string $path, array $exclude ): bool {
		foreach ( $exclude as $ex ) {
			if ( $path === $ex || str_starts_with( $path, $ex . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
