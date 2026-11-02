<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Backup\BackupException;
use PrymeOperator\Backup\Backups;
use PrymeOperator\Core\Services;
use PrymeOperator\Operator\Audit;
use PrymeOperator\Security\Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Site backups. Administrators only: a backup holds the whole database.
 */
final class BackupController {

	private const ID = '(?P<id>\d{8}-\d{6}-[a-f0-9]{6})';

	public function register_routes(): void {
		$ns    = ServerController::NS;
		$admin = [ Guard::class, 'is_full_admin' ];
		$id    = [ 'id' => [ 'type' => 'string', 'required' => true ] ];

		register_rest_route( $ns, '/backups', [
			[
				'methods'             => 'GET',
				'callback'            => fn () => $this->guarded( fn () => $this->index() ),
				'permission_callback' => $admin,
			],
			[
				'methods'             => 'POST',
				'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->create( $r ) ),
				'permission_callback' => $admin,
				'args'                => [
					'files'    => [ 'type' => 'string', 'enum' => [ 'none', 'content', 'all' ], 'default' => 'content' ],
					'database' => [ 'type' => 'boolean', 'default' => true ],
					'label'    => [ 'type' => 'string', 'default' => '' ],
				],
			],
		] );
		register_rest_route( $ns, '/backups/step', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->step( (string) $r['id'] ) ),
			// A restore may replace the user tables and log the admin out half
			// way; its secret token lets the same browser finish the job.
			'permission_callback' => fn ( WP_REST_Request $r ) => Guard::is_full_admin() || $this->backups()->restore_token_ok( (string) $r['id'], (string) $r['token'] ),
			'args'                => $id + [ 'token' => [ 'type' => 'string', 'default' => '' ] ],
		] );
		register_rest_route( $ns, '/backups/cancel', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->cancel( (string) $r['id'] ) ),
			'permission_callback' => $admin,
			'args'                => $id,
		] );
		register_rest_route( $ns, '/backups/' . self::ID, [
			'methods'             => 'DELETE',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->delete( (string) $r['id'] ) ),
			'permission_callback' => $admin,
		] );
		register_rest_route( $ns, '/backups/' . self::ID . '/download', [
			'methods'             => 'GET',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->download( (string) $r['id'] ) ),
			'permission_callback' => $admin,
		] );
		register_rest_route( $ns, '/backups/' . self::ID . '/restore', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->restore( (string) $r['id'] ) ),
			'permission_callback' => $admin,
		] );
	}

	private function backups(): Backups {
		return new Backups( Services::vault() );
	}

	private function index(): array {
		$users = [];
		$items = array_map(
			static function ( array $b ) use ( &$users ): array {
				$uid = (int) ( $b['user_id'] ?? 0 );
				if ( ! isset( $users[ $uid ] ) ) {
					$user          = $uid ? get_userdata( $uid ) : null;
					$users[ $uid ] = $user ? $user->display_name : '';
				}
				$b['user'] = $users[ $uid ];
				return $b;
			},
			$this->backups()->list()
		);
		return [
			'backups' => $items,
			'running' => $this->backups()->running(),
			'zip'     => class_exists( \ZipArchive::class ),
			'next'    => wp_next_scheduled( \PrymeOperator\Plugin::BACKUP_HOOK ) ?: null,
		];
	}

	private function create( WP_REST_Request $r ): array {
		$progress = $this->backups()->start( (string) $r['files'], (bool) $r['database'], (string) $r['label'], false, get_current_user_id() );
		Audit::log( 'backup.start', $progress['id'], [ 'files' => (string) $r['files'], 'database' => (bool) $r['database'] ] );
		return $progress;
	}

	private function step( string $id ): array {
		$progress = $this->backups()->step( $id );
		if ( 'done' === $progress['status'] ) {
			Audit::log( 'restore' === $progress['kind'] ? 'backup.restored' : 'backup.done', $id );
		} elseif ( 'failed' === $progress['status'] ) {
			Audit::log( 'backup.failed', $id, [ 'error' => $progress['error'] ] );
			$progress['error'] = $this->translate( $progress['error'] );
		}
		return $progress;
	}

	private function cancel( string $id ): array {
		$this->backups()->cancel( $id );
		Audit::log( 'backup.cancel', $id );
		return [ 'ok' => true ];
	}

	private function delete( string $id ): array {
		$this->backups()->delete( $id );
		Audit::log( 'backup.delete', $id );
		return [ 'ok' => true ];
	}

	private function restore( string $id ): array {
		$progress = $this->backups()->restore_start( $id, get_current_user_id() );
		Audit::log( 'backup.restore', $id, [ 'safety' => $progress['safety'] ] );
		return $progress;
	}

	private function download( string $id ): never {
		$file = $this->backups()->zip_path( $id );
		Audit::log( 'backup.download', $id );
		$name = 'backup-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' ) . '-' . $id . '.zip';
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		readfile( $file );
		exit;
	}

	private function translate( string $message ): string {
		// Messages may carry a database error after a colon; translate the sentence part.
		$parts = explode( ': ', $message, 2 );
		$first = __( $parts[0] . ( isset( $parts[1] ) ? ': %s' : '' ), 'prymeoperator' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		return isset( $parts[1] ) ? sprintf( $first, $parts[1] ) : $first;
	}

	private function guarded( callable $fn ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $fn() );
		} catch ( BackupException $e ) {
			return new WP_Error( 'prymeoperator_backup', $this->translate( $e->getMessage() ), [ 'status' => $e->getCode() ?: 400 ] );
		}
	}
}
