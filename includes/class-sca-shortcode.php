<?php
/**
 * Shortcode rendering class.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCA_Shortcode {

	public function __construct() {
		add_shortcode( 'social_posts', array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'platform' => '',
				'sort'     => 'engagement',
				'limit'    => 6,
				'hashtag'  => '',
				'source'   => '',
			),
			$atts,
			'social_posts'
		);

		$settings  = get_option( 'sca_settings', array() );
		$post_type = isset( $settings['target_post_type'] ) && post_type_exists( $settings['target_post_type'] ) ? $settings['target_post_type'] : 'social_posts';
		$limit     = absint( $atts['limit'] );
		$platform  = sanitize_key( $atts['platform'] );
		$sort      = 'recent' === sanitize_key( $atts['sort'] ) ? 'recent' : 'engagement';
		$hashtag   = ltrim( sanitize_text_field( $atts['hashtag'] ), '#' );
		$source    = sanitize_key( $atts['source'] );

		$meta_query = array();
		if ( ! empty( $platform ) ) {
			$meta_query[] = array( 'key' => '_sca_platform', 'value' => $platform );
		}
		if ( ! empty( $hashtag ) ) {
			$meta_query[] = array( 'key' => '_sca_hashtags', 'value' => $hashtag, 'compare' => 'LIKE' );
		}
		if ( in_array( $source, array( 'api', 'feed', 'scrape' ), true ) ) {
			$meta_query[] = array( 'key' => '_sca_ingest_source', 'value' => $source );
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => $limit > 0 ? $limit : 6,
			'meta_key'       => 'engagement' === $sort ? '_sca_engagement_score' : '_sca_timestamp',
			'orderby'        => 'engagement' === $sort ? 'meta_value_num' : 'meta_value',
			'order'          => 'DESC',
		);
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No social posts available.', 'social-content-aggregator' ) . '</p>';
		}

		ob_start();
		?>
		<div class="sca-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<article class="sca-card" style="border:1px solid #ddd;padding:12px;">
					<?php if ( has_post_thumbnail() ) : the_post_thumbnail( 'medium', array( 'style' => 'width:100%;height:auto;' ) ); endif; ?>
					<p><?php echo esc_html( wp_trim_words( get_the_content(), 24 ) ); ?></p>
					<strong><?php echo esc_html( sprintf( __( 'Engagement: %d', 'social-content-aggregator' ), (int) get_post_meta( get_the_ID(), '_sca_engagement_score', true ) ) ); ?></strong>
					<?php $url = get_post_meta( get_the_ID(), '_sca_original_url', true ); ?>
					<?php if ( ! empty( $url ) ) : ?><p><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Original Post', 'social-content-aggregator' ); ?></a></p><?php endif; ?>
				</article>
			<?php endwhile; ?>
		</div>
		<?php
		wp_reset_postdata();
		return (string) ob_get_clean();
	}
}
