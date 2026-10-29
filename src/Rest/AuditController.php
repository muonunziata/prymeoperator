<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Operator\Audit;
use WP_REST_Request;
use WP_REST_Response;

final class AuditController {

	public function register_routes(): void {
		register_rest_route( ServerController::NS, '/audit', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
				'action'   => [ 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$result   = Audit::query( (int) $request['per_page'], (int) $request['page'], (string) $request['action'] );
		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}
}
