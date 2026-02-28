<?php
/**
 * Admin settings class.
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI and settings.
 */
class Social_Aggregator_Admin {

	/** @var string */
	const OPTION_KEY = 'sca_settings';

	/** @var Social_Aggregator_API */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Social_Aggregator_API $api API.
	 */
	public function __construct( $api ) {
		$this->api = $api;
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sca_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'sca_settings_group', self::OPTION_KEY, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
		add_settings_section( 'sca_main', esc_html__( 'Social Aggregator Configuration', 'social-content-aggregator' ), '__return_false', 'sca-settings' );
	}

	/**
	 * Add settings page.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page( 'Social Aggregator', 'Social Aggregator', 'manage_options', 'sca-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		$old = get_option( self::OPTION_KEY, array() );
		$out = array();

		$text_keys = array( 'facebook_page_id', 'instagram_account_id', 'pinterest_board_id', 'cache_ttl', 'sync_limit', 'schedule_time', 'schedule_frequency', 'publish_mode', 'target_post_type', 'fallback_feed_urls', 'scrape_urls', 'hashtag_blacklist' );
		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		$tokens = array( 'meta_access_token', 'pinterest_access_token' );
		foreach ( $tokens as $token_key ) {
			$raw = isset( $input[ $token_key ] ) ? sanitize_text_field( wp_unslash( $input[ $token_key ] ) ) : '';
			if ( '' === $raw && isset( $old[ $token_key ] ) ) {
				$out[ $token_key ] = $old[ $token_key ];
			} else {
				$out[ $token_key ] = $raw;
			}
		}

		$out['enable_feed_ingest'] = isset( $input['enable_feed_ingest'] ) ? '1' : '0';
		$out['enable_scraping']    = isset( $input['enable_scraping'] ) ? '1' : '0';
		$out['cache_ttl']          = (string) max( 300, absint( isset( $out['cache_ttl'] ) ? $out['cache_ttl'] : HOUR_IN_SECONDS ) );
		$out['sync_limit']         = (string) max( 1, min( 50, absint( isset( $out['sync_limit'] ) ? $out['sync_limit'] : 25 ) ) );
		$out['min_engagement']     = (string) absint( isset( $input['min_engagement'] ) ? $input['min_engagement'] : 0 );
		$out['publish_mode']       = in_array( $out['publish_mode'], array( 'draft', 'publish', 'schedule' ), true ) ? $out['publish_mode'] : 'draft';
		$out['schedule_frequency'] = in_array( $out['schedule_frequency'], array( 'once', 'daily', 'weekly' ), true ) ? $out['schedule_frequency'] : 'once';
		$out['target_post_type']   = post_type_exists( $out['target_post_type'] ) ? $out['target_post_type'] : 'social_posts';

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $out['schedule_time'] ) ) {
			$out['schedule_time'] = '09:00';
		}

		return $out;
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options    = get_option( self::OPTION_KEY, array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Social Aggregator Settings', 'social-content-aggregator' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'sca_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>Facebook Page ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[facebook_page_id]" value="<?php echo esc_attr( isset( $options['facebook_page_id'] ) ? $options['facebook_page_id'] : '' ); ?>" /></td></tr>
					<tr><th>Instagram Business Account ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[instagram_account_id]" value="<?php echo esc_attr( isset( $options['instagram_account_id'] ) ? $options['instagram_account_id'] : '' ); ?>" /></td></tr>
					<tr><th>Pinterest Board ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pinterest_board_id]" value="<?php echo esc_attr( isset( $options['pinterest_board_id'] ) ? $options['pinterest_board_id'] : '' ); ?>" /></td></tr>
					<tr><th>Meta Access Token</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[meta_access_token]" value="" placeholder="Leave blank to keep existing" /></td></tr>
					<tr><th>Pinterest Access Token</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pinterest_access_token]" value="" placeholder="Leave blank to keep existing" /></td></tr>
					<tr><th>Minimum Engagement Score</th><td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_engagement]" value="<?php echo esc_attr( isset( $options['min_engagement'] ) ? $options['min_engagement'] : '0' ); ?>" /></td></tr>
					<tr><th>Publishing Mode</th><td>
						<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[publish_mode]">
							<option value="draft" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : 'draft', 'draft' ); ?>>Save as Draft</option>
							<option value="publish" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'publish' ); ?>>Publish Immediately</option>
							<option value="schedule" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'schedule' ); ?>>Schedule Automatically</option>
						</select></td></tr>
					<tr><th>Schedule Time (HH:MM)</th><td><input type="time" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_time]" value="<?php echo esc_attr( isset( $options['schedule_time'] ) ? $options['schedule_time'] : '09:00' ); ?>" /></td></tr>
					<tr><th>Schedule Frequency</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_frequency]"><option value="once" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : 'once', 'once' ); ?>>Once</option><option value="daily" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'daily' ); ?>>Daily</option><option value="weekly" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'weekly' ); ?>>Weekly</option></select></td></tr>
					<tr><th>Target Post Type</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_post_type]"><?php foreach ( $post_types as $post_type ) : ?><option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( isset( $options['target_post_type'] ) ? $options['target_post_type'] : 'social_posts', $post_type->name ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th>Enable RSS/Atom Ingestion</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_feed_ingest]" value="1" <?php checked( isset( $options['enable_feed_ingest'] ) ? $options['enable_feed_ingest'] : '0', '1' ); ?> /> Enabled</label></td></tr>
					<tr><th>Fallback Feed URLs</th><td><textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_feed_urls]" rows="4" class="large-text"><?php echo esc_textarea( isset( $options['fallback_feed_urls'] ) ? $options['fallback_feed_urls'] : '' ); ?></textarea></td></tr>
					<tr><th>Enable Scraping</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_scraping]" value="1" <?php checked( isset( $options['enable_scraping'] ) ? $options['enable_scraping'] : '0', '1' ); ?> /> Enabled (public pages only)</label></td></tr>
					<tr><th>Scrape URLs</th><td><textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scrape_urls]" rows="4" class="large-text"><?php echo esc_textarea( isset( $options['scrape_urls'] ) ? $options['scrape_urls'] : '' ); ?></textarea><p class="description">One URL per line.</p></td></tr>
					<tr><th>Hashtag blacklist</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hashtag_blacklist]" value="<?php echo esc_attr( isset( $options['hashtag_blacklist'] ) ? $options['hashtag_blacklist'] : '' ); ?>" /><p class="description">Comma-separated terms to exclude.</p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sca_manual_sync" />
				<?php wp_nonce_field( 'sca_manual_sync', 'sca_manual_sync_nonce' ); ?>
				<?php submit_button( __( 'Sync Now', 'social-content-aggregator' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Manual sync action.
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'social-content-aggregator' ) );
		}
		check_admin_referer( 'sca_manual_sync', 'sca_manual_sync_nonce' );
		$this->api->sync_all_platform_posts( true );
		wp_safe_redirect( add_query_arg( 'sca_synced', '1', wp_get_referer() ) );
		exit;
	}
}

if ( ! class_exists( 'SCA_Admin' ) ) {
	class_alias( 'Social_Aggregator_Admin', 'SCA_Admin' );
}
