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
 * Main plugin class.
 */
class SCA_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var SCA_Plugin|null
	 */
	private static $instance = null;

	/**
	 * API service instance.
	 *
	 * @var SCA_API_Service
	 */
	private $api_service;

	/**
	 * Gets singleton instance.
	 *
	 * @return SCA_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->api_service = new SCA_API_Service();

		new SCA_Admin();
		new SCA_CPT();
		new SCA_Shortcode();

		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_action( 'sca_refresh_posts_event', array( $this, 'refresh_posts' ) );
	}

	/**
	 * Activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		$cpt = new SCA_CPT();
		$cpt->register();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'sca_refresh_posts_event' ) ) {
			wp_schedule_event( time(), 'sca_every_two_hours', 'sca_refresh_posts_event' );
		}
	}

	/**
	 * Deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'sca_refresh_posts_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sca_refresh_posts_event' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Adds custom cron interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_cron_interval( $schedules ) {
		if ( ! isset( $schedules['sca_every_two_hours'] ) ) {
			$schedules['sca_every_two_hours'] = array(
				'interval' => 2 * HOUR_IN_SECONDS,
				'display'  => esc_html__( 'Every Two Hours (Social Content Aggregator)', 'social-content-aggregator' ),
			);
		}

		return $schedules;
	}

	/**
	 * Triggers refresh of all configured platform posts.
	 *
	 * @return void
	 */
	public function refresh_posts() {
		$this->api_service->sync_all_platform_posts();
	}
}
