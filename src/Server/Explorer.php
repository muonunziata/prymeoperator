<?php
namespace PrymeOperator\Server;

use PrymeOperator\Security\Jail;

/**
 * Read-only view of the server filesystem, always through the Jail.
 * No WordPress dependency so it can be unit tested directly.
 */
final class Explorer {

	public const PREVIEW_LIMIT = 1048576; // 1 MB.

	public function __construct( private Jail $jail ) {}

	/**
	 * @return array{path: string, entries: array<int, array<string, mixed>>}
	 */
	public function list( string $relative ): array {
		$dir = $this->jail->resolve( $relative );
		if ( ! is_dir( $dir ) ) {
			throw new ExplorerException( 'Not a directory.', 404 );
		}
		if ( ! is_readable( $dir ) ) {
			throw new ExplorerException( 'Directory is not readable.', 403 );
		}

		$entries = [];
		foreach ( new \DirectoryIterator( $dir ) as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			try {
				// Skip symlinks that point outside the root rather than leaking them.
				$this->jail->resolve( $this->jail->relative( $item->getPathname() ) );
			} catch ( \RuntimeException ) {
				continue;
			}
			$entries[] = $this->describe( $item->getPathname() );
		}

		usort(
			$entries,
			static fn ( array $a, array $b ): int => [ 'dir' !== $a['type'], strtolower( $a['name'] ) ] <=> [ 'dir' !== $b['type'], strtolower( $b['name'] ) ]
		);

		return [ 'path' => $this->jail->relative( $dir ), 'entries' => $entries ];
	}

	/**
	 * Text preview of a file. Binary or oversized files return content null.
	 *
	 * @return array<string, mixed>
	 */
	public function read( string $relative ): array {
		$file = $this->jail->resolve( $relative );
		if ( ! is_file( $file ) ) {
			throw new ExplorerException( 'Not a file.', 404 );
		}
		if ( ! is_readable( $file ) ) {
			throw new ExplorerException( 'File is not readable.', 403 );
		}

		$info    = $this->describe( $file );
		$content = null;
		$reason  = null;

		if ( $info['size'] > self::PREVIEW_LIMIT ) {
			$reason = 'too_large';
		} else {
			$data = (string) file_get_contents( $file );
			if ( str_contains( $data, "\0" ) || ! mb_check_encoding( $data, 'UTF-8' ) ) {
				$reason = 'binary';
			} else {
				$content = $data;
			}
		}

		return $info + [ 'content' => $content, 'reason' => $reason ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describe( string $absolute ): array {
		$is_dir = is_dir( $absolute );
		return [
			'name'     => basename( $absolute ),
			'path'     => $this->jail->relative( $absolute ),
			'type'     => $is_dir ? 'dir' : 'file',
			'ext'      => $is_dir ? '' : strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ),
			'size'     => $is_dir ? 0 : (int) @filesize( $absolute ),
			'modified' => (int) @filemtime( $absolute ),
			'perms'    => substr( sprintf( '%o', (int) @fileperms( $absolute ) ), -4 ),
			'writable' => is_writable( $absolute ),
			'link'     => is_link( $absolute ),
		];
	}
}
