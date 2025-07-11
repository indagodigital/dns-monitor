<?php
/**
 * API Endpoints helper for DNS Monitor HTMX
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS Monitor API helper class.
 */
class DNS_Monitor_API {
	/**
	 * Instance of this class
	 *
	 * @var DNS_Monitor_API
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
	 * @return DNS_Monitor_API
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
		$this->register_default_endpoints();
	}

	/**
	 * Register default HTMX endpoints
	 */
	private function register_default_endpoints() {
		// DNS Check endpoint
		$this->htmx->register_endpoint(
			'dns_check',
			array( $this, 'handle_dns_check' ),
			array(
				'methods' => array( 'POST' ),
				'capability' => 'manage_options',
			)
		);

		// Refresh snapshots table
		$this->htmx->register_endpoint(
			'refresh_snapshots',
			array( $this, 'handle_refresh_snapshots' ),
			array(
				'methods' => array( 'GET' ),
				'capability' => 'manage_options',
			)
		);

		// Get snapshot details
		$this->htmx->register_endpoint(
			'snapshot_details',
			array( $this, 'handle_snapshot_details' ),
			array(
				'methods' => array( 'GET' ),
				'capability' => 'manage_options',
			)
		);

		// Delete snapshot
		$this->htmx->register_endpoint(
			'delete_snapshot',
			array( $this, 'handle_delete_snapshot' ),
			array(
				'methods' => array( 'POST' ),
				'capability' => 'manage_options',
			)
		);
	}

	/**
	 * Handle DNS check request
	 *
	 * @param array $request Request data.
	 * @return string HTML response.
	 */
	public function handle_dns_check( $request ) {
		$site_url = DNS_MONITOR_SITE_URL;
		$domain   = parse_url( $site_url, PHP_URL_HOST );

		if ( ! $domain ) {
			return $this->error_response( __( 'Unable to determine domain from site URL.', 'dns-monitor' ) );
		}

		try {
			$result = DNS_Monitor_Records::fetch_and_process_records( $domain, true, true );

			if ( $result ) {
				$message = $result['changes_detected'] 
					? __( 'DNS check completed. Changes detected and snapshot saved.', 'dns-monitor' )
					: __( 'DNS check completed. No changes detected.', 'dns-monitor' );

				$status = $result['changes_detected'] ? 'warning' : 'success';

				// Return updated button state and notification
				return $this->success_response( 
					$message, 
					array(
						'changes_detected' => $result['changes_detected'],
						'total_records' => count( $result['records'] ?? array() ),
						'last_check' => current_time( 'mysql' ),
						'domain' => $domain,
						'refresh_snapshots' => true, // Signal to refresh the snapshots table
					),
					$status
				);
			} else {
				return $this->error_response( __( 'DNS check failed. Unable to retrieve DNS records.', 'dns-monitor' ) );
			}
		} catch ( Exception $e ) {
			return $this->error_response( 
				sprintf( 
					/* translators: %s: Error message */
					__( 'DNS check failed: %s', 'dns-monitor' ), 
					$e->getMessage() 
				) 
			);
		} catch ( Error $e ) {
			return $this->error_response( 
				sprintf( 
					/* translators: %s: Error message */
					__( 'DNS check failed with fatal error: %s', 'dns-monitor' ), 
					$e->getMessage() 
				) 
			);
		}
	}

	/**
	 * Handle refresh snapshots table request
	 *
	 * @param array $request Request data.
	 * @return string HTML response.
	 */
	public function handle_refresh_snapshots( $request ) {
		$db = new DNS_Monitor_DB();
		$page = max( 1, intval( $request['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, intval( $request['per_page'] ?? 20 ) ) );

		$snapshots = $db->get_snapshots( $page, $per_page );

		if ( empty( $snapshots ) ) {
			return '<div class="dns-monitor-content-loading">' . 
				   esc_html__( 'No snapshots found.', 'dns-monitor' ) . 
				   '</div>';
		}

		$html = $this->render_snapshots_table( $snapshots, $db );
		return $html;
	}

	/**
	 * Handle snapshot details request
	 *
	 * @param array $request Request data.
	 * @return string HTML response.
	 */
	public function handle_snapshot_details( $request ) {
		$snapshot_id = intval( $request['snapshot_id'] ?? 0 );
		
		if ( ! $snapshot_id ) {
			return $this->error_response( __( 'Invalid snapshot ID.', 'dns-monitor' ) );
		}

		$db = new DNS_Monitor_DB();
		$snapshot = $db->get_snapshot( $snapshot_id );

		if ( ! $snapshot ) {
			return $this->error_response( __( 'Snapshot not found.', 'dns-monitor' ) );
		}

		$records = $db->decode_snapshot_records( $snapshot->snapshot_records );
		$previous_record = $db->get_previous_snapshot( $snapshot->domain, $snapshot->checked_at );
		$previous_records = null;

		if ( $previous_record ) {
			$previous_records = $db->decode_snapshot_records( $previous_record->snapshot_records );
		}

		return $this->render_snapshot_details( $snapshot, $records, $previous_records );
	}

	/**
	 * Handle delete snapshot request
	 *
	 * @param array $request Request data.
	 * @return array JSON response.
	 */
	public function handle_delete_snapshot( $request ) {
		$snapshot_id = intval( $request['snapshot_id'] ?? 0 );
		
		if ( ! $snapshot_id ) {
			return array( 'error' => __( 'Invalid snapshot ID.', 'dns-monitor' ) );
		}

		$db = new DNS_Monitor_DB();
		$deleted = $db->delete_snapshot( $snapshot_id );

		if ( $deleted ) {
			return array( 
				'success' => true,
				'message' => __( 'Snapshot deleted successfully.', 'dns-monitor' ),
			);
		} else {
			return array( 'error' => __( 'Failed to delete snapshot.', 'dns-monitor' ) );
		}
	}

	/**
	 * Render snapshots table
	 *
	 * @param array $snapshots Snapshots array.
	 * @param DNS_Monitor_DB $db Database instance.
	 * @return string HTML table.
	 */
	private function render_snapshots_table( $snapshots, $db ) {
		if ( empty( $snapshots ) ) {
			return '<div class="dns-monitor-content-loading">' . 
				   '<p>' . esc_html__( 'No snapshots found. Click "Check DNS Now" to create your first snapshot.', 'dns-monitor' ) . '</p>' .
				   '</div>';
		}

		$html = '<table id="dns-monitor-snapshots-table" class="wp-list-table widefat fixed">';
		$html .= '<thead>';
		$html .= '<tr>';
		$html .= '<th>' . esc_html__( 'Date', 'dns-monitor' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Changes', 'dns-monitor' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Actions', 'dns-monitor' ) . '</th>';
		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';

		foreach ( $snapshots as $index => $snapshot ) {
			$records = $db->decode_snapshot_records( $snapshot->snapshot_records );
			if ( $records === false ) {
				continue; // Skip corrupted snapshots
			}

			// Get the changes breakdown from the database
			$changes_count = isset( $snapshot->snapshot_changes ) ? intval( $snapshot->snapshot_changes ) : 0;
			$additions = isset( $snapshot->snapshot_additions ) ? intval( $snapshot->snapshot_additions ) : 0;
			$removals = isset( $snapshot->snapshot_removals ) ? intval( $snapshot->snapshot_removals ) : 0;
			$modifications = isset( $snapshot->snapshot_modifications ) ? intval( $snapshot->snapshot_modifications ) : 0;
			
			// Get the previous record for comparison (for display purposes)
			$previous_record = null;
			$previous_records = array();
			if ( $index < count( $snapshots ) - 1 ) {
				$previous_record = $snapshots[ $index + 1 ];
				$previous_records = $db->decode_snapshot_records( $previous_record->snapshot_records );
				if ( $previous_records === false ) {
					$previous_records = array(); // Handle corrupted data gracefully
				}
			}

			// Add CSS class for highlighting rows with changes
			$row_class = $changes_count > 0 ? 'dns-changes-detected' : '';
			
			$html .= '<tr class="snapshot-row ' . esc_attr( $row_class ) . '">';
			$html .= '<td>' . esc_html( $this->format_wp_date( $snapshot->created_at ) ) . '</td>';
			$html .= '<td>';
			
			if ( $changes_count > 0 ) {
				$html .= '<div class="dns-changes-breakdown">';

				$html .= '<span title="' . esc_attr__( 'Changes', 'dns-monitor' ) . '">' . esc_html( $changes_count ) . ' Changes</span>';

				if ( $additions > 0 ) {
					$html .= '<span class="dns-badge dns-badge-addition" title="' . esc_attr__( 'Additions', 'dns-monitor' ) . '">+' . esc_html( $additions ) . '</span>';
				}
				if ( $removals > 0 ) {
					$html .= '<span class="dns-badge dns-badge-removal" title="' . esc_attr__( 'Removals', 'dns-monitor' ) . '">−' . esc_html( $removals ) . '</span>';
				}
				if ( $modifications > 0 ) {
					$html .= '<span class="dns-badge dns-badge-modification" title="' . esc_attr__( 'Modifications', 'dns-monitor' ) . '">~' . esc_html( $modifications ) . '</span>';
				}
				$html .= '</div>';
			} else {
				$html .= '<span>0 Changes</span>';
			}
			
			$html .= '</td>';
			$html .= '<td>';
			$html .= '<a href="#" class="dns-toggle-records" data-record-id="' . esc_attr( $snapshot->ID ) . '" aria-expanded="false" aria-controls="dns-record-content-' . esc_attr( $snapshot->ID ) . '">';
			$html .= '<span class="dashicons dashicons-arrow-right"></span>';
			$html .= '<span class="toggle-text">' . esc_html__( 'View Records', 'dns-monitor' ) . '</span>';
			$html .= '</a>';
			$html .= '</td>';
			$html .= '</tr>';
			
			$html .= '<tr class="snapshot-records-row">';
			$html .= '<td colspan="3" class="dns-records-content" id="dns-record-content-' . esc_attr( $snapshot->ID ) . '">';
			$html .= $this->render_snapshot_comparison_admin( $snapshot, $previous_record, $records, $previous_records, $db );
			$html .= '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody>';
		$html .= '</table>';

		return $html;
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
	 * Render snapshot comparison for admin page
	 *
	 * @param object $snapshot Current snapshot.
	 * @param object|null $previous_record Previous snapshot.
	 * @param array $records Current records.
	 * @param array $previous_records Previous records.
	 * @param DNS_Monitor_DB $db Database instance.
	 * @return string HTML comparison.
	 */
	private function render_snapshot_comparison_admin( $snapshot, $previous_record, $records, $previous_records, $db ) {
		$ignore_keys = array( 'class', 'ttl', 'entries' );

		if ( $previous_record ) {
			$html = '<div class="dns-comparison">';
			// Current snapshot column
			$html .= '<div class="dns-column">';
			$html .= '<div class="dns-column-header">' . sprintf( __( 'Current Snapshot (%s)', 'dns-monitor' ), esc_html( $this->format_wp_date( $snapshot->created_at ) ) ) . '</div>';

			// Process current records
			$current_record_keys = array();
			foreach ( $records as $dns_record ) {
				// Use TTL-ignoring record key generation method
				$record_key = $db->get_record_key_without_ttl( $dns_record );
				$current_record_keys[] = $record_key;

				// Find if this record exists in previous snapshot
				$found = false;
				$changed = false;

				foreach ( $previous_records as $prev_record ) {
					// Use TTL-ignoring record key generation method
					$prev_key = $db->get_record_key_without_ttl( $prev_record );

					if ( $record_key === $prev_key ) {
						$found = true;

						// Check if any values changed (ignoring TTL)
						foreach ( $dns_record as $key => $value ) {
							if ( 'ttl' === $key ) {
								continue; // Skip TTL comparison
							}
							if ( isset( $prev_record[ $key ] ) && $prev_record[ $key ] !== $value ) {
								$changed = true;
								break;
							}
						}

						break;
					}
				}

				$class = $found ? ( $changed ? 'dns-record-diff' : 'dns-record-unchanged' ) : 'dns-record-added';
				
				$html .= '<div class="dns-record-block ' . esc_attr( $class ) . '">';
				$html .= '<strong>' . esc_html__( 'Type:', 'dns-monitor' ) . '</strong> ' . esc_html( $dns_record['type'] ) . '<br>';

				foreach ( $dns_record as $key => $value ) {
					if ( in_array( $key, $ignore_keys ) ) {
						continue;
					}
					
					if ( 'type' !== $key ) {
						$html .= '<strong>' . esc_html( $key ) . ':</strong> ' . esc_html( is_array( $value ) ? wp_json_encode( $value ) : $value ) . '<br>';
					}
				}
				$html .= '</div>';
			}

			$html .= '</div>';

			// Previous snapshot column
			$html .= '<div class="dns-column">';
			$html .= '<div class="dns-column-header">' . sprintf( __( 'Previous Snapshot (%s)', 'dns-monitor' ), esc_html( $this->format_wp_date( $previous_record->created_at ) ) ) . '</div>';

			// Process previous records
			foreach ( $previous_records as $dns_record ) {
				// Use TTL-ignoring record key generation method
				$record_key = $db->get_record_key_without_ttl( $dns_record );

				// Check if this record exists in current snapshot
				$exists_in_current = in_array( $record_key, $current_record_keys );

				$class = $exists_in_current ? 'dns-record-unchanged' : 'dns-record-removed';
				
				$html .= '<div class="dns-record-block ' . esc_attr( $class ) . '">';
				$html .= '<strong>' . esc_html__( 'Type:', 'dns-monitor' ) . '</strong> ' . esc_html( $dns_record['type'] ) . '<br>';

				foreach ( $dns_record as $key => $value ) {
					if ( in_array( $key, $ignore_keys ) ) {
						continue;
					}

					if ( 'type' !== $key ) {
						$html .= '<strong>' . esc_html( $key ) . ':</strong> ' . esc_html( is_array( $value ) ? wp_json_encode( $value ) : $value ) . '<br>';
					}
				}
				$html .= '</div>';
			}

			$html .= '</div>';
			$html .= '</div>';
		} else {
			$html = '<div class="dns-comparison">';
			$html .= '<div class="dns-column">';
			$html .= '<div class="dns-column-header">' . sprintf( __( 'Initial Snapshot (%s)', 'dns-monitor' ), esc_html( $this->format_wp_date( $snapshot->created_at ) ) ) . '</div>';

			foreach ( $records as $dns_record ) {
				$html .= '<div class="dns-record-block">';
				$html .= '<strong>' . sprintf( __( 'Type: %s', 'dns-monitor' ), esc_html( $dns_record['type'] ) ) . '</strong><br>';

				foreach ( $dns_record as $key => $value ) {
					if ( in_array( $key, $ignore_keys ) ) {
						continue;
					}

					if ( 'type' !== $key ) {
						$html .= '<strong>' . esc_html( $key ) . ':</strong> ' . esc_html( is_array( $value ) ? wp_json_encode( $value ) : $value ) . '<br>';
					}
				}
				$html .= '</div>';
			}

			$html .= '</div>';
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Render snapshot details
	 *
	 * @param object $snapshot Snapshot object.
	 * @param array $records Current records.
	 * @param array $previous_records Previous records.
	 * @return string HTML details.
	 */
	private function render_snapshot_details( $snapshot, $records, $previous_records ) {
		$html = '<div class="dns-monitor-card">';
		$html .= '<div class="dns-monitor-card-header">';
		$html .= sprintf(
			/* translators: %s: Domain name */
			esc_html__( 'DNS Records for %s', 'dns-monitor' ),
			esc_html( $snapshot->domain )
		);
		$html .= '</div>';
		$html .= '<div class="dns-monitor-card-body">';

		if ( $previous_records && $snapshot->changes_detected ) {
			$html .= $this->render_records_comparison( $records, $previous_records );
		} else {
			$html .= $this->render_records_list( $records );
		}

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render records comparison
	 *
	 * @param array $current_records Current records.
	 * @param array $previous_records Previous records.
	 * @return string HTML comparison.
	 */
	private function render_records_comparison( $current_records, $previous_records ) {
		$html = '<div class="dns-comparison">';
		$html .= '<div class="dns-column">';
		$html .= '<h4>' . esc_html__( 'Previous Records', 'dns-monitor' ) . '</h4>';
		$html .= $this->render_records_list( $previous_records, 'previous' );
		$html .= '</div>';
		$html .= '<div class="dns-column">';
		$html .= '<h4>' . esc_html__( 'Current Records', 'dns-monitor' ) . '</h4>';
		$html .= $this->render_records_list( $current_records, 'current' );
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render records list
	 *
	 * @param array $records DNS records.
	 * @param string $context Context for styling (current, previous, or empty).
	 * @return string HTML list.
	 */
	private function render_records_list( $records, $context = '' ) {
		if ( empty( $records ) ) {
			return '<p>' . esc_html__( 'No records found.', 'dns-monitor' ) . '</p>';
		}

		$html = '<div class="dns-records-list">';
		
		foreach ( $records as $record ) {
			$html .= '<div class="dns-record-block">';
			$html .= '<strong>' . esc_html( strtoupper( $record['type'] ?? 'Unknown' ) ) . '</strong> ';
			$html .= esc_html( $record['host'] ?? '' ) . ' → ';
			$html .= esc_html( $record['target'] ?? $record['value'] ?? '' );
			
			if ( ! empty( $record['ttl'] ) ) {
				$html .= ' <em>(TTL: ' . esc_html( $record['ttl'] ) . ')</em>';
			}
			
			$html .= '</div>';
		}
		
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate success response HTML
	 *
	 * @param string $message Success message.
	 * @param array $data Additional data.
	 * @param string $status Status type (success, warning, etc.).
	 * @return string HTML response.
	 */
	private function success_response( $message, $data = array(), $status = 'success' ) {
		$extra_attrs = '';
		if ( isset( $data['refresh_snapshots'] ) && $data['refresh_snapshots'] ) {
			$extra_attrs = ' data-refresh-snapshots="true"';
		}

		$html = '<div class="dns-monitor-notification notice notice-' . esc_attr( $status ) . '"' . $extra_attrs . '>';
		$html .= '<p>' . esc_html( $message ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate error response HTML
	 *
	 * @param string $message Error message.
	 * @return string HTML response.
	 */
	private function error_response( $message ) {
		return '<div class="dns-monitor-notification notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Helper method to register a simple CRUD endpoint
	 *
	 * @param string $name Endpoint name.
	 * @param string $table Database table name.
	 * @param array $fields Field configuration.
	 * @param array $args Additional arguments.
	 * @return bool Success status.
	 */
	public function register_crud_endpoint( $name, $table, $fields, $args = array() ) {
		$defaults = array(
			'capability' => 'manage_options',
			'validate_callback' => null,
			'transform_callback' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->htmx->register_endpoint(
			$name,
			function( $request ) use ( $table, $fields, $args ) {
				return $this->handle_crud_operation( $request, $table, $fields, $args );
			},
			array(
				'methods' => array( 'GET', 'POST', 'PUT', 'DELETE' ),
				'capability' => $args['capability'],
			)
		);
	}

	/**
	 * Handle CRUD operations
	 *
	 * @param array $request Request data.
	 * @param string $table Table name.
	 * @param array $fields Field configuration.
	 * @param array $args Additional arguments.
	 * @return mixed Response data.
	 */
	private function handle_crud_operation( $request, $table, $fields, $args ) {
		// Validate table name to prevent SQL injection
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return array( 'error' => 'Invalid table name' );
		}
		
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		
		switch ( $method ) {
			case 'GET':
				return $this->handle_crud_read( $request, $table, $fields );
			case 'POST':
				return $this->handle_crud_create( $request, $table, $fields, $args );
			case 'PUT':
				return $this->handle_crud_update( $request, $table, $fields, $args );
			case 'DELETE':
				return $this->handle_crud_delete( $request, $table );
			default:
				return array( 'error' => 'Method not supported' );
		}
	}

	/**
	 * Handle CRUD read operation
	 *
	 * @param array $request Request data.
	 * @param string $table Table name.
	 * @param array $fields Field configuration.
	 * @return array Response data.
	 */
	private function handle_crud_read( $request, $table, $fields ) {
		global $wpdb;
		
		$id = intval( $request['id'] ?? 0 );
		
		if ( $id ) {
			$result = $wpdb->get_row( 
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ),
				ARRAY_A
			);
		} else {
			$limit = min( 100, max( 1, intval( $request['limit'] ?? 20 ) ) );
			$offset = max( 0, intval( $request['offset'] ?? 0 ) );
			
			$result = $wpdb->get_results( 
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		return array( 'data' => $result );
	}

	/**
	 * Handle CRUD create operation
	 *
	 * @param array $request Request data.
	 * @param string $table Table name.
	 * @param array $fields Field configuration.
	 * @param array $args Additional arguments.
	 * @return array Response data.
	 */
	private function handle_crud_create( $request, $table, $fields, $args ) {
		global $wpdb;
		
		$data = array();
		foreach ( $fields as $field => $config ) {
			if ( isset( $request[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( $request[ $field ] );
			}
		}

		if ( $args['validate_callback'] && is_callable( $args['validate_callback'] ) ) {
			$validation = call_user_func( $args['validate_callback'], $data );
			if ( is_wp_error( $validation ) ) {
				return array( 'error' => $validation->get_error_message() );
			}
		}

		if ( $args['transform_callback'] && is_callable( $args['transform_callback'] ) ) {
			$data = call_user_func( $args['transform_callback'], $data );
		}

		$result = $wpdb->insert( $wpdb->prefix . $table, $data );
		
		if ( $result ) {
			return array( 
				'success' => true, 
				'id' => $wpdb->insert_id,
				'message' => __( 'Record created successfully.', 'dns-monitor' ),
			);
		} else {
			return array( 'error' => __( 'Failed to create record.', 'dns-monitor' ) );
		}
	}

	/**
	 * Handle CRUD update operation
	 *
	 * @param array $request Request data.
	 * @param string $table Table name.
	 * @param array $fields Field configuration.
	 * @param array $args Additional arguments.
	 * @return array Response data.
	 */
	private function handle_crud_update( $request, $table, $fields, $args ) {
		global $wpdb;
		
		$id = intval( $request['id'] ?? 0 );
		if ( ! $id ) {
			return array( 'error' => __( 'ID is required for update.', 'dns-monitor' ) );
		}

		$data = array();
		foreach ( $fields as $field => $config ) {
			if ( isset( $request[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( $request[ $field ] );
			}
		}

		if ( $args['validate_callback'] && is_callable( $args['validate_callback'] ) ) {
			$validation = call_user_func( $args['validate_callback'], $data );
			if ( is_wp_error( $validation ) ) {
				return array( 'error' => $validation->get_error_message() );
			}
		}

		if ( $args['transform_callback'] && is_callable( $args['transform_callback'] ) ) {
			$data = call_user_func( $args['transform_callback'], $data );
		}

		$result = $wpdb->update( 
			$wpdb->prefix . $table, 
			$data, 
			array( 'id' => $id ),
			null,
			array( '%d' )
		);
		
		if ( false !== $result ) {
			return array( 
				'success' => true,
				'message' => __( 'Record updated successfully.', 'dns-monitor' ),
			);
		} else {
			return array( 'error' => __( 'Failed to update record.', 'dns-monitor' ) );
		}
	}

	/**
	 * Handle CRUD delete operation
	 *
	 * @param array $request Request data.
	 * @param string $table Table name.
	 * @return array Response data.
	 */
	private function handle_crud_delete( $request, $table ) {
		global $wpdb;
		
		$id = intval( $request['id'] ?? 0 );
		if ( ! $id ) {
			return array( 'error' => __( 'ID is required for delete.', 'dns-monitor' ) );
		}

		$result = $wpdb->delete( 
			$wpdb->prefix . $table, 
			array( 'id' => $id ),
			array( '%d' )
		);
		
		if ( $result ) {
			return array( 
				'success' => true,
				'message' => __( 'Record deleted successfully.', 'dns-monitor' ),
			);
		} else {
			return array( 'error' => __( 'Failed to delete record.', 'dns-monitor' ) );
		}
	}
} 