<?php
/**
 * Plugin Name: DNS Monitor
 * Plugin URI: https://indagodigital.us/dns-monitor
 * Description: Keep a vigilant eye on your domain's most critical infrastructure. DNS Monitor automatically tracks your DNS records, takes periodic snapshots, and instantly alerts you to any changes. Prevent downtime, detect unauthorized modifications, and gain peace of mind knowing your site's foundation is secure.
 * Version: 1.0.1
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.4
 * Author: Indago Digital
 * Author URI: https://indagodigital.us
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dns-monitor
 * Domain Path: /languages
 * Network: false
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'DNS_MONITOR_VERSION', '1.0.0' );
define( 'DNS_MONITOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DNS_MONITOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DNS_MONITOR_SITE_URL', get_site_url() );



// Include the main DNS Monitor class.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-dns-monitor.php';

/**
 * Main function to initialize the plugin
 *
 * @return DNS_Monitor
 */
function dns_monitor_init() {
	return DNS_Monitor::get_instance( __FILE__ );
}

// Initialize the plugin.
dns_monitor_init();