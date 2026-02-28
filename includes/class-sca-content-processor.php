<?php
/**
 * Content processing utilities.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans imported captions.
 */
class Social_Aggregator_Content_Processor {

	/**
	 * Clean and sanitize caption text.
	 *
	 * @param string $caption Raw caption.
	 * @return string
	 */
	public function clean_caption( $caption ) {
		$clean = wp_kses_post( (string) $caption );
		$clean = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', $clean );
		$clean = preg_replace( '/https?:\/\/[^\s]+/i', '', $clean );
		$clean = preg_replace( '/\b[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '', $clean );
		$clean = preg_replace( '/@\w+/u', '', $clean );
		$clean = preg_replace( '/\b(posted by|credit|via)\s+@?\w+/iu', '', $clean );
		$clean = preg_replace( '/\?[^\s]*utm_[^\s]*/iu', '', $clean );
		$clean = wp_strip_all_tags( (string) $clean );
		$clean = preg_replace( '/\s+/u', ' ', (string) $clean );

		return sanitize_text_field( trim( (string) $clean ) );
	}

	/**
	 * Enforce no links in post content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function enforce_link_removal( $content ) {
		$clean = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', (string) $content );
		$clean = preg_replace( '/https?:\/\/[^\s]+/i', '', (string) $clean );
		$clean = preg_replace( '/\butm_[a-z_]+=[^&\s]+/i', '', (string) $clean );
		$clean = preg_replace( '/\s+/u', ' ', (string) $clean );

		return sanitize_text_field( trim( (string) $clean ) );
	}
}
