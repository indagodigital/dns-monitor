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
			'swap' => 'innerHTML',
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
	 * Render an HTMX-enabled snapshots list with unified view support
	 *
	 * @param array $attributes Optional list attributes.
	 * @return string List HTML.
	 */
	public function render_snapshots_table( $attributes = array() ) {
		$defaults = array(
			'id' => 'dns-snapshots-container', // Use the container ID
			'auto_refresh' => false,
			'refresh_interval' => 60000, // 60 seconds
			'unified_view' => true,
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$htmx_attrs = array(
			'trigger' => 'load',
			'target' => '#' . $attributes['id'], // Target the container itself
			'swap' => 'innerHTML',
		);

		if ( $attributes['auto_refresh'] ) {
			$htmx_attrs['trigger'] = 'load, every ' . $attributes['refresh_interval'] . 'ms';
		}

		// Add unified view parameter to the request
		if ( $attributes['unified_view'] ) {
			$htmx_attrs['hx-vals'] = wp_json_encode( array( 'unified_view' => true ) );
		}

		$container_attrs = $this->htmx->get_htmx_attributes( 'refresh_snapshots', $htmx_attrs );

		$html = '<div id="' . esc_attr( $attributes['id'] ) . '" ' . $container_attrs . '>';
		$html .= '<div class="dns-monitor-content-loading">';
		$html .= '<span class="spinner is-active"></span> ';
		$html .= esc_html__( 'Loading snapshots...', 'dns-monitor' );
		$html .= '</div>';
		$html .= '</div>';

		return $html;
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