<?php
namespace PrymeOperator\Security;

/**
 * Confines every filesystem path to one root directory.
 *
 * Paths from the browser are always relative to the root. They are normalised
 * lexically first (so "../" can never climb above the root), then the nearest
 * existing ancestor is resolved with realpath() so a symlink pointing outside
 * the root is rejected too. Has no WordPress dependency, so it is unit tested
 * on its own.
 */
final class Jail {

	private string $root;

	public function __construct( string $root ) {
		$real = realpath( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new JailException( 'Jail root does not exist.' );
		}
		$this->root = rtrim( str_replace( '\\', '/', $real ), '/' );
		if ( '' === $this->root ) {
			$this->root = '/';
		}
	}

	public function root(): string {
		return $this->root;
	}

	/**
	 * Returns the absolute path for a root-relative path, or throws.
	 * The target does not need to exist (so it can be used for new files).
	 */
	public function resolve( string $relative ): string {
		if ( str_contains( $relative, "\0" ) ) {
			throw new JailException( 'Invalid path.' );
		}

		$segments = [];
		foreach ( explode( '/', str_replace( '\\', '/', $relative ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( [] === $segments ) {
					throw new JailException( 'Path escapes the allowed root.' );
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		$candidate = $this->join( $segments );

		// Walk up to the nearest existing ancestor and make sure its real
		// location (symlinks followed) is still inside the root.
		$probe = $segments;
		while ( true ) {
			$path = $this->join( $probe );
			if ( file_exists( $path ) || is_link( $path ) ) {
				$real = realpath( $path );
				if ( false === $real || ! $this->contains( str_replace( '\\', '/', $real ) ) ) {
					throw new JailException( 'Path escapes the allowed root.' );
				}
				break;
			}
			if ( [] === $probe ) {
				break;
			}
			array_pop( $probe );
		}

		return $candidate;
	}

	/**
	 * Converts an absolute path inside the root back to a root-relative one.
	 */
	public function relative( string $absolute ): string {
		$absolute = rtrim( str_replace( '\\', '/', $absolute ), '/' );
		if ( ! $this->contains( $absolute ) ) {
			throw new JailException( 'Path escapes the allowed root.' );
		}
		return ltrim( substr( $absolute, strlen( $this->root ) ), '/' );
	}

	private function contains( string $absolute ): bool {
		if ( '/' === $this->root ) {
			return str_starts_with( $absolute, '/' );
		}
		return $absolute === $this->root || str_starts_with( $absolute, $this->root . '/' );
	}

	private function join( array $segments ): string {
		if ( [] === $segments ) {
			return $this->root;
		}
		return ( '/' === $this->root ? '' : $this->root ) . '/' . implode( '/', $segments );
	}
}
