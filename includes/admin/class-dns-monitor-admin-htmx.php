<?php
/**
 * Admin HTMX helpers for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DNS Monitor Admin HTMX helper class.
 */
class DNS_Monitor_Admin_HTMX {
    /**
     * Instance of this class
     *
     * @var DNS_Monitor_Admin_HTMX
     */
    protected static $instance = null;

    /**
     * HTMX instance
     *
     * @var DNS_Monitor_HTMX
     */
    protected $htmx;

    /**
     * Get the singleton instance of this class
     *
     * @return DNS_Monitor_Admin_HTMX
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->htmx = DNS_Monitor_HTMX::get_instance();
    }

    /**
     * Render an HTMX-enabled DNS check button
     *
     * @param array $attributes Optional button attributes.
     * @return string Button HTML.
     */
    public function render_dns_check_button( $attributes = array() ) {
        $defaults = array(
            'class' => 'dns-monitor-button',
            'loading_text' => __( 'Checking DNS...', 'dns-monitor' ),
            'target' => '#dns-snapshots-container',
            'swap' => 'outerHTML',
            'method' => 'POST', // DNS check endpoint requires POST
        );

        $attributes = wp_parse_args( $attributes, $defaults );

        return $this->htmx->get_htmx_button(
            'dns_check',
            __( 'Check DNS Now', 'dns-monitor' ),
            $attributes
        );
    }

    /**
     * Render the snapshots table for initial page load.
     * This method now directly renders the content instead of a loader.
     *
     * @return string The HTML for the snapshots table.
     */
    public function render_snapshots_table() {
        // To render the table, we need access to methods from the DNS_Monitor_Admin class
        // like format_wp_date(), so we get an instance of it here.
        $admin_instance = DNS_Monitor_Admin::get_instance();
        $db             = DNS_Monitor_DB::get_instance();
        $snapshots      = $db->get_snapshots( 1, 10 ); // Fetch initial 10 snapshots

        ob_start();

        // We pass the admin instance to the template so it can call the necessary helper methods.
        // This is a way to maintain context when rendering a view from a helper class.
        $template_scope = $admin_instance;
        include DNS_MONITOR_PLUGIN_DIR . 'includes/admin/views/snapshots-list.php';

        return ob_get_clean();
    }

    /**
     * Render HTMX-enabled DNS check section
     *
     * @return string Section HTML.
     */
    public function render_dns_check_section() {
        $site_url = DNS_MONITOR_SITE_URL;
        $domain = parse_url( $site_url, PHP_URL_HOST );

        $html = '<div class="dns-monitor-card">';
        $html .= '<div class="dns-monitor-card-header">' . esc_html__( 'Last Snapshot Results', 'dns-monitor' ) . '</div>';
        $html .= '<div class="dns-monitor-card-body">';
        
        if ( $domain ) {
            $html .= $this->render_dns_check_button();
            $html .= '<div id="dns-check-results" class="dns-monitor-results-area"></div>';
        } else {
            $html .= '<p class="dns-monitor-error">' . esc_html__( 'Unable to determine domain from site URL.', 'dns-monitor' ) . '</p>';
        }
        
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}