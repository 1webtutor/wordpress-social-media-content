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

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sca_settings';

	/**
	 * API service.
	 *
	 * @var Social_Aggregator_API
	 */
	private $api;

	/**
	 * Keyword scheduler service.
	 *
	 * @var Social_Aggregator_Keyword_Scheduler
	 */
	private $keyword_scheduler;

	/**
	 * Constructor.
	 *
	 * @param Social_Aggregator_API               $api API service.
	 * @param Social_Aggregator_Keyword_Scheduler $keyword_scheduler Keyword scheduler.
	 */
	public function __construct( $api, $keyword_scheduler ) {
		$this->api               = $api;
		$this->keyword_scheduler = $keyword_scheduler;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sca_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Register settings group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'sca_settings_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Add plugin menus.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_menu_page(
			esc_html__( 'Social Aggregator', 'social-content-aggregator' ),
			esc_html__( 'Social Aggregator', 'social-content-aggregator' ),
			'manage_options',
			'sca-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-share',
			60
		);

		add_submenu_page(
			'sca-settings',
			esc_html__( 'General Settings', 'social-content-aggregator' ),
			esc_html__( 'General Settings', 'social-content-aggregator' ),
			'manage_options',
			'sca-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'sca-settings',
			esc_html__( 'Keyword Scheduler', 'social-content-aggregator' ),
			esc_html__( 'Keyword Scheduler', 'social-content-aggregator' ),
			'manage_options',
			'sca-keyword-scheduler',
			array( $this, 'render_keyword_scheduler_page' )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		$old = get_option( self::OPTION_KEY, array() );
		$out = array();

		$text_keys = array(
			'facebook_page_id',
			'instagram_account_id',
			'pinterest_board_id',
			'hashtag_blacklist',
		);

		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		if ( isset( $input['fallback_feed_urls'] ) ) {
			$out['fallback_feed_urls'] = sanitize_textarea_field( wp_unslash( $input['fallback_feed_urls'] ) );
		}

		$token_keys = array( 'meta_access_token', 'pinterest_access_token' );
		foreach ( $token_keys as $token_key ) {
			$new_value = isset( $input[ $token_key ] ) ? sanitize_text_field( wp_unslash( $input[ $token_key ] ) ) : '';
			if ( '' === $new_value && isset( $old[ $token_key ] ) ) {
				$out[ $token_key ] = (string) $old[ $token_key ];
			} else {
				$out[ $token_key ] = $new_value;
			}
		}

		$out['cache_ttl']      = isset( $input['cache_ttl'] ) ? (string) max( 300, absint( $input['cache_ttl'] ) ) : ( isset( $old['cache_ttl'] ) ? (string) max( 300, absint( $old['cache_ttl'] ) ) : (string) HOUR_IN_SECONDS );
		$out['sync_limit']     = isset( $input['sync_limit'] ) ? (string) max( 1, min( 50, absint( $input['sync_limit'] ) ) ) : ( isset( $old['sync_limit'] ) ? (string) max( 1, min( 50, absint( $old['sync_limit'] ) ) ) : '25' );
		$out['min_engagement'] = isset( $input['min_engagement'] ) ? (string) absint( $input['min_engagement'] ) : ( isset( $old['min_engagement'] ) ? (string) absint( $old['min_engagement'] ) : '0' );

		$publish_mode         = isset( $input['publish_mode'] ) ? sanitize_key( $input['publish_mode'] ) : ( isset( $old['publish_mode'] ) ? sanitize_key( $old['publish_mode'] ) : 'draft' );
		$out['publish_mode']  = in_array( $publish_mode, array( 'draft', 'publish', 'schedule' ), true ) ? $publish_mode : 'draft';
		$frequency            = isset( $input['schedule_frequency'] ) ? sanitize_key( $input['schedule_frequency'] ) : ( isset( $old['schedule_frequency'] ) ? sanitize_key( $old['schedule_frequency'] ) : 'once' );
		$out['schedule_frequency'] = in_array( $frequency, array( 'once', 'daily', 'weekly' ), true ) ? $frequency : 'once';

		$time = isset( $input['schedule_time'] ) ? sanitize_text_field( wp_unslash( $input['schedule_time'] ) ) : ( isset( $old['schedule_time'] ) ? (string) $old['schedule_time'] : '09:00' );
		$out['schedule_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '09:00';

		$target = isset( $input['target_post_type'] ) ? sanitize_key( $input['target_post_type'] ) : ( isset( $old['target_post_type'] ) ? sanitize_key( $old['target_post_type'] ) : 'social_posts' );
		$out['target_post_type'] = post_type_exists( $target ) ? $target : 'social_posts';

		$out['enable_feed_ingest'] = isset( $input['enable_feed_ingest'] ) ? '1' : '0';

		return $out;
	}

	/**
	 * Render settings page.
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
					<tr><th><?php esc_html_e( 'Facebook Page ID', 'social-content-aggregator' ); ?></th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[facebook_page_id]" value="<?php echo esc_attr( isset( $options['facebook_page_id'] ) ? $options['facebook_page_id'] : '' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Instagram Business Account ID', 'social-content-aggregator' ); ?></th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[instagram_account_id]" value="<?php echo esc_attr( isset( $options['instagram_account_id'] ) ? $options['instagram_account_id'] : '' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Pinterest Board ID', 'social-content-aggregator' ); ?></th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pinterest_board_id]" value="<?php echo esc_attr( isset( $options['pinterest_board_id'] ) ? $options['pinterest_board_id'] : '' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Meta Access Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[meta_access_token]" value="" /></td></tr>
					<tr><th><?php esc_html_e( 'Pinterest Access Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pinterest_access_token]" value="" /></td></tr>
					<tr><th><?php esc_html_e( 'Cache TTL (seconds)', 'social-content-aggregator' ); ?></th><td><input type="number" min="300" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_ttl]" value="<?php echo esc_attr( isset( $options['cache_ttl'] ) ? $options['cache_ttl'] : HOUR_IN_SECONDS ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Sync Limit Per Platform', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" max="50" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_limit]" value="<?php echo esc_attr( isset( $options['sync_limit'] ) ? $options['sync_limit'] : 25 ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Minimum Engagement Score', 'social-content-aggregator' ); ?></th><td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_engagement]" value="<?php echo esc_attr( isset( $options['min_engagement'] ) ? $options['min_engagement'] : '0' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Publishing Mode', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[publish_mode]"><option value="draft" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : 'draft', 'draft' ); ?>><?php esc_html_e( 'Save as Draft', 'social-content-aggregator' ); ?></option><option value="publish" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'publish' ); ?>><?php esc_html_e( 'Publish Immediately', 'social-content-aggregator' ); ?></option><option value="schedule" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'schedule' ); ?>><?php esc_html_e( 'Schedule Automatically', 'social-content-aggregator' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Schedule Time (HH:MM)', 'social-content-aggregator' ); ?></th><td><input type="time" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_time]" value="<?php echo esc_attr( isset( $options['schedule_time'] ) ? $options['schedule_time'] : '09:00' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Schedule Frequency', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_frequency]"><option value="once" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : 'once', 'once' ); ?>><?php esc_html_e( 'Once', 'social-content-aggregator' ); ?></option><option value="daily" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'social-content-aggregator' ); ?></option><option value="weekly" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'social-content-aggregator' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Target Post Type', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_post_type]"><?php foreach ( $post_types as $post_type ) : ?><option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( isset( $options['target_post_type'] ) ? $options['target_post_type'] : 'social_posts', $post_type->name ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></option><?php endforeach; ?></select></td></tr>
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
	 * Render keyword scheduler page.
	 *
	 * @return void
	 */
	public function render_keyword_scheduler_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$configs    = $this->keyword_scheduler->get_active_configs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Keyword Scheduler', 'social-content-aggregator' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sca_save_keyword_scheduler" />
				<?php wp_nonce_field( 'sca_save_keyword_scheduler', 'sca_keyword_scheduler_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Keyword', 'social-content-aggregator' ); ?></th><td><input type="text" required name="keyword" class="regular-text" /></td></tr>
					<tr><th><?php esc_html_e( 'Platforms', 'social-content-aggregator' ); ?></th><td><label><input type="checkbox" name="platforms[]" value="instagram" /> Instagram</label><br/><label><input type="checkbox" name="platforms[]" value="facebook" /> Facebook</label><br/><label><input type="checkbox" name="platforms[]" value="pinterest" /> Pinterest</label></td></tr>
					<tr><th><?php esc_html_e( 'Target Post Type', 'social-content-aggregator' ); ?></th><td><select name="post_type"><?php foreach ( $post_types as $post_type ) : ?><option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->singular_name ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><?php esc_html_e( 'Publish Mode', 'social-content-aggregator' ); ?></th><td><select name="publish_mode"><option value="draft">Draft</option><option value="publish">Publish</option><option value="schedule">Scheduled</option></select></td></tr>
					<tr><th><?php esc_html_e( 'Schedule Time', 'social-content-aggregator' ); ?></th><td><input type="time" name="schedule_time" value="09:00" /></td></tr>
					<tr><th><?php esc_html_e( 'Minimum Engagement Score', 'social-content-aggregator' ); ?></th><td><input type="number" min="0" name="min_engagement" value="0" /></td></tr>
					<tr><th><?php esc_html_e( 'Max Posts Per Fetch', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" max="50" name="max_posts" value="10" /></td></tr>
					<tr><th><?php esc_html_e( 'Frequency', 'social-content-aggregator' ); ?></th><td><select name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option></select></td></tr>
				</table>
				<?php submit_button( __( 'Add Keyword Scheduler', 'social-content-aggregator' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Active Keyword Schedulers', 'social-content-aggregator' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Keyword</th><th>Platforms</th><th>Post Type</th><th>Mode</th><th>Time</th><th>Min Engagement</th><th>Max Posts</th><th>Frequency</th></tr></thead>
				<tbody>
					<?php if ( empty( $configs ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No active keyword schedulers found.', 'social-content-aggregator' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $configs as $config ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $config->id ); ?></td>
								<td><?php echo esc_html( (string) $config->keyword ); ?></td>
								<td><?php echo esc_html( (string) $config->platforms ); ?></td>
								<td><?php echo esc_html( (string) $config->post_type ); ?></td>
								<td><?php echo esc_html( (string) $config->publish_mode ); ?></td>
								<td><?php echo esc_html( (string) $config->schedule_time ); ?></td>
								<td><?php echo esc_html( (string) $config->min_engagement ); ?></td>
								<td><?php echo esc_html( (string) $config->max_posts ); ?></td>
								<td><?php echo esc_html( (string) $config->frequency ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle manual sync.
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
