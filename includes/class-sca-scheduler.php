<?php
/**
 * Scheduling and publishing engine.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines post timing/status.
 */
class Social_Aggregator_Scheduler {

	/**
	 * Build post args according to publishing mode.
	 *
	 * @param array<string,mixed> $normalized_item Item.
	 * @param array<string,mixed> $settings Settings.
	 * @param int                 $index Queue index.
	 * @return array<string,mixed>
	 */
	public function build_post_args( $normalized_item, $settings, $index = 0 ) {
		$mode      = isset( $settings['publish_mode'] ) ? sanitize_key( $settings['publish_mode'] ) : 'draft';
		$post_type = isset( $settings['target_post_type'] ) ? sanitize_key( $settings['target_post_type'] ) : 'social_posts';
		$post_type = post_type_exists( $post_type ) ? $post_type : 'social_posts';

		$args = array(
			'post_type'    => $post_type,
			'post_title'   => wp_trim_words( wp_strip_all_tags( (string) $normalized_item['caption'] ), 10, '…' ),
			'post_content' => (string) $normalized_item['caption'],
			'post_status'  => 'draft',
		);

		if ( 'publish' === $mode ) {
			$args['post_status'] = 'publish';
			return $args;
		}

		if ( 'schedule' !== $mode ) {
			return $args;
		}

		$scheduled = $this->calculate_next_schedule_timestamp( $settings, $index );
		$args['post_status'] = 'future';
		$args['post_date']   = wp_date( 'Y-m-d H:i:s', $scheduled );

		return $args;
	}

	/**
	 * Ensure recurring sync event for scheduled mode.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	public function ensure_recurring_sync( $settings ) {
		$mode      = isset( $settings['publish_mode'] ) ? sanitize_key( $settings['publish_mode'] ) : 'draft';
		$frequency = isset( $settings['schedule_frequency'] ) ? sanitize_key( $settings['schedule_frequency'] ) : 'once';

		if ( 'schedule' !== $mode || ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
			$timestamp = wp_next_scheduled( 'sca_scheduled_publish_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'sca_scheduled_publish_event' );
			}
			return;
		}

		if ( ! wp_next_scheduled( 'sca_scheduled_publish_event' ) ) {
			wp_schedule_event( time(), $frequency, 'sca_scheduled_publish_event' );
		}
	}

	/**
	 * Get next schedule timestamp in site timezone.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param int                 $index Queue index.
	 * @return int
	 */
	public function calculate_next_schedule_timestamp( $settings, $index = 0 ) {
		$now       = (int) current_time( 'timestamp' );
		$time      = isset( $settings['schedule_time'] ) ? (string) $settings['schedule_time'] : '09:00';
		$frequency = isset( $settings['schedule_frequency'] ) ? sanitize_key( $settings['schedule_frequency'] ) : 'once';

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '09:00';
		}

		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );
		$target                  = mktime( $hours, $minutes, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );

		if ( $target <= $now ) {
			$target += DAY_IN_SECONDS;
		}

		if ( 'daily' === $frequency ) {
			$target += (int) $index * DAY_IN_SECONDS;
		} elseif ( 'weekly' === $frequency ) {
			$target += (int) $index * WEEK_IN_SECONDS;
		} elseif ( $index > 0 ) {
			$target += (int) $index * HOUR_IN_SECONDS;
		}

		return $target;
	}
}
