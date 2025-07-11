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
			<h1 class="wp-heading-inline"><?php printf( __( 'DNS Monitor - %s', 'dns-monitor' ), esc_html( $domain ) ); ?></h1>
			<hr class="wp-header-end">

			<!-- HTMX-enabled Snapshots Table -->
			<div class="dns-monitor-card">
				<div class="dns-monitor-card-header">
					<?php esc_html_e( 'DNS Snapshots', 'dns-monitor' ); ?>
					<div class="dns-monitor-button-group">
						<?php if ( $domain ) : ?>
							<?php echo $this->admin_htmx->render_dns_check_button( array(
								'class' => 'button button-primary button-small',
							) ); ?>
						<?php endif; ?>
						<button type="button" class="button button-small dns-refresh-snapshots">
							<?php esc_html_e( 'Refresh', 'dns-monitor' ); ?>
						</button>
					</div>
				</div>
				<div id="dns-snapshots-container" class="dns-monitor-card-body">
					<?php echo $this->admin_htmx->render_snapshots_table( array(
						'auto_refresh' => false,
						'refresh_interval' => 60000, // 60 seconds
					) ); ?>
				</div>
			</div>

			<!-- DNS Check Results Area -->
			<div id="dns-check-results" class="dns-monitor-results-area"></div>

			<!-- Global notification area for HTMX responses -->
			<div id="dns-monitor-notifications" class="dns-monitor-notifications"></div>
		</div>
		<?php
	}

	/**
	 * Render admin footer
	 */
	private function render_admin_footer() {
		?>
		<div class="dns-monitor-admin-footer">
			<div class="dns-monitor-footer-content">
				<div class="dns-monitor-footer-text">
					<h3><?php esc_html_e( 'Frequently Asked Questions', 'dns-monitor' ); ?></h3>
					
					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'What happens when record changes are detected?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'When changes are detected, the plugin will:', 'dns-monitor' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'Create a new snapshot with the current DNS records', 'dns-monitor' ); ?></li>
							<li><?php esc_html_e( 'Send an email notification to the configured email addresses', 'dns-monitor' ); ?></li>
							<li><?php esc_html_e( 'Store the changes for historical comparison', 'dns-monitor' ); ?></li>
						</ul>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'Where are the email notifications sent?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Notifications are sent to the Administration Email Address configured in the WordPress General Settings.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'How can I review snapshots?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Simply navigate to "DNS Monitor" in your WordPress admin menu to see a detailed comparison view of your most recent snapshots.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'Can I manually check DNS records?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Yes, you can trigger a snapshot from the plugin dashboard at any time, in addition to the automated scheduled checks.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'How often are snapshots taken?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Automatic snapshots are taken once per day, but you can take snapshots manually anytime.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'Why are the snapshots not being taken at regular intervals?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Like most plugins with scheduled tasks, DNS Monitor uses the WordPress cron for automated snapshots, which requires the site to be loaded by live traffic. If you want to ensure the snapshots occur more regularly, you can use a third-party uptime monitoring service to intermittently load your site.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'How much storage does the plugin use?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'The plugin stores snapshots in your WordPress database. Each snapshot is typically very small (just a few KB), and the plugin cleans up older snapshots to keep storage usage to a minimum.', 'dns-monitor' ); ?></p>
					</div>

					<div class="dns-monitor-faq-item">
						<h4><?php esc_html_e( 'Is the plugin secure?', 'dns-monitor' ); ?></h4>
						<p><?php esc_html_e( 'Yes! The plugin follows WordPress guidelines and best practices for security. Additionally, the plugin does not store any sensitive data, and the data it does store is isolated in its own table.', 'dns-monitor' ); ?></p>
					</div>

				</div>
			</div>
		</div>
		<?php
	}
}