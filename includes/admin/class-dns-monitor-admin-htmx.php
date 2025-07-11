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
			'class' => 'button button-primary',
			'loading_text' => __( 'Checking DNS...', 'dns-monitor' ),
			'target' => '#dns-check-results',
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
	 * Render an HTMX-enabled snapshots table
	 *
	 * @param array $attributes Optional table attributes.
	 * @return string Table HTML.
	 */
	public function render_snapshots_table( $attributes = array() ) {
		$defaults = array(
			'id' => 'dns-monitor-snapshots-table',
			'auto_refresh' => false,
			'refresh_interval' => 30000, // 30 seconds
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$htmx_attrs = array(
			'trigger' => 'load',
			'target' => '#dns-snapshots-container',
			'swap' => 'innerHTML',
		);

		if ( $attributes['auto_refresh'] ) {
			$htmx_attrs['trigger'] = 'load, every ' . $attributes['refresh_interval'] . 'ms';
		}

		$table_attrs = $this->htmx->get_htmx_attributes( 'refresh_snapshots', $htmx_attrs );

		$html = '<div id="' . esc_attr( $attributes['id'] ) . '" ' . $table_attrs . '>';
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

	/**
	 * Render HTMX-enabled settings form
	 *
	 * @param array $fields Form fields configuration.
	 * @param string $endpoint Endpoint to submit to.
	 * @param array $attributes Form attributes.
	 * @return string Form HTML.
	 */
	public function render_settings_form( $fields, $endpoint, $attributes = array() ) {
		$defaults = array(
			'method' => 'POST',
			'class' => 'dns-monitor-htmx-form',
			'target' => '#settings-results',
			'swap' => 'innerHTML',
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$html = $this->htmx->get_htmx_form_start( $endpoint, $attributes );
		$html .= '<table class="form-table">';

		foreach ( $fields as $field_id => $field_config ) {
			$html .= $this->render_form_field( $field_id, $field_config );
		}

		$html .= '</table>';
		$html .= '<p class="submit">';
		$html .= '<button type="submit" class="button button-primary" data-loading-text="' . esc_attr__( 'Saving...', 'dns-monitor' ) . '">';
		$html .= esc_html__( 'Save Settings', 'dns-monitor' );
		$html .= '</button>';
		$html .= '</p>';
		$html .= '</form>';
		$html .= '<div id="settings-results" class="dns-monitor-results-area"></div>';

		return $html;
	}

	/**
	 * Render a form field
	 *
	 * @param string $field_id Field ID.
	 * @param array $field_config Field configuration.
	 * @return string Field HTML.
	 */
	private function render_form_field( $field_id, $field_config ) {
		$defaults = array(
			'type' => 'text',
			'label' => '',
			'description' => '',
			'value' => '',
			'options' => array(),
			'attributes' => array(),
		);

		$field = wp_parse_args( $field_config, $defaults );

		$html = '<tr>';
		$html .= '<th scope="row">';
		$html .= '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $field['label'] ) . '</label>';
		$html .= '</th>';
		$html .= '<td>';

		switch ( $field['type'] ) {
			case 'select':
				$html .= $this->render_select_field( $field_id, $field );
				break;
			case 'textarea':
				$html .= $this->render_textarea_field( $field_id, $field );
				break;
			case 'checkbox':
				$html .= $this->render_checkbox_field( $field_id, $field );
				break;
			default:
				$html .= $this->render_input_field( $field_id, $field );
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			$html .= '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}

		$html .= '</td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * Render an input field
	 *
	 * @param string $field_id Field ID.
	 * @param array $field Field configuration.
	 * @return string Input HTML.
	 */
	private function render_input_field( $field_id, $field ) {
		$attributes = wp_parse_args( $field['attributes'], array(
			'class' => 'regular-text',
		) );

		$html = '<input type="' . esc_attr( $field['type'] ) . '" ';
		$html .= 'id="' . esc_attr( $field_id ) . '" ';
		$html .= 'name="' . esc_attr( $field_id ) . '" ';
		$html .= 'value="' . esc_attr( $field['value'] ) . '" ';

		foreach ( $attributes as $attr => $value ) {
			$html .= esc_attr( $attr ) . '="' . esc_attr( $value ) . '" ';
		}

		$html .= '/>';

		return $html;
	}

	/**
	 * Render a select field
	 *
	 * @param string $field_id Field ID.
	 * @param array $field Field configuration.
	 * @return string Select HTML.
	 */
	private function render_select_field( $field_id, $field ) {
		$attributes = wp_parse_args( $field['attributes'], array() );

		$html = '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" ';

		foreach ( $attributes as $attr => $value ) {
			$html .= esc_attr( $attr ) . '="' . esc_attr( $value ) . '" ';
		}

		$html .= '>';

		foreach ( $field['options'] as $value => $label ) {
			$selected = selected( $field['value'], $value, false );
			$html .= '<option value="' . esc_attr( $value ) . '"' . $selected . '>';
			$html .= esc_html( $label );
			$html .= '</option>';
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Render a textarea field
	 *
	 * @param string $field_id Field ID.
	 * @param array $field Field configuration.
	 * @return string Textarea HTML.
	 */
	private function render_textarea_field( $field_id, $field ) {
		$attributes = wp_parse_args( $field['attributes'], array(
			'rows' => 4,
			'cols' => 50,
			'class' => 'large-text',
		) );

		$html = '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" ';

		foreach ( $attributes as $attr => $value ) {
			$html .= esc_attr( $attr ) . '="' . esc_attr( $value ) . '" ';
		}

		$html .= '>' . esc_textarea( $field['value'] ) . '</textarea>';

		return $html;
	}

	/**
	 * Render a checkbox field
	 *
	 * @param string $field_id Field ID.
	 * @param array $field Field configuration.
	 * @return string Checkbox HTML.
	 */
	private function render_checkbox_field( $field_id, $field ) {
		$attributes = wp_parse_args( $field['attributes'], array() );
		$checked = checked( $field['value'], 1, false );

		$html = '<label for="' . esc_attr( $field_id ) . '">';
		$html .= '<input type="checkbox" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" value="1" ';

		foreach ( $attributes as $attr => $value ) {
			$html .= esc_attr( $attr ) . '="' . esc_attr( $value ) . '" ';
		}

		$html .= $checked . ' /> ';
		$html .= esc_html( $field['label'] );
		$html .= '</label>';

		return $html;
	}

	/**
	 * Render an HTMX-enabled data table
	 *
	 * @param string $endpoint Endpoint for data.
	 * @param array $columns Column configuration.
	 * @param array $attributes Table attributes.
	 * @return string Table HTML.
	 */
	public function render_data_table( $endpoint, $columns, $attributes = array() ) {
		$defaults = array(
			'id' => 'dns-monitor-data-table',
			'class' => 'dns-monitor-htmx-table widefat striped',
			'pagination' => true,
			'per_page' => 20,
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$html = '<div class="dns-monitor-card">';
		$html .= '<div class="dns-monitor-card-body">';

		// Table controls
		if ( $attributes['pagination'] ) {
			$html .= '<div class="dns-monitor-table-controls">';
			$html .= '<div class="dns-monitor-pagination-controls">';
			$html .= '<button class="button" ' . $this->htmx->get_htmx_attributes( $endpoint, array(
				'hx-vals' => wp_json_encode( array( 'page' => 1, 'per_page' => $attributes['per_page'] ) ),
				'target' => '#' . $attributes['id'] . '-body',
				'swap' => 'innerHTML',
			) ) . '>' . esc_html__( 'First', 'dns-monitor' ) . '</button>';
			$html .= '<button class="button" id="prev-page-btn">' . esc_html__( 'Previous', 'dns-monitor' ) . '</button>';
			$html .= '<span id="page-info">Page 1</span>';
			$html .= '<button class="button" id="next-page-btn">' . esc_html__( 'Next', 'dns-monitor' ) . '</button>';
			$html .= '</div>';
			$html .= '</div>';
		}

		// Table
		$html .= '<table id="' . esc_attr( $attributes['id'] ) . '" class="' . esc_attr( $attributes['class'] ) . '">';
		$html .= '<thead>';
		$html .= '<tr>';

		foreach ( $columns as $column_id => $column_config ) {
			$html .= '<th>' . esc_html( $column_config['label'] ?? $column_id ) . '</th>';
		}

		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody id="' . esc_attr( $attributes['id'] ) . '-body" ';
		$html .= $this->htmx->get_htmx_attributes( $endpoint, array(
			'trigger' => 'load',
			'hx-vals' => wp_json_encode( array( 'page' => 1, 'per_page' => $attributes['per_page'] ) ),
		) );
		$html .= '>';
		$html .= '<tr><td colspan="' . count( $columns ) . '">';
		$html .= '<div class="dns-monitor-content-loading">';
		$html .= '<span class="spinner is-active"></span> ';
		$html .= esc_html__( 'Loading data...', 'dns-monitor' );
		$html .= '</div>';
		$html .= '</td></tr>';
		$html .= '</tbody>';
		$html .= '</table>';

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render HTMX status indicator
	 *
	 * @param string $endpoint Endpoint to check status.
	 * @param array $attributes Indicator attributes.
	 * @return string Indicator HTML.
	 */
	public function render_status_indicator( $endpoint, $attributes = array() ) {
		$defaults = array(
			'id' => 'dns-monitor-status',
			'refresh_interval' => 10000, // 10 seconds
			'auto_refresh' => true,
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$htmx_attrs = array(
			'trigger' => 'load',
			'target' => '#' . $attributes['id'],
			'swap' => 'innerHTML',
		);

		if ( $attributes['auto_refresh'] ) {
			$htmx_attrs['trigger'] = 'load, every ' . $attributes['refresh_interval'] . 'ms';
		}

		$html = '<div id="' . esc_attr( $attributes['id'] ) . '" class="dns-monitor-status-indicator" ';
		$html .= $this->htmx->get_htmx_attributes( $endpoint, $htmx_attrs );
		$html .= '>';
		$html .= '<span class="dns-monitor-status info">' . esc_html__( 'Checking...', 'dns-monitor' ) . '</span>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render HTMX modal dialog
	 *
	 * @param string $modal_id Modal ID.
	 * @param string $title Modal title.
	 * @param string $content_endpoint Endpoint for modal content.
	 * @param array $attributes Modal attributes.
	 * @return string Modal HTML.
	 */
	public function render_modal( $modal_id, $title, $content_endpoint, $attributes = array() ) {
		$defaults = array(
			'width' => '600px',
			'height' => 'auto',
		);

		$attributes = wp_parse_args( $attributes, $defaults );

		$html = '<div id="' . esc_attr( $modal_id ) . '" class="dns-monitor-modal" style="display: none;">';
		$html .= '<div class="dns-monitor-modal-overlay"></div>';
		$html .= '<div class="dns-monitor-modal-content" style="width: ' . esc_attr( $attributes['width'] ) . '; height: ' . esc_attr( $attributes['height'] ) . ';">';
		$html .= '<div class="dns-monitor-modal-header">';
		$html .= '<h2>' . esc_html( $title ) . '</h2>';
		$html .= '<button class="dns-monitor-modal-close" data-modal-id="' . esc_attr( $modal_id ) . '">&times;</button>';
		$html .= '</div>';
		$html .= '<div class="dns-monitor-modal-body" ';
		$html .= $this->htmx->get_htmx_attributes( $content_endpoint, array(
			'trigger' => 'load',
			'target' => '#' . $modal_id . ' .dns-monitor-modal-body',
			'swap' => 'innerHTML',
		) );
		$html .= '>';
		$html .= '<div class="dns-monitor-content-loading">';
		$html .= '<span class="spinner is-active"></span> ';
		$html .= esc_html__( 'Loading...', 'dns-monitor' );
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get HTMX helper instance for direct access
	 *
	 * @return DNS_Monitor_HTMX HTMX instance.
	 */
	public function get_htmx() {
		return $this->htmx;
	}
} 