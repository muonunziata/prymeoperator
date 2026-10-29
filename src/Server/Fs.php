<?php
namespace PrymeOperator\Server;

/**
 * Low-level filesystem helpers. Callers resolve every path through the Jail
 * first; these functions never follow symlinks into directories.
 */
final class Fs {

	public static function copy( string $from, string $to ): void {
		if ( is_link( $from ) ) {
			throw new OperationException( 'Symbolic links cannot be copied.', 400 );
		}
		if ( is_dir( $from ) ) {
			if ( ! mkdir( $to, 0755 ) && ! is_dir( $to ) ) {
				throw new OperationException( 'Could not create folder.', 500 );
			}
			foreach ( new \DirectoryIterator( $from ) as $item ) {
				if ( $item->isDot() || $item->isLink() ) {
					continue;
				}
				self::copy( $item->getPathname(), $to . '/' . $item->getFilename() );
			}
			return;
		}
		if ( ! copy( $from, $to ) ) {
			throw new OperationException( 'Could not copy file.', 500 );
		}
	}

	public static function move( string $from, string $to ): void {
		if ( @rename( $from, $to ) ) {
			return;
		}
		// Different filesystems: copy then remove.
		self::copy( $from, $to );
		self::remove( $from );
	}

	public static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! unlink( $path ) ) {
				throw new OperationException( 'Could not delete file.', 500 );
			}
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( new \DirectoryIterator( $path ) as $item ) {
				if ( ! $item->isDot() ) {
					self::remove( $item->getPathname() );
				}
			}
			if ( ! rmdir( $path ) ) {
				throw new OperationException( 'Could not delete folder.', 500 );
			}
		}
	}

	public static function size( string $path ): int {
		if ( is_link( $path ) ) {
			return 0;
		}
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		$total = 0;
		if ( is_dir( $path ) ) {
			foreach ( new \DirectoryIterator( $path ) as $item ) {
				if ( ! $item->isDot() ) {
					$total += self::size( $item->getPathname() );
				}
			}
		}
		return $total;
	}

	/**
	 * "report.pdf" -> "report (2).pdf" until the name is free.
	 */
	public static function unique( string $dir, string $name ): string {
		if ( ! file_exists( $dir . '/' . $name ) ) {
			return $name;
		}
		$dot  = strrpos( $name, '.' );
		$stem = ( false === $dot || 0 === $dot ) ? $name : substr( $name, 0, $dot );
		$ext  = ( false === $dot || 0 === $dot ) ? '' : substr( $name, $dot );
		for ( $i = 2; $i < 10000; $i++ ) {
			$candidate = "{$stem} ({$i}){$ext}";
			if ( ! file_exists( $dir . '/' . $candidate ) ) {
				return $candidate;
			}
		}
		throw new OperationException( 'Could not find a free name.', 409 );
	}

	/**
	 * Accepts a single file or folder name, never a path.
	 */
	public static function clean_name( string $name ): string {
		$name = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', $name ) ?? '' );
		if ( class_exists( \Normalizer::class ) ) {
			$name = \Normalizer::normalize( $name, \Normalizer::FORM_C ) ?: $name;
		}
		if ( '' === $name || '.' === $name || '..' === $name || strpbrk( $name, '/\\' ) !== false || strlen( $name ) > 255 ) {
			throw new OperationException( 'Invalid name.', 400 );
		}
		return $name;
	}
}
