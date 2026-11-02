<?php
namespace PrymeOperator\Library;

use PrymeOperator\Core\Settings;

/**
 * Hooks the folders into WordPress: the sidebar on list screens, filtering
 * by folder, uploads into the open folder and cleanup of deleted posts.
 */
final class Integration {

	/** Query var and request parameter that select a folder. "none" means unassigned. */
	public const PARAM = 'po_folder';

	public function hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'pre_get_posts', [ $this, 'main_query' ] );
		add_filter( 'ajax_query_attachments_args', [ $this, 'media_grid_query' ] );
		add_filter( 'posts_clauses', [ $this, 'clauses' ], 10, 2 );
		add_action( 'add_attachment', [ $this, 'file_upload' ] );
		add_action( 'deleted_post', [ Folders::class, 'forget' ] );
	}

	/**
	 * Post types with folders: enabled in the settings and shown in the admin.
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		$out = [];
		foreach ( (array) Settings::get( 'library_post_types' ) as $type ) {
			$obj = get_post_type_object( (string) $type );
			if ( $obj && $obj->show_ui ) {
				$out[] = $obj->name;
			}
		}
		return $out;
	}

	/**
	 * Post types that can be offered in the settings.
	 *
	 * @return array<int, array{value:string, label:string}>
	 */
	public static function available_scopes(): array {
		$out = [];
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $obj ) {
			if ( in_array( $obj->name, [ 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ], true ) ) {
				continue;
			}
			$out[] = [ 'value' => $obj->name, 'label' => (string) $obj->labels->name ];
		}
		return $out;
	}

	/**
	 * Who may see and organise the folders of a post type: whoever can upload
	 * media (Media Library) or edit that kind of post.
	 */
	public static function can_manage( string $scope ): bool {
		if ( ! in_array( $scope, self::scopes(), true ) ) {
			return false;
		}
		if ( 'attachment' === $scope ) {
			return current_user_can( 'upload_files' );
		}
		$obj = get_post_type_object( $scope );
		return $obj && current_user_can( $obj->cap->edit_posts );
	}

	/**
	 * Creating, renaming, moving and deleting folders changes them for
	 * everyone, so it needs the right to edit other people's content
	 * (Editors and Administrators by default). Authors can still file their
	 * own items into existing folders.
	 */
	public static function can_organise( string $scope ): bool {
		if ( ! self::can_manage( $scope ) ) {
			return false;
		}
		$obj = get_post_type_object( 'attachment' === $scope ? 'post' : $scope );
		$can = $obj && current_user_can( $obj->cap->edit_others_posts );
		return (bool) apply_filters( 'prymeoperator_library_can_organise', $can, $scope );
	}

	/**
	 * Parses the folder request value: positive ID, "none" (unassigned) or null (all).
	 */
	public static function parse( mixed $value ): int|string|null {
		if ( null === $value || '' === $value || 'all' === $value ) {
			return null;
		}
		if ( 'none' === $value || '0' === (string) $value ) {
			return 'none';
		}
		$id = absint( $value );
		return $id ?: null;
	}

	private function screen_scope(): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return null;
		}
		if ( 'upload' === $screen->base ) {
			return 'attachment';
		}
		if ( 'edit' === $screen->base && $screen->post_type ) {
			return $screen->post_type;
		}
		return null;
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'edit.php', 'upload.php' ], true ) ) {
			return;
		}
		$scope = $this->screen_scope();
		if ( ! $scope || ! self::can_manage( $scope ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = self::parse( wp_unslash( $_GET[ self::PARAM ] ?? null ) );
		$mode    = 'list';
		if ( 'attachment' === $scope ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$mode = isset( $_GET['mode'] ) && in_array( $_GET['mode'], [ 'grid', 'list' ], true ) ? sanitize_key( $_GET['mode'] ) : ( get_user_option( 'media_library_mode' ) ?: 'grid' );
		}

		wp_enqueue_style( 'prymeoperator-library', PRYMEOPERATOR_URL . 'assets/library.css', [ 'dashicons' ], PRYMEOPERATOR_VERSION );
		wp_enqueue_script( 'prymeoperator-library', PRYMEOPERATOR_URL . 'assets/library.js', [ 'wp-api-fetch', 'wp-i18n', 'wp-url' ], PRYMEOPERATOR_VERSION, true );
		wp_set_script_translations( 'prymeoperator-library', 'prymeoperator', PRYMEOPERATOR_DIR . 'languages' );

		$type = get_post_type_object( $scope );
		wp_localize_script( 'prymeoperator-library', 'PrymeOperatorLibrary', [
			'scope'     => $scope,
			'allLabel'  => 'attachment' === $scope || ! $type ? __( 'All media', 'prymeoperator' ) : (string) $type->labels->all_items,
			'mode'      => $mode,
			'current'   => null === $current ? 'all' : (string) $current,
			'param'     => self::PARAM,
			'organise'  => self::can_organise( $scope ),
			'mediaflow' => current_user_can( 'manage_options' ) && ! get_option( 'prymeoperator_mediaflow_map' ) && Folders::mediaflow_available(),
		] );
	}

	/**
	 * Filters the Posts, Pages and Media (list mode) tables by the chosen folder.
	 */
	public function main_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$folder = self::parse( wp_unslash( $_GET[ self::PARAM ] ?? null ) );
		$scope  = $this->screen_scope();
		if ( null !== $folder && $scope && self::can_manage( $scope ) ) {
			$query->set( self::PARAM, $folder );
		}
	}

	/**
	 * The Media Library grid and the media modal load items over admin-ajax.
	 */
	public function media_grid_query( array $args ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw    = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'][ self::PARAM ] ?? null ) : null;
		$folder = self::parse( $raw );
		if ( null !== $folder && self::can_manage( 'attachment' ) ) {
			$args[ self::PARAM ] = $folder;
		}
		return $args;
	}

	/**
	 * @param array<string, string> $clauses
	 */
	public function clauses( array $clauses, \WP_Query $query ): array {
		$folder = $query->get( self::PARAM );
		if ( '' === $folder || null === $folder ) {
			return $clauses;
		}
		global $wpdb;
		$it = Folders::items_table();
		if ( 'none' === $folder ) {
			$clauses['join']  .= " LEFT JOIN {$it} AS po_fi ON po_fi.object_id = {$wpdb->posts}.ID";
			$clauses['where'] .= ' AND po_fi.object_id IS NULL';
		} else {
			// One folder ID, or a list (a folder with its subfolders).
			$ids = array_values( array_filter( array_map( 'intval', (array) $folder ) ) ) ?: [ 0 ];
			$clauses['join']  .= " INNER JOIN {$it} AS po_fi ON po_fi.object_id = {$wpdb->posts}.ID";
			$clauses['where'] .= ' AND po_fi.folder_id IN (' . implode( ',', $ids ) . ')';
		}
		return $clauses;
	}

	/**
	 * Files uploaded while a folder is open in the Media Library go into it.
	 */
	public function file_upload( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- async-upload.php checks its own nonce.
		$folder = self::parse( wp_unslash( $_POST[ self::PARAM ] ?? null ) );
		if ( ! is_int( $folder ) || ! self::can_manage( 'attachment' ) ) {
			return;
		}
		$row = Folders::get( $folder );
		if ( $row && 'attachment' === $row['scope'] ) {
			Folders::assign( [ $post_id ], $folder );
		}
	}
}
