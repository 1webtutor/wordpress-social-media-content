<?php
/**
 * Shortcode rendering class.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles [social_posts] shortcode rendering.
 */
class SCA_Shortcode {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'social_posts', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renders shortcode output.
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'platform' => '',
				'sort'     => 'engagement',
				'limit'    => 6,
				'hashtag'  => '',
			),
			$atts,
			'social_posts'
		);

		$limit    = absint( $atts['limit'] );
		$platform = sanitize_key( $atts['platform'] );
		$sort_raw = sanitize_key( $atts['sort'] );
		$sort     = 'recent' === $sort_raw ? 'recent' : 'engagement';
		$hashtag  = ltrim( sanitize_text_field( $atts['hashtag'] ), '#' );

		$meta_query = array();
		if ( ! empty( $platform ) ) {
			$meta_query[] = array(
				'key'   => '_sca_platform',
				'value' => $platform,
			);
		}

		if ( ! empty( $hashtag ) ) {
			$meta_query[] = array(
				'key'     => '_sca_hashtags',
				'value'   => $hashtag,
				'compare' => 'LIKE',
			);
		}

		$query_args = array(
			'post_type'      => 'social_posts',
			'posts_per_page' => $limit > 0 ? $limit : 6,
		);

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		if ( 'engagement' === $sort ) {
			$query_args['meta_key'] = '_sca_engagement_score';
			$query_args['orderby']  = 'meta_value_num';
			$query_args['order']    = 'DESC';
		} else {
			$query_args['meta_key'] = '_sca_timestamp';
			$query_args['orderby']  = 'meta_value';
			$query_args['order']    = 'DESC';
		}

		$query = new WP_Query( $query_args );
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No social posts available.', 'social-content-aggregator' ) . '</p>';
		}

		ob_start();
		?>
		<div class="sca-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$post_id     = get_the_ID();
				$engagement  = (int) get_post_meta( $post_id, '_sca_engagement_score', true );
				$original_url= get_post_meta( $post_id, '_sca_original_url', true );
				?>
				<article class="sca-card" style="border:1px solid #ddd;padding:12px;">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="sca-card__media" style="margin-bottom:8px;">
							<?php the_post_thumbnail( 'medium', array( 'style' => 'width:100%;height:auto;' ) ); ?>
						</div>
					<?php endif; ?>
					<div class="sca-card__caption"><?php echo esc_html( get_the_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 20 ) ); ?></div>
					<div class="sca-card__engagement" style="margin-top:8px;font-weight:600;">
						<?php echo esc_html( sprintf( __( 'Engagement: %d', 'social-content-aggregator' ), $engagement ) ); ?>
					</div>
					<?php if ( ! empty( $original_url ) ) : ?>
						<p><a href="<?php echo esc_url( $original_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Original Post', 'social-content-aggregator' ); ?></a></p>
					<?php endif; ?>
				</article>
				<?php
			endwhile;
			?>
		</div>
		<?php
		wp_reset_postdata();

		return (string) ob_get_clean();
	}
}
