<?php
/**
 * Plugin Name: Social Content Aggregator
 * Description: Aggregates social media posts from APIs and optional scraping fallbacks, then publishes using configurable workflows.
 * Version: 2.0.0
 * Author: Social Content Aggregator Team
 * License: GPL-2.0-or-later
 * Text Domain: social-content-aggregator
 *
 * @package SocialContentAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCA_PLUGIN_FILE', __FILE__ );
define( 'SCA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SCA_PLUGIN_DIR . 'includes/class-sca-content-processor.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-hashtag-engine.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-scheduler.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-cpt.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-api-service.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-admin.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-shortcode.php';
require_once SCA_PLUGIN_DIR . 'includes/class-sca-plugin.php';

/**
 * Boot plugin.
 *
 * @return Social_Aggregator_Main
 */
function sca_plugin() {
	return Social_Aggregator_Main::get_instance();
}

register_activation_hook( __FILE__, array( 'Social_Aggregator_Main', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Social_Aggregator_Main', 'deactivate' ) );

sca_plugin();
