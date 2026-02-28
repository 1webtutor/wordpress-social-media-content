<?php
/**
 * Hashtag processing and trends.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles hashtag extraction and scoring.
 */
class Social_Aggregator_Hashtag_Engine {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sca_settings';

	/**
	 * Returns hashtag table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'social_hashtags';
	}

	/**
	 * Extract and normalize hashtags.
	 *
	 * @param string $caption Caption.
	 * @return array<int,string>
	 */
	public function extract_hashtags( $caption ) {
		preg_match_all( '/#\w+/u', (string) $caption, $matches );
		$raw       = isset( $matches[0] ) ? $matches[0] : array();
		$settings  = get_option( self::OPTION_KEY, array() );
		$blacklist = isset( $settings['hashtag_blacklist'] ) ? array_filter( array_map( 'trim', explode( ',', strtolower( (string) $settings['hashtag_blacklist'] ) ) ) ) : array();

		$hashtags = array();
		foreach ( $raw as $tag ) {
			$tag = strtolower( ltrim( sanitize_text_field( $tag ), '#' ) );
			if ( empty( $tag ) || in_array( $tag, $blacklist, true ) || preg_match( '/(official|admin|team)/', $tag ) ) {
				continue;
			}
			$hashtags[] = $tag;
		}

		return array_values( array_unique( $hashtags ) );
	}

	/**
	 * Persist hashtag statistics.
	 *
	 * @param array<int,string> $hashtags Hashtags.
	 * @param int               $engagement Engagement score.
	 * @return void
	 */
	public function update_trending_stats( $hashtags, $engagement ) {
		global $wpdb;

		$table = $this->get_table_name();
		foreach ( $hashtags as $hashtag ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, usage_count, avg_engagement FROM {$table} WHERE hashtag = %s", $hashtag ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $existing ) {
				$usage  = (int) $existing->usage_count + 1;
				$newavg = ( ( (float) $existing->avg_engagement * ( $usage - 1 ) ) + (int) $engagement ) / $usage;
				$wpdb->update(
					$table,
					array(
						'usage_count'    => $usage,
						'avg_engagement' => $newavg,
						'last_used'      => current_time( 'mysql' ),
					),
					array( 'id' => (int) $existing->id ),
					array( '%d', '%f', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'hashtag'        => $hashtag,
						'usage_count'    => 1,
						'avg_engagement' => (float) $engagement,
						'last_used'      => current_time( 'mysql' ),
					),
					array( '%s', '%d', '%f', '%s' )
				);
			}
		}
	}

	/**
	 * Get top trending hashtags.
	 *
	 * @param int $limit Limit.
	 * @return array<int,string>
	 */
	public function get_top_hashtags( $limit = 5 ) {
		global $wpdb;
		$table = $this->get_table_name();
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT hashtag FROM {$table} ORDER BY avg_engagement DESC, usage_count DESC LIMIT %d", max( 1, min( 10, absint( $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_map( 'sanitize_text_field', $rows ) : array();
	}
}
