<?php
/**
 * Main plugin orchestrator.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main runtime class.
 */
class Social_Aggregator_Main {

	/**
	 * Singleton.
	 *
	 * @var Social_Aggregator_Main|null
	 */
	private static $instance = null;

	/** @var Social_Aggregator_API */
	private $api;

	/** @var Social_Aggregator_Scheduler */
	private $scheduler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$content_processor = new Social_Aggregator_Content_Processor();
		$hashtag_engine    = new Social_Aggregator_Hashtag_Engine();
		$this->scheduler   = new Social_Aggregator_Scheduler();
		$cpt               = new SCA_CPT();
		$this->api         = new Social_Aggregator_API( $cpt, $this->scheduler, $content_processor, $hashtag_engine );

		new Social_Aggregator_Admin( $this->api );
		new SCA_Shortcode();

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( 'sca_refresh_posts_event', array( $this->api, 'sync_all_platform_posts' ) );
		add_action( 'sca_scheduled_publish_event', array( $this->api, 'sync_all_platform_posts' ) );
		add_action( 'init', array( $this, 'maybe_register_recurring_schedule' ) );
	}

	/**
	 * Singleton getter.
	 *
	 * @return Social_Aggregator_Main
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$cpt = new SCA_CPT();
		$cpt->register();

		$table_name      = $wpdb->prefix . 'social_hashtags';
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table_name} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, hashtag VARCHAR(191) NOT NULL, usage_count BIGINT UNSIGNED NOT NULL DEFAULT 0, avg_engagement FLOAT NOT NULL DEFAULT 0, last_used DATETIME NOT NULL, PRIMARY KEY(id), UNIQUE KEY hashtag (hashtag)) {$charset_collate};" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'sca_refresh_posts_event' ) ) {
			wp_schedule_event( time(), 'sca_every_two_hours', 'sca_refresh_posts_event' );
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'sca_refresh_posts_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sca_refresh_posts_event' );
		}

		$scheduled_publish = wp_next_scheduled( 'sca_scheduled_publish_event' );
		if ( $scheduled_publish ) {
			wp_unschedule_event( $scheduled_publish, 'sca_scheduled_publish_event' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Add cron frequency.
	 *
	 * @param array<string,mixed> $schedules Schedules.
	 * @return array<string,mixed>
	 */
	public function register_cron_schedule( $schedules ) {
		$schedules['sca_every_two_hours'] = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => esc_html__( 'Every 2 Hours (Social Aggregator)', 'social-content-aggregator' ),
		);

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => esc_html__( 'Once Weekly', 'social-content-aggregator' ),
			);
		}

		return $schedules;
	}

	/**
	 * Register recurring schedule event when schedule mode is active.
	 *
	 * @return void
	 */
	public function maybe_register_recurring_schedule() {
		$settings = get_option( 'sca_settings', array() );
		$this->scheduler->ensure_recurring_sync( $settings );
	}
}

if ( ! class_exists( 'SCA_Plugin' ) ) {
	class_alias( 'Social_Aggregator_Main', 'SCA_Plugin' );
}
