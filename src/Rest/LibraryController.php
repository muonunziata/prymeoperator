<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Library\Folders;
use PrymeOperator\Library\Integration;
use PrymeOperator\Library\LibraryException;
use PrymeOperator\Library\Tree;
use PrymeOperator\Operator\Audit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Virtual folders for the Media Library and post lists.
 */
final class LibraryController {

	public function register_routes(): void {
		$ns    = ServerController::NS;
		$scope = [ 'type' => 'string', 'required' => true ];
		$id    = '/library/folders/(?P<id>\d+)';

		register_rest_route( $ns, '/library/folders', [
			[
				'methods'             => 'GET',
				'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->tree( (string) $r['scope'] ) ),
				'permission_callback' => fn ( WP_REST_Request $r ) => Integration::can_manage( (string) $r['scope'] ),
				'args'                => [ 'scope' => $scope ],
			],
			[
				'methods'             => 'POST',
				'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->create( $r ) ),
				'permission_callback' => fn ( WP_REST_Request $r ) => Integration::can_organise( (string) $r['scope'] ),
				'args'                => [
					'scope'  => $scope,
					'name'   => [ 'type' => 'string', 'required' => true ],
					'parent' => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
				],
			],
		] );
		register_rest_route( $ns, $id, [
			[
				'methods'             => 'POST',
				'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->update( $r ) ),
				'permission_callback' => [ $this, 'can_organise_folder' ],
				'args'                => [
					'name'   => [ 'type' => 'string' ],
					'parent' => [ 'type' => 'integer', 'minimum' => 0 ],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->delete( (int) $r['id'] ) ),
				'permission_callback' => [ $this, 'can_organise_folder' ],
			],
		] );
		register_rest_route( $ns, '/library/assign', [
			'methods'             => 'POST',
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->assign( $r ) ),
			'permission_callback' => fn ( WP_REST_Request $r ) => Integration::can_manage( (string) $r['scope'] ),
			'args'                => [
				'scope'  => $scope,
				'ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'required' => true ],
				'folder' => [ 'type' => 'integer', 'required' => true, 'minimum' => 0 ],
			],
		] );
		register_rest_route( $ns, '/library/import-mediaflow', [
			'methods'             => 'POST',
			'callback'            => fn () => $this->guarded( fn () => $this->import() ),
			'permission_callback' => fn () => current_user_can( 'manage_options' ),
		] );
	}

	public function can_organise_folder( WP_REST_Request $r ): bool {
		$folder = Folders::get( (int) $r['id'] );
		// Unknown IDs pass so the callback can answer 404 instead of a misleading 403.
		return ! $folder || Integration::can_organise( $folder['scope'] );
	}

	private function tree( string $scope ): array {
		$counts = Folders::counts( $scope );
		return [
			'folders'    => array_map(
				static fn ( array $f ) => $f + [ 'count' => $counts['folders'][ $f['id'] ] ?? 0 ],
				Folders::all( $scope )
			),
			'total'      => $counts['total'],
			'unassigned' => $counts['unassigned'],
		];
	}

	private function create( WP_REST_Request $r ): array {
		$scope = (string) $r['scope'];
		$id    = Folders::create( $scope, (string) $r['name'], (int) $r['parent'] );
		$rows  = Folders::all( $scope );
		Audit::log( 'library.create', implode( ' / ', Tree::path( $rows, $id ) ), [ 'scope' => $scope, 'id' => $id ] );
		return [ 'id' => $id ] + $this->tree( $scope );
	}

	private function update( WP_REST_Request $r ): array {
		$id     = (int) $r['id'];
		$before = Folders::get( $id );
		$name   = $r->has_param( 'name' ) ? (string) $r['name'] : null;
		$parent = $r->has_param( 'parent' ) ? (int) $r['parent'] : null;
		$after  = Folders::update( $id, $name, $parent );
		$rows   = Folders::all( $after['scope'] );

		if ( $before && $before['name'] !== $after['name'] ) {
			Audit::log( 'library.rename', implode( ' / ', Tree::path( $rows, $id ) ), [ 'scope' => $after['scope'], 'from' => $before['name'] ] );
		}
		if ( $before && $before['parent_id'] !== $after['parent_id'] ) {
			Audit::log( 'library.move', implode( ' / ', Tree::path( $rows, $id ) ), [ 'scope' => $after['scope'] ] );
		}
		return $this->tree( $after['scope'] );
	}

	private function delete( int $id ): array {
		$folder = Folders::get( $id );
		if ( ! $folder ) {
			throw new LibraryException( 'That folder no longer exists.', 404 );
		}
		$path   = implode( ' / ', Tree::path( Folders::all( $folder['scope'] ), $id ) );
		$result = Folders::delete( $id );
		Audit::log( 'library.delete', $path, [ 'scope' => $folder['scope'] ] + $result );
		return $result + $this->tree( $folder['scope'] );
	}

	/**
	 * Only items of the folder's post type that the user may edit are moved;
	 * the rest are reported back as skipped.
	 */
	private function assign( WP_REST_Request $r ): array {
		$scope  = (string) $r['scope'];
		$folder = (int) $r['folder'];
		if ( $folder ) {
			$row = Folders::get( $folder );
			if ( ! $row || $row['scope'] !== $scope ) {
				throw new LibraryException( 'That folder no longer exists.', 404 );
			}
		}
		$ok = [];
		foreach ( array_unique( array_map( 'intval', (array) $r['ids'] ) ) as $post_id ) {
			if ( $post_id > 0 && get_post_type( $post_id ) === $scope && current_user_can( 'edit_post', $post_id ) ) {
				$ok[] = $post_id;
			}
		}
		$moved = Folders::assign( $ok, $folder );
		if ( $moved ) {
			$target = $folder ? implode( ' / ', Tree::path( Folders::all( $scope ), $folder ) ) : '—';
			Audit::log( 'library.assign', $target, [ 'scope' => $scope, 'ids' => array_slice( $ok, 0, 100 ), 'count' => $moved ] );
		}
		return [ 'moved' => $moved, 'skipped' => count( (array) $r['ids'] ) - $moved ] + $this->tree( $scope );
	}

	private function import(): array {
		$result = Folders::import_mediaflow( Integration::scopes() );
		Audit::log( 'library.import', 'MediaFlow Pro', $result );
		return $result;
	}

	private function guarded( callable $fn ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $fn() );
		} catch ( LibraryException $e ) {
			$message = __( $e->getMessage(), 'prymeoperator' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			return new WP_Error( 'prymeoperator_library', $message, [ 'status' => $e->getCode() ?: 400 ] );
		}
	}
}
