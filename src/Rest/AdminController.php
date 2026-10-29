<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Core\Services;
use PrymeOperator\Core\Settings;
use PrymeOperator\Operator\Audit;
use PrymeOperator\Security\Guard;
use PrymeOperator\Security\JailException;
use PrymeOperator\Server\OperationException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Trash, settings and dashboard endpoints.
 */
final class AdminController {

	public function register_routes(): void {
		$ns    = ServerController::NS;
		$admin = [ Guard::class, 'is_full_admin' ];
		$id    = [ 'id' => [ 'type' => 'string', 'required' => true ] ];

		register_rest_route( $ns, '/trash', [
			'methods'             => 'GET',
			'callback'            => fn () => $this->guarded( fn () => $this->trash_list() ),
			'permission_callback' => [ Guard::class, 'can_read_server' ],
		] );
		register_rest_route( $ns, '/trash/restore', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->trash_restore( (string) $r['id'] ) ),
			'permission_callback' => [ Guard::class, 'can_read_server' ],
			'args'                => $id,
		] );
		register_rest_route( $ns, '/trash/purge', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->trash_purge( (string) $r['id'] ) ),
			'permission_callback' => $admin,
			'args'                => $id,
		] );
		register_rest_route( $ns, '/trash/empty', [
			'methods'             => 'POST',
			'callback'            => fn () => $this->guarded( fn () => $this->trash_empty() ),
			'permission_callback' => $admin,
		] );
		register_rest_route( $ns, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => fn () => $this->settings_payload(),
				'permission_callback' => $admin,
			],
			[
				'methods'             => 'POST',
				'callback'            => fn ( WP_REST_Request $r ) => $this->settings_save( $r ),
				'permission_callback' => $admin,
			],
		] );
		register_rest_route( $ns, '/dashboard', [
			'methods'             => 'GET',
			'callback'            => fn () => $this->guarded( fn () => $this->dashboard() ),
			'permission_callback' => [ Guard::class, 'can_read_server' ],
		] );
	}

	/**
	 * Restricted users only see what they deleted themselves.
	 */
	private function trash_list(): array {
		$items = Services::trash()->list();
		if ( ! Guard::is_full_admin() ) {
			$me    = get_current_user_id();
			$items = array_values( array_filter( $items, static fn ( $i ) => (int) $i['user_id'] === $me ) );
		}
		$users = [];
		foreach ( $items as &$item ) {
			$uid = (int) $item['user_id'];
			if ( ! isset( $users[ $uid ] ) ) {
				$user          = $uid ? get_userdata( $uid ) : null;
				$users[ $uid ] = $user ? $user->display_name : '';
			}
			$item['user']  = $users[ $uid ];
			$item['purge'] = (int) $item['deleted_at'] + (int) Settings::get( 'trash_days' ) * DAY_IN_SECONDS;
		}
		return $items;
	}

	private function trash_restore( string $id ): array {
		if ( ! Guard::is_full_admin() ) {
			$mine = array_filter( Services::trash()->list(), static fn ( $i ) => $i['id'] === $id && (int) $i['user_id'] === get_current_user_id() );
			if ( ! $mine ) {
				throw new OperationException( __( 'You can only restore items you deleted.', 'prymeoperator' ), 403 );
			}
		}
		$path = Services::operations()->restore( $id );
		Audit::log( 'trash.restore', $path, [ 'trash_id' => $id ] );
		return [ 'path' => $path ];
	}

	private function trash_purge( string $id ): array {
		Services::trash()->purge( $id );
		Audit::log( 'trash.purge', $id );
		return [ 'ok' => true ];
	}

	private function trash_empty(): array {
		$count = Services::trash()->empty();
		Audit::log( 'trash.empty', (string) $count );
		return [ 'count' => $count ];
	}

	private function settings_payload(): array {
		$roles = [];
		foreach ( wp_roles()->roles as $key => $role ) {
			if ( 'administrator' !== $key ) {
				$roles[] = [ 'value' => $key, 'label' => translate_user_role( $role['name'] ) ];
			}
		}
		return [
			'settings'     => Settings::all(),
			'roles'        => $roles,
			'permissions'  => Settings::PERMISSIONS,
			'default_root' => ABSPATH,
			'root_locked'  => defined( 'PRYMEOPERATOR_ROOT' ),
		];
	}

	private function settings_save( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$input = (array) $r->get_json_params();
		$saved = Settings::update( $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Audit::log( 'settings.update', 'settings', [ 'keys' => array_keys( $input ) ] );
		return new WP_REST_Response( $this->settings_payload() );
	}

	private function dashboard(): array {
		$admin = Guard::is_full_admin();
		$root  = Guard::jail()->root();
		$trash = Services::trash()->list();

		$data = [
			'disk'    => [
				'total' => (int) @disk_total_space( $root ),
				'free'  => (int) @disk_free_space( $root ),
			],
			'trash'   => [
				'count' => count( $trash ),
				'size'  => array_sum( array_column( $trash, 'size' ) ),
			],
			'limits'  => [
				'upload'   => (int) wp_max_upload_size(),
				'plugin'   => (int) Settings::get( 'max_upload_mb' ) * MB_IN_BYTES,
				'chunking' => true,
			],
			'is_admin' => $admin,
		];

		if ( $admin ) {
			$data['root']        = $root;
			$data['environment'] = [
				'php'               => PHP_VERSION,
				'wordpress'         => get_bloginfo( 'version' ),
				'zip'               => class_exists( \ZipArchive::class ),
				'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
				'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			];
			$data['recent'] = Audit::query( 8 )['items'];
		}
		return $data;
	}

	private function guarded( callable $fn ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $fn() );
		} catch ( JailException $e ) {
			return new WP_Error( 'prymeoperator_forbidden_path', __( 'That path is outside the allowed folder.', 'prymeoperator' ), [ 'status' => 403 ] );
		} catch ( OperationException $e ) {
			// The WordPress-free core throws English messages; their translations ship in the .mo files.
			$message = __( $e->getMessage(), 'prymeoperator' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			return new WP_Error( 'prymeoperator_error', $message, [ 'status' => $e->getCode() ?: 400 ] );
		}
	}
}
