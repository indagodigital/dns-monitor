<?php
/**
 * Admin functionality for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functionality class for DNS Monitor.
 */
class DNS_Monitor_Admin {
	/**
	 * Admin HTMX helper instance
	 *
	 * @var DNS_Monitor_Admin_HTMX
	 */
	protected $admin_htmx;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		
		// Custom admin footer hooks
		add_filter( 'admin_footer_text', array( $this, 'custom_admin_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'custom_admin_footer_version' ), 9999 );
		
		// Initialize HTMX helper
		$this->admin_htmx = DNS_Monitor_Admin_HTMX::get_instance();
	}

	/**
	 * Format WordPress date with timezone conversion
	 *
	 * @param string $date_string UTC date string.
	 * @param bool   $include_time Whether to include time.
	 * @return string Formatted date.
	 */
	private function format_wp_date( $date_string, $include_time = true ) {
		if ( empty( $date_string ) ) {
			return __( 'Never', 'dns-monitor' );
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		if ( $include_time ) {
			$format = $date_format . ' ' . $time_format;
		} else {
			$format = $date_format;
		}

		// Convert from UTC to WordPress timezone.
		try {
			$wp_timezone = wp_timezone();
			$date        = new DateTime( $date_string, new DateTimeZone( 'UTC' ) );
			$date->setTimezone( $wp_timezone );

			return wp_date( $format, $date->getTimestamp() );
		} catch ( Exception $e ) {
			// Fallback to legacy method if timezone conversion fails.
			$timestamp = strtotime( $date_string );
			if ( ! $timestamp ) {
				return __( 'Never', 'dns-monitor' );
			}

			return date_i18n( $format, $timestamp );
		}
	}

	/**
	 * Register admin menu pages
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'DNS Monitor', 'dns-monitor' ),
			__( 'DNS Monitor', 'dns-monitor' ),
			'manage_options',
			'dns-monitor',
			array( $this, 'snapshots_page' ),
			'dashicons-welcome-view-site',
			30
		);

		add_submenu_page(
			'dns-monitor',
			__( 'DNS Snapshots', 'dns-monitor' ),
			__( 'Snapshots', 'dns-monitor' ),
			'manage_options',
			'dns-monitor',
			array( $this, 'snapshots_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( false !== strpos( $hook, 'dns-monitor' ) ) {
			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style( 'dns-monitor-admin', DNS_MONITOR_PLUGIN_URL . 'assets/css/admin.css', array(), DNS_MONITOR_VERSION );
			wp_enqueue_script( 'dns-monitor-admin', DNS_MONITOR_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), DNS_MONITOR_VERSION, true );
		}
	}

	/**
	 * Main snapshots page
	 */
	public function snapshots_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'dns-monitor' ) );
		}

		// Get current site domain for display.
		$site_url = DNS_MONITOR_SITE_URL;
		$domain   = parse_url( $site_url, PHP_URL_HOST );

		?>
		<div class="wrap">
			<div class="dns-monitor-header">
				<div class="dns-monitor-logo-group">
					<img src="<?php echo DNS_MONITOR_PLUGIN_URL . 'assets/img/dns-monitor-logo.svg'; ?>" alt="DNS Monitor Logo" class="dns-monitor-logo">
					<h1 class="dns-monitor-title">DNS Monitor</h1>
				</div>
				<div class="dns-monitor-button-group">
					<?php if ( $domain ) : ?>
						<?php echo $this->admin_htmx->render_dns_check_button(); ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- HTMX-enabled Snapshots Table -->
			<div id="dns-snapshots-container">
				<?php echo $this->admin_htmx->render_snapshots_table( array(
					'auto_refresh' => false,
					'refresh_interval' => 60000, // 60 seconds
				) ); ?>
			</div>

			<!-- DNS Check Results Area -->
			<div id="dns-check-results" class="dns-monitor-results-area" style="display: none;"></div>

			<!-- Global notification area for HTMX responses -->
			<div id="dns-monitor-notifications" class="dns-monitor-notifications"></div>
		</div>
		<?php
	}

	/**
	 * Handle admin actions
	 */
	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show admin messages
		if ( isset( $_GET['dns_monitor_message'] ) ) {
			add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		}
	}

	/**
	 * Custom admin footer text for DNS Monitor pages
	 *
	 * @param string $footer_text Default footer text.
	 * @return string Custom or default footer text.
	 */
	public function custom_admin_footer_text( $footer_text ) {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'dns-monitor' ) !== false ) {
			$logo_url = DNS_MONITOR_PLUGIN_URL . 'assets/img/indago-digital-logo.svg';
			return sprintf(
				'<span class="dns-monitor-footer-wrapper"><img src="%s" alt="Indago Digital Logo" class="dns-monitor-footer-logo"> <span>%s</span></span>',
				esc_url( $logo_url ),
				__( 'Created by <a href="https://indagodigital.us" class="dns-monitor-footer-link" target="_blank">IndaGo&nbsp;Digital</a>', 'dns-monitor' )
			);
		}
		return $footer_text;
	}

	/**
	 * Custom admin footer version for DNS Monitor pages
	 *
	 * @param string $footer_version Default footer version text.
	 * @return string Custom or default footer version.
	 */
	public function custom_admin_footer_version( $footer_version ) {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'dns-monitor' ) !== false ) {
			return sprintf(
				'<span class="dns-monitor-footer-wrapper"><a href="https://indagodigital.us/dns-monitor-documentation" class="dns-monitor-footer-link" target="_blank">%s</a> <span class="dns-monitor-footer-version">%s</span></span>',
				__( 'Documentation', 'dns-monitor' ),
				sprintf( __( 'Version %s', 'dns-monitor' ), DNS_MONITOR_VERSION ),
			);
		}
		return $footer_version;
	}
}