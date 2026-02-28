<?php
/**
 * CPT and persistence class.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers post type and writes imported posts.
 */
class SCA_CPT {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register social_posts CPT.
	 *
	 * @return void
	 */
	public function register() {
		register_post_type(
			'social_posts',
			array(
				'labels'       => array(
					'name'          => esc_html__( 'Social Posts', 'social-content-aggregator' ),
					'singular_name' => esc_html__( 'Social Post', 'social-content-aggregator' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'has_archive'  => true,
			)
		);
	}

	/**
	 * Upsert by permalink/external id with post args.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $post_args Post args.
	 * @return int|WP_Error
	 */
	public function upsert_social_post( $data, $post_args = array() ) {
		$permalink   = isset( $data['permalink'] ) ? esc_url_raw( $data['permalink'] ) : '';
		$external_id = isset( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : '';

		$existing = $this->find_existing_post( $permalink, $external_id, isset( $post_args['post_type'] ) ? $post_args['post_type'] : 'social_posts' );
		$post     = wp_parse_args(
			$post_args,
			array(
				'post_type'    => 'social_posts',
				'post_status'  => 'draft',
				'post_title'   => wp_trim_words( wp_strip_all_tags( (string) $data['caption'] ), 10, '…' ),
				'post_content' => wp_kses_post( (string) $data['caption'] ),
			)
		);

		if ( $existing ) {
			$post['ID'] = $existing;
			$post_id    = wp_update_post( $post, true );
		} else {
			$post_id = wp_insert_post( $post, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_sca_external_id', $external_id );
		update_post_meta( $post_id, '_sca_original_url', $permalink );
		update_post_meta( $post_id, '_sca_platform', sanitize_key( isset( $data['platform'] ) ? $data['platform'] : '' ) );
		update_post_meta( $post_id, '_sca_engagement_score', absint( isset( $data['engagement_score'] ) ? $data['engagement_score'] : 0 ) );
		update_post_meta( $post_id, '_sca_like_count', absint( isset( $data['like_count'] ) ? $data['like_count'] : 0 ) );
		update_post_meta( $post_id, '_sca_comments_count', absint( isset( $data['comments_count'] ) ? $data['comments_count'] : 0 ) );
		update_post_meta( $post_id, '_sca_timestamp', sanitize_text_field( isset( $data['timestamp'] ) ? $data['timestamp'] : '' ) );
		update_post_meta( $post_id, '_sca_ingest_source', sanitize_key( isset( $data['ingest_source'] ) ? $data['ingest_source'] : 'api' ) );
		update_post_meta( $post_id, '_sca_hashtags', isset( $data['hashtags'] ) ? array_map( 'sanitize_text_field', (array) $data['hashtags'] ) : array() );

		if ( ! empty( $data['media_url'] ) ) {
			$this->maybe_attach_media( $post_id, $data['media_url'] );
		}

		return (int) $post_id;
	}

	/**
	 * Avoid duplicate imports by permalink/external ID.
	 */
	private function find_existing_post( $permalink, $external_id, $post_type ) {
		$query = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( 'relation' => 'OR' ),
		);

		if ( ! empty( $permalink ) ) {
			$query['meta_query'][] = array( 'key' => '_sca_original_url', 'value' => $permalink );
		}
		if ( ! empty( $external_id ) ) {
			$query['meta_query'][] = array( 'key' => '_sca_external_id', 'value' => $external_id );
		}
		$ids = get_posts( $query );
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Sideload media with duplicate/file validation.
	 */
	private function maybe_attach_media( $post_id, $media_url ) {
		$media_url = esc_url_raw( $media_url );
		if ( empty( $media_url ) ) {
			return;
		}
		$attachment = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_sca_source_media_url',
				'meta_value'     => $media_url,
			)
		);
		if ( ! empty( $attachment ) ) {
			set_post_thumbnail( $post_id, (int) $attachment[0] );
			return;
		}

		$filetype = wp_check_filetype( wp_parse_url( $media_url, PHP_URL_PATH ) );
		if ( empty( $filetype['type'] ) || 0 !== strpos( $filetype['type'], 'image/' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $media_url, $post_id, null, 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_sca_source_media_url', $media_url );
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}
}
