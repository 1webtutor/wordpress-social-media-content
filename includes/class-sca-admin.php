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
			'facebook_page_id'      => esc_html__( 'Facebook Page ID', 'social-content-aggregator' ),
			'instagram_account_id'  => esc_html__( 'Instagram Business Account ID', 'social-content-aggregator' ),
			'pinterest_board_id'    => esc_html__( 'Pinterest Board ID', 'social-content-aggregator' ),
			'meta_access_token'     => esc_html__( 'Meta Access Token', 'social-content-aggregator' ),
			'pinterest_access_token'=> esc_html__( 'Pinterest Access Token', 'social-content-aggregator' ),
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
		$keys      = array(
			'facebook_page_id',
			'instagram_account_id',
			'pinterest_board_id',
			'meta_access_token',
			'pinterest_access_token',
		);

		foreach ( $keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
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

		printf(
			'<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" autocomplete="off" />',
			esc_attr( $type ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $value )
		);
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
			<form action="options.php" method="post">
				<?php
				settings_fields( 'sca_settings_group' );
				do_settings_sections( 'sca-settings' );
				submit_button( esc_html__( 'Save Settings', 'social-content-aggregator' ) );
				?>
			</form>
		</div>
		<?php
	}
}
