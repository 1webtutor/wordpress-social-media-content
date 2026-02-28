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

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sca_settings';

	/**
	 * Monthly usage option key.
	 *
	 * @var string
	 */
	const SCRAPER_USAGE_OPTION = 'sca_scraper_monthly_usage';

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
	 * Sync content from all configured sources.
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

		$merged = $this->deduplicate_posts( $merged );
		$merged = $this->filter_by_engagement( $merged, isset( $settings['min_engagement'] ) ? absint( $settings['min_engagement'] ) : 0 );

		foreach ( array_values( $merged ) as $index => $item ) {
			$item['caption'] = $this->processor->clean_caption( isset( $item['caption'] ) ? (string) $item['caption'] : '' );
			$hashtags        = $this->hashtag_engine->extract_hashtags( $item['caption'] );
			$this->hashtag_engine->update_trending_stats( $hashtags, (int) $item['engagement_score'] );
			$top_hashtags = $this->hashtag_engine->get_top_hashtags( 5 );

			if ( ! empty( $top_hashtags ) ) {
				$item['caption'] = $item['caption'] . ' #' . implode( ' #', $top_hashtags );
			}

			$item['caption']  = $this->processor->enforce_link_removal( $item['caption'] );
			$item['hashtags'] = $hashtags;

			$post_args = $this->scheduler->build_post_args( $item, $settings, $index );
			$this->cpt->upsert_social_post( $item, $post_args );
		}
	}

	/**
	 * Fetch posts for a platform.
	 *
	 * @param string $platform Platform.
	 * @param bool   $force_refresh Force cache refresh.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function fetch_platform_posts( $platform, $force_refresh = false ) {
		$settings      = get_option( self::OPTION_KEY, array() );
		$cache_ttl     = isset( $settings['cache_ttl'] ) ? max( 300, absint( $settings['cache_ttl'] ) ) : HOUR_IN_SECONDS;
		$transient_key = 'sca_platform_' . sanitize_key( $platform );

		if ( $force_refresh ) {
			delete_transient( $transient_key );
		}

		$cached = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$posts = array();
		if ( $this->should_use_api_for_platform( $platform, $settings ) ) {
			$posts = $this->fetch_platform_via_api( $platform );
		} else {
			$posts = $this->fetch_pooled_scraper_posts( '', array( $platform ), $settings );
		}

		if ( ! is_wp_error( $posts ) ) {
			set_transient( $transient_key, $posts, $cache_ttl );
		}

		return $posts;
	}

	/**
	 * Fetch keyword-specific posts with API-first and pooled scraper fallback.
	 *
	 * @param string            $keyword Keyword.
	 * @param array<int,string> $platforms Platforms.
	 * @param int               $max_posts Max posts.
	 * @param int               $min_engagement Minimum engagement threshold.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch_keyword_posts( $keyword, $platforms, $max_posts = 10, $min_engagement = 0 ) {
		$keyword   = sanitize_text_field( $keyword );
		$platforms = array_values( array_unique( array_map( 'sanitize_key', (array) $platforms ) ) );
		$max_posts = max( 1, min( 50, absint( $max_posts ) ) );

		$cache_key = 'sca_kw_' . md5( wp_json_encode( array( $keyword, $platforms, $max_posts, $min_engagement ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$items    = array();

		foreach ( $platforms as $platform ) {
			$posts = array();
			if ( $this->should_use_api_for_platform( $platform, $settings ) ) {
				$posts = $this->fetch_platform_via_api( $platform );
			}

			if ( empty( $posts ) || is_wp_error( $posts ) ) {
				$posts = $this->fetch_pooled_scraper_posts( $keyword, array( $platform ), $settings );
			}

			if ( is_wp_error( $posts ) || ! is_array( $posts ) ) {
				continue;
			}

			foreach ( $posts as $post ) {
				$caption = isset( $post['caption'] ) ? (string) $post['caption'] : '';
				if ( ! $this->keyword_match_in_content( $caption, $keyword ) ) {
					continue;
				}

				$engagement = isset( $post['engagement_score'] ) ? (int) $post['engagement_score'] : 0;
				if ( $engagement < $min_engagement ) {
					continue;
				}

				$relevance = $this->calculate_relevance_score( $caption, $keyword );
				if ( $relevance < 50 ) {
					continue;
				}

				$post['relevance_score'] = $relevance;
				$post['final_score']     = ( $relevance * 0.6 ) + ( $engagement * 0.4 );
				$items[]                 = $post;
			}
		}

		usort(
			$items,
			static function ( $a, $b ) {
				$left  = isset( $a['final_score'] ) ? (float) $a['final_score'] : 0;
				$right = isset( $b['final_score'] ) ? (float) $b['final_score'] : 0;
				if ( $left === $right ) {
					return 0;
				}
				return ( $left > $right ) ? -1 : 1;
			}
		);

		$items = array_slice( $this->deduplicate_posts( $items ), 0, $max_posts );
		set_transient( $cache_key, $items, 15 * MINUTE_IN_SECONDS );
		return $items;
	}

	/**
	 * Calculate relevance score.
	 *
	 * @param string $content Content.
	 * @param string $keyword Keyword.
	 * @return int
	 */
	public function calculate_relevance_score( $content, $keyword ) {
		$text          = strtolower( preg_replace( '/[^a-z0-9#\s]/i', ' ', (string) $content ) );
		$keyword_text  = strtolower( preg_replace( '/[^a-z0-9\s]/i', ' ', (string) $keyword ) );
		$keyword_words = array_filter( preg_split( '/\s+/', $keyword_text ) );
		$score         = 0;

		if ( false !== strpos( $text, trim( $keyword_text ) ) ) {
			$score += 50;
		}

		foreach ( $keyword_words as $word ) {
			if ( false !== strpos( $text, $word ) ) {
				$score += 20;
			}
			if ( false !== strpos( $text, '#' . $word ) ) {
				$score += 10;
			}
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '[a-z0-9]*\b/', $text ) ) {
				$score += 5;
			}
		}

		return max( 0, (int) $score );
	}

	/**
	 * Fetch feed fallback posts.
	 *
	 * @param bool $force_refresh Force refresh.
	 * @return array<int,array<string,mixed>>|WP_Error
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
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$limit = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;
		$data  = array();

		foreach ( $urls as $url ) {
			$feed = fetch_feed( $url );
			if ( is_wp_error( $feed ) ) {
				continue;
			}

			foreach ( $feed->get_items( 0, $limit ) as $item ) {
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
	 * Fetch pooled scraper posts from Decodo, Apify, Scrape.do.
	 *
	 * @param string               $keyword Keyword.
	 * @param array<int,string>    $platforms Platforms.
	 * @param array<string,mixed>  $settings Settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_pooled_scraper_posts( $keyword, $platforms, $settings ) {
		$providers = $this->get_enabled_scraper_providers( $settings );
		if ( empty( $providers ) ) {
			return array();
		}

		$max_posts = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;
		$all       = array();
		$index     = 0;

		foreach ( range( 1, $max_posts ) as $unused ) {
			if ( empty( $providers ) ) {
				break;
			}

			$provider = $providers[ $index % count( $providers ) ];
			$result   = $this->fetch_from_scraper_provider( $provider, $keyword, $platforms, $settings );
			if ( ! empty( $result ) ) {
				$all = array_merge( $all, $result );
			}
			$index++;
		}

		return $this->deduplicate_posts( $all );
	}

	/**
	 * Get available scraper providers respecting configured call limits.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,string>
	 */
	private function get_enabled_scraper_providers( $settings ) {
		$providers = array(
			'decodo'   => isset( $settings['decodo_api_key'] ) ? (string) $settings['decodo_api_key'] : '',
			'apify'    => isset( $settings['apify_api_token'] ) ? (string) $settings['apify_api_token'] : '',
			'scrape_do'=> isset( $settings['scrape_do_api_token'] ) ? (string) $settings['scrape_do_api_token'] : '',
		);

		$enabled = array();
		foreach ( $providers as $provider => $token ) {
			if ( empty( $token ) ) {
				continue;
			}
			if ( $this->can_make_scraper_call( $provider, $settings ) ) {
				$enabled[] = $provider;
			}
		}

		return $enabled;
	}

	/**
	 * Provider-level fetch adapter.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $keyword Keyword.
	 * @param array<int,string>   $platforms Platforms.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_from_scraper_provider( $provider, $keyword, $platforms, $settings ) {
		if ( ! $this->register_scraper_call( $provider ) ) {
			return array();
		}

		if ( 'apify' === $provider ) {
			return $this->fetch_from_apify( $keyword, $platforms, $settings );
		}
		if ( 'decodo' === $provider ) {
			return $this->fetch_from_decodo( $keyword, $platforms, $settings );
		}
		if ( 'scrape_do' === $provider ) {
			return $this->fetch_from_scrape_do( $keyword, $platforms, $settings );
		}

		return array();
	}

	/**
	 * Fetch from Decodo API.
	 */
	private function fetch_from_decodo( $keyword, $platforms, $settings ) {
		$token = isset( $settings['decodo_api_key'] ) ? (string) $settings['decodo_api_key'] : '';
		if ( empty( $token ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'keyword'   => rawurlencode( $keyword ),
				'platforms' => rawurlencode( implode( ',', $platforms ) ),
			),
			'https://scrape.decodo.com/social/search'
		);

		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		return $this->normalize_provider_response( $response, 'decodo' );
	}

	/**
	 * Fetch from Apify API.
	 */
	private function fetch_from_apify( $keyword, $platforms, $settings ) {
		$token = isset( $settings['apify_api_token'] ) ? (string) $settings['apify_api_token'] : '';
		if ( empty( $token ) ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'token'    => rawurlencode( $token ),
				'keyword'  => rawurlencode( $keyword ),
				'platform' => rawurlencode( implode( ',', $platforms ) ),
			),
			'https://api.apify.com/v2/acts/social-search/run-sync-get-dataset-items'
		);

		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 25 ) );
		return $this->normalize_provider_response( $response, 'apify' );
	}

	/**
	 * Fetch from Scrape.do API.
	 */
	private function fetch_from_scrape_do( $keyword, $platforms, $settings ) {
		$token = isset( $settings['scrape_do_api_token'] ) ? (string) $settings['scrape_do_api_token'] : '';
		if ( empty( $token ) ) {
			return array();
		}

		$target_url = 'https://www.example.com/social-search?q=' . rawurlencode( $keyword . ' ' . implode( ' ', $platforms ) );
		$url        = add_query_arg(
			array(
				'token' => rawurlencode( $token ),
				'url'   => rawurlencode( $target_url ),
			),
			'https://api.scrape.do/'
		);

		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 20 ) );
		return $this->normalize_provider_response( $response, 'scrape_do' );
	}

	/**
	 * Normalize provider response payload.
	 *
	 * @param array<string,mixed>|WP_Error $response Response.
	 * @param string                       $provider Provider.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_provider_response( $response, $provider ) {
		if ( is_wp_error( $response ) ) {
			return array();
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		$rows  = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : ( isset( $body[0] ) ? $body : array() );

		foreach ( $rows as $row ) {
			$caption = isset( $row['caption'] ) ? (string) $row['caption'] : ( isset( $row['text'] ) ? (string) $row['text'] : '' );
			$link    = isset( $row['permalink'] ) ? (string) $row['permalink'] : ( isset( $row['url'] ) ? (string) $row['url'] : '' );
			$likes   = isset( $row['like_count'] ) ? (int) $row['like_count'] : 0;
			$comms   = isset( $row['comments_count'] ) ? (int) $row['comments_count'] : 0;

			$items[] = array(
				'external_id'      => ! empty( $row['id'] ) ? (string) $row['id'] : md5( $provider . '|' . $link . '|' . $caption ),
				'caption'          => $caption,
				'media_url'        => isset( $row['media_url'] ) ? (string) $row['media_url'] : '',
				'permalink'        => $link,
				'timestamp'        => isset( $row['timestamp'] ) ? (string) $row['timestamp'] : gmdate( 'c' ),
				'like_count'       => $likes,
				'comments_count'   => $comms,
				'engagement_score' => $likes + $comms,
				'platform'         => isset( $row['platform'] ) ? sanitize_key( $row['platform'] ) : $this->detect_platform( $link ),
				'ingest_source'    => $provider,
			);
		}

		return $items;
	}

	/**
	 * Check provider can make another call this month.
	 *
	 * @param string              $provider Provider.
	 * @param array<string,mixed> $settings Settings.
	 * @return bool
	 */
	private function can_make_scraper_call( $provider, $settings ) {
		$usage = $this->get_monthly_scraper_usage();
		$limit = isset( $settings[ $provider . '_monthly_limit' ] ) ? absint( $settings[ $provider . '_monthly_limit' ] ) : 5000;
		$used  = isset( $usage['counts'][ $provider ] ) ? absint( $usage['counts'][ $provider ] ) : 0;
		return $used < max( 1, $limit );
	}

	/**
	 * Increment provider call usage.
	 *
	 * @param string $provider Provider.
	 * @return bool
	 */
	private function register_scraper_call( $provider ) {
		$usage = $this->get_monthly_scraper_usage();
		if ( ! isset( $usage['counts'][ $provider ] ) ) {
			$usage['counts'][ $provider ] = 0;
		}
		$usage['counts'][ $provider ]++;
		update_option( self::SCRAPER_USAGE_OPTION, $usage, false );
		return true;
	}

	/**
	 * Get monthly usage structure.
	 *
	 * @return array<string,mixed>
	 */
	private function get_monthly_scraper_usage() {
		$current_month = gmdate( 'Y-m' );
		$usage         = get_option( self::SCRAPER_USAGE_OPTION, array() );

		if ( empty( $usage ) || ! isset( $usage['month'] ) || $usage['month'] !== $current_month ) {
			$usage = array(
				'month'  => $current_month,
				'counts' => array(
					'decodo'    => 0,
					'apify'     => 0,
					'scrape_do' => 0,
				),
			);
			update_option( self::SCRAPER_USAGE_OPTION, $usage, false );
		}

		return $usage;
	}

	/**
	 * Determine whether API should be preferred.
	 *
	 * @param string               $platform Platform.
	 * @param array<string,mixed> $settings Settings.
	 * @return bool
	 */
	private function should_use_api_for_platform( $platform, $settings ) {
		switch ( $platform ) {
			case 'instagram':
				return ! empty( $settings['instagram_account_id'] ) && ! empty( $settings['meta_access_token'] );
			case 'facebook':
				return ! empty( $settings['facebook_page_id'] ) && ! empty( $settings['meta_access_token'] );
			case 'pinterest':
				return ! empty( $settings['pinterest_board_id'] ) && ! empty( $settings['pinterest_access_token'] );
			default:
				return false;
		}
	}

	/**
	 * Fetch one platform via API.
	 *
	 * @param string $platform Platform.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_platform_via_api( $platform ) {
		switch ( $platform ) {
			case 'instagram':
				return $this->fetch_instagram_posts();
			case 'facebook':
				return $this->fetch_facebook_posts();
			case 'pinterest':
				return $this->fetch_pinterest_posts();
			default:
				return array();
		}
	}

	/**
	 * Fetch Instagram API posts.
	 */
	private function fetch_instagram_posts() {
		$settings   = get_option( self::OPTION_KEY, array() );
		$account_id = isset( $settings['instagram_account_id'] ) ? $settings['instagram_account_id'] : '';
		$token      = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		$limit      = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $account_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_ig', __( 'Missing Instagram credentials.', 'social-content-aggregator' ) );
		}

		$url      = sprintf( 'https://graph.facebook.com/v20.0/%1$s/media?fields=id,caption,media_url,media_type,permalink,timestamp,like_count,comments_count&limit=%2$d&access_token=%3$s', rawurlencode( $account_id ), $limit, rawurlencode( $token ) );
		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );

		return $this->normalize_meta_response( $response, 'instagram' );
	}

	/**
	 * Fetch Facebook API posts.
	 */
	private function fetch_facebook_posts() {
		$settings = get_option( self::OPTION_KEY, array() );
		$page_id  = isset( $settings['facebook_page_id'] ) ? $settings['facebook_page_id'] : '';
		$token    = isset( $settings['meta_access_token'] ) ? $settings['meta_access_token'] : '';
		$limit    = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $page_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_fb', __( 'Missing Facebook credentials.', 'social-content-aggregator' ) );
		}

		$url      = sprintf( 'https://graph.facebook.com/v20.0/%1$s/posts?fields=id,message,permalink_url,created_time,full_picture,likes.summary(true),comments.summary(true)&limit=%2$d&access_token=%3$s', rawurlencode( $page_id ), $limit, rawurlencode( $token ) );
		$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'sca_fb_error', __( 'Facebook API request failed.', 'social-content-aggregator' ) );
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

	/**
	 * Fetch Pinterest API posts.
	 */
	private function fetch_pinterest_posts() {
		$settings = get_option( self::OPTION_KEY, array() );
		$board_id = isset( $settings['pinterest_board_id'] ) ? $settings['pinterest_board_id'] : '';
		$token    = isset( $settings['pinterest_access_token'] ) ? $settings['pinterest_access_token'] : '';
		$limit    = isset( $settings['sync_limit'] ) ? max( 1, min( 50, absint( $settings['sync_limit'] ) ) ) : 25;

		if ( empty( $board_id ) || empty( $token ) ) {
			return new WP_Error( 'sca_missing_pin', __( 'Missing Pinterest credentials.', 'social-content-aggregator' ) );
		}

		$url      = sprintf( 'https://api.pinterest.com/v5/boards/%1$s/pins?page_size=%2$d', rawurlencode( $board_id ), $limit );
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
			return new WP_Error( 'sca_pin_error', __( 'Pinterest API request failed.', 'social-content-aggregator' ) );
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

	/**
	 * Normalize Meta Graph API response.
	 */
	private function normalize_meta_response( $response, $platform ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'sca_meta_error', __( 'Meta API request failed.', 'social-content-aggregator' ) );
		}

		$out = array();
		foreach ( $body['data'] as $item ) {
			$likes = isset( $item['like_count'] ) ? (int) $item['like_count'] : 0;
			$comm  = isset( $item['comments_count'] ) ? (int) $item['comments_count'] : 0;
			$out[] = array(
				'external_id'      => isset( $item['id'] ) ? $item['id'] : '',
				'caption'          => isset( $item['caption'] ) ? (string) $item['caption'] : '',
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

	/**
	 * Deduplicate by permalink or external id.
	 */
	private function deduplicate_posts( $posts ) {
		$unique = array();
		$seen   = array();

		foreach ( $posts as $post ) {
			$key = ! empty( $post['permalink'] ) ? 'url:' . $post['permalink'] : 'id:' . ( isset( $post['external_id'] ) ? $post['external_id'] : '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $post;
		}

		return $unique;
	}

	/**
	 * Filter by engagement threshold.
	 */
	private function filter_by_engagement( $posts, $min_score ) {
		return array_values(
			array_filter(
				$posts,
				static function ( $item ) use ( $min_score ) {
					$score = isset( $item['engagement_score'] ) ? (int) $item['engagement_score'] : 0;
					return $score >= (int) $min_score;
				}
			)
		);
	}

	/**
	 * Quick keyword in-content matcher.
	 *
	 * @param string $content Content.
	 * @param string $keyword Keyword.
	 * @return bool
	 */
	private function keyword_match_in_content( $content, $keyword ) {
		$content = strtolower( (string) $content );
		$keyword = strtolower( (string) $keyword );
		if ( false !== strpos( $content, $keyword ) ) {
			return true;
		}

		foreach ( array_filter( preg_split( '/\s+/', $keyword ) ) as $part ) {
			if ( false !== strpos( $content, $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect platform from URL.
	 */
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

	/**
	 * Parse URL list from textarea.
	 */
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

	/**
	 * Basic rate limiting for sync attempts.
	 */
	private function allow_rate_limited_sync() {
		$count = (int) get_transient( 'sca_sync_count' );
		if ( $count >= 10 ) {
			return false;
		}

		set_transient( 'sca_sync_count', $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Log plugin message.
	 */
	private function log_message( $message ) {
		error_log( '[Social Content Aggregator] ' . sanitize_text_field( $message ) );
	}
}

if ( ! class_exists( 'SCA_API_Service' ) ) {
	class_alias( 'Social_Aggregator_API', 'SCA_API_Service' );
}
