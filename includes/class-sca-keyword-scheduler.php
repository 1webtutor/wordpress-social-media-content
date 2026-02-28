<?php
/**
 * Keyword-based scheduler engine.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles keyword scheduler CRUD and cron execution.
 */
class Social_Aggregator_Keyword_Scheduler {

	/**
	 * Scheduler table suffix.
	 *
	 * @var string
	 */
	const SCHEDULER_TABLE = 'social_keyword_schedulers';

	/**
	 * Log table suffix.
	 *
	 * @var string
	 */
	const LOG_TABLE = 'social_keyword_logs';

	/**
	 * API service.
	 *
	 * @var Social_Aggregator_API
	 */
	private $api;

	/**
	 * Content processor.
	 *
	 * @var Social_Aggregator_Content_Processor
	 */
	private $processor;

	/**
	 * Hashtag engine.
	 *
	 * @var Social_Aggregator_Hashtag_Engine
	 */
	private $hashtag_engine;

	/**
	 * Constructor.
	 *
	 * @param Social_Aggregator_API               $api API service.
	 * @param Social_Aggregator_Content_Processor $processor Content processor.
	 * @param Social_Aggregator_Hashtag_Engine    $hashtag_engine Hashtag engine.
	 */
	public function __construct( $api, $processor, $hashtag_engine ) {
		$this->api            = $api;
		$this->processor      = $processor;
		$this->hashtag_engine = $hashtag_engine;

		add_action( 'admin_post_sca_save_keyword_scheduler', array( $this, 'handle_save_scheduler' ) );
		add_action( 'sca_keyword_scheduler_event', array( $this, 'run_active_schedulers' ) );
	}

	/**
	 * Return scheduler table name.
	 *
	 * @return string
	 */
	public static function get_scheduler_table() {
		global $wpdb;
		return $wpdb->prefix . self::SCHEDULER_TABLE;
	}

	/**
	 * Return log table name.
	 *
	 * @return string
	 */
	public static function get_log_table() {
		global $wpdb;
		return $wpdb->prefix . self::LOG_TABLE;
	}

	/**
	 * Handle admin create scheduler request.
	 *
	 * @return void
	 */
	public function handle_save_scheduler() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'social-content-aggregator' ) );
		}

		check_admin_referer( 'sca_save_keyword_scheduler', 'sca_keyword_scheduler_nonce' );

		$keyword        = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$post_type      = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$publish_mode   = isset( $_POST['publish_mode'] ) ? sanitize_key( wp_unslash( $_POST['publish_mode'] ) ) : 'draft';
		$schedule_time  = isset( $_POST['schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time'] ) ) : '09:00';
		$min_engagement = isset( $_POST['min_engagement'] ) ? absint( $_POST['min_engagement'] ) : 0;
		$max_posts      = isset( $_POST['max_posts'] ) ? max( 1, min( 50, absint( $_POST['max_posts'] ) ) ) : 10;
		$frequency      = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'daily';
		$platforms      = isset( $_POST['platforms'] ) && is_array( $_POST['platforms'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['platforms'] ) ) : array();

		if ( empty( $keyword ) || empty( $platforms ) ) {
			wp_safe_redirect( add_query_arg( 'sca_keyword_error', '1', wp_get_referer() ) );
			exit;
		}

		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		if ( ! in_array( $publish_mode, array( 'draft', 'publish', 'schedule' ), true ) ) {
			$publish_mode = 'draft';
		}

		if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
			$frequency = 'daily';
		}

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $schedule_time ) ) {
			$schedule_time = '09:00';
		}

		global $wpdb;
		$wpdb->insert(
			self::get_scheduler_table(),
			array(
				'keyword'        => $keyword,
				'platforms'      => wp_json_encode( array_values( array_unique( $platforms ) ) ),
				'post_type'      => $post_type,
				'publish_mode'   => $publish_mode,
				'schedule_time'  => $schedule_time,
				'min_engagement' => $min_engagement,
				'max_posts'      => $max_posts,
				'frequency'      => $frequency,
				'is_active'      => 1,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'sca_keyword_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Fetch active configs.
	 *
	 * @return array<int,object>
	 */
	public function get_active_configs() {
		global $wpdb;
		$table = self::get_scheduler_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cron entrypoint for all active keyword schedulers.
	 *
	 * @return void
	 */
	public function run_active_schedulers() {
		$configs = $this->get_active_configs();
		foreach ( $configs as $config ) {
			if ( ! $this->is_due( $config ) ) {
				continue;
			}
			$this->run_single_scheduler( $config );
		}
	}

	/**
	 * Run one scheduler config.
	 *
	 * @param object $config Scheduler row.
	 * @return void
	 */
	private function run_single_scheduler( $config ) {
		$platforms = json_decode( (string) $config->platforms, true );
		$platforms = is_array( $platforms ) ? array_map( 'sanitize_key', $platforms ) : array();

		$items = $this->api->fetch_keyword_posts(
			(string) $config->keyword,
			$platforms,
			(int) $config->max_posts,
			(int) $config->min_engagement
		);

		$fetched_count   = is_array( $items ) ? count( $items ) : 0;
		$published_count = 0;
		$skipped_count   = 0;
		$notes           = '';

		if ( ! is_array( $items ) ) {
			$this->insert_log( $config, 0, 0, 0, 'Fetch failed.' );
			return;
		}

		foreach ( $items as $item ) {
			if ( ! $this->is_relevant( $item, (string) $config->keyword ) ) {
				++$skipped_count;
				continue;
			}

			if ( ! $this->verify_keyword_content( $item, (string) $config->keyword ) ) {
				++$skipped_count;
				continue;
			}

			$clean_caption = $this->processor->clean_caption( isset( $item['caption'] ) ? (string) $item['caption'] : '' );
			$processed     = $this->process_hashtags( $clean_caption, (string) $config->keyword );
			$content_hash  = md5( $processed );
			$media_url     = isset( $item['media_url'] ) ? esc_url_raw( $item['media_url'] ) : '';
			$permalink     = isset( $item['permalink'] ) ? esc_url_raw( $item['permalink'] ) : '';

			if ( $this->is_duplicate_item( $permalink, $content_hash, $media_url ) ) {
				++$skipped_count;
				continue;
			}

			$post_args = $this->build_post_args( $config, $processed );
			$post_id   = wp_insert_post( $post_args, true );

			if ( is_wp_error( $post_id ) ) {
				++$skipped_count;
				continue;
			}

			update_post_meta( $post_id, '_sca_original_url', $permalink );
			update_post_meta( $post_id, '_sca_content_hash', $content_hash );
			update_post_meta( $post_id, '_sca_source_media_url', $media_url );
			update_post_meta( $post_id, '_sca_keyword', sanitize_text_field( (string) $config->keyword ) );
			update_post_meta( $post_id, '_sca_platform', sanitize_key( isset( $item['platform'] ) ? $item['platform'] : '' ) );
			update_post_meta( $post_id, '_sca_engagement_score', absint( isset( $item['engagement_score'] ) ? $item['engagement_score'] : 0 ) );
			update_post_meta( $post_id, '_sca_relevance_score', absint( isset( $item['relevance_score'] ) ? $item['relevance_score'] : 0 ) );
			update_post_meta( $post_id, '_sca_final_score', (float) ( isset( $item['final_score'] ) ? $item['final_score'] : 0 ) );

			++$published_count;
		}

		$notes = sprintf( 'Processed keyword scheduler ID %d.', (int) $config->id );
		$this->insert_log( $config, $fetched_count, $published_count, $skipped_count, $notes );
	}

	/**
	 * Calculate relevance score.
	 *
	 * @param string $content Content text.
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
	 * Process hashtags for keyword relevance.
	 *
	 * @param string $caption Caption.
	 * @param string $keyword Keyword.
	 * @return string
	 */
	public function process_hashtags( $caption, $keyword ) {
		preg_match_all( '/#(\w+)/u', (string) $caption, $matches );
		$found_tags = isset( $matches[1] ) ? array_map( 'strtolower', $matches[1] ) : array();
		$kw_words   = array_filter( preg_split( '/\s+/', strtolower( sanitize_text_field( $keyword ) ) ) );
		$kept       = array();

		foreach ( $found_tags as $tag ) {
			foreach ( $kw_words as $word ) {
				if ( false !== strpos( $tag, $word ) ) {
					$kept[] = $tag;
					break;
				}
			}
		}

		$trending = $this->hashtag_engine->get_top_hashtags( 5 );
		$merged   = array_values( array_unique( array_merge( $kept, $trending ) ) );

		$clean_caption = preg_replace( '/#\w+/u', '', (string) $caption );
		$clean_caption = trim( preg_replace( '/\s+/', ' ', (string) $clean_caption ) );

		if ( empty( $merged ) ) {
			return $clean_caption;
		}

		return trim( $clean_caption . ' #' . implode( ' #', $merged ) );
	}

	/**
	 * Optional AI verification hook.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param string              $keyword Keyword.
	 * @return bool
	 */
	private function verify_keyword_content( $item, $keyword ) {
		$verified = apply_filters( 'sca_keyword_ai_verify', null, $item, $keyword );
		if ( null === $verified ) {
			return true;
		}
		return (bool) $verified;
	}

	/**
	 * Check relevance threshold.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param string              $keyword Keyword.
	 * @return bool
	 */
	private function is_relevant( $item, $keyword ) {
		$content          = isset( $item['caption'] ) ? (string) $item['caption'] : '';
		$relevance_score  = $this->calculate_relevance_score( $content, $keyword );
		$item['relevance_score'] = $relevance_score;
		return $relevance_score >= 50;
	}

	/**
	 * Build post args from scheduler config.
	 *
	 * @param object $config Config.
	 * @param string $content Content.
	 * @return array<string,mixed>
	 */
	private function build_post_args( $config, $content ) {
		$status = 'draft';
		if ( 'publish' === $config->publish_mode ) {
			$status = 'publish';
		}

		$post_args = array(
			'post_title'   => wp_trim_words( wp_strip_all_tags( $content ), 10, '…' ),
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $status,
			'post_type'    => post_type_exists( $config->post_type ) ? $config->post_type : 'post',
		);

		if ( 'schedule' === $config->publish_mode ) {
			$scheduled_ts            = $this->calculate_schedule_timestamp( (string) $config->schedule_time );
			$post_args['post_status'] = 'future';
			$post_args['post_date']   = wp_date( 'Y-m-d H:i:s', $scheduled_ts );
		}

		return $post_args;
	}

	/**
	 * Calculate schedule timestamp.
	 *
	 * @param string $time HH:MM.
	 * @return int
	 */
	private function calculate_schedule_timestamp( $time ) {
		$now = (int) current_time( 'timestamp' );
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '09:00';
		}
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );
		$target                  = mktime( $hours, $minutes, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		if ( $target <= $now ) {
			$target += DAY_IN_SECONDS;
		}
		return $target;
	}

	/**
	 * Determine if config should run now.
	 *
	 * @param object $config Config row.
	 * @return bool
	 */
	private function is_due( $config ) {
		global $wpdb;
		$log_table = self::get_log_table();
		$last_run  = $wpdb->get_var( $wpdb->prepare( "SELECT last_run FROM {$log_table} WHERE keyword = %s ORDER BY id DESC LIMIT 1", $config->keyword ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $last_run ) ) {
			return true;
		}

		$last_ts = strtotime( (string) $last_run );
		if ( false === $last_ts ) {
			return true;
		}

		$interval = ( 'weekly' === $config->frequency ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		return ( (int) current_time( 'timestamp' ) - $last_ts ) >= $interval;
	}

	/**
	 * Duplicate check by permalink/content hash/media URL.
	 *
	 * @param string $permalink Permalink.
	 * @param string $content_hash Content hash.
	 * @param string $media_url Media URL.
	 * @return bool
	 */
	private function is_duplicate_item( $permalink, $content_hash, $media_url ) {
		$args = array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => '_sca_original_url',
					'value' => $permalink,
				),
				array(
					'key'   => '_sca_content_hash',
					'value' => $content_hash,
				),
				array(
					'key'   => '_sca_source_media_url',
					'value' => $media_url,
				),
			),
		);

		$ids = get_posts( $args );
		return ! empty( $ids );
	}

	/**
	 * Insert scheduler run log.
	 *
	 * @param object $config Config row.
	 * @param int    $fetched Fetched.
	 * @param int    $published Published.
	 * @param int    $skipped Skipped.
	 * @param string $notes Notes.
	 * @return void
	 */
	private function insert_log( $config, $fetched, $published, $skipped, $notes ) {
		global $wpdb;
		$wpdb->insert(
			self::get_log_table(),
			array(
				'keyword'         => sanitize_text_field( (string) $config->keyword ),
				'fetched_count'   => absint( $fetched ),
				'published_count' => absint( $published ),
				'skipped_count'   => absint( $skipped ),
				'last_run'        => current_time( 'mysql' ),
				'notes'           => sanitize_text_field( $notes ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}
}
