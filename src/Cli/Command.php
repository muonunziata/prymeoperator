<?php
namespace PrymeOperator\Cli;

use PrymeOperator\Backup\BackupException;
use PrymeOperator\Backup\Backups;
use PrymeOperator\Core\Services;
use PrymeOperator\Library\Folders;
use PrymeOperator\Library\Integration;
use PrymeOperator\Library\Tree;
use PrymeOperator\Operator\Audit;
use PrymeOperator\Security\Guard;
use PrymeOperator\Security\Jail;
use PrymeOperator\Server\OperationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage PrymeOperator backups, trash, folders and the activity log.
 *
 * ## EXAMPLES
 *
 *     wp prymeoperator backup create --files=content
 *     wp prymeoperator backup list
 *     wp prymeoperator trash list
 *     wp prymeoperator audit --limit=20
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'prymeoperator backup', [ self::class, 'backup' ], [ 'shortdesc' => 'Create, list, restore or delete site backups.' ] );
		WP_CLI::add_command( 'prymeoperator trash', [ self::class, 'trash' ], [ 'shortdesc' => 'List, restore or empty the PrymeOperator trash.' ] );
		WP_CLI::add_command( 'prymeoperator folders', [ self::class, 'folders' ], [ 'shortdesc' => 'List library folders or import them from MediaFlow Pro.' ] );
		WP_CLI::add_command( 'prymeoperator audit', [ self::class, 'audit' ], [ 'shortdesc' => 'Show the activity log.' ] );
	}

	/**
	 * Create, list, restore or delete site backups.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : create, list, restore or delete.
	 * ---
	 * options:
	 *   - create
	 *   - list
	 *   - restore
	 *   - delete
	 * ---
	 *
	 * [<id>]
	 * : Backup ID (for restore and delete).
	 *
	 * [--files=<files>]
	 * : Files to include when creating.
	 * ---
	 * default: content
	 * options:
	 *   - none
	 *   - content
	 *   - all
	 * ---
	 *
	 * [--no-database]
	 * : Leave the database out of a new backup.
	 *
	 * [--label=<label>]
	 * : A note for the new backup.
	 *
	 * [--yes]
	 * : Do not ask before restoring or deleting.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp prymeoperator backup create --files=all --label="Before the update"
	 *     wp prymeoperator backup restore 20261102-030000-a1b2c3 --yes
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public static function backup( array $args, array $assoc ): void {
		$backups = new Backups( Services::vault() );
		$action  = $args[0];
		$id      = (string) ( $args[1] ?? '' );

		try {
			switch ( $action ) {
				case 'create':
					$job = $backups->start( (string) $assoc['files'], ! Utils\get_flag_value( $assoc, 'no-database', false ), (string) ( $assoc['label'] ?? '' ) );
					Audit::log( 'backup.start', $job['id'], [ 'cli' => true ] );
					$result = self::drive( $backups, $job['id'], 'Creating backup' );
					Audit::log( 'backup.done', $job['id'], [ 'cli' => true ] );
					$size = filesize( $backups->zip_path( $job['id'] ) );
					WP_CLI::success( sprintf( 'Backup %s created (%s, %d files).', $job['id'], size_format( (int) $size ), $result['files']['total'] ) );
					break;

				case 'list':
					$rows = array_map(
						static fn ( array $b ) => [
							'id'       => $b['id'],
							'date'     => wp_date( 'Y-m-d H:i', (int) $b['created_at'] ),
							'database' => $b['database'] ? 'yes' : 'no',
							'files'    => $b['files'],
							'size'     => size_format( (int) $b['size'] ),
							'auto'     => empty( $b['automatic'] ) ? '' : 'yes',
							'label'    => $b['label'],
						],
						$backups->list()
					);
					Utils\format_items( (string) $assoc['format'], $rows, [ 'id', 'date', 'database', 'files', 'size', 'auto', 'label' ] );
					break;

				case 'restore':
					self::need_id( $id );
					WP_CLI::confirm( "Restore the database from backup {$id}? Everything written since then is replaced (a safety backup is made first).", $assoc );
					$job = $backups->restore_start( $id );
					Audit::log( 'backup.restore', $id, [ 'cli' => true, 'safety' => $job['safety'] ] );
					self::drive( $backups, $job['id'], 'Restoring database' );
					Audit::log( 'backup.restored', $id, [ 'cli' => true ] );
					WP_CLI::success( "Database restored. Safety backup: {$job['safety']}." );
					break;

				case 'delete':
					self::need_id( $id );
					$backups->zip_path( $id );
					WP_CLI::confirm( "Delete backup {$id} permanently?", $assoc );
					$backups->delete( $id );
					Audit::log( 'backup.delete', $id, [ 'cli' => true ] );
					WP_CLI::success( "Backup {$id} deleted." );
					break;
			}
		} catch ( BackupException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * List, restore or empty the PrymeOperator trash.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, restore or empty.
	 * ---
	 * options:
	 *   - list
	 *   - restore
	 *   - empty
	 * ---
	 *
	 * [<id>]
	 * : Trash item ID (for restore).
	 *
	 * [--yes]
	 * : Do not ask before emptying.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public static function trash( array $args, array $assoc ): void {
		$trash = Services::trash();
		try {
			switch ( $args[0] ) {
				case 'list':
					$rows = array_map(
						static fn ( array $i ) => [
							'id'       => $i['id'],
							'name'     => $i['name'],
							'original' => '/' . $i['original'],
							'size'     => size_format( (int) $i['size'] ),
							'deleted'  => wp_date( 'Y-m-d H:i', (int) $i['deleted_at'] ),
						],
						$trash->list()
					);
					Utils\format_items( (string) $assoc['format'], $rows, [ 'id', 'name', 'original', 'size', 'deleted' ] );
					break;

				case 'restore':
					self::need_id( (string) ( $args[1] ?? '' ) );
					// The command line works with the full root, like an administrator.
					$path = $trash->restore( (string) $args[1], new Jail( Guard::server_root() ) );
					Audit::log( 'trash.restore', $path, [ 'trash_id' => $args[1], 'cli' => true ] );
					WP_CLI::success( "Restored to /{$path}" );
					break;

				case 'empty':
					WP_CLI::confirm( 'Delete everything in the trash permanently?', $assoc );
					$count = $trash->empty();
					Audit::log( 'trash.empty', (string) $count, [ 'cli' => true ] );
					WP_CLI::success( "{$count} items deleted." );
					break;
			}
		} catch ( OperationException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * List library folders or import them from MediaFlow Pro.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or import-mediaflow.
	 * ---
	 * options:
	 *   - list
	 *   - import-mediaflow
	 * ---
	 *
	 * [--type=<post_type>]
	 * : Post type whose folders to list.
	 * ---
	 * default: attachment
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public static function folders( array $args, array $assoc ): void {
		if ( 'import-mediaflow' === $args[0] ) {
			if ( ! Folders::mediaflow_available() ) {
				WP_CLI::error( 'No MediaFlow Pro folders were found.' );
			}
			$result = Folders::import_mediaflow( Integration::scopes() );
			Audit::log( 'library.import', 'MediaFlow Pro', $result + [ 'cli' => true ] );
			WP_CLI::success( sprintf( 'Imported %d folders and %d items.', $result['folders'], $result['items'] ) );
			return;
		}
		$scope  = sanitize_key( (string) $assoc['type'] );
		$rows   = Folders::all( $scope );
		$counts = Folders::counts( $scope );
		$out    = array_map(
			static fn ( array $f ) => [
				'id'    => $f['id'],
				'path'  => implode( ' / ', Tree::path( $rows, $f['id'] ) ),
				'items' => $counts['folders'][ $f['id'] ] ?? 0,
			],
			$rows
		);
		usort( $out, static fn ( $a, $b ) => strnatcasecmp( $a['path'], $b['path'] ) );
		Utils\format_items( (string) $assoc['format'], $out, [ 'id', 'path', 'items' ] );
		WP_CLI::log( sprintf( '%d items in total, %d unassigned.', $counts['total'], $counts['unassigned'] ) );
	}

	/**
	 * Show the activity log, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--action=<action>]
	 * : Only this action, e.g. server.delete or backup.done.
	 *
	 * [--limit=<number>]
	 * : How many entries.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public static function audit( array $args, array $assoc ): void {
		$items = Audit::query( (int) $assoc['limit'], 1, (string) ( $assoc['action'] ?? '' ) )['items'];
		$rows  = array_map(
			static fn ( array $i ) => [
				'date'   => $i['created_at'],
				'user'   => $i['user'] ?: '-',
				'action' => $i['action'],
				'target' => $i['target'],
				'ip'     => $i['ip'],
			],
			$items
		);
		Utils\format_items( (string) $assoc['format'], $rows, [ 'date', 'user', 'action', 'target', 'ip' ] );
	}

	/**
	 * Runs a job to the end with a progress bar.
	 *
	 * @return array<string, mixed>
	 */
	private static function drive( Backups $backups, string $id, string $label ): array {
		$bar  = Utils\make_progress_bar( $label, 100 );
		$last = 0;
		$result = $backups->run(
			$id,
			static function ( array $p ) use ( $bar, &$last ): void {
				if ( $p['percent'] > $last ) {
					$bar->tick( $p['percent'] - $last );
					$last = $p['percent'];
				}
			}
		);
		$bar->finish();
		if ( 'done' !== $result['status'] ) {
			WP_CLI::error( $result['error'] ?: 'The job failed.' );
		}
		return $result;
	}

	private static function need_id( string $id ): void {
		if ( '' === $id ) {
			WP_CLI::error( 'Please give an ID.' );
		}
	}
}
