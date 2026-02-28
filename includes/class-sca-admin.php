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

	/** @var string */
	const PREVIEW_TRANSIENT_PREFIX = 'sca_keyword_preview_user_';

	/** @var Social_Aggregator_API */
	private $api;

	/** @var Social_Aggregator_Keyword_Scheduler */
	private $keyword_scheduler;

	/**
	 * Constructor.
	 */
	public function __construct( $api, $keyword_scheduler ) {
		$this->api               = $api;
		$this->keyword_scheduler = $keyword_scheduler;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sca_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_sca_preview_keyword_content', array( $this, 'handle_preview_keyword_content' ) );
		add_action( 'admin_post_sca_import_preview_content', array( $this, 'handle_import_preview_content' ) );
	}

	/**
	 * Register settings group.
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

		add_submenu_page(
			'sca-settings',
			esc_html__( 'Content Workbench', 'social-content-aggregator' ),
			esc_html__( 'Content Workbench', 'social-content-aggregator' ),
			'manage_options',
			'sca-content-workbench',
			array( $this, 'render_content_workbench_page' )
		);
	}

	/**
	 * Sanitize settings.
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

		$secure_keys = array(
			'meta_access_token',
			'pinterest_access_token',
			'decodo_api_key',
			'apify_api_token',
			'scrape_do_api_token',
		);
		foreach ( $secure_keys as $key ) {
			$value = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
			if ( '' === $value && isset( $old[ $key ] ) ) {
				$out[ $key ] = (string) $old[ $key ];
			} else {
				$out[ $key ] = $value;
			}
		}

		$out['cache_ttl']      = (string) max( 300, absint( isset( $input['cache_ttl'] ) ? $input['cache_ttl'] : ( isset( $old['cache_ttl'] ) ? $old['cache_ttl'] : HOUR_IN_SECONDS ) ) );
		$out['sync_limit']     = (string) max( 1, min( 50, absint( isset( $input['sync_limit'] ) ? $input['sync_limit'] : ( isset( $old['sync_limit'] ) ? $old['sync_limit'] : 25 ) ) ) );
		$out['min_engagement'] = (string) absint( isset( $input['min_engagement'] ) ? $input['min_engagement'] : ( isset( $old['min_engagement'] ) ? $old['min_engagement'] : 0 ) );

		$publish_mode        = isset( $input['publish_mode'] ) ? sanitize_key( $input['publish_mode'] ) : ( isset( $old['publish_mode'] ) ? sanitize_key( $old['publish_mode'] ) : 'draft' );
		$out['publish_mode'] = in_array( $publish_mode, array( 'draft', 'publish', 'schedule' ), true ) ? $publish_mode : 'draft';

		$frequency                 = isset( $input['schedule_frequency'] ) ? sanitize_key( $input['schedule_frequency'] ) : ( isset( $old['schedule_frequency'] ) ? sanitize_key( $old['schedule_frequency'] ) : 'once' );
		$out['schedule_frequency'] = in_array( $frequency, array( 'once', 'daily', 'weekly' ), true ) ? $frequency : 'once';

		$time = isset( $input['schedule_time'] ) ? sanitize_text_field( wp_unslash( $input['schedule_time'] ) ) : ( isset( $old['schedule_time'] ) ? (string) $old['schedule_time'] : '09:00' );
		$out['schedule_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '09:00';

		$target                  = isset( $input['target_post_type'] ) ? sanitize_key( $input['target_post_type'] ) : ( isset( $old['target_post_type'] ) ? sanitize_key( $old['target_post_type'] ) : 'social_posts' );
		$out['target_post_type'] = post_type_exists( $target ) ? $target : 'social_posts';

		$out['enable_feed_ingest']      = isset( $input['enable_feed_ingest'] ) ? '1' : '0';
		$out['decodo_monthly_limit']    = (string) max( 1, absint( isset( $input['decodo_monthly_limit'] ) ? $input['decodo_monthly_limit'] : ( isset( $old['decodo_monthly_limit'] ) ? $old['decodo_monthly_limit'] : 5000 ) ) );
		$out['apify_monthly_limit']     = (string) max( 1, absint( isset( $input['apify_monthly_limit'] ) ? $input['apify_monthly_limit'] : ( isset( $old['apify_monthly_limit'] ) ? $old['apify_monthly_limit'] : 5000 ) ) );
		$out['scrape_do_monthly_limit'] = (string) max( 1, absint( isset( $input['scrape_do_monthly_limit'] ) ? $input['scrape_do_monthly_limit'] : ( isset( $old['scrape_do_monthly_limit'] ) ? $old['scrape_do_monthly_limit'] : 5000 ) ) );

		return $out;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = get_option( self::OPTION_KEY, array() );
		$this->render_general_settings_form( $options );
	}

	/**
	 * Render keyword scheduler page.
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
			<table class="widefat striped"><thead><tr><th>ID</th><th>Keyword</th><th>Platforms</th><th>Post Type</th><th>Mode</th><th>Time</th><th>Min Engagement</th><th>Max Posts</th><th>Frequency</th></tr></thead><tbody>
			<?php if ( empty( $configs ) ) : ?><tr><td colspan="9"><?php esc_html_e( 'No active keyword schedulers found.', 'social-content-aggregator' ); ?></td></tr><?php else : foreach ( $configs as $config ) : ?>
			<tr><td><?php echo esc_html( (string) $config->id ); ?></td><td><?php echo esc_html( (string) $config->keyword ); ?></td><td><?php echo esc_html( (string) $config->platforms ); ?></td><td><?php echo esc_html( (string) $config->post_type ); ?></td><td><?php echo esc_html( (string) $config->publish_mode ); ?></td><td><?php echo esc_html( (string) $config->schedule_time ); ?></td><td><?php echo esc_html( (string) $config->min_engagement ); ?></td><td><?php echo esc_html( (string) $config->max_posts ); ?></td><td><?php echo esc_html( (string) $config->frequency ); ?></td></tr>
			<?php endforeach; endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * New workbench page to fetch preview and import selected rows.
	 */
	public function render_content_workbench_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id      = get_current_user_id();
		$preview_data = get_transient( self::PREVIEW_TRANSIENT_PREFIX . $user_id );
		$post_types   = get_post_types( array( 'public' => true ), 'objects' );

		$default_columns = array( 'select', 'title', 'caption', 'platform', 'source', 'engagement', 'relevance', 'url' );
		$columns         = isset( $preview_data['columns'] ) && is_array( $preview_data['columns'] ) ? $preview_data['columns'] : $default_columns;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Workbench', 'social-content-aggregator' ); ?></h1>
			<p><?php esc_html_e( 'Use this page to test data acquisition by keyword, inspect source/API provider, edit content, and import selected rows as posts.', 'social-content-aggregator' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sca_preview_keyword_content" />
				<?php wp_nonce_field( 'sca_preview_keyword_content', 'sca_preview_keyword_content_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Keyword', 'social-content-aggregator' ); ?></th><td><input type="text" class="regular-text" name="keyword" required /></td></tr>
					<tr><th><?php esc_html_e( 'Platforms', 'social-content-aggregator' ); ?></th><td><label><input type="checkbox" name="platforms[]" value="instagram" checked /> Instagram</label><br/><label><input type="checkbox" name="platforms[]" value="facebook" checked /> Facebook</label><br/><label><input type="checkbox" name="platforms[]" value="pinterest" checked /> Pinterest</label></td></tr>
					<tr><th><?php esc_html_e( 'Max Results', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" max="50" name="max_posts" value="10" /></td></tr>
					<tr><th><?php esc_html_e( 'Minimum Engagement', 'social-content-aggregator' ); ?></th><td><input type="number" min="0" name="min_engagement" value="0" /></td></tr>
					<tr><th><?php esc_html_e( 'Visible Columns', 'social-content-aggregator' ); ?></th><td>
						<label><input type="checkbox" name="columns[]" value="title" checked /> Title</label><br/>
						<label><input type="checkbox" name="columns[]" value="caption" checked /> Caption</label><br/>
						<label><input type="checkbox" name="columns[]" value="platform" checked /> Platform</label><br/>
						<label><input type="checkbox" name="columns[]" value="source" checked /> Source/API used</label><br/>
						<label><input type="checkbox" name="columns[]" value="engagement" checked /> Engagement</label><br/>
						<label><input type="checkbox" name="columns[]" value="relevance" checked /> Relevance</label><br/>
						<label><input type="checkbox" name="columns[]" value="url" checked /> Original URL</label>
					</td></tr>
				</table>
				<?php submit_button( __( 'Fetch Preview', 'social-content-aggregator' ) ); ?>
			</form>

			<?php if ( ! empty( $preview_data['items'] ) && is_array( $preview_data['items'] ) ) : ?>
				<?php $this->render_source_diagnostics( $preview_data['items'] ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sca_import_preview_content" />
					<?php wp_nonce_field( 'sca_import_preview_content', 'sca_import_preview_content_nonce' ); ?>
					<p><strong><?php esc_html_e( 'Import Options', 'social-content-aggregator' ); ?></strong></p>
					<p><label><?php esc_html_e( 'Target Post Type:', 'social-content-aggregator' ); ?> <select name="import_post_type"><?php foreach ( $post_types as $post_type ) : ?><option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->singular_name ); ?></option><?php endforeach; ?></select></label></p>
					<p><label><?php esc_html_e( 'Post Status:', 'social-content-aggregator' ); ?> <select name="import_post_status"><option value="draft">Draft</option><option value="publish">Publish</option></select></label></p>
					<table class="widefat striped">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Select', 'social-content-aggregator' ); ?></th>
							<?php if ( in_array( 'title', $columns, true ) ) : ?><th><?php esc_html_e( 'Title', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'caption', $columns, true ) ) : ?><th><?php esc_html_e( 'Caption (Editable)', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'platform', $columns, true ) ) : ?><th><?php esc_html_e( 'Platform', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'source', $columns, true ) ) : ?><th><?php esc_html_e( 'Source/API', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'engagement', $columns, true ) ) : ?><th><?php esc_html_e( 'Engagement', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'relevance', $columns, true ) ) : ?><th><?php esc_html_e( 'Relevance', 'social-content-aggregator' ); ?></th><?php endif; ?>
							<?php if ( in_array( 'url', $columns, true ) ) : ?><th><?php esc_html_e( 'Original URL', 'social-content-aggregator' ); ?></th><?php endif; ?>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $preview_data['items'] as $index => $item ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="selected_rows[]" value="<?php echo esc_attr( (string) $index ); ?>" />
									<input type="hidden" name="rows[<?php echo esc_attr( (string) $index ); ?>][title]" value="<?php echo esc_attr( wp_trim_words( wp_strip_all_tags( isset( $item['caption'] ) ? (string) $item['caption'] : '' ), 10, '…' ) ); ?>" />
									<input type="hidden" name="rows[<?php echo esc_attr( (string) $index ); ?>][platform]" value="<?php echo esc_attr( isset( $item['platform'] ) ? (string) $item['platform'] : '' ); ?>" />
									<input type="hidden" name="rows[<?php echo esc_attr( (string) $index ); ?>][source]" value="<?php echo esc_attr( isset( $item['ingest_source'] ) ? (string) $item['ingest_source'] : '' ); ?>" />
									<input type="hidden" name="rows[<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( isset( $item['permalink'] ) ? (string) $item['permalink'] : '' ); ?>" />
								</td>
								<?php if ( in_array( 'title', $columns, true ) ) : ?><td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( isset( $item['caption'] ) ? (string) $item['caption'] : '' ), 10, '…' ) ); ?></td><?php endif; ?>
								<?php if ( in_array( 'caption', $columns, true ) ) : ?><td><textarea name="rows[<?php echo esc_attr( (string) $index ); ?>][caption]" rows="4" class="large-text"><?php echo esc_textarea( isset( $item['caption'] ) ? (string) $item['caption'] : '' ); ?></textarea></td><?php endif; ?>
								<?php if ( in_array( 'platform', $columns, true ) ) : ?><td><?php echo esc_html( isset( $item['platform'] ) ? (string) $item['platform'] : '' ); ?></td><?php endif; ?>
								<?php if ( in_array( 'source', $columns, true ) ) : ?><td><?php echo esc_html( isset( $item['ingest_source'] ) ? (string) $item['ingest_source'] : '' ); ?></td><?php endif; ?>
								<?php if ( in_array( 'engagement', $columns, true ) ) : ?><td><?php echo esc_html( (string) ( isset( $item['engagement_score'] ) ? (int) $item['engagement_score'] : 0 ) ); ?></td><?php endif; ?>
								<?php if ( in_array( 'relevance', $columns, true ) ) : ?><td><?php echo esc_html( (string) ( isset( $item['relevance_score'] ) ? (int) $item['relevance_score'] : 0 ) ); ?></td><?php endif; ?>
								<?php if ( in_array( 'url', $columns, true ) ) : ?><td><?php if ( ! empty( $item['permalink'] ) ) : ?><a href="<?php echo esc_url( (string) $item['permalink'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'social-content-aggregator' ); ?></a><?php endif; ?></td><?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Import Selected Rows', 'social-content-aggregator' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render quick source diagnostics.
	 */
	private function render_source_diagnostics( $items ) {
		$counts = array();
		foreach ( $items as $item ) {
			$source = isset( $item['ingest_source'] ) ? sanitize_key( $item['ingest_source'] ) : 'unknown';
			if ( ! isset( $counts[ $source ] ) ) {
				$counts[ $source ] = 0;
			}
			$counts[ $source ]++;
		}
		echo '<h2>' . esc_html__( 'Source Diagnostics', 'social-content-aggregator' ) . '</h2><ul>';
		foreach ( $counts as $source => $count ) {
			echo '<li><strong>' . esc_html( $source ) . ':</strong> ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Handle preview fetch action.
	 */
	public function handle_preview_keyword_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'social-content-aggregator' ) );
		}
		check_admin_referer( 'sca_preview_keyword_content', 'sca_preview_keyword_content_nonce' );

		$keyword        = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$platforms      = isset( $_POST['platforms'] ) && is_array( $_POST['platforms'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['platforms'] ) ) : array();
		$max_posts      = isset( $_POST['max_posts'] ) ? max( 1, min( 50, absint( $_POST['max_posts'] ) ) ) : 10;
		$min_engagement = isset( $_POST['min_engagement'] ) ? absint( $_POST['min_engagement'] ) : 0;
		$columns        = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['columns'] ) ) : array();

		if ( empty( $keyword ) || empty( $platforms ) ) {
			wp_safe_redirect( add_query_arg( 'sca_preview_error', '1', admin_url( 'admin.php?page=sca-content-workbench' ) ) );
			exit;
		}

		$items = $this->api->fetch_keyword_posts( $keyword, $platforms, $max_posts, $min_engagement );

		set_transient(
			self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'keyword' => $keyword,
				'items'   => is_array( $items ) ? $items : array(),
				'columns' => $columns,
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=sca-content-workbench&sca_preview=1' ) );
		exit;
	}

	/**
	 * Handle import from preview table.
	 */
	public function handle_import_preview_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'social-content-aggregator' ) );
		}
		check_admin_referer( 'sca_import_preview_content', 'sca_import_preview_content_nonce' );

		$selected   = isset( $_POST['selected_rows'] ) && is_array( $_POST['selected_rows'] ) ? array_map( 'absint', wp_unslash( $_POST['selected_rows'] ) ) : array();
		$rows       = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		$post_type  = isset( $_POST['import_post_type'] ) ? sanitize_key( wp_unslash( $_POST['import_post_type'] ) ) : 'post';
		$post_status= isset( $_POST['import_post_status'] ) ? sanitize_key( wp_unslash( $_POST['import_post_status'] ) ) : 'draft';
		$post_type  = post_type_exists( $post_type ) ? $post_type : 'post';
		$post_status= in_array( $post_status, array( 'draft', 'publish' ), true ) ? $post_status : 'draft';

		$imported = 0;
		foreach ( $selected as $index ) {
			if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) ) {
				continue;
			}
			$row     = $rows[ $index ];
			$title   = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$caption = isset( $row['caption'] ) ? wp_kses_post( $row['caption'] ) : '';
			$url     = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
			$source  = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : 'unknown';
			$platform= isset( $row['platform'] ) ? sanitize_key( $row['platform'] ) : '';

			$post_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => $post_status,
					'post_title'   => ! empty( $title ) ? $title : wp_trim_words( wp_strip_all_tags( $caption ), 10, '…' ),
					'post_content' => $caption,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, '_sca_original_url', $url );
			update_post_meta( $post_id, '_sca_ingest_source', $source );
			update_post_meta( $post_id, '_sca_platform', $platform );
			++$imported;
		}

		wp_safe_redirect( add_query_arg( 'sca_imported', (string) $imported, admin_url( 'admin.php?page=sca-content-workbench' ) ) );
		exit;
	}

	/**
	 * Handle manual sync.
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

	/**
	 * Render main settings form.
	 */
	private function render_general_settings_form( $options ) {
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
					<tr><th><?php esc_html_e( 'Meta Access Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[meta_access_token]" value="" placeholder="<?php esc_attr_e( 'Leave blank to retain current', 'social-content-aggregator' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Pinterest Access Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pinterest_access_token]" value="" placeholder="<?php esc_attr_e( 'Leave blank to retain current', 'social-content-aggregator' ); ?>" /></td></tr>
					<tr><th colspan="2"><h2><?php esc_html_e( 'Scraper Providers (Pooling)', 'social-content-aggregator' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Decodo API Key', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[decodo_api_key]" value="" /></td></tr>
					<tr><th><?php esc_html_e( 'Apify API Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apify_api_token]" value="" /></td></tr>
					<tr><th><?php esc_html_e( 'Scrape.do API Token', 'social-content-aggregator' ); ?></th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scrape_do_api_token]" value="" /></td></tr>
					<tr><th><?php esc_html_e( 'Decodo Monthly API Call Limit', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[decodo_monthly_limit]" value="<?php echo esc_attr( isset( $options['decodo_monthly_limit'] ) ? $options['decodo_monthly_limit'] : 5000 ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Apify Monthly API Call Limit', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apify_monthly_limit]" value="<?php echo esc_attr( isset( $options['apify_monthly_limit'] ) ? $options['apify_monthly_limit'] : 5000 ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Scrape.do Monthly API Call Limit', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scrape_do_monthly_limit]" value="<?php echo esc_attr( isset( $options['scrape_do_monthly_limit'] ) ? $options['scrape_do_monthly_limit'] : 5000 ); ?>" /></td></tr>
					<tr><th colspan="2"><h2><?php esc_html_e( 'General Publishing', 'social-content-aggregator' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Cache TTL (seconds)', 'social-content-aggregator' ); ?></th><td><input type="number" min="300" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_ttl]" value="<?php echo esc_attr( isset( $options['cache_ttl'] ) ? $options['cache_ttl'] : HOUR_IN_SECONDS ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Sync Limit Per Platform', 'social-content-aggregator' ); ?></th><td><input type="number" min="1" max="50" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_limit]" value="<?php echo esc_attr( isset( $options['sync_limit'] ) ? $options['sync_limit'] : 25 ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Minimum Engagement Score', 'social-content-aggregator' ); ?></th><td><input type="number" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_engagement]" value="<?php echo esc_attr( isset( $options['min_engagement'] ) ? $options['min_engagement'] : '0' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Publishing Mode', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[publish_mode]"><option value="draft" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : 'draft', 'draft' ); ?>><?php esc_html_e( 'Save as Draft', 'social-content-aggregator' ); ?></option><option value="publish" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'publish' ); ?>><?php esc_html_e( 'Publish Immediately', 'social-content-aggregator' ); ?></option><option value="schedule" <?php selected( isset( $options['publish_mode'] ) ? $options['publish_mode'] : '', 'schedule' ); ?>><?php esc_html_e( 'Schedule Automatically', 'social-content-aggregator' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Schedule Time (HH:MM)', 'social-content-aggregator' ); ?></th><td><input type="time" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_time]" value="<?php echo esc_attr( isset( $options['schedule_time'] ) ? $options['schedule_time'] : '09:00' ); ?>" /></td></tr>
					<tr><th><?php esc_html_e( 'Schedule Frequency', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_frequency]"><option value="once" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : 'once', 'once' ); ?>><?php esc_html_e( 'Once', 'social-content-aggregator' ); ?></option><option value="daily" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'social-content-aggregator' ); ?></option><option value="weekly" <?php selected( isset( $options['schedule_frequency'] ) ? $options['schedule_frequency'] : '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'social-content-aggregator' ); ?></option></select></td></tr>
					<tr><th><?php esc_html_e( 'Target Post Type', 'social-content-aggregator' ); ?></th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_post_type]"><?php foreach ( $post_types as $post_type ) : ?><option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( isset( $options['target_post_type'] ) ? $options['target_post_type'] : 'social_posts', $post_type->name ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><?php esc_html_e( 'Enable RSS/Atom Ingestion', 'social-content-aggregator' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_feed_ingest]" value="1" <?php checked( isset( $options['enable_feed_ingest'] ) ? $options['enable_feed_ingest'] : '0', '1' ); ?> /> <?php esc_html_e( 'Enabled', 'social-content-aggregator' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Fallback Feed URLs', 'social-content-aggregator' ); ?></th><td><textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_feed_urls]" rows="4" class="large-text"><?php echo esc_textarea( isset( $options['fallback_feed_urls'] ) ? $options['fallback_feed_urls'] : '' ); ?></textarea></td></tr>
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
}

if ( ! class_exists( 'SCA_Admin' ) ) {
	class_alias( 'Social_Aggregator_Admin', 'SCA_Admin' );
}
