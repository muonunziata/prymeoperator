<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Operator\Audit;
use PrymeOperator\Security\Guard;
use PrymeOperator\Security\JailException;
use PrymeOperator\Server\Explorer;
use PrymeOperator\Server\ExplorerException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ServerController {

	public const NS = 'prymeoperator/v1';

	public function register_routes(): void {
		$path_arg = [
			'path' => [
				'type'    => 'string',
				'default' => '',
			],
		];

		register_rest_route( self::NS, '/server/list', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list' ],
			'permission_callback' => [ Guard::class, 'can_read_server' ],
			'args'                => $path_arg,
		] );

		register_rest_route( self::NS, '/server/file', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'read' ],
			'permission_callback' => [ Guard::class, 'can_read_server' ],
			'args'                => $path_arg,
		] );
	}

	public function list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run( fn ( Explorer $e ) => $e->list( (string) $request['path'] ) );
	}

	public function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run( function ( Explorer $e ) use ( $request ) {
			$file = $e->read( (string) $request['path'] );
			Audit::log( 'server.read', $file['path'] );
			return $file;
		} );
	}

	private function run( callable $fn ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $fn( new Explorer( Guard::jail() ) ) );
		} catch ( JailException $e ) {
			Audit::log( 'security.jail_violation', 'request', [ 'message' => $e->getMessage() ] );
			return new WP_Error( 'prymeoperator_forbidden_path', __( 'That path is outside the allowed folder.', 'prymeoperator' ), [ 'status' => 403 ] );
		} catch ( ExplorerException $e ) {
			return new WP_Error( 'prymeoperator_explorer', $e->getMessage(), [ 'status' => $e->getCode() ?: 400 ] );
		}
	}
}
