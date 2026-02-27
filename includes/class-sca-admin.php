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
 * Handles plugin settings page.
 */
class SCA_Admin {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'sca_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_sca_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Adds options page under Settings.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			esc_html__( 'Social Aggregator Settings', 'social-content-aggregator' ),
			esc_html__( 'Social Aggregator', 'social-content-aggregator' ),
			'manage_options',
			'sca-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'sca_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'sca_api_section',
			esc_html__( 'Social API Configuration', 'social-content-aggregator' ),
			array( $this, 'render_section_text' ),
			'sca-settings'
		);

		$fields = array(
			'facebook_page_id'       => esc_html__( 'Facebook Page ID', 'social-content-aggregator' ),
			'instagram_account_id'   => esc_html__( 'Instagram Business Account ID', 'social-content-aggregator' ),
			'pinterest_board_id'     => esc_html__( 'Pinterest Board ID', 'social-content-aggregator' ),
			'meta_access_token'      => esc_html__( 'Meta Access Token', 'social-content-aggregator' ),
			'pinterest_access_token' => esc_html__( 'Pinterest Access Token', 'social-content-aggregator' ),
			'cache_ttl'              => esc_html__( 'Cache TTL (seconds)', 'social-content-aggregator' ),
			'sync_limit'             => esc_html__( 'Sync Limit Per Platform', 'social-content-aggregator' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_text_field' ),
				'sca-settings',
				'sca_api_section',
				array(
					'key'  => $key,
					'type' => false !== strpos( $key, 'token' ) ? 'password' : 'text',
				)
			);
		}
	}

	/**
	 * Sanitizes settings payload.
	 *
	 * @param array<string,string> $input Raw input.
	 * @return array<string,string>
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		$existing  = get_option( self::OPTION_KEY, array() );
		$keys      = array(
			'facebook_page_id',
			'instagram_account_id',
			'pinterest_board_id',
			'meta_access_token',
			'pinterest_access_token',
			'cache_ttl',
			'sync_limit',
		);

		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $input[ $key ] ) );

			if ( in_array( $key, array( 'meta_access_token', 'pinterest_access_token' ), true ) && '' === $value ) {
				$value = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
			}

			if ( 'cache_ttl' === $key ) {
				$ttl              = max( 300, absint( $value ) );
				$sanitized[ $key ] = (string) $ttl;
				continue;
			}

			if ( 'sync_limit' === $key ) {
				$limit             = absint( $value );
				$limit             = $limit < 1 || $limit > 50 ? 25 : $limit;
				$sanitized[ $key ] = (string) $limit;
				continue;
			}

			$sanitized[ $key ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Renders section heading text.
	 *
	 * @return void
	 */
	public function render_section_text() {
		echo '<p>' . esc_html__( 'Use OAuth-generated access tokens from official Meta and Pinterest APIs.', 'social-content-aggregator' ) . '</p>';
	}

	/**
	 * Renders input field.
	 *
	 * @param array<string,string> $args Field args.
	 * @return void
	 */
	public function render_text_field( $args ) {
		$options = get_option( self::OPTION_KEY, array() );
		$key     = isset( $args['key'] ) ? $args['key'] : '';
		$type    = isset( $args['type'] ) ? $args['type'] : 'text';
		$value   = isset( $options[ $key ] ) ? $options[ $key ] : '';

		if ( in_array( $key, array( 'meta_access_token', 'pinterest_access_token' ), true ) ) {
			$value = '';
		}

		printf(
			'<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" autocomplete="off" />',
			esc_attr( $type ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $value )
		);

		if ( in_array( $key, array( 'meta_access_token', 'pinterest_access_token' ), true ) ) {
			echo '<p class="description">' . esc_html__( 'Leave blank to keep existing token.', 'social-content-aggregator' ) . '</p>';
		}
	}

	/**
	 * Displays settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Social Content Aggregator', 'social-content-aggregator' ); ?></h1>
			<?php if ( isset( $_GET['sca_sync'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Manual sync completed.', 'social-content-aggregator' ); ?></p></div>
			<?php endif; ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'sca_settings_group' );
				do_settings_sections( 'sca-settings' );
				submit_button( esc_html__( 'Save Settings', 'social-content-aggregator' ) );
				?>
			</form>
			<hr />
			<h2><?php echo esc_html__( 'Manual Sync', 'social-content-aggregator' ); ?></h2>
			<p><?php echo esc_html__( 'Run an immediate sync from authorized APIs.', 'social-content-aggregator' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="sca_manual_sync" />
				<?php wp_nonce_field( 'sca_manual_sync_action', 'sca_manual_sync_nonce' ); ?>
				<?php submit_button( esc_html__( 'Sync Now', 'social-content-aggregator' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles manual sync request.
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'social-content-aggregator' ) );
		}

		$nonce = isset( $_POST['sca_manual_sync_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sca_manual_sync_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sca_manual_sync_action' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'social-content-aggregator' ) );
		}

		$api_service = new SCA_API_Service();
		$api_service->sync_all_platform_posts( true );

		wp_safe_redirect( add_query_arg( array( 'page' => 'sca-settings', 'sca_sync' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
