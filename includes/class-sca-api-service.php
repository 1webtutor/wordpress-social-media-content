<?php
/**
 * API integrations for social providers.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and normalizes social content from official APIs.
 */
class SCA_API_Service {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sca_settings';

	/**
	 * Syncs all configured platforms.
	 *
	 * @param bool $force_refresh Skip transient cache when true.
	 * @return void
	 */
	public function sync_all_platform_posts( $force_refresh = false ) {
		$platforms = array( 'instagram', 'facebook', 'pinterest' );

		foreach ( $platforms as $platform ) {
			$posts = $this->fetch_platform_posts( $platform, $force_refresh );
			if ( is_wp_error( $posts ) || empty( $posts ) ) {
				continue;
			}

			foreach ( $posts as $post ) {
				SCA_CPT::upsert_social_post( $post );
			}
		}
	}

	/**
	 * Returns platform posts using cached transient.
	 *
	 * @param string $platform Platform key.
	 * @param bool   $force_refresh Skip transient cache when true.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function fetch_platform_posts( $platform, $force_refresh = false ) {
		$settings      = get_option( self::OPTION_KEY, array() );
		$cache_ttl     = isset( $settings['cache_ttl'] ) ? max( 300, absint( $settings['cache_ttl'] ) ) : HOUR_IN_SECONDS;
		$transient_key = 'sca_api_' . sanitize_key( $platform );

		if ( $force_refresh ) {
			delete_transient( $transient_key );
		}

		$cached = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		switch ( $platform ) {
			case 'instagram':
				$posts = $this->fetch_instagram_posts();
				break;
			case 'facebook':
				$posts = $this->fetch_facebook_posts();
				break;
			case 'pinterest':
				$posts = $this->fetch_pinterest_posts();
				break;
			default:
				return new WP_Error( 'sca_invalid_platform', __( 'Unsupported platform requested.', 'social-content-aggregator' ) );
		}

		if ( ! is_wp_error( $posts ) ) {
			set_transient( $transient_key, $posts, $cache_ttl );
		}

		return $posts;
	}

	/**
	 * Fetches Instagram business media.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_instagram_posts() {
		$settings   = get_option( self::OPTION_KEY, array() );
		$account_id = isset( $settings['instagram_account_id'] ) ? $settings['instagram_account_id'] : '';
		$token      = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		$sync_limit = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $account_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_instagram_credentials', __( 'Instagram API credentials are not configured.', 'social-content-aggregator' ) );
		}

		$url = sprintf(
			'https://graph.facebook.com/v20.0/%1$s/media?fields=id,caption,media_url,media_type,permalink,timestamp,like_count,comments_count&limit=%2$d&access_token=%3$s',
			rawurlencode( $account_id ),
			$sync_limit,
			rawurlencode( $token )
		);

		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );

		return $this->normalize_meta_response( $response, 'instagram' );
	}

	/**
	 * Fetches Facebook page posts.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_facebook_posts() {
		$settings   = get_option( self::OPTION_KEY, array() );
		$page_id    = isset( $settings['facebook_page_id'] ) ? $settings['facebook_page_id'] : '';
		$token      = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		$sync_limit = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $page_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_facebook_credentials', __( 'Facebook API credentials are not configured.', 'social-content-aggregator' ) );
		}

		$url = sprintf(
			'https://graph.facebook.com/v20.0/%1$s/posts?fields=id,message,permalink_url,created_time,full_picture,likes.summary(true),comments.summary(true)&limit=%2$d&access_token=%3$s',
			rawurlencode( $page_id ),
			$sync_limit,
			rawurlencode( $token )
		);

		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code || ! isset( $body['data'] ) ) {
			return new WP_Error( 'sca_facebook_api_error', __( 'Facebook API request failed.', 'social-content-aggregator' ) );
		}

		$normalized = array();
		foreach ( $body['data'] as $item ) {
			$caption       = isset( $item['message'] ) ? (string) $item['message'] : '';
			$like_count    = isset( $item['likes']['summary']['total_count'] ) ? (int) $item['likes']['summary']['total_count'] : 0;
			$comment_count = isset( $item['comments']['summary']['total_count'] ) ? (int) $item['comments']['summary']['total_count'] : 0;
			$normalized[]  = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => $caption,
				'media_url'        => isset( $item['full_picture'] ) ? $item['full_picture'] : '',
				'media_type'       => 'IMAGE',
				'permalink'        => isset( $item['permalink_url'] ) ? $item['permalink_url'] : '',
				'timestamp'        => isset( $item['created_time'] ) ? $item['created_time'] : '',
				'like_count'       => $like_count,
				'comments_count'   => $comment_count,
				'engagement_score' => $this->calculate_engagement_score( $like_count, $comment_count ),
				'platform'         => 'facebook',
			);
		}

		return $normalized;
	}

	/**
	 * Fetches Pinterest board pins via API v5.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_pinterest_posts() {
		$settings   = get_option( self::OPTION_KEY, array() );
		$board_id   = isset( $settings['pinterest_board_id'] ) ? $settings['pinterest_board_id'] : '';
		$token      = isset( $settings['pinterest_access_token'] ) ? $settings['pinterest_access_token'] : '';
		$sync_limit = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $board_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_pinterest_credentials', __( 'Pinterest API credentials are not configured.', 'social-content-aggregator' ) );
		}

		$url      = sprintf( 'https://api.pinterest.com/v5/boards/%s/pins?page_size=%d', rawurlencode( $board_id ), $sync_limit );
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code || ! isset( $body['items'] ) ) {
			return new WP_Error( 'sca_pinterest_api_error', __( 'Pinterest API request failed.', 'social-content-aggregator' ) );
		}

		$normalized = array();
		foreach ( $body['items'] as $item ) {
			$description   = isset( $item['description'] ) ? (string) $item['description'] : '';
			$title         = isset( $item['title'] ) ? (string) $item['title'] : '';
			$caption       = trim( $title . ' ' . $description );
			$media_url     = '';
			$media_type    = 'IMAGE';
			$like_count    = isset( $item['pin_metrics']['save_count'] ) ? (int) $item['pin_metrics']['save_count'] : 0;
			$comment_count = isset( $item['pin_metrics']['comment_count'] ) ? (int) $item['pin_metrics']['comment_count'] : 0;

			if ( isset( $item['media']['images']['originals']['url'] ) ) {
				$media_url = $item['media']['images']['originals']['url'];
			}

			if ( isset( $item['media']['media_type'] ) ) {
				$media_type = (string) $item['media']['media_type'];
			}

			$normalized[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => $caption,
				'media_url'        => $media_url,
				'media_type'       => $media_type,
				'permalink'        => isset( $item['link'] ) ? $item['link'] : '',
				'timestamp'        => isset( $item['created_at'] ) ? $item['created_at'] : '',
				'like_count'       => $like_count,
				'comments_count'   => $comment_count,
				'engagement_score' => $this->calculate_engagement_score( $like_count, $comment_count ),
				'platform'         => 'pinterest',
			);
		}

		return $normalized;
	}

	/**
	 * Normalizes Meta Graph response for Instagram media.
	 *
	 * @param array<string,mixed>|WP_Error $response HTTP response.
	 * @param string                       $platform Platform key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function normalize_meta_response( $response, $platform ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code || ! isset( $body['data'] ) ) {
			return new WP_Error( 'sca_meta_api_error', __( 'Meta API request failed.', 'social-content-aggregator' ) );
		}

		$normalized = array();
		foreach ( $body['data'] as $item ) {
			$caption       = isset( $item['caption'] ) ? (string) $item['caption'] : '';
			$like_count    = isset( $item['like_count'] ) ? (int) $item['like_count'] : 0;
			$comment_count = isset( $item['comments_count'] ) ? (int) $item['comments_count'] : 0;

			$normalized[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => $caption,
				'media_url'        => isset( $item['media_url'] ) ? $item['media_url'] : '',
				'media_type'       => isset( $item['media_type'] ) ? $item['media_type'] : '',
				'permalink'        => isset( $item['permalink'] ) ? $item['permalink'] : '',
				'timestamp'        => isset( $item['timestamp'] ) ? $item['timestamp'] : '',
				'like_count'       => $like_count,
				'comments_count'   => $comment_count,
				'engagement_score' => $this->calculate_engagement_score( $like_count, $comment_count ),
				'platform'         => $platform,
			);
		}

		return $normalized;
	}

	/**
	 * Computes engagement score.
	 *
	 * @param int $likes Like count.
	 * @param int $comments Comment count.
	 * @return int
	 */
	private function calculate_engagement_score( $likes, $comments ) {
		return max( 0, (int) $likes ) + max( 0, (int) $comments );
	}
}
