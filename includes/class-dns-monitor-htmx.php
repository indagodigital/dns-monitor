<?php
/**
 * HTMX functionality for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS Monitor HTMX class.
 */
class DNS_Monitor_HTMX {
	/**
	 * Instance of this class
	 *
	 * @var DNS_Monitor_HTMX
	 */
	protected static $instance = null;

	/**
	 * Registered API endpoints
	 *
	 * @var array
	 */
	protected $endpoints = array();

	/**
	 * HTMX nonce action
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'dns_monitor_htmx_nonce';

	/**
	 * Get the singleton instance of this class
	 *
	 * @return DNS_Monitor_HTMX
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_htmx_scripts' ) );
		add_action( 'wp_ajax_dns_monitor_htmx', array( $this, 'handle_htmx_request' ) );
		add_action( 'wp_ajax_nopriv_dns_monitor_htmx', array( $this, 'handle_htmx_request' ) );
		add_action( 'admin_footer', array( $this, 'output_htmx_config' ) );
	}

	/**
	 * Enqueue HTMX scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_htmx_scripts( $hook ) {
		// Only load on DNS Monitor pages
		if ( false === strpos( $hook, 'dns-monitor' ) ) {
			return;
		}

		// Enqueue HTMX library
		wp_enqueue_script( 
			'htmx', 
			DNS_MONITOR_PLUGIN_URL . 'assets/js/htmx.min.js', 
			array(), 
			'2.0.6', 
			true 
		);

		// Enqueue custom HTMX helpers
		wp_enqueue_script(
			'dns-monitor-htmx',
			DNS_MONITOR_PLUGIN_URL . 'assets/js/htmx-helpers.js',
			array( 'htmx', 'jquery' ),
			DNS_MONITOR_VERSION,
			true
		);

		// Localize script with AJAX URL and nonce
		wp_localize_script(
			'dns-monitor-htmx',
			'dnsMonitorHtmx',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => 'dns_monitor_htmx',
			)
		);
	}

	/**
	 * Register a new HTMX API endpoint
	 *
	 * @param string   $endpoint   Endpoint identifier.
	 * @param callable $callback   Callback function to handle the endpoint.
	 * @param array    $args       Optional arguments (capability, methods, etc.).
	 * @return bool True if registered successfully.
	 */
	public function register_endpoint( $endpoint, $callback, $args = array() ) {
		if ( empty( $endpoint ) || ! is_callable( $callback ) ) {
			return false;
		}

		$defaults = array(
			'capability' => 'manage_options',
			'methods'    => array( 'GET', 'POST' ),
			'public'     => false,
		);

		$args = wp_parse_args( $args, $defaults );

		$this->endpoints[ $endpoint ] = array(
			'callback'   => $callback,
			'capability' => $args['capability'],
			'methods'    => $args['methods'],
			'public'     => $args['public'],
		);

		return true;
	}

	/**
	 * Handle HTMX AJAX requests
	 */
	public function handle_htmx_request() {
		// Get nonce from URL parameter or header
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) );
		if ( empty( $nonce ) && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
		}
		
		// Verify nonce
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( 
				wp_json_encode( array( 'error' => 'Invalid nonce' ) ), 
				'Security Error', 
				array( 'response' => 403 ) 
			);
		}

		$endpoint = sanitize_text_field( wp_unslash( $_REQUEST['endpoint'] ?? '' ) );

		if ( empty( $endpoint ) || ! isset( $this->endpoints[ $endpoint ] ) ) {
			wp_die( 
				wp_json_encode( array( 'error' => 'Invalid endpoint' ) ), 
				'Invalid Endpoint', 
				array( 'response' => 404 ) 
			);
		}

		$endpoint_config = $this->endpoints[ $endpoint ];
		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		// Check if method is allowed
		if ( ! in_array( $method, $endpoint_config['methods'], true ) ) {
			wp_die( 
				wp_json_encode( array( 'error' => 'Method not allowed' ) ), 
				'Method Not Allowed', 
				array( 'response' => 405 ) 
			);
		}

		// Check capabilities (unless public endpoint)
		if ( ! $endpoint_config['public'] && ! current_user_can( $endpoint_config['capability'] ) ) {
			wp_die( 
				wp_json_encode( array( 'error' => 'Insufficient permissions' ) ), 
				'Forbidden', 
				array( 'response' => 403 ) 
			);
		}

		// Call the endpoint callback
		try {
			$result = call_user_func( $endpoint_config['callback'], $_REQUEST );
			
			// If result is an array, assume it's JSON data
			if ( is_array( $result ) ) {
				wp_send_json( $result );
			} else {
				// Otherwise, output as HTML
				echo wp_kses_post( $result );
			}
		} catch ( Exception $e ) {
			wp_die( 
				wp_json_encode( array( 'error' => $e->getMessage() ) ), 
				'Internal Server Error', 
				array( 'response' => 500 ) 
			);
		} catch ( Error $e ) {
			wp_die( 
				wp_json_encode( array( 'error' => $e->getMessage() ) ), 
				'Internal Server Error', 
				array( 'response' => 500 ) 
			);
		}

		wp_die();
	}

	/**
	 * Output HTMX configuration in admin footer
	 */
	public function output_htmx_config() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'dns-monitor' ) ) {
			return;
		}

		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Configure HTMX globally
			htmx.config.globalViewTransitions = true;
			htmx.config.defaultSwapStyle = 'outerHTML';
			htmx.config.defaultSwapDelay = 100;
			htmx.config.defaultSettleDelay = 100;
			
			// Set up global request headers
			document.body.addEventListener('htmx:configRequest', function(evt) {
				evt.detail.headers['X-WP-Nonce'] = dnsMonitorHtmx.nonce;
			});
			
			// Handle errors globally - delegate to DNSMonitorHTMX helper
			document.body.addEventListener('htmx:responseError', function(evt) {
				console.error('HTMX Error:', evt.detail);
				
				// Error handling is managed by DNSMonitorHTMX helper
				if (typeof DNSMonitorHTMX !== 'undefined') {
					DNSMonitorHTMX.showNotification('An error occurred while processing your request.', 'error', 5000);
				}
			});
			
			// Handle HTTP errors (4xx, 5xx status codes)
			document.body.addEventListener('htmx:afterRequest', function(evt) {
				// Handle HTTP error responses
				if (!evt.detail.successful && evt.detail.xhr) {
					const xhr = evt.detail.xhr;
					let errorMessage = 'An error occurred while processing your request.';
					
					// Try to extract error message from response
					try {
						const response = JSON.parse(xhr.responseText);
						if (response.error) {
							errorMessage = response.error;
						}
					} catch (e) {
						// If not JSON, use status text or default message
						if (xhr.statusText) {
							errorMessage = xhr.statusText;
						}
					}
					
					// Show error notification via DNSMonitorHTMX helper
					if (typeof DNSMonitorHTMX !== 'undefined') {
						DNSMonitorHTMX.showNotification(errorMessage, 'error', 5000);
					}
				}
			});
			

		});
		</script>
		<?php
	}

	/**
	 * Generate HTMX attributes for an element
	 *
	 * @param string $endpoint    The endpoint to call.
	 * @param array  $attributes  HTMX attributes.
	 * @return string HTML attributes string.
	 */
	public function get_htmx_attributes( $endpoint, $attributes = array() ) {
		$defaults = array(
			'trigger' => 'click',
			'target'  => '#dns-monitor-content',
			'swap'    => 'innerHTML',
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$ajax_url = admin_url( 'admin-ajax.php' );
		$url = add_query_arg(
			array(
				'action'    => 'dns_monitor_htmx',
				'endpoint'  => $endpoint,
				'_wpnonce'  => wp_create_nonce( self::NONCE_ACTION ),
			),
			$ajax_url
		);

		$html_attrs = array();
		
		// Set the URL
		if ( 'GET' === ( $attributes['method'] ?? 'GET' ) ) {
			$html_attrs[] = 'hx-get="' . esc_url( $url ) . '"';
		} else {
			$html_attrs[] = 'hx-post="' . esc_url( $url ) . '"';
		}

		// Set other HTMX attributes
		foreach ( $attributes as $key => $value ) {
			if ( 'method' === $key ) {
				continue; // Already handled above
			}
			
			$attr_name = 'hx-' . str_replace( '_', '-', $key );
			$html_attrs[] = $attr_name . '="' . esc_attr( $value ) . '"';
		}

		return implode( ' ', $html_attrs );
	}

	/**
	 * Generate a complete HTMX button
	 *
	 * @param string $endpoint   The endpoint to call.
	 * @param string $text       Button text.
	 * @param array  $attributes HTMX and HTML attributes.
	 * @return string Complete button HTML.
	 */
	public function get_htmx_button( $endpoint, $text, $attributes = array() ) {
		$htmx_attrs = $this->get_htmx_attributes( $endpoint, $attributes );
		
		$classes = $attributes['class'] ?? 'button button-primary';
		$loading_text = $attributes['loading_text'] ?? __( 'Loading...', 'dns-monitor' );
		
		$button_attrs = array(
			'type'              => 'button',
			'class'             => $classes,
			'data-loading-text' => $loading_text,
		);

		$button_html_attrs = array();
		foreach ( $button_attrs as $attr => $value ) {
			$button_html_attrs[] = $attr . '="' . esc_attr( $value ) . '"';
		}

		return sprintf(
			'<button %s %s>%s</button>',
			implode( ' ', $button_html_attrs ),
			$htmx_attrs,
			esc_html( $text )
		);
	}

	/**
	 * Create an HTMX-enabled form
	 *
	 * @param string $endpoint   The endpoint to call.
	 * @param array  $attributes Form and HTMX attributes.
	 * @return string Form opening tag with HTMX attributes.
	 */
	public function get_htmx_form_start( $endpoint, $attributes = array() ) {
		$form_defaults = array(
			'method' => 'POST',
			'class'  => 'dns-monitor-htmx-form',
		);

		$attributes = wp_parse_args( $attributes, $form_defaults );
		$htmx_attrs = $this->get_htmx_attributes( $endpoint, $attributes );

		$form_attrs = array();
		foreach ( array( 'class', 'id' ) as $attr ) {
			if ( isset( $attributes[ $attr ] ) ) {
				$form_attrs[] = $attr . '="' . esc_attr( $attributes[ $attr ] ) . '"';
			}
		}

		return sprintf(
			'<form %s %s>',
			implode( ' ', $form_attrs ),
			$htmx_attrs
		);
	}

	/**
	 * Get list of registered endpoints
	 *
	 * @return array Registered endpoints.
	 */
	public function get_endpoints() {
		return $this->endpoints;
	}
} 