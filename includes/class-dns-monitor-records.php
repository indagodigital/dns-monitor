<?php
/**
 * DNS Records handling for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS Records handling class.
 */
class DNS_Monitor_Records {
	/**
	 * Check site DNS for changes
	 */
	public static function check_site_dns() {
		// Get the current site's domain.
		$site_url = DNS_MONITOR_SITE_URL;
		$domain   = parse_url( $site_url, PHP_URL_HOST );

		if ( ! $domain || empty( $domain ) ) {
			return false;
		}

		$notifications = new DNS_Monitor_Notifications();
		$result = self::fetch_and_process_records( $domain );

		if ( ! $result ) {
			return false;
		}

		// Send notification if changes were detected.
		if ( $result['changes_detected'] ) {
			$previous_records = null;
			if ( $result['last_record'] ) {
				$db = new DNS_Monitor_DB();
				$db_records = $db->get_snapshot_records( $result['last_record']->ID );
				if ( $db_records !== false ) {
					$previous_records = $db->convert_db_records_to_dns_format( $db_records );
				}
			}
			$notifications->send_change_notification( $domain, $result['records'], $previous_records );
		}

		return $result;
	}

	/**
	 * Fetch and process DNS records for the site domain
	 *
	 * @param string $domain_name      Domain name.
	 * @param bool   $save_snapshot    Whether to save snapshot.
	 * @param bool   $check_for_changes Whether to check for changes.
	 * @return array|false
	 */
	public static function fetch_and_process_records( $domain_name, $save_snapshot = true, $check_for_changes = true ) {
		$db = new DNS_Monitor_DB();

		// Fetch DNS records.
		$records = dns_get_record( $domain_name, DNS_A );

		if ( ! $records ) {
			return false;
		}

		// Sort records to ensure consistent order before serialization.
		$sorted_records = self::sort_records( $records );
		$records_json  = wp_json_encode( $sorted_records );

		$result = array(
			'records'          => $records,
			'records_json'     => $records_json,
			'changes_detected' => false,
			'last_record'      => null,
		);

		if ( $check_for_changes || $save_snapshot ) {
			// Get the last snapshot for comparison.
			$last_record = $db->get_latest_snapshot();
			$result['last_record'] = $last_record;

			// Calculate changes breakdown
			$changes_breakdown = array(
				'additions' => 0,
				'removals' => 0,
				'modifications' => 0,
				'total' => 0,
			);
			if ( $last_record ) {
				$db_records = $db->get_snapshot_records( $last_record->ID );
				if ( $db_records !== false ) {
					$previous_records = $db->convert_db_records_to_dns_format( $db_records );
					if ( $previous_records ) {
						$changes_breakdown = $db->calculate_dns_changes( $records, $previous_records );
					}
				}
			}

					// Check if this is a new record or if changes were detected.
		if ( $check_for_changes && $last_record && $changes_breakdown['total'] > 0 ) {
			$result['changes_detected'] = true;
		}

		// Add changes breakdown to result for API usage
		$result['changes_breakdown'] = $changes_breakdown;

			// Save the snapshot if requested AND (it's the first snapshot OR changes were detected).
			$is_first_snapshot = ! $last_record;
			$snapshot_behavior = get_option( 'dns_monitor_snapshot_behavior', 'always' );
			if ( $save_snapshot && ( $is_first_snapshot || $result['changes_detected'] || $snapshot_behavior === 'always' ) ) {
				// Use transactional method to ensure atomicity between snapshot and records creation
				$snapshot_id = $db->create_snapshot_with_records( $records, $changes_breakdown );
				
				// Check if the transactional operation succeeded
				if ( false === $snapshot_id ) {
					// Log error and set result to indicate failure
					error_log( 'DNS Monitor: Failed to create snapshot with records atomically for domain: ' . $domain_name );
					$result['snapshot_error'] = true;
					$result['snapshot_error_message'] = 'Failed to save DNS snapshot. Please check error logs.';
				} else {
					// Success - add snapshot ID to result for reference
					$result['snapshot_id'] = $snapshot_id;
					$result['snapshot_saved'] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Sort DNS records for consistent comparison
	 * Sort by type first, then by the record's primary value according to its type
	 *
	 * @param array $records DNS records to sort.
	 * @return array Sorted DNS records.
	 */
	private static function sort_records( $records ) {
		usort( $records, function( $a, $b ) {
			// First sort by type.
			$type_comparison = strcmp( $a['type'], $b['type'] );
			if ( $type_comparison !== 0 ) {
				return $type_comparison;
			}

			// Secondary sort by the record's primary value according to its type.
			$a_primary = self::get_record_primary_value( $a );
			$b_primary = self::get_record_primary_value( $b );

			$primary_comparison = strcmp( $a_primary, $b_primary );
			if ( $primary_comparison !== 0 ) {
				return $primary_comparison;
			}

			// Tertiary sort by host if primary values are the same.
			$a_host = isset( $a['host'] ) ? $a['host'] : '';
			$b_host = isset( $b['host'] ) ? $b['host'] : '';

			return strcmp( $a_host, $b_host );
		} );

		return $records;
	}

	/**
	 * Get the primary value for a DNS record based on its type
	 *
	 * @param array $record DNS record.
	 * @return string Primary value for sorting.
	 */
	private static function get_record_primary_value( $record ) {
		$type = $record['type'];

		switch ( $type ) {
			case 'A':
				return isset( $record['ip'] ) ? $record['ip'] : '';

			case 'AAAA':
				return isset( $record['ipv6'] ) ? $record['ipv6'] : '';

			case 'CNAME':
			case 'NS':
			case 'PTR':
				return isset( $record['target'] ) ? $record['target'] : '';

			case 'MX':
				// Sort by priority first, then target.
				$priority = isset( $record['pri'] ) ? str_pad( $record['pri'], 5, '0', STR_PAD_LEFT ) : '00000';
				$target = isset( $record['target'] ) ? $record['target'] : '';
				return $priority . '|' . $target;

			case 'TXT':
				if ( isset( $record['txt'] ) ) {
					if ( is_array( $record['txt'] ) ) {
						// Sort array elements and join them.
						$txt_array = $record['txt'];
						sort( $txt_array );
						return implode( '|', $txt_array );
					} else {
						return $record['txt'];
					}
				}
				return '';

			default:
				// For unknown record types, try common fields in order of preference.
				if ( isset( $record['target'] ) ) {
					return $record['target'];
				} elseif ( isset( $record['ip'] ) ) {
					return $record['ip'];
				} elseif ( isset( $record['ipv6'] ) ) {
					return $record['ipv6'];
				} elseif ( isset( $record['value'] ) ) {
					return $record['value'];
				} elseif ( isset( $record['txt'] ) ) {
					return is_array( $record['txt'] ) ? implode( '|', $record['txt'] ) : $record['txt'];
				} else {
					// Fallback: concatenate all non-standard fields.
					$other_fields = array();
					foreach ( $record as $key => $value ) {
						if ( ! in_array( $key, array( 'type', 'host', 'class', 'ttl' ) ) ) {
							$other_fields[] = $key . ':' . ( is_array( $value ) ? implode( ',', $value ) : $value );
						}
					}
					sort( $other_fields );
					return implode( '|', $other_fields );
				}
		}
	}
} 