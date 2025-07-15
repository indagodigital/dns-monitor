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
				// Check if there was a snapshot error
				if ( isset( $result['snapshot_error'] ) && $result['snapshot_error'] ) {
					$error_message = isset( $result['snapshot_error_message'] ) 
						? $result['snapshot_error_message'] 
						: __( 'Failed to save DNS snapshot.', 'dns-monitor' );
					return $this->error_response( $error_message );
				}

				// Get the total number of changes
				$total_changes = isset( $result['changes_breakdown']['total'] ) ? $result['changes_breakdown']['total'] : 0;
				
				$message = $result['changes_detected'] 
					? sprintf( 
						/* translators: %d: Number of changes detected */
						_n( 
							'DNS check completed. %d change found.', 
							'DNS check completed. %d changes found.', 
							$total_changes, 
							'dns-monitor' 
						), 
						$total_changes 
					)
					: __( 'DNS check completed. No changes found.', 'dns-monitor' );

				$status = $result['changes_detected'] ? 'warning' : 'success';

				// Build response data
				$response_data = array(
					'changes_detected' => $result['changes_detected'],
					'total_records' => count( $result['records'] ?? array() ),
					'last_check' => current_time( 'mysql' ),
					'domain' => $domain,
					'refresh_snapshots' => true,
				);

				// Add snapshot info if available
				if ( isset( $result['snapshot_saved'] ) && $result['snapshot_saved'] ) {
					$response_data['snapshot_saved'] = true;
					if ( isset( $result['snapshot_id'] ) ) {
						$response_data['snapshot_id'] = $result['snapshot_id'];
					}
				}

				// Return updated button state and notification
				return $this->success_response( $message, $response_data, $status );
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
		$db   = DNS_Monitor_DB::get_instance();
		$page = max( 1, intval( $request['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, intval( $request['per_page'] ?? 20 ) ) );
		$unified_view = isset( $request['unified_view'] ) && $request['unified_view'];

		$snapshots = $db->get_snapshots( $page, $per_page );

		ob_start();
		include DNS_MONITOR_PLUGIN_DIR . 'includes/admin/views/snapshots-list.php';
		return ob_get_clean();
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

		$records = $this->get_records_for_snapshot( $snapshot->ID, $db );
		$previous_record = $db->get_previous_snapshot( $snapshot->ID );
		$previous_records = null;

		if ( $previous_record ) {
			$previous_records = $this->get_records_for_snapshot( $previous_record->ID, $db );
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

		$db = DNS_Monitor_DB::get_instance();
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
	 * @param bool $unified_view Whether to use unified view (default: true).
	 * @return string HTML table.
	 */
	private function render_snapshots_list( $snapshots, $db, $unified_view = true ) {
		ob_start();
		include DNS_MONITOR_PLUGIN_DIR . 'includes/admin/views/snapshots-list.php';
		return ob_get_clean();
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
		if ( $previous_record ) {
			// Calculate changes for unified display
			$changes = array(
				'added' => array(),
				'removed' => array(),
				'modified' => array()
			);

			// Process current records to find additions and modifications
			$current_record_keys = array();
			foreach ( $records as $dns_record ) {
				$record_key = $db->get_record_key_without_ttl( $dns_record );
				$current_record_keys[] = $record_key;

				$found = false;
				$changed = false;
				$original_record = null;

				foreach ( $previous_records as $prev_record ) {
					$prev_key = $db->get_record_key_without_ttl( $prev_record );

					if ( $record_key === $prev_key ) {
						$found = true;
						$original_record = $prev_record;

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

				if ( ! $found ) {
					$changes['added'][] = array(
						'record' => $dns_record,
						'type' => 'added'
					);
				} elseif ( $changed ) {
					$changes['modified'][] = array(
						'record' => $dns_record,
						'original' => $original_record,
						'type' => 'modified'
					);
				}
			}

			// Process previous records to find removals
			foreach ( $previous_records as $dns_record ) {
				$record_key = $db->get_record_key_without_ttl( $dns_record );
				
				if ( ! in_array( $record_key, $current_record_keys ) ) {
					$changes['removed'][] = array(
						'record' => $dns_record,
						'type' => 'removed'
					);
				}
			}

			// Render unified records list
			$html .= '<div class="dns-records-container">';
			$html .= $this->render_unified_records_list( $records, $previous_records, $changes, $db );
			$html .= '</div>';

		} else {
			// Initial snapshot - show all records without comparison
			$html = '<div class="dns-initial-snapshot">';
			$html .= '<div class="dns-records-container">';
			
			// For initial snapshots, create empty changes array and show all records as unchanged
			$empty_changes = array( 'added' => array(), 'removed' => array(), 'modified' => array() );
			$html .= $this->render_unified_records_list( $records, array(), $empty_changes, $db );
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

		$html .= $this->render_records_list( $records );

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render unified records list with change indicators
	 *
	 * @param array $current_records Current snapshot records.
	 * @param array $previous_records Previous snapshot records.
	 * @param array $changes Change analysis array.
	 * @param DNS_Monitor_DB $db Database instance.
	 * @return string HTML list.
	 */
	private function render_unified_records_list( $current_records, $previous_records, $changes, $db ) {
		if ( empty( $current_records ) && empty( $previous_records ) ) {
			return '<p>' . esc_html__( 'No records found.', 'dns-monitor' ) . '</p>';
		}

		$html = '<div class="dns-records-list dns-records-unified">';
		
		// Create a comprehensive list of all records with their status
		$unified_records = array();
		
		// Add current records (unchanged, added, or modified)
		foreach ( $current_records as $record ) {
			$record_key = $db->get_record_key_without_ttl( $record );
			$status = 'unchanged';
			$original_record = null;
			
			// Check if this record was added
			foreach ( $changes['added'] as $added_change ) {
				if ( $db->get_record_key_without_ttl( $added_change['record'] ) === $record_key ) {
					$status = 'added';
					break;
				}
			}
			
			// Check if this record was modified
			if ( $status === 'unchanged' ) {
				foreach ( $changes['modified'] as $modified_change ) {
					if ( $db->get_record_key_without_ttl( $modified_change['record'] ) === $record_key ) {
						$status = 'modified';
						$original_record = $modified_change['original'];
						break;
					}
				}
			}
			
			$unified_records[] = array(
				'record' => $record,
				'original' => $original_record,
				'status' => $status,
				'sort_key' => $record['type'] . '_' . $record['host'] . '_current'
			);
		}
		
		// Add removed records
		foreach ( $changes['removed'] as $removed_change ) {
			$unified_records[] = array(
				'record' => $removed_change['record'],
				'original' => null,
				'status' => 'removed',
				'sort_key' => $removed_change['record']['type'] . '_' . $removed_change['record']['host'] . '_removed'
			);
		}
		
		// Sort records by type and host for consistent display
		usort( $unified_records, function( $a, $b ) {
			return strcmp( $a['sort_key'], $b['sort_key'] );
		});
		
		// Render each record with appropriate styling
		foreach ( $unified_records as $unified_record ) {
			$record = $unified_record['record'];
			$status = $unified_record['status'];
			$original = $unified_record['original'];
			
			$html .= '<div class="dns-record-block dns-record-' . esc_attr( $status ) . '">';
			
			// Record content
			$html .= '<div class="dns-record-content">';
			$html .= '<div class="dns-record-main">';
			$html .= '<span class="dns-record-type">' . esc_html( strtoupper( $record['type'] ?? 'Unknown' ) ) . '</span>';
			$html .= '<span class="dns-record-host">' . esc_html( $record['host'] ?? '' ) . '</span>';
			$html .= '<span class="dns-record-value">' . esc_html( $this->get_dns_record_display_value( $record ) ) . '</span>';
			$html .= '</div>';
			
			// Show original value for modified records
			if ( $status === 'modified' && $original ) {
				$html .= '<div class="dns-record-original">';
				$html .= '<span class="dns-record-original-label">' . esc_html__( 'Previous:', 'dns-monitor' ) . '</span>';
				$html .= '<span class="dns-record-original-value">' . esc_html( $this->get_dns_record_display_value( $original ) ) . '</span>';
				$html .= '</div>';
			}
			
			$html .= '</div>'; // End record content
			$html .= '</div>'; // End record block
		}
		
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get display value for a DNS record based on its type
	 *
	 * @param array $record DNS record.
	 * @return string Display value for the record.
	 */
	private function get_dns_record_display_value( $record ) {
		switch ( $record['type'] ?? '' ) {
			case 'A':
				return $record['ip'] ?? '';
			case 'AAAA':
				return $record['ipv6'] ?? '';
			case 'CNAME':
			case 'NS':
			case 'PTR':
				return $record['target'] ?? '';
			case 'MX':
				$pri = $record['pri'] ?? '';
				$target = $record['target'] ?? '';
				return $pri . ' ' . $target;
			case 'TXT':
				$txt = $record['txt'] ?? '';
				return is_array( $txt ) ? implode( ' ', $txt ) : $txt;
			default:
				return $record['target'] ?? $record['ip'] ?? $record['ipv6'] ?? '';
		}
	}

	/**
	 * Render records list (legacy method for backward compatibility)
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
			$html .= '<span class="type">' . esc_html( strtoupper( $record['type'] ?? 'Unknown' ) ) . '</span>';
			$html .= '<span class="host">' . esc_html( $record['host'] ?? '' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $this->get_dns_record_display_value( $record ) ) . '</span>';
			$html .= '</div>';
		}
		
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get records for a snapshot from the dns_records table
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @param DNS_Monitor_DB $db Database instance.
	 * @return array|false DNS records array or false on failure.
	 */
	private function get_records_for_snapshot( $snapshot_id, $db ) {
		$db_records = $db->get_snapshot_records( $snapshot_id );
		if ( $db_records === false ) {
			return false;
		}

		// Convert database records back to DNS record format
		return $db->convert_db_records_to_dns_format( $db_records );
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