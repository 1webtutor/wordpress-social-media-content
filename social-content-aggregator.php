<?php
/**
 * Plugin Name: Social Content Aggregator
 * Description: Aggregates authorized social media posts from official APIs and displays them via shortcode.
 * Version: 1.0.0
 * Author: Social Content Aggregator Team
 * License: GPL-2.0-or-later
 * Text Domain: social-content-aggregator
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SCA_PLUGIN_FILE' ) ) {
	define( 'SCA_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'SCA_PLUGIN_DIR' ) ) {
	define( 'SCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once SCA_PLUGIN_DIR . 'includes/class-sca-admin.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-cpt.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-api-service.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-shortcode.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-plugin.php';

/**
 * Boots plugin instance.
 *
 * @return SCA_Plugin
 */
function sca_plugin() {
	return SCA_Plugin::get_instance();
}

register_activation_hook( __FILE__, array( 'SCA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCA_Plugin', 'deactivate' ) );

sca_plugin();
