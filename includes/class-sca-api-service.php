<?php
/**
 * API and scraping service.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and stores social posts.
 */
class Social_Aggregator_API {

	/** @var string */
	const OPTION_KEY = 'sca_settings';

	/** @var SCA_CPT */
	private $cpt;

	/** @var Social_Aggregator_Scheduler */
	private $scheduler;

	/** @var Social_Aggregator_Content_Processor */
	private $processor;

	/** @var Social_Aggregator_Hashtag_Engine */
	private $hashtag_engine;

	/**
	 * Constructor.
	 */
	public function __construct( $cpt, $scheduler, $processor, $hashtag_engine ) {
		$this->cpt            = $cpt;
		$this->scheduler      = $scheduler;
		$this->processor      = $processor;
		$this->hashtag_engine = $hashtag_engine;
	}

	/**
	 * Sync content from all sources.
	 *
	 * @param bool $force_refresh Force cache clear.
	 * @return void
	 */
	public function sync_all_platform_posts( $force_refresh = false ) {
		if ( ! $this->allow_rate_limited_sync() ) {
			$this->log_message( 'Sync skipped due to rate limit.' );
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$merged   = array();
		foreach ( array( 'instagram', 'facebook', 'pinterest' ) as $platform ) {
			$posts = $this->fetch_platform_posts( $platform, $force_refresh );
			if ( ! is_wp_error( $posts ) ) {
				$merged = array_merge( $merged, $posts );
			}
		}

		$feed_posts = $this->fetch_fallback_feed_posts( $force_refresh );
		if ( ! is_wp_error( $feed_posts ) ) {
			$merged = array_merge( $merged, $feed_posts );
		}

		$scraped_posts = $this->fetch_scraped_posts( $force_refresh );
		if ( ! is_wp_error( $scraped_posts ) ) {
			$merged = array_merge( $merged, $scraped_posts );
		}

		$merged = $this->deduplicate_posts( $merged );
		$merged = $this->filter_by_engagement( $merged, isset( $settings['min_engagement'] ) ? absint( $settings['min_engagement'] ) : 0 );

		foreach ( array_values( $merged ) as $index => $item ) {
			$item['caption'] = $this->processor->clean_caption( $item['caption'] );
			$hashtags        = $this->hashtag_engine->extract_hashtags( $item['caption'] );
			$this->hashtag_engine->update_trending_stats( $hashtags, (int) $item['engagement_score'] );
			$top_hashtags    = $this->hashtag_engine->get_top_hashtags( 5 );
			$item['caption'] = trim( $this->processor->enforce_link_removal( $item['caption'] . ' #' . implode( ' #', $top_hashtags ) ) );
			$item['hashtags'] = $hashtags;
			$post_args       = $this->scheduler->build_post_args( $item, $settings, $index );
			$this->cpt->upsert_social_post( $item, $post_args );
		}
	}

	/**
	 * Fetch platform posts with cache.
	 */
	public function fetch_platform_posts( $platform, $force_refresh = false ) {
		$settings      = get_option( self::OPTION_KEY, array() );
		$cache_ttl     = isset( $settings['cache_ttl'] ) ? max( 300, absint( $settings['cache_ttl'] ) ) : HOUR_IN_SECONDS;
		$transient_key = 'sca_api_' . sanitize_key( $platform );
		if ( $force_refresh ) {
			delete_transient( $transient_key );
		}
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$posts = array();
		if ( 'instagram' === $platform ) {
			$posts = $this->fetch_instagram_posts();
		} elseif ( 'facebook' === $platform ) {
			$posts = $this->fetch_facebook_posts();
		} elseif ( 'pinterest' === $platform ) {
			$posts = $this->fetch_pinterest_posts();
		}
		if ( ! is_wp_error( $posts ) ) {
			set_transient( $transient_key, $posts, $cache_ttl );
		}
		return $posts;
	}

	/**
	 * Fetch RSS fallback.
	 */
	private function fetch_fallback_feed_posts( $force_refresh = false ) {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( empty( $settings['enable_feed_ingest'] ) || '1' !== (string) $settings['enable_feed_ingest'] ) {
			return array();
		}
		require_once ABSPATH . WPINC . '/feed.php';
		$urls = $this->extract_urls( isset( $settings['fallback_feed_urls'] ) ? $settings['fallback_feed_urls'] : '' );
		if ( empty( $urls ) ) {
			return array();
		}

		$key = 'sca_feed_' . md5( wp_json_encode( $urls ) );
		if ( $force_refresh ) {
			delete_transient( $key );
		}
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$data = array();
		foreach ( $urls as $url ) {
			$feed = fetch_feed( $url );
			if ( is_wp_error( $feed ) ) {
				continue;
			}
			foreach ( $feed->get_items( 0, 20 ) as $item ) {
				$link = (string) $item->get_permalink();
				$data[] = array(
					'external_id'      => md5( 'feed|' . $link ),
					'caption'          => trim( (string) $item->get_title() . ' ' . wp_strip_all_tags( (string) $item->get_description() ) ),
					'media_url'        => '',
					'permalink'        => $link,
					'timestamp'        => gmdate( 'c', (int) $item->get_date( 'U' ) ),
					'like_count'       => 0,
					'comments_count'   => 0,
					'engagement_score' => 0,
					'platform'         => $this->detect_platform( $link ),
					'ingest_source'    => 'feed',
				);
			}
		}
		set_transient( $key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Optional scrape import.
	 */
	private function fetch_scraped_posts( $force_refresh = false ) {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( empty( $settings['enable_scraping'] ) || '1' !== (string) $settings['enable_scraping'] ) {
			return array();
		}

		$urls = $this->extract_urls( isset( $settings['scrape_urls'] ) ? $settings['scrape_urls'] : '' );
		if ( empty( $urls ) ) {
			return array();
		}
		$key = 'sca_scrape_' . md5( wp_json_encode( $urls ) );
		if ( $force_refresh ) {
			delete_transient( $key );
		}
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$items = array();
		foreach ( $urls as $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$html = (string) wp_remote_retrieve_body( $response );
			preg_match( '/<title>(.*?)<\/title>/is', $html, $title );
			preg_match( '/property="og:image"\s+content="([^"]+)"/is', $html, $image );
			$items[] = array(
				'external_id'      => md5( 'scrape|' . $url ),
				'caption'          => isset( $title[1] ) ? wp_strip_all_tags( html_entity_decode( $title[1], ENT_QUOTES, 'UTF-8' ) ) : '',
				'media_url'        => isset( $image[1] ) ? esc_url_raw( $image[1] ) : '',
				'permalink'        => esc_url_raw( $url ),
				'timestamp'        => gmdate( 'c' ),
				'like_count'       => 0,
				'comments_count'   => 0,
				'engagement_score' => 0,
				'platform'         => $this->detect_platform( $url ),
				'ingest_source'    => 'scrape',
			);
		}
		set_transient( $key, $items, HOUR_IN_SECONDS );
		return $items;
	}

	private function fetch_instagram_posts() {
		$settings   = get_option( self::OPTION_KEY, array() );
		$account_id = isset( $settings['instagram_account_id'] ) ? $settings['instagram_account_id'] : '';
		$token      = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		if ( empty( $account_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_ig', 'Missing Instagram credentials.' );
		}
		$url      = sprintf( 'https://graph.facebook.com/v20.0/%1$s/media?fields=id,caption,media_url,media_type,permalink,timestamp,like_count,comments_count&access_token=%2$s', rawurlencode( $account_id ), rawurlencode( $token ) );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		return $this->normalize_meta_response( $response, 'instagram' );
	}

	private function fetch_facebook_posts() {
		$settings = get_option( self::OPTION_KEY, array() );
		$page_id  = isset( $settings['facebook_page_id'] ) ? $settings['facebook_page_id'] : '';
		$token    = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		if ( empty( $page_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_fb', 'Missing Facebook credentials.' );
		}
		$url      = sprintf( 'https://graph.facebook.com/v20.0/%1$s/posts?fields=id,message,permalink_url,created_time,full_picture,likes.summary(true),comments.summary(true)&access_token=%2$s', rawurlencode( $page_id ), rawurlencode( $token ) );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) ) {
			return new WP_Error( 'sca_fb_error', 'Facebook API request failed.' );
		}
		$out = array();
		foreach ( $body['data'] as $item ) {
			$likes = isset( $item['likes']['summary']['total_count'] ) ? (int) $item['likes']['summary']['total_count'] : 0;
			$comm  = isset( $item['comments']['summary']['total_count'] ) ? (int) $item['comments']['summary']['total_count'] : 0;
			$out[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => isset( $item['message'] ) ? (string) $item['message'] : '',
				'media_url'        => isset( $item['full_picture'] ) ? $item['full_picture'] : '',
				'permalink'        => isset( $item['permalink_url'] ) ? $item['permalink_url'] : '',
				'timestamp'        => isset( $item['created_time'] ) ? $item['created_time'] : '',
				'like_count'       => $likes,
				'comments_count'   => $comm,
				'engagement_score' => $likes + $comm,
				'platform'         => 'facebook',
				'ingest_source'    => 'api',
			);
		}
		return $out;
	}

	private function fetch_pinterest_posts() {
		$settings = get_option( self::OPTION_KEY, array() );
		$board_id = isset( $settings['pinterest_board_id'] ) ? $settings['pinterest_board_id'] : '';
		$token    = isset( $settings['pinterest_access_token'] ) ? $settings['pinterest_access_token'] : '';
		if ( empty( $board_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_pin', 'Missing Pinterest credentials.' );
		}
		$response = wp_remote_get( 'https://api.pinterest.com/v5/boards/' . rawurlencode( $board_id ) . '/pins', array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['items'] ) ) {
			return new WP_Error( 'sca_pin_error', 'Pinterest API request failed.' );
		}
		$out = array();
		foreach ( $body['items'] as $item ) {
			$likes = isset( $item['pin_metrics']['save_count'] ) ? (int) $item['pin_metrics']['save_count'] : 0;
			$comm  = isset( $item['pin_metrics']['comment_count'] ) ? (int) $item['pin_metrics']['comment_count'] : 0;
			$out[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => trim( ( isset( $item['title'] ) ? $item['title'] : '' ) . ' ' . ( isset( $item['description'] ) ? $item['description'] : '' ) ),
				'media_url'        => isset( $item['media']['images']['originals']['url'] ) ? $item['media']['images']['originals']['url'] : '',
				'permalink'        => isset( $item['link'] ) ? $item['link'] : '',
				'timestamp'        => isset( $item['created_at'] ) ? $item['created_at'] : '',
				'like_count'       => $likes,
				'comments_count'   => $comm,
				'engagement_score' => $likes + $comm,
				'platform'         => 'pinterest',
				'ingest_source'    => 'api',
			);
		}
		return $out;
	}

	private function normalize_meta_response( $response, $platform ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) ) {
			return new WP_Error( 'sca_meta_error', 'Meta API request failed.' );
		}
		$out = array();
		foreach ( $body['data'] as $item ) {
			$likes = isset( $item['like_count'] ) ? (int) $item['like_count'] : 0;
			$comm  = isset( $item['comments_count'] ) ? (int) $item['comments_count'] : 0;
			$out[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => isset( $item['caption'] ) ? $item['caption'] : '',
				'media_url'        => isset( $item['media_url'] ) ? $item['media_url'] : '',
				'permalink'        => isset( $item['permalink'] ) ? $item['permalink'] : '',
				'timestamp'        => isset( $item['timestamp'] ) ? $item['timestamp'] : '',
				'like_count'       => $likes,
				'comments_count'   => $comm,
				'engagement_score' => $likes + $comm,
				'platform'         => $platform,
				'ingest_source'    => 'api',
			);
		}
		return $out;
	}

	private function deduplicate_posts( $posts ) {
		$unique = array();
		$seen   = array();
		foreach ( $posts as $post ) {
			$key = ! empty( $post['permalink'] ) ? 'url:' . $post['permalink'] : 'id:' . $post['external_id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $post;
		}
		return $unique;
	}

	private function filter_by_engagement( $posts, $min_score ) {
		return array_values(
			array_filter(
				$posts,
				static function ( $item ) use ( $min_score ) {
					return (int) $item['engagement_score'] >= (int) $min_score;
				}
			)
		);
	}

	private function detect_platform( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( false !== strpos( $host, 'instagram' ) ) {
			return 'instagram';
		}
		if ( false !== strpos( $host, 'facebook' ) ) {
			return 'facebook';
		}
		if ( false !== strpos( $host, 'pinterest' ) ) {
			return 'pinterest';
		}
		return 'external';
	}

	private function extract_urls( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$urls  = array();
		foreach ( (array) $lines as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( ! empty( $url ) ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	private function allow_rate_limited_sync() {
		$count = (int) get_transient( 'sca_sync_count' );
		if ( $count >= 10 ) {
			return false;
		}
		set_transient( 'sca_sync_count', $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function log_message( $message ) {
		error_log( '[Social Content Aggregator] ' . sanitize_text_field( $message ) );
	}
}

if ( ! class_exists( 'SCA_API_Service' ) ) {
	class_alias( 'Social_Aggregator_API', 'SCA_API_Service' );
}
