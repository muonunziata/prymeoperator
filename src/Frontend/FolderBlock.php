<?php
namespace PrymeOperator\Frontend;

use PrymeOperator\Library\Folders;
use PrymeOperator\Library\Integration;
use PrymeOperator\Library\Tree;

/**
 * Shows the contents of a Media Library folder on the site, as a gallery or
 * a list of downloads. Available as the "Folder" block and as the
 * [prymeoperator_folder] shortcode.
 *
 * Only what WordPress already makes public is shown: attachment URLs, titles
 * and captions. Items attached to private or draft posts are left out.
 */
final class FolderBlock {

	public const SHORTCODE = 'prymeoperator_folder';

	/** @var array<string, array{type:string, default:mixed}> */
	private const ATTRIBUTES = [
		'folder'     => [ 'type' => 'number', 'default' => 0 ],
		'view'       => [ 'type' => 'string', 'default' => 'grid' ],
		'columns'    => [ 'type' => 'number', 'default' => 3 ],
		'size'       => [ 'type' => 'string', 'default' => 'medium' ],
		'subfolders' => [ 'type' => 'boolean', 'default' => false ],
		'orderby'    => [ 'type' => 'string', 'default' => 'date' ],
		'limit'      => [ 'type' => 'number', 'default' => 60 ],
		'captions'   => [ 'type' => 'boolean', 'default' => true ],
	];

	public function hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		wp_register_style( 'prymeoperator-folder', PRYMEOPERATOR_URL . 'assets/folder-block.css', [], PRYMEOPERATOR_VERSION );
		wp_register_script(
			'prymeoperator-folder-editor',
			PRYMEOPERATOR_URL . 'assets/folder-block.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ],
			PRYMEOPERATOR_VERSION,
			true
		);
		wp_set_script_translations( 'prymeoperator-folder-editor', 'prymeoperator', PRYMEOPERATOR_DIR . 'languages' );

		register_block_type( 'prymeoperator/folder', [
			'api_version'     => 3,
			'title'           => __( 'Folder', 'prymeoperator' ),
			'description'     => __( 'Shows the files of a Media Library folder as a gallery or a list of downloads.', 'prymeoperator' ),
			'category'        => 'media',
			'icon'            => 'portfolio',
			'keywords'        => [ 'gallery', 'downloads', 'files', 'prymeoperator' ],
			'attributes'      => self::ATTRIBUTES,
			'supports'        => [ 'align' => [ 'wide', 'full' ], 'html' => false ],
			'editor_script'   => 'prymeoperator-folder-editor',
			'style'           => 'prymeoperator-folder',
			'render_callback' => fn ( array $atts ) => $this->render( $atts, true ),
		] );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array_map( static fn ( $a ) => $a['default'], self::ATTRIBUTES ) + [ 'id' => 0 ],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE
		);
		if ( $atts['id'] && ! $atts['folder'] ) {
			$atts['folder'] = $atts['id'];
		}
		foreach ( [ 'subfolders', 'captions' ] as $flag ) {
			$atts[ $flag ] = filter_var( $atts[ $flag ], FILTER_VALIDATE_BOOLEAN );
		}
		wp_enqueue_style( 'prymeoperator-folder' );
		return $this->render( $atts );
	}

	/**
	 * @param array<string, mixed> $atts
	 */
	public function render( array $atts, bool $is_block = false ): string {
		$folder_id = absint( $atts['folder'] ?? 0 );
		$folder    = $folder_id ? Folders::get( $folder_id ) : null;
		if ( ! $folder || 'attachment' !== $folder['scope'] ) {
			return $this->notice( __( 'Choose a Media Library folder to show.', 'prymeoperator' ) );
		}

		$view    = in_array( $atts['view'] ?? '', [ 'grid', 'list' ], true ) ? $atts['view'] : 'grid';
		$columns = max( 1, min( 8, (int) ( $atts['columns'] ?? 3 ) ) );
		$size    = in_array( $atts['size'] ?? '', get_intermediate_image_sizes(), true ) || 'full' === ( $atts['size'] ?? '' ) ? $atts['size'] : 'medium';
		$limit   = max( 1, min( 500, (int) ( $atts['limit'] ?? 60 ) ) );
		$orderby = in_array( $atts['orderby'] ?? '', [ 'date', 'title', 'menu_order', 'rand' ], true ) ? $atts['orderby'] : 'date';

		$ids = [ $folder_id ];
		if ( ! empty( $atts['subfolders'] ) ) {
			$ids = array_merge( $ids, Tree::descendants( Folders::all( 'attachment' ), $folder_id ) );
		}

		$query = new \WP_Query( [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => $limit,
			'orderby'                => $orderby,
			'order'                  => 'title' === $orderby ? 'ASC' : 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			Integration::PARAM       => $ids,
		] );

		$items = array_values( array_filter( $query->posts, [ $this, 'is_public' ] ) );
		if ( ! $items ) {
			return $this->notice( __( 'This folder is empty.', 'prymeoperator' ) );
		}

		$captions = ! empty( $atts['captions'] );
		$classes  = 'po-folder po-folder--' . $view . ( ! empty( $atts['align'] ) ? ' align' . sanitize_html_class( (string) $atts['align'] ) : '' );
		$wrapper  = $is_block && function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( [ 'class' => $classes, 'style' => '--po-cols:' . $columns ] )
			: 'class="' . esc_attr( $classes ) . '" style="--po-cols:' . $columns . '"';

		$out = '<div ' . $wrapper . '>';
		if ( 'grid' === $view ) {
			$out .= '<ul class="po-folder__grid">';
			foreach ( $items as $post ) {
				$url   = wp_get_attachment_url( $post->ID );
				$thumb = wp_attachment_is_image( $post->ID )
					? wp_get_attachment_image( $post->ID, $size, false, [ 'loading' => 'lazy' ] )
					: '<span class="po-folder__icon"><img src="' . esc_url( wp_mime_type_icon( $post->ID ) ) . '" alt="" loading="lazy"></span>';
				$caption = $captions ? ( wp_get_attachment_caption( $post->ID ) ?: get_the_title( $post ) ) : '';
				$out    .= '<li class="po-folder__item"><figure><a href="' . esc_url( (string) $url ) . '">' . $thumb . '</a>'
					. ( $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '' ) . '</figure></li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<ul class="po-folder__list">';
			foreach ( $items as $post ) {
				$url  = (string) wp_get_attachment_url( $post->ID );
				$file = get_attached_file( $post->ID );
				$size_label = $file && is_file( $file ) ? size_format( (int) filesize( $file ) ) : '';
				$ext  = strtoupper( (string) pathinfo( $url, PATHINFO_EXTENSION ) );
				$out .= '<li class="po-folder__row">'
					. '<img class="po-folder__type" src="' . esc_url( wp_mime_type_icon( $post->ID ) ) . '" alt="" loading="lazy">'
					. '<a class="po-folder__name" href="' . esc_url( $url ) . '" download>' . esc_html( get_the_title( $post ) ?: basename( $url ) ) . '</a>'
					. '<span class="po-folder__meta">' . esc_html( trim( $ext . ' · ' . $size_label, ' ·' ) ) . '</span>'
					. '</li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}

	/**
	 * Attachments of private, draft or password-protected posts stay hidden.
	 */
	private function is_public( \WP_Post $post ): bool {
		if ( ! $post->post_parent ) {
			return true;
		}
		$parent = get_post( $post->post_parent );
		return ! $parent || ( 'publish' === $parent->post_status && '' === $parent->post_password );
	}

	private function notice( string $message ): string {
		// Visitors never see setup hints; editors previewing the block do.
		if ( ! current_user_can( 'upload_files' ) ) {
			return '';
		}
		return '<p class="po-folder po-folder--notice">' . esc_html( $message ) . '</p>';
	}
}
