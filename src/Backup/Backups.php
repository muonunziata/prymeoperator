<?php
namespace PrymeOperator\Backup;

use PrymeOperator\Operator\Vault;
use PrymeOperator\Server\Fs;

/**
 * Site backups: the database and/or files in one ZIP, built in short steps
 * so large sites never hit the PHP time limit.
 *
 * Layout in the vault (never reachable from the web):
 *   backups/<id>.zip       finished backup (database.sql + files/…)
 *   backups/<id>.json      its description
 *   backups/work-<id>/     state.json, files.txt and database.sql while running
 */
final class Backups {

	/** Seconds of work per step; the browser (or cron) calls step() again. */
	public const BUDGET = 8;

	/** Bytes added to the ZIP per step: compression happens when it is closed. */
	private const BYTES_PER_STEP = 32 * 1048576;
	private const FILES_PER_STEP = 2000;
	private const ROWS_PER_QUERY = 500;
	private const ROWS_PER_INSERT = 100;
	private const STATEMENTS_PER_STEP = 400;

	/** Already-compressed types are stored as they are, which is much faster. */
	private const STORED = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'webm', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'pdf', 'woff', 'woff2' ];

	private string $dir;

	public function __construct( private Vault $vault ) {
		$this->dir = $vault->dir( 'backups' );
	}

	// ── Listing ─────────────────────────────────────────────────────────────

	/**
	 * @return array<int, array<string, mixed>> Finished backups, newest first.
	 */
	public function list(): array {
		$items = [];
		foreach ( glob( $this->dir . '/*.json' ) ?: [] as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true );
			$id   = basename( $file, '.json' );
			if ( is_array( $meta ) && is_file( $this->dir . '/' . $id . '.zip' ) ) {
				$meta['size'] = (int) filesize( $this->dir . '/' . $id . '.zip' );
				$items[]      = $meta;
			}
		}
		usort( $items, static fn ( $a, $b ) => strcmp( (string) $b['id'], (string) $a['id'] ) );
		return $items;
	}

	public function zip_path( string $id ): string {
		$this->check_id( $id );
		$file = $this->dir . '/' . $id . '.zip';
		if ( ! is_file( $file ) ) {
			throw new BackupException( 'Backup not found.', 404 );
		}
		return $file;
	}

	public function delete( string $id ): void {
		$this->check_id( $id );
		@unlink( $this->dir . '/' . $id . '.zip' );
		@unlink( $this->dir . '/' . $id . '.json' );
		if ( is_dir( $this->dir . '/work-' . $id ) ) {
			Fs::remove( $this->dir . '/work-' . $id );
		}
	}

	/**
	 * Deletes the oldest automatic backups beyond $keep. Manual ones are kept.
	 */
	public function prune_automatic( int $keep ): int {
		if ( $keep <= 0 ) {
			return 0;
		}
		$auto  = array_values( array_filter( $this->list(), static fn ( $b ) => ! empty( $b['automatic'] ) ) );
		$count = 0;
		foreach ( array_slice( $auto, $keep ) as $old ) {
			$this->delete( (string) $old['id'] );
			++$count;
		}
		return $count;
	}

	/**
	 * Running jobs (backups and restores), so the screen can resume them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function running(): array {
		$out = [];
		foreach ( glob( $this->dir . '/work-*/state.json' ) ?: [] as $file ) {
			$state = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $state ) && 'running' === ( $state['status'] ?? '' ) ) {
				$out[] = $this->progress( $state );
			}
		}
		return $out;
	}

	// ── Creating a backup ───────────────────────────────────────────────────

	/**
	 * @param string $files 'none', 'content' (wp-content) or 'all' (the whole WordPress folder).
	 * @return array<string, mixed> Progress of the new job.
	 */
	public function start( string $files, bool $database, string $label = '', bool $automatic = false, int $user_id = 0 ): array {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new BackupException( 'The PHP zip extension is not installed.' );
		}
		if ( ! in_array( $files, [ 'none', 'content', 'all' ], true ) ) {
			throw new BackupException( 'Choose what to back up.' );
		}
		if ( 'none' === $files && ! $database ) {
			throw new BackupException( 'Choose what to back up.' );
		}
		$id   = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
		$work = $this->dir . '/work-' . $id;
		mkdir( $work, 0755, true );

		$state = [
			'kind'       => 'backup',
			'id'         => $id,
			'label'      => mb_substr( trim( wp_strip_all_tags( $label ) ), 0, 120 ),
			'files'      => $files,
			'database'   => $database,
			'automatic'  => $automatic,
			'user_id'    => $user_id,
			'created_at' => time(),
			'status'     => 'running',
			'phase'      => $database ? 'database' : 'scan',
			'tables'     => $database ? $this->tables() : [],
			'table'      => 0,
			'row'        => 0,
			'rows_total' => 0,
			'file_line'  => 0,
			'files_total' => 0,
			'bytes_total' => 0,
			'error'      => '',
		];
		$this->save( $state );
		return $this->progress( $state );
	}

	/**
	 * Does up to BUDGET seconds of work on a job.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function step( string $id ): array {
		$state = $this->load( $id );
		if ( 'running' !== $state['status'] ) {
			return $this->progress( $state );
		}
		$until = microtime( true ) + self::BUDGET;
		try {
			if ( 'restore' === $state['kind'] ) {
				$state = $this->restore_work( $state, $until );
			} else {
				while ( 'running' === $state['status'] && microtime( true ) < $until ) {
					$state = match ( $state['phase'] ) {
						'database' => $this->dump_work( $state, $until ),
						'scan'     => $this->scan_work( $state ),
						'files'    => $this->files_work( $state, $until ),
						'finish'   => $this->finish( $state ),
					};
				}
			}
		} catch ( \Throwable $e ) {
			$state['status'] = 'failed';
			$state['error']  = $e->getMessage();
		}
		$this->save( $state );
		return $this->progress( $state );
	}

	/**
	 * Runs a job to the end (cron, WP-CLI). Returns the final progress.
	 *
	 * @return array<string, mixed>
	 */
	public function run( string $id, ?callable $tick = null ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		do {
			$progress = $this->step( $id );
			if ( $tick ) {
				$tick( $progress );
			}
		} while ( 'running' === $progress['status'] );
		return $progress;
	}

	public function cancel( string $id ): void {
		$state = $this->load( $id );
		if ( 'backup' === $state['kind'] ) {
			$this->delete( $id );
			return;
		}
		// A restore can only be stopped; what was already restored stays.
		$state['status'] = 'failed';
		$state['error']  = 'Restore stopped.';
		$this->save( $state );
	}

	// ── Restoring the database ──────────────────────────────────────────────

	/**
	 * Restores the database of a backup. A database-only safety backup is
	 * taken first so the restore itself can be undone.
	 *
	 * Each table is rebuilt under a temporary name and only swapped in once
	 * it is complete, so the site (and this restore) never sees a half-filled
	 * table. The returned token lets the browser continue the restore even
	 * if the restored data logs the current user out.
	 *
	 * @return array<string, mixed>
	 */
	public function restore_start( string $id, int $user_id = 0 ): array {
		$zip_file = $this->zip_path( $id );
		$zip      = new \ZipArchive();
		if ( true !== $zip->open( $zip_file ) || false === $zip->locateName( 'database.sql' ) ) {
			throw new BackupException( 'This backup has no database.' );
		}
		$zip->close();

		$source = json_decode( (string) @file_get_contents( $this->dir . '/' . $id . '.json' ), true );
		$when   = is_array( $source ) && ! empty( $source['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $source['created_at'] ) : $id;
		/* translators: %s: date of the backup being restored. */
		$safety = $this->start( 'none', true, sprintf( __( 'Safety copy before restoring the backup from %s', 'prymeoperator' ), $when ), false, $user_id );
		$rid    = 'restore-' . $id;
		$work   = $this->dir . '/work-' . $rid;
		if ( is_dir( $work ) ) {
			Fs::remove( $work );
		}
		mkdir( $work, 0755, true );
		$token = bin2hex( random_bytes( 24 ) );
		$state = [
			'kind'       => 'restore',
			'id'         => $rid,
			'source'     => $id,
			'safety'     => $safety['id'],
			'user_id'    => $user_id,
			'sessions'   => $user_id ? get_user_meta( $user_id, 'session_tokens', true ) : null,
			'token'      => hash( 'sha256', $token ),
			'created_at' => time(),
			'status'     => 'running',
			'phase'      => 'safety',
			'offset'     => 0,
			'size'       => 0,
			'table'      => '',
			'statements' => 0,
			'error'      => '',
		];
		$this->save( $state );
		return $this->progress( $state ) + [ 'token' => $token ];
	}

	public function restore_token_ok( string $id, string $token ): bool {
		try {
			$state = $this->load( $id );
		} catch ( BackupException $e ) {
			return false;
		}
		return 'restore' === $state['kind'] && 'running' === $state['status'] && '' !== $token && hash_equals( (string) $state['token'], hash( 'sha256', $token ) );
	}

	private function restore_work( array $state, float $until ): array {
		if ( 'safety' === $state['phase'] ) {
			$safety = $this->step( (string) $state['safety'] );
			if ( 'failed' === $safety['status'] ) {
				throw new BackupException( 'The safety backup failed, so nothing was restored.' );
			}
			if ( 'done' !== $safety['status'] ) {
				return $state;
			}
			// Unpack the SQL next to the job so it can be read with offsets.
			$sql = $this->dir . '/work-' . $state['id'] . '/database.sql';
			if ( ! copy( 'zip://' . $this->zip_path( (string) $state['source'] ) . '#database.sql', $sql ) ) {
				throw new BackupException( 'Could not read the database from the backup.' );
			}
			$state['size']  = (int) filesize( $sql );
			$state['phase'] = 'database';
			return $state;
		}

		global $wpdb;
		$sql = $this->dir . '/work-' . $state['id'] . '/database.sql';
		$in  = fopen( $sql, 'rb' );
		fseek( $in, (int) $state['offset'] );
		$done = 0;
		// Every statement is one line: the dump escapes newlines inside values.
		while ( microtime( true ) < $until && $done < self::STATEMENTS_PER_STEP ) {
			$line = fgets( $in );
			if ( false === $line ) {
				break;
			}
			$state['offset'] = ftell( $in );
			$line            = rtrim( $line, "\r\n" );
			if ( '' === $line || str_starts_with( $line, '-- ' ) ) {
				continue;
			}
			if ( preg_match( '/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`;$/', $line, $m ) ) {
				$state          = $this->swap_in( $state );
				$state['table'] = str_replace( '``', '`', $m[1] );
				$line           = 'DROP TABLE IF EXISTS ' . $this->quote( $this->temp_name( $state['table'] ) ) . ';';
			} elseif ( '' !== $state['table'] ) {
				$live = $this->quote( $state['table'] );
				$temp = $this->quote( $this->temp_name( $state['table'] ) );
				if ( str_starts_with( $line, 'CREATE TABLE ' . $live ) ) {
					$state['create'] = $line; // Needed if the database cannot rename tables.
				}
				foreach ( [ 'CREATE TABLE ', 'INSERT INTO ' ] as $verb ) {
					if ( str_starts_with( $line, $verb . $live ) ) {
						$line = $verb . $temp . substr( $line, strlen( $verb . $live ) );
					}
				}
			}
			if ( false === $wpdb->query( $line ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				throw new BackupException( 'The database could not be restored: ' . $wpdb->last_error );
			}
			++$done;
			++$state['statements'];
		}
		fclose( $in );
		if ( $state['offset'] >= $state['size'] ) {
			$state           = $this->swap_in( $state );
			$state['status'] = 'done';
			$state['phase']  = 'done';
			wp_cache_flush();
		}
		return $state;
	}

	/**
	 * Replaces the live table with the finished temporary copy, then repairs
	 * what must survive a restore: PrymeOperator stays active and the person
	 * restoring stays logged in.
	 */
	private function swap_in( array $state ): array {
		global $wpdb;
		$table = (string) $state['table'];
		if ( '' === $table ) {
			return $state;
		}
		$live = $this->quote( $table );
		$temp = $this->quote( $this->temp_name( $table ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$live}" );
		$wpdb->query( "ALTER TABLE {$temp} RENAME TO {$live}" );
		if ( ! $this->table_exists( $live ) ) {
			// Some database drop-ins (SQLite) accept the rename but ignore it: copy instead.
			if ( empty( $state['create'] ) || false === $wpdb->query( (string) $state['create'] )
				|| false === $wpdb->query( "INSERT INTO {$live} SELECT * FROM {$temp}" ) ) {
				throw new BackupException( 'The database could not be restored: ' . $wpdb->last_error );
			}
			$wpdb->query( "DROP TABLE IF EXISTS {$temp}" );
		}
		// phpcs:enable
		$state['create'] = '';
		$state['table'] = '';
		wp_cache_flush();

		if ( $table === $wpdb->options ) {
			$this->keep_plugin_active();
		}
		if ( $table === $wpdb->usermeta && $state['user_id'] && is_array( $state['sessions'] ) && get_userdata( (int) $state['user_id'] ) ) {
			$current = get_user_meta( (int) $state['user_id'], 'session_tokens', true );
			update_user_meta( (int) $state['user_id'], 'session_tokens', array_merge( is_array( $current ) ? $current : [], $state['sessions'] ) );
		}
		return $state;
	}

	private function table_exists( string $quoted ): bool {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$wpdb->query( "SELECT 1 FROM {$quoted} LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $quiet );
		return $ok;
	}

	private function keep_plugin_active(): void {
		if ( ! defined( 'PRYMEOPERATOR_FILE' ) || ! function_exists( 'plugin_basename' ) ) {
			return;
		}
		$me     = plugin_basename( PRYMEOPERATOR_FILE );
		$active = get_option( 'active_plugins', [] );
		$active = is_array( $active ) ? $active : [];
		if ( ! in_array( $me, $active, true ) ) {
			$active[] = $me;
			update_option( 'active_plugins', $active );
		}
	}

	private function temp_name( string $table ): string {
		return 'po_restore_' . substr( md5( $table ), 0, 16 );
	}

	private function quote( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	// ── Backup phases ───────────────────────────────────────────────────────

	/**
	 * Tables that belong to this site (current table prefix).
	 *
	 * @return string[]
	 */
	private function tables(): array {
		global $wpdb;
		$all = $wpdb->get_col( 'SHOW TABLES' ) ?: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( $all, static fn ( $t ) => str_starts_with( (string) $t, $wpdb->prefix ) && ! str_starts_with( (string) $t, '_' ) ) );
	}

	private function dump_work( array $state, float $until ): array {
		global $wpdb;
		$file = $this->dir . '/work-' . $state['id'] . '/database.sql';
		$out  = fopen( $file, 'ab' );
		if ( 0 === $state['table'] && 0 === $state['row'] && 0 === (int) filesize( $file ) ) {
			fwrite( $out, '-- PrymeOperator database backup ' . gmdate( 'c' ) . "\n-- Prefix: {$wpdb->prefix}\n" );
		}

		while ( microtime( true ) < $until && $state['table'] < count( $state['tables'] ) ) {
			$table = (string) $state['tables'][ $state['table'] ];
			$q     = '`' . str_replace( '`', '``', $table ) . '`';

			if ( 0 === $state['row'] ) {
				$create = $wpdb->get_row( "SHOW CREATE TABLE {$q}", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $create ) {
					++$state['table']; // Gone since the job started.
					continue;
				}
				fwrite( $out, "DROP TABLE IF EXISTS {$q};\n" . preg_replace( '/\s*\n\s*/', ' ', (string) $create[1] ) . ";\n" );
			}

			$order = $this->primary_order( $q );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q}{$order} LIMIT %d OFFSET %d", self::ROWS_PER_QUERY, $state['row'] ), ARRAY_A ) ?: [];
			foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
				$cols   = implode( ',', array_map( static fn ( $c ) => '`' . str_replace( '`', '``', (string) $c ) . '`', array_keys( $chunk[0] ) ) );
				$values = array_map( fn ( array $row ) => '(' . implode( ',', array_map( [ $this, 'sql_value' ], $row ) ) . ')', $chunk );
				fwrite( $out, "INSERT INTO {$q} ({$cols}) VALUES " . implode( ',', $values ) . ";\n" );
			}
			$state['row']        += count( $rows );
			$state['rows_total'] += count( $rows );
			if ( count( $rows ) < self::ROWS_PER_QUERY ) {
				++$state['table'];
				$state['row'] = 0;
			}
		}
		fclose( $out );

		if ( $state['table'] >= count( $state['tables'] ) ) {
			$state['phase'] = 'none' === $state['files'] ? 'finish' : 'scan';
		}
		return $state;
	}

	private function primary_order( string $quoted_table ): string {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$keys  = $wpdb->get_results( "SHOW KEYS FROM {$quoted_table} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $quiet );
		$cols = array_map( static fn ( $k ) => '`' . str_replace( '`', '``', (string) $k['Column_name'] ) . '`', $keys ?: [] );
		return $cols ? ' ORDER BY ' . implode( ',', $cols ) : '';
	}

	/**
	 * One SQL literal. Newlines are written as escapes so that every
	 * statement stays on a single line of the dump.
	 */
	private function sql_value( mixed $value ): string {
		global $wpdb;
		if ( null === $value ) {
			return 'NULL';
		}
		$escaped = $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $value ) );
		return "'" . str_replace( [ "\r", "\n" ], [ '\\r', '\\n' ], $escaped ) . "'";
	}

	private function scan_work( array $state ): array {
		[ $base, $start ] = $this->file_roots( $state['files'] );
		$result                = FileScanner::scan( $base, $start, $this->excluded(), $this->dir . '/work-' . $state['id'] . '/files.txt' );
		$state['files_total']  = $result['files'];
		$state['bytes_total']  = $result['bytes'];
		$state['bytes_done']   = 0;
		$state['phase']        = 'files';
		return $state;
	}

	/**
	 * @return array{0:string, 1:string} Base for relative paths, folder to walk.
	 */
	private function file_roots( string $files ): array {
		$abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		if ( 'all' === $files ) {
			return [ $abspath, $abspath ];
		}
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		return [ dirname( $content ), $content ];
	}

	/**
	 * @return string[]
	 */
	private function excluded(): array {
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$list    = [
			$this->vault->base(),
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/updraft',
			$content . '/ai1wm-backups',
			$content . '/backups-dup-lite',
			$content . '/wpvivid_backup',
		];
		$real = realpath( $this->vault->base() );
		if ( $real ) {
			$list[] = str_replace( '\\', '/', $real );
		}
		return (array) apply_filters( 'prymeoperator_backup_exclude', $list );
	}

	private function files_work( array $state, float $until ): array {
		[ $base ] = $this->file_roots( $state['files'] );
		$list     = fopen( $this->dir . '/work-' . $state['id'] . '/files.txt', 'rb' );
		$zip      = new \ZipArchive();
		if ( true !== $zip->open( $this->dir . '/work-' . $state['id'] . '/backup.zip', \ZipArchive::CREATE ) ) {
			throw new BackupException( 'Could not write the backup file.' );
		}

		fseek( $list, (int) ( $state['file_offset'] ?? 0 ) );
		$added = 0;
		$bytes = 0;
		$eof   = false;
		while ( $added < self::FILES_PER_STEP && $bytes < self::BYTES_PER_STEP && microtime( true ) < $until ) {
			$line = fgets( $list );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			++$state['file_line'];
			$state['file_offset'] = ftell( $list );
			$rel                  = rtrim( $line, "\n" );
			$path = $base . '/' . $rel;
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue; // Removed or locked since the scan.
			}
			$name = 'files/' . $rel;
			$zip->addFile( $path, $name );
			if ( in_array( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ), self::STORED, true ) ) {
				$zip->setCompressionName( $name, \ZipArchive::CM_STORE );
			}
			$size                 = (int) @filesize( $path );
			$bytes               += $size;
			$state['bytes_done'] += $size;
			++$added;
		}
		fclose( $list );
		if ( ! $zip->close() && $added ) {
			throw new BackupException( 'Could not write the backup file.' );
		}
		if ( $eof || $state['file_line'] >= $state['files_total'] ) {
			$state['phase'] = 'finish';
		}
		return $state;
	}

	private function finish( array $state ): array {
		$work = $this->dir . '/work-' . $state['id'];
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $work . '/backup.zip', \ZipArchive::CREATE ) ) {
			throw new BackupException( 'Could not write the backup file.' );
		}
		if ( $state['database'] ) {
			$zip->addFile( $work . '/database.sql', 'database.sql' );
		}
		$info = [
			'plugin'    => 'PrymeOperator',
			'created'   => gmdate( 'c', (int) $state['created_at'] ),
			'site'      => function_exists( 'home_url' ) ? home_url() : '',
			'wordpress' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'files'     => $state['files'],
			'database'  => $state['database'],
		];
		$zip->addFromString( 'backup.json', (string) json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! $zip->close() ) {
			throw new BackupException( 'Could not write the backup file.' );
		}

		rename( $work . '/backup.zip', $this->dir . '/' . $state['id'] . '.zip' );
		file_put_contents( $this->dir . '/' . $state['id'] . '.json', (string) json_encode( [
			'id'         => $state['id'],
			'label'      => $state['label'],
			'files'      => $state['files'],
			'database'   => $state['database'],
			'automatic'  => $state['automatic'],
			'user_id'    => $state['user_id'],
			'created_at' => $state['created_at'],
			'file_count' => $state['files_total'],
			'rows'       => $state['rows_total'],
		] ) );
		Fs::remove( $work );
		$state['status'] = 'done';
		$state['phase']  = 'done';
		return $state;
	}

	// ── State ───────────────────────────────────────────────────────────────

	/**
	 * @return array<string, mixed>
	 */
	public function progress( array $state ): array {
		if ( 'restore' === $state['kind'] ) {
			$percent = match ( $state['phase'] ) {
				'safety'   => 5,
				'database' => 10 + (int) floor( 89 * ( $state['size'] ? $state['offset'] / $state['size'] : 0 ) ),
				default    => 100,
			};
		} else {
			$tables  = max( 1, count( $state['tables'] ) );
			$db_part = $state['database'] ? ( 'none' === $state['files'] ? 95 : 30 ) : 0;
			$percent = match ( $state['phase'] ) {
				'database' => (int) floor( $db_part * $state['table'] / $tables ),
				'scan'     => $db_part,
				'files'    => $db_part + (int) floor( ( 95 - $db_part ) * ( $state['bytes_total'] ? ( $state['bytes_done'] ?? 0 ) / $state['bytes_total'] : 1 ) ),
				'finish'   => 96,
				default    => 100,
			};
		}
		if ( 'done' === $state['status'] ) {
			$percent = 100;
		}
		return [
			'id'      => $state['id'],
			'kind'    => $state['kind'],
			'status'  => $state['status'],
			'phase'   => $state['phase'],
			'percent' => max( 0, min( 100, $percent ) ),
			'error'   => $state['error'],
			'files'   => [ 'done' => (int) ( $state['file_line'] ?? 0 ), 'total' => (int) ( $state['files_total'] ?? 0 ) ],
			'safety'  => $state['safety'] ?? null,
		];
	}

	private function load( string $id ): array {
		$this->check_id( $id );
		$file  = $this->dir . '/work-' . $id . '/state.json';
		$state = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $state ) ) {
			throw new BackupException( 'Backup not found.', 404 );
		}
		return $state;
	}

	private function save( array $state ): void {
		if ( ! is_dir( $this->dir . '/work-' . $state['id'] ) ) {
			return; // Finished backups clean up their working folder.
		}
		file_put_contents( $this->dir . '/work-' . $state['id'] . '/state.json', (string) json_encode( $state ), LOCK_EX );
	}

	private function check_id( string $id ): void {
		if ( ! preg_match( '/^(restore-)?\d{8}-\d{6}-[a-f0-9]{6}$/', $id ) ) {
			throw new BackupException( 'Backup not found.', 404 );
		}
	}
}
