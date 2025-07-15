<?php
/**
 * Main DNS Monitor class
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main DNS Monitor class.
 */
class DNS_Monitor {
	/**
	 * Instance of this class
	 *
	 * @var DNS_Monitor
	 */
	protected static $instance = null;

	/**
	 * Admin instance
	 *
	 * @var DNS_Monitor_Admin
	 */
	protected $admin = null;

	/**
	 * HTMX instance
	 *
	 * @var DNS_Monitor_HTMX
	 */
	protected $htmx = null;

	/**
	 * API instance
	 *
	 * @var DNS_Monitor_API
	 */
	protected $api = null;

	/**
	 * DB instance
	 *
	 * @var DNS_Monitor_DB
	 */
	protected $db = null;

	/**
	 * Notifications instance
	 *
	 * @var DNS_Monitor_Notifications
	 */
	protected $notifications = null;

	/**
	 * The plugin file path
	 *
	 * @var string
	 */
	protected $plugin_file = null;

	/**
	 * Get the singleton instance of this class
	 *
	 * @param string $plugin_file The main plugin file path.
	 * @return DNS_Monitor
	 */
	public static function get_instance( $plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @param string $plugin_file The main plugin file path.
	 */
	private function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->includes();
		$this->instantiate();
		$this->init_hooks();
	}

	/**
	 * Include required files
	 */
	private function includes() {
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/class-dns-monitor-db.php';
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/class-dns-monitor-records.php';
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/class-dns-monitor-notifications.php';
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/admin/class-dns-monitor-admin.php';
		
		// HTMX functionality
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/class-dns-monitor-htmx.php';
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/class-dns-monitor-api.php';
		require_once DNS_MONITOR_PLUGIN_DIR . 'includes/admin/class-dns-monitor-admin-htmx.php';
	}

	/**
	 * Instantiate classes
	 */
	private function instantiate() {
		$this->db            = DNS_Monitor_DB::get_instance();
		$this->notifications = DNS_Monitor_Notifications::get_instance();
		$this->htmx          = DNS_Monitor_HTMX::get_instance();
		$this->api           = DNS_Monitor_API::get_instance();

		if ( is_admin() ) {
			$this->admin = DNS_Monitor_Admin::get_instance();
		}
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		register_activation_hook( $this->plugin_file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate' ) );

		// Schedule cron job.
		add_action( 'dns_monitor_check', array( 'DNS_Monitor_Records', 'check_site_dns' ) );
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		$this->db->create_tables();

		// Set default options if they don't exist.
		if ( false === get_option( 'dns_monitor_delete_data_on_uninstall' ) ) {
			add_option( 'dns_monitor_delete_data_on_uninstall', 'no' );
		}

		// Get the current site's domain for initial snapshot.
		$site_url = DNS_MONITOR_SITE_URL;
		$domain   = parse_url( $site_url, PHP_URL_HOST );

		// Create an initial snapshot if we have a valid domain.
		if ( $domain && ! empty( $domain ) ) {
			DNS_Monitor_Records::fetch_and_process_records( $domain, true, false );
		}

		$this->schedule_cron();

		// Send activation notification.
		$this->notifications->send_activation_notification( $domain );
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		$this->deactivate_cron();

		// Send deactivation notification.
		$this->notifications->send_deactivation_notification();
	}

	/**
	 * Schedule cron job
	 */
	public function schedule_cron() {
		$frequency = get_option( 'dns_monitor_check_frequency', 'daily' );

		// Check if cron is already scheduled.
		$next_scheduled = wp_next_scheduled( 'dns_monitor_check' );

		if ( ! $next_scheduled ) {
			// No cron scheduled, create it.
			wp_schedule_event( time(), $frequency, 'dns_monitor_check' );
		} else {
			// Cron exists, check if frequency matches.
			$scheduled_events = wp_get_scheduled_event( 'dns_monitor_check' );
			if ( $scheduled_events && isset( $scheduled_events->schedule ) && $scheduled_events->schedule !== $frequency ) {
				// Frequency mismatch, reschedule.
				wp_clear_scheduled_hook( 'dns_monitor_check' );
				wp_schedule_event( time(), $frequency, 'dns_monitor_check' );
			}
		}
	}

	/**
	 * Deactivate cron job
	 */
	public function deactivate_cron() {
		wp_clear_scheduled_hook( 'dns_monitor_check' );
	}

	/**
	 * Get HTMX instance
	 *
	 * @return DNS_Monitor_HTMX HTMX instance.
	 */
	public function get_htmx() {
		return $this->htmx;
	}

	/**
	 * Get API instance
	 *
	 * @return DNS_Monitor_API API instance.
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * Get Admin HTMX helper instance
	 *
	 * @return DNS_Monitor_Admin_HTMX Admin HTMX helper instance.
	 */
	public function get_admin_htmx() {
		return DNS_Monitor_Admin_HTMX::get_instance();
	}
}