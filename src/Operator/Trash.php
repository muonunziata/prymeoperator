<?php
namespace PrymeOperator\Operator;

use PrymeOperator\Security\Jail;
use PrymeOperator\Server\Fs;
use PrymeOperator\Server\OperationException;

/**
 * Deleted server files are moved here instead of being destroyed, so every
 * delete can be undone until the retention period ends.
 */
final class Trash {

	private string $dir;

	public function __construct( Vault $vault ) {
		$this->dir = $vault->dir( 'trash' );
	}

	/**
	 * @return array<string, mixed> The trash entry.
	 */
	public function put( string $absolute, string $relative, int $user_id = 0 ): array {
		$id    = gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 4 ) );
		$entry = $this->dir . '/' . $id;
		mkdir( $entry, 0755 );

		$meta = [
			'id'         => $id,
			'name'       => basename( $absolute ),
			'original'   => $relative,
			'absolute'   => $absolute,
			'type'       => is_dir( $absolute ) ? 'dir' : 'file',
			'size'       => Fs::size( $absolute ),
			'deleted_at' => time(),
			'user_id'    => $user_id,
		];

		Fs::move( $absolute, $entry . '/payload' );
		file_put_contents( $entry . '/meta.json', json_encode( $meta ) );
		return $meta;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list(): array {
		$items = [];
		foreach ( glob( $this->dir . '/*/meta.json' ) ?: [] as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $meta ) ) {
				$items[] = $meta;
			}
		}
		usort( $items, static fn ( $a, $b ) => $b['deleted_at'] <=> $a['deleted_at'] );
		return $items;
	}

	/**
	 * Puts the item back where it was (with a new name if that spot is taken).
	 *
	 * @return string The restored root-relative path.
	 */
	public function restore( string $id, Jail $jail ): string {
		$entry = $this->entry( $id );
		$meta  = json_decode( (string) file_get_contents( $entry . '/meta.json' ), true );

		// The absolute path lets an administrator restore what a user with a
		// narrower root deleted; relative() + resolve() still enforce the jail.
		$relative = isset( $meta['absolute'] ) ? $jail->relative( (string) $meta['absolute'] ) : (string) $meta['original'];
		$target   = $jail->resolve( $relative );
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) ) {
			mkdir( $parent, 0755, true );
		}
		$target = $parent . '/' . Fs::unique( $parent, basename( $target ) );
		Fs::move( $entry . '/payload', $target );
		Fs::remove( $entry );
		return $jail->relative( $target );
	}

	public function purge( string $id ): void {
		Fs::remove( $this->entry( $id ) );
	}

	public function empty(): int {
		$count = 0;
		foreach ( $this->list() as $item ) {
			$this->purge( $item['id'] );
			$count++;
		}
		return $count;
	}

	public function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		$limit = time() - $days * 86400;
		$count = 0;
		foreach ( $this->list() as $item ) {
			if ( $item['deleted_at'] < $limit ) {
				$this->purge( $item['id'] );
				$count++;
			}
		}
		return $count;
	}

	private function entry( string $id ): string {
		if ( ! preg_match( '/^\d{14}-[a-f0-9]{8}$/', $id ) || ! is_file( $this->dir . '/' . $id . '/meta.json' ) ) {
			throw new OperationException( 'Item not found in trash.', 404 );
		}
		return $this->dir . '/' . $id;
	}
}
