<?php
/**
 * CPT registration and persistence.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages social post CPT.
 */
class SCA_CPT {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers post type.
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
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-share',
			)
		);
	}

	/**
	 * Inserts/updates social post by external unique ID.
	 *
	 * @param array<string,mixed> $data Normalized post payload.
	 * @return int|WP_Error
	 */
	public static function upsert_social_post( $data ) {
		$external_id = isset( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : '';
		if ( empty( $external_id ) ) {
			return new WP_Error( 'sca_missing_external_id', __( 'External ID is required.', 'social-content-aggregator' ) );
		}

		$existing = get_posts(
			array(
				'post_type'      => 'social_posts',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_sca_external_id',
				'meta_value'     => $external_id,
			)
		);

		$post_data = array(
			'post_type'    => 'social_posts',
			'post_status'  => 'publish',
			'post_title'   => wp_trim_words( wp_strip_all_tags( $data['caption'] ), 8, '…' ),
			'post_content' => wp_kses_post( $data['caption'] ),
		);

		if ( ! empty( $existing ) ) {
			$post_data['ID'] = (int) $existing[0];
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$hashtags = self::parse_hashtags( $data['caption'] );
		update_post_meta( $post_id, '_sca_external_id', $external_id );
		update_post_meta( $post_id, '_sca_hashtags', $hashtags );
		update_post_meta( $post_id, '_sca_engagement_score', (int) $data['engagement_score'] );
		update_post_meta( $post_id, '_sca_original_url', esc_url_raw( $data['permalink'] ) );
		update_post_meta( $post_id, '_sca_platform', sanitize_key( $data['platform'] ) );
		update_post_meta( $post_id, '_sca_like_count', (int) $data['like_count'] );
		update_post_meta( $post_id, '_sca_comments_count', (int) $data['comments_count'] );
		update_post_meta( $post_id, '_sca_timestamp', sanitize_text_field( $data['timestamp'] ) );
		update_post_meta( $post_id, '_sca_ingest_source', isset( $data['ingest_source'] ) ? sanitize_key( $data['ingest_source'] ) : 'api' );

		if ( ! empty( $data['media_url'] ) ) {
			self::maybe_attach_media( $post_id, $data['media_url'] );
		}

		return $post_id;
	}

	/**
	 * Parses hashtags from text.
	 *
	 * @param string $text Source caption.
	 * @return array<int,string>
	 */
	public static function parse_hashtags( $text ) {
		$hashtags = array();
		if ( preg_match_all( '/#(\w+)/u', $text, $matches ) ) {
			$hashtags = array_map( 'sanitize_text_field', $matches[1] );
		}

		return array_values( array_unique( $hashtags ) );
	}

	/**
	 * Downloads and sets featured media if available.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $media_url Remote media URL.
	 * @return void
	 */
	public static function maybe_attach_media( $post_id, $media_url ) {
		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		if ( ! filter_var( $media_url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( esc_url_raw( $media_url ), $post_id, null, 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}
}
