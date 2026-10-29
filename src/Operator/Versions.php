<?php
namespace PrymeOperator\Operator;

use PrymeOperator\Server\OperationException;

/**
 * Keeps earlier copies of files edited through PrymeOperator, so any save can
 * be compared with or rolled back to a previous one.
 */
final class Versions {

	private string $dir;

	public function __construct( Vault $vault, private int $keep = 10 ) {
		$this->dir = $vault->dir( 'versions' );
	}

	/**
	 * Saves the current content of a file before it is overwritten.
	 *
	 * @return array<string, mixed>|null Null when the file does not exist yet.
	 */
	public function snapshot( string $absolute, string $relative, int $user_id = 0 ): ?array {
		if ( ! is_file( $absolute ) ) {
			return null;
		}
		$folder = $this->folder( $relative );
		if ( ! is_dir( $folder ) ) {
			mkdir( $folder, 0755, true );
			file_put_contents( $folder . '/path.txt', $relative );
		}
		// Sortable id: second + microsecond, so saves within one second keep their order.
		$now = microtime( true );
		$id  = gmdate( 'YmdHis', (int) $now ) . '-' . sprintf( '%06d', (int) ( fmod( $now, 1 ) * 1000000 ) ) . bin2hex( random_bytes( 2 ) );
		copy( $absolute, $folder . '/' . $id . '.bak' );
		file_put_contents( $folder . '/' . $id . '.json', json_encode( [ 'id' => $id, 'created_at' => time(), 'user_id' => $user_id, 'size' => filesize( $absolute ) ] ) );
		$this->prune( $folder );
		return $this->meta( $folder, $id );
	}

	/**
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public function list( string $relative ): array {
		$folder = $this->folder( $relative );
		$items  = [];
		foreach ( glob( $folder . '/*.json' ) ?: [] as $file ) {
			$items[] = $this->meta( $folder, basename( $file, '.json' ) );
		}
		usort( $items, static fn ( $a, $b ) => strcmp( $b['id'], $a['id'] ) );
		return $items;
	}

	public function content( string $relative, string $id ): string {
		return (string) file_get_contents( $this->file( $relative, $id ) );
	}

	private function file( string $relative, string $id ): string {
		$file = $this->folder( $relative ) . '/' . $id . '.bak';
		if ( ! preg_match( '/^\d{14}-\d{6}[a-f0-9]{4}$/', $id ) || ! is_file( $file ) ) {
			throw new OperationException( 'Version not found.', 404 );
		}
		return $file;
	}

	private function folder( string $relative ): string {
		return $this->dir . '/' . sha1( $relative );
	}

	private function meta( string $folder, string $id ): array {
		$meta = json_decode( (string) @file_get_contents( $folder . '/' . $id . '.json' ), true );
		return is_array( $meta ) ? $meta : [ 'id' => $id ];
	}

	private function prune( string $folder ): void {
		if ( $this->keep <= 0 ) {
			return;
		}
		$ids = array_map( static fn ( $f ) => basename( $f, '.json' ), glob( $folder . '/*.json' ) ?: [] );
		rsort( $ids );
		foreach ( array_slice( $ids, $this->keep ) as $old ) {
			@unlink( $folder . '/' . $old . '.json' );
			@unlink( $folder . '/' . $old . '.bak' );
		}
	}
}
