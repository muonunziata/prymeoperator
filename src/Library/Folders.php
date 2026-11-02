<?php
namespace PrymeOperator\Library;

/**
 * Virtual folders for the Media Library, Pages, Posts and other post types.
 *
 * Folders never touch files or posts: an item belongs to at most one folder
 * and deleting a folder only releases its items ("Unassigned") and lifts its
 * subfolders one level up. Nothing is ever destroyed.
 */
final class Folders {

	public static function folders_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prymeoperator_folders';
	}

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'prymeoperator_folder_items';
	}

	/**
	 * @return array<int, array{id:int, parent_id:int, name:string}>
	 */
	public static function all( string $scope ): array {
		global $wpdb;
		$table = self::folders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, parent_id, name FROM {$table} WHERE scope = %s", $scope ), ARRAY_A ) ?: [];
		$rows = array_map(
			static fn ( array $r ): array => [ 'id' => (int) $r['id'], 'parent_id' => (int) $r['parent_id'], 'name' => (string) $r['name'] ],
			$rows
		);
		usort( $rows, static fn ( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	/**
	 * @return array{id:int, parent_id:int, name:string, scope:string}|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::folders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, parent_id, name, scope FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return [ 'id' => (int) $row['id'], 'parent_id' => (int) $row['parent_id'], 'name' => (string) $row['name'], 'scope' => (string) $row['scope'] ];
	}

	public static function create( string $scope, string $name, int $parent ): int {
		global $wpdb;
		$name = Tree::clean_name( $name );
		if ( '' === $name ) {
			throw new LibraryException( 'The folder needs a name.' );
		}
		$rows = self::all( $scope );
		if ( $parent && ! Tree::exists( $rows, $parent ) ) {
			throw new LibraryException( 'That folder no longer exists.', 404 );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::folders_table(),
			[
				'parent_id'  => $parent,
				'scope'      => $scope,
				'name'       => Tree::unique_name( $rows, $parent, $name ),
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Renames and/or moves a folder. Returns the stored row.
	 *
	 * @return array{id:int, parent_id:int, name:string, scope:string}
	 */
	public static function update( int $id, ?string $name, ?int $parent ): array {
		global $wpdb;
		$folder = self::require( $id );
		$rows   = self::all( $folder['scope'] );

		$new_parent = null === $parent ? $folder['parent_id'] : $parent;
		if ( ! Tree::can_move( $rows, $id, $new_parent ) ) {
			throw new LibraryException( 'A folder cannot be moved inside itself.' );
		}
		$new_name = null === $name ? $folder['name'] : Tree::clean_name( $name );
		if ( '' === $new_name ) {
			throw new LibraryException( 'The folder needs a name.' );
		}
		$new_name = Tree::unique_name( $rows, $new_parent, $new_name, $id );

		$wpdb->update( self::folders_table(), [ 'name' => $new_name, 'parent_id' => $new_parent ], [ 'id' => $id ], [ '%s', '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_merge( $folder, [ 'name' => $new_name, 'parent_id' => $new_parent ] );
	}

	/**
	 * @return array{children:int, released:int}
	 */
	public static function delete( int $id ): array {
		global $wpdb;
		$folder = self::require( $id );
		$ft     = self::folders_table();
		$it     = self::items_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$children = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$ft} SET parent_id = %d WHERE parent_id = %d", $folder['parent_id'], $id ) );
		$released = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$it} WHERE folder_id = %d", $id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ft} WHERE id = %d", $id ) );
		// phpcs:enable

		// Lifted subfolders may now clash with a sibling's name.
		$rows = self::all( $folder['scope'] );
		foreach ( $rows as $row ) {
			if ( $row['parent_id'] === $folder['parent_id'] ) {
				$unique = Tree::unique_name( $rows, $row['parent_id'], $row['name'], $row['id'] );
				if ( $unique !== $row['name'] ) {
					$wpdb->update( $ft, [ 'name' => $unique ], [ 'id' => $row['id'] ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					foreach ( $rows as &$r ) {
						if ( $r['id'] === $row['id'] ) {
							$r['name'] = $unique;
						}
					}
					unset( $r );
				}
			}
		}

		return [ 'children' => max( 0, $children ), 'released' => max( 0, $released ) ];
	}

	/**
	 * Puts the given posts in $folder, or takes them out of any folder when $folder is 0.
	 *
	 * @param int[] $ids
	 */
	public static function assign( array $ids, int $folder ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$it = self::items_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $folder ) {
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$it} WHERE object_id IN ({$in})" );
			return count( $ids );
		}
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$values = implode( ',', array_map( static fn ( int $id ) => $wpdb->prepare( '(%d,%d)', $id, $folder ), $chunk ) );
			$wpdb->query( "REPLACE INTO {$it} (object_id, folder_id) VALUES {$values}" );
		}
		// phpcs:enable
		return count( $ids );
	}

	public static function folder_of( int $object_id ): int {
		global $wpdb;
		$it = self::items_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT folder_id FROM {$it} WHERE object_id = %d", $object_id ) );
	}

	public static function forget( int $object_id ): void {
		global $wpdb;
		$wpdb->delete( self::items_table(), [ 'object_id' => $object_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Items per folder, plus the scope's total and unassigned count, using the
	 * same statuses the list screens show under "All".
	 *
	 * @return array{folders: array<int, int>, total: int, unassigned: int}
	 */
	public static function counts( string $scope ): array {
		global $wpdb;
		$ft       = self::folders_table();
		$it       = self::items_table();
		$statuses = "p.post_status NOT IN ('trash','auto-draft')";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$per = $wpdb->get_results( $wpdb->prepare(
			"SELECT i.folder_id, COUNT(*) AS n FROM {$it} i
			 INNER JOIN {$ft} f ON f.id = i.folder_id AND f.scope = %s
			 INNER JOIN {$wpdb->posts} p ON p.ID = i.object_id AND p.post_type = %s AND {$statuses}
			 GROUP BY i.folder_id",
			$scope,
			$scope
		), ARRAY_A ) ?: [];
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = %s AND {$statuses}", $scope ) );
		// phpcs:enable

		$folders = [];
		$filed   = 0;
		foreach ( $per as $row ) {
			$folders[ (int) $row['folder_id'] ] = (int) $row['n'];
			$filed += (int) $row['n'];
		}
		return [ 'folders' => $folders, 'total' => $total, 'unassigned' => max( 0, $total - $filed ) ];
	}

	// ── MediaFlow Pro import ────────────────────────────────────────────────

	public static function mediaflow_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mfp_folders';
		// A plain count instead of SHOW TABLES works on every database drop-in;
		// a missing or empty table both mean there is nothing to import.
		$quiet = $wpdb->suppress_errors( true );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $quiet );
		return $count > 0;
	}

	/**
	 * Copies MediaFlow Pro's folders and their contents. Smart folders are
	 * skipped (they have no contents of their own). An item that MediaFlow
	 * filed in several folders keeps the first one. Running it twice does not
	 * duplicate anything: folders already imported are reused and items
	 * already in a PrymeOperator folder are left where they are.
	 *
	 * @param string[] $scopes Post types to import.
	 * @return array{folders:int, items:int}
	 */
	public static function import_mediaflow( array $scopes ): array {
		global $wpdb;
		if ( ! self::mediaflow_available() || ! $scopes ) {
			return [ 'folders' => 0, 'items' => 0 ];
		}
		$src  = $wpdb->prefix . 'mfp_folders';
		$rels = $wpdb->prefix . 'mfp_relationships';
		$in   = implode( ',', array_map( static fn ( $s ) => $wpdb->prepare( '%s', $s ), $scopes ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, parent, name, post_type FROM {$src} WHERE is_smart = 0 AND post_type IN ({$in}) ORDER BY parent ASC, id ASC", ARRAY_A ) ?: [];
		$map  = get_option( 'prymeoperator_mediaflow_map', [] );
		$map  = is_array( $map ) ? $map : [];

		$created = 0;
		$pending = $rows;
		// Parents first; repeat until nothing more can be placed.
		for ( $pass = 0; $pending && $pass < 50; $pass++ ) {
			$left = [];
			foreach ( $pending as $row ) {
				$old    = (int) $row['id'];
				$parent = (int) $row['parent'];
				if ( isset( $map[ $old ] ) && self::get( (int) $map[ $old ] ) ) {
					continue;
				}
				if ( $parent && ! isset( $map[ $parent ] ) ) {
					$left[] = $row;
					continue;
				}
				$map[ $old ] = self::create( (string) $row['post_type'], (string) $row['name'] ?: 'Folder', $parent ? (int) $map[ $parent ] : 0 );
				++$created;
			}
			if ( count( $left ) === count( $pending ) ) {
				// Orphans whose parent is gone (or smart) go to the top level.
				foreach ( $left as $row ) {
					$map[ (int) $row['id'] ] = self::create( (string) $row['post_type'], (string) $row['name'] ?: 'Folder', 0 );
					++$created;
				}
				$left = [];
			}
			$pending = $left;
		}
		update_option( 'prymeoperator_mediaflow_map', $map, false );

		$items = 0;
		// Only items of the folder's own type, and never ones already filed here.
		$it    = self::items_table();
		$links = $wpdb->get_results(
			"SELECT r.folder_id, r.post_id FROM {$rels} r
			 INNER JOIN {$src} f ON f.id = r.folder_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = r.post_id AND p.post_type = f.post_type
			 LEFT JOIN {$it} i ON i.object_id = r.post_id
			 WHERE i.object_id IS NULL
			 ORDER BY r.folder_id ASC",
			ARRAY_A
		) ?: [];
		// phpcs:enable
		$by_folder = [];
		$seen      = [];
		foreach ( $links as $link ) {
			$post = (int) $link['post_id'];
			$old  = (int) $link['folder_id'];
			if ( isset( $seen[ $post ] ) || ! isset( $map[ $old ] ) ) {
				continue;
			}
			$seen[ $post ]                    = true;
			$by_folder[ (int) $map[ $old ] ][] = $post;
		}
		foreach ( $by_folder as $folder => $ids ) {
			$items += self::assign( $ids, $folder );
		}

		return [ 'folders' => $created, 'items' => $items ];
	}

	/**
	 * @return array{id:int, parent_id:int, name:string, scope:string}
	 */
	private static function require( int $id ): array {
		$folder = self::get( $id );
		if ( ! $folder ) {
			throw new LibraryException( 'That folder no longer exists.', 404 );
		}
		return $folder;
	}
}
