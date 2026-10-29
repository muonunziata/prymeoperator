<?php
namespace PrymeOperator\Rest;

use PrymeOperator\Core\Services;
use PrymeOperator\Core\Settings;
use PrymeOperator\Operator\Audit;
use PrymeOperator\Operator\Notifier;
use PrymeOperator\Security\Guard;
use PrymeOperator\Security\JailException;
use PrymeOperator\Server\ExplorerException;
use PrymeOperator\Server\Operations;
use PrymeOperator\Server\OperationException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ServerController {

	public const NS = 'prymeoperator/v1';

	/** MIME types that are safe to show inline in the browser. */
	private const INLINE = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon', 'application/pdf' ];

	public function register_routes(): void {
		$s = [ 'type' => 'string', 'default' => '' ];
		$a = [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'required' => true ];

		$this->route( 'GET', '/server/list', 'list', [ 'path' => $s ] );
		$this->route( 'GET', '/server/file', 'read', [ 'path' => $s ] );
		$this->route( 'GET', '/server/raw', 'raw', [ 'path' => $s, 'download' => [ 'type' => 'boolean', 'default' => false ] ] );
		$this->route( 'GET', '/server/search', 'search', [ 'path' => $s, 'q' => $s, 'content' => [ 'type' => 'boolean', 'default' => false ] ] );
		$this->route( 'GET', '/server/size', 'size', [ 'path' => $s ] );
		$this->route( 'GET', '/server/versions', 'versions', [ 'path' => $s ] );
		$this->route( 'GET', '/server/version', 'version', [ 'path' => $s, 'id' => $s ] );

		$this->route( 'POST', '/server/mkdir', 'mkdir', [ 'path' => $s, 'name' => $s ] );
		$this->route( 'POST', '/server/touch', 'touch', [ 'path' => $s, 'name' => $s ] );
		$this->route( 'POST', '/server/rename', 'rename', [ 'path' => $s, 'name' => $s ] );
		$this->route( 'POST', '/server/copy', 'copy', [ 'paths' => $a, 'dest' => $s ] );
		$this->route( 'POST', '/server/move', 'move', [ 'paths' => $a, 'dest' => $s ] );
		$this->route( 'POST', '/server/delete', 'delete', [ 'paths' => $a ] );
		$this->route( 'POST', '/server/write', 'write', [ 'path' => $s, 'content' => [ 'type' => 'string', 'required' => true ] ] );
		$this->route( 'POST', '/server/chmod', 'chmod', [ 'path' => $s, 'mode' => $s ] );
		$this->route( 'POST', '/server/compress', 'compress', [ 'paths' => $a, 'dest' => $s, 'name' => $s ] );
		$this->route( 'POST', '/server/extract', 'extract', [ 'path' => $s ] );
		$this->route( 'POST', '/server/upload', 'upload', [
			'path'     => $s,
			'name'     => $s,
			'uploadId' => $s,
			'index'    => [ 'type' => 'integer', 'default' => 0 ],
			'total'    => [ 'type' => 'integer', 'default' => 1 ],
		] );
	}

	private function route( string $method, string $path, string $callback, array $args ): void {
		register_rest_route( self::NS, $path, [
			'methods'             => $method,
			'callback'            => fn ( WP_REST_Request $r ) => $this->guarded( fn () => $this->$callback( $r ) ),
			'permission_callback' => [ Guard::class, 'can_read_server' ],
			'args'                => $args,
		] );
	}

	// ── Reads ───────────────────────────────────────────────────────────────

	private function list( WP_REST_Request $r ): array {
		$listing           = Services::explorer()->list( (string) $r['path'] );
		$listing['policy'] = $this->policy_summary();
		return $listing;
	}

	private function read( WP_REST_Request $r ): array {
		$file = Services::explorer()->read( (string) $r['path'] );
		Audit::log( 'server.read', $file['path'] );
		return $file;
	}

	/**
	 * Streams a file. Folders are zipped on the fly. Only well-known media types
	 * are ever served inline, always with nosniff and a sandboxing CSP.
	 */
	private function raw( WP_REST_Request $r ): never {
		$path     = (string) $r['path'];
		$download = (bool) $r['download'];
		$policy   = Guard::policy();
		if ( $download && ! $policy->allows( 'download' ) ) {
			throw new OperationException( 'You do not have permission for this action.', 403 );
		}

		$jail  = Guard::jail();
		$abs   = $jail->resolve( $path );
		$vault = Services::vault();
		$temp  = null;
		if ( $vault->contains( $abs ) ) {
			throw new JailException( 'Path is protected.' );
		}

		if ( is_dir( $abs ) ) {
			if ( ! $download ) {
				throw new OperationException( 'Not a file.', 400 );
			}
			$name = 'download-' . get_current_user_id() . '-' . bin2hex( random_bytes( 6 ) ) . '.zip';
			// Build the archive inside the vault so it never shows up in the listing.
			$temp = $vault->dir( 'tmp' ) . '/' . $name;
			$zip  = new \ZipArchive();
			$zip->open( $temp, \ZipArchive::CREATE );
			$this->zip_dir( $zip, $abs, basename( $abs ) ?: 'root', $vault );
			$zip->close();
			$file     = $temp;
			$filename = ( basename( $abs ) ?: 'root' ) . '.zip';
		} else {
			$file     = Services::explorer()->file_path( $path );
			$filename = basename( $file );
		}

		$type   = wp_check_filetype( $filename )['type'] ?: 'application/octet-stream';
		$inline = ! $download && in_array( $type, self::INLINE, true );
		if ( ! $download && ! $inline && preg_match( '#^(video|audio)/#', $type ) ) {
			$inline = true;
		}

		Audit::log( $download ? 'server.download' : 'server.preview', $jail->relative( $abs ) );

		nocache_headers();
		header( 'Content-Type: ' . ( $inline ? $type : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'application/pdf' !== $type || ! $inline ) {
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox" );
		}
		header( sprintf( 'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', str_replace( '"', '', $filename ), rawurlencode( $filename ) ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $file );
		if ( $temp ) {
			@unlink( $temp );
		}
		exit;
	}

	private function search( WP_REST_Request $r ): array {
		return Services::operations()->search( (string) $r['path'], (string) $r['q'], (bool) $r['content'] );
	}

	private function size( WP_REST_Request $r ): array {
		return [ 'size' => Services::operations()->size( (string) $r['path'] ) ];
	}

	private function versions( WP_REST_Request $r ): array {
		return Services::operations()->versions( (string) $r['path'] );
	}

	private function version( WP_REST_Request $r ): array {
		return [ 'content' => Services::operations()->version_content( (string) $r['path'], (string) $r['id'] ) ];
	}

	// ── Writes ──────────────────────────────────────────────────────────────

	private function mkdir( WP_REST_Request $r ): array {
		$path = Services::operations()->mkdir( (string) $r['path'], (string) $r['name'] );
		Audit::log( 'server.mkdir', $path );
		return [ 'path' => $path ];
	}

	private function touch( WP_REST_Request $r ): array {
		$path = Services::operations()->create_file( (string) $r['path'], (string) $r['name'] );
		Audit::log( 'server.create', $path );
		return [ 'path' => $path ];
	}

	private function rename( WP_REST_Request $r ): array {
		$path = Services::operations()->rename( (string) $r['path'], (string) $r['name'] );
		Audit::log( 'server.rename', $path, [ 'from' => (string) $r['path'] ] );
		return [ 'path' => $path ];
	}

	private function copy( WP_REST_Request $r ): array {
		$paths = Services::operations()->copy( (array) $r['paths'], (string) $r['dest'] );
		Audit::log( 'server.copy', (string) $r['dest'], [ 'from' => (array) $r['paths'] ] );
		return [ 'paths' => $paths ];
	}

	private function move( WP_REST_Request $r ): array {
		$paths = Services::operations()->move( (array) $r['paths'], (string) $r['dest'] );
		Audit::log( 'server.move', (string) $r['dest'], [ 'from' => (array) $r['paths'] ] );
		return [ 'paths' => $paths ];
	}

	private function delete( WP_REST_Request $r ): array {
		$entries = Services::operations()->delete( (array) $r['paths'] );
		foreach ( $entries as $entry ) {
			Audit::log( 'server.delete', $entry['original'], [ 'trash_id' => $entry['id'] ] );
		}
		Notifier::deleted( $entries );
		return [ 'trash' => $entries ];
	}

	/**
	 * Saves a file. PHP is syntax-checked first; after saving, a loopback
	 * request checks the site still loads and rolls back if it does not.
	 */
	private function write( WP_REST_Request $r ): array {
		$path    = (string) $r['path'];
		$content = (string) $r['content'];
		$is_php  = in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'php', 'phtml', 'inc' ], true );

		if ( $is_php && Settings::get( 'php_lint' ) ) {
			$error = Operations::lint_php( $content );
			if ( null !== $error ) {
				throw new OperationException( sprintf( __( 'Not saved: PHP syntax error. %s', 'prymeoperator' ), $error ), 422 );
			}
		}

		$ops     = Services::operations();
		$version = $ops->write( $path, $content );
		Audit::log( 'server.write', $path, [ 'version' => $version['id'] ?? null ] );

		$warning = null;
		if ( $is_php && $version && Settings::get( 'loopback_check' ) ) {
			$check = $this->site_is_healthy();
			if ( false === $check ) {
				$ops->write( $path, $ops->version_content( $path, $version['id'] ) );
				Audit::log( 'server.rollback', $path, [ 'version' => $version['id'] ] );
				throw new OperationException( __( 'This change broke the site, so the previous version was restored automatically.', 'prymeoperator' ), 409 );
			}
			if ( null === $check ) {
				$warning = __( 'Saved, but the site could not be checked after saving.', 'prymeoperator' );
			}
		}

		return [ 'version' => $version, 'warning' => $warning ];
	}

	private function chmod( WP_REST_Request $r ): array {
		Services::operations()->chmod( (string) $r['path'], (string) $r['mode'] );
		Audit::log( 'server.chmod', (string) $r['path'], [ 'mode' => (string) $r['mode'] ] );
		return [ 'ok' => true ];
	}

	private function compress( WP_REST_Request $r ): array {
		$name = (string) $r['name'] ?: 'archive';
		$path = Services::operations()->compress( (array) $r['paths'], (string) $r['dest'], $name );
		Audit::log( 'server.compress', $path, [ 'from' => (array) $r['paths'] ] );
		return [ 'path' => $path ];
	}

	private function extract( WP_REST_Request $r ): array {
		$path = Services::operations()->extract( (string) $r['path'] );
		Audit::log( 'server.extract', $path, [ 'from' => (string) $r['path'] ] );
		return [ 'path' => $path ];
	}

	private function upload( WP_REST_Request $r ): array {
		$files = $r->get_file_params();
		if ( empty( $files['chunk']['tmp_name'] ) || ! is_uploaded_file( $files['chunk']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $files['chunk']['error'] ) {
			throw new OperationException( __( 'Upload failed.', 'prymeoperator' ), 400 );
		}
		$result = Services::operations()->upload_chunk(
			(string) $r['path'],
			(string) $r['name'],
			(string) $r['uploadId'],
			(int) $r['index'],
			(int) $r['total'],
			$files['chunk']['tmp_name']
		);
		if ( $result['done'] ) {
			Audit::log( 'server.upload', $result['path'] );
		}
		return $result;
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private function guarded( callable $fn ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $fn() );
		} catch ( JailException $e ) {
			Audit::log( 'security.jail_violation', 'request', [ 'message' => $e->getMessage() ] );
			return new WP_Error( 'prymeoperator_forbidden_path', __( 'That path is outside the allowed folder.', 'prymeoperator' ), [ 'status' => 403 ] );
		} catch ( ExplorerException | OperationException $e ) {
			// The WordPress-free core throws English messages; their translations ship in the .mo files.
			$message = __( $e->getMessage(), 'prymeoperator' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			return new WP_Error( 'prymeoperator_error', $message, [ 'status' => $e->getCode() ?: 400 ] );
		}
	}

	/**
	 * true = healthy, false = broken, null = could not tell.
	 */
	private function site_is_healthy(): ?bool {
		$response = wp_remote_get( add_query_arg( 'prymeoperator-check', time(), home_url( '/' ) ), [
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 500 || str_contains( $body, 'Fatal error' ) ) {
			return false;
		}
		return true;
	}

	private function policy_summary(): array {
		$policy = Guard::policy();
		$perms  = [];
		foreach ( Settings::PERMISSIONS as $p ) {
			$perms[ $p ] = $policy->allows( $p );
		}
		$perms['chmod'] = $policy->is_admin();
		return $perms;
	}

	private function zip_dir( \ZipArchive $zip, string $abs, string $local, \PrymeOperator\Operator\Vault $vault ): void {
		if ( is_link( $abs ) || $vault->contains( $abs ) ) {
			return;
		}
		if ( is_dir( $abs ) ) {
			$zip->addEmptyDir( $local );
			foreach ( new \DirectoryIterator( $abs ) as $item ) {
				if ( ! $item->isDot() ) {
					$this->zip_dir( $zip, $item->getPathname(), $local . '/' . $item->getFilename(), $vault );
				}
			}
			return;
		}
		$zip->addFile( $abs, $local );
	}
}
