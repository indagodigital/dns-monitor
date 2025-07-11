<?php
/**
 * Database management for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database management class for DNS Monitor.
 */
class DNS_Monitor_DB {
	/**
	 * Table prefix
	 *
	 * @var string
	 */
	private $table_prefix;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_prefix = $wpdb->prefix . 'dns_';
	}

	/**
	 * Create database tables
	 * Note: TIMESTAMP columns store in UTC by default in MySQL
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql[] = "CREATE TABLE {$this->table_prefix}snapshots (
			ID INT(11) NOT NULL auto_increment,
			snapshot_records LONGTEXT NOT NULL,
			snapshot_changes INT(11) NOT NULL DEFAULT 0,
			snapshot_additions INT(11) NOT NULL DEFAULT 0,
			snapshot_removals INT(11) NOT NULL DEFAULT 0,
			snapshot_modifications INT(11) NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY ID (ID)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	/**
	 * Get latest snapshot
	 *
	 * @return object|null
	 */
	public function get_latest_snapshot() {
		global $wpdb;

		return $wpdb->get_row(
			"SELECT * FROM {$this->table_prefix}snapshots 
			ORDER BY created_at DESC 
			LIMIT 1"
		);
	}

	/**
	 * Get all snapshots
	 *
	 * @return array
	 */
	public function get_snapshots() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT * FROM {$this->table_prefix}snapshots 
			ORDER BY created_at DESC"
		);
	}

	/**
	 * Add new snapshot
	 *
	 * @param string $records_json JSON encoded records.
	 * @param array|int $changes   Change breakdown array or total changes (for backward compatibility).
	 * @return int|false
	 */
	public function add_snapshot( $records_json, $changes = 0 ) {
		global $wpdb;

		// Validate inputs.
		if ( empty( $records_json ) ) {
			return false;
		}

		// Handle both array (new format) and integer (backward compatibility) input
		if ( is_array( $changes ) ) {
			$additions = isset( $changes['additions'] ) ? intval( $changes['additions'] ) : 0;
			$removals = isset( $changes['removals'] ) ? intval( $changes['removals'] ) : 0;
			$modifications = isset( $changes['modifications'] ) ? intval( $changes['modifications'] ) : 0;
			$total_changes = $additions + $removals + $modifications;
		} else {
			// Backward compatibility: if integer is passed, treat as total changes
			$total_changes = intval( $changes );
			$additions = 0;
			$removals = 0;
			$modifications = 0;
		}

		// Check current row count and delete oldest if we have 10 or more rows.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_prefix}snapshots" );
		if ( $count >= 10 ) {
			// Delete the oldest record(s) to make room for the new one.
			$wpdb->query(
				"DELETE FROM {$this->table_prefix}snapshots 
				ORDER BY created_at ASC 
				LIMIT " . ( $count - 9 )
			);
		}

		$result = $wpdb->insert(
			$this->table_prefix . 'snapshots',
			array(
				'snapshot_records' => $records_json,
				'snapshot_changes' => $total_changes,
				'snapshot_additions' => $additions,
				'snapshot_removals' => $removals,
				'snapshot_modifications' => $modifications,
			),
			array( '%s', '%d', '%d', '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Safely decode JSON records from snapshot
	 *
	 * @param string $json_data JSON data.
	 * @return array|false
	 */
	public function decode_snapshot_records( $json_data ) {
		if ( empty( $json_data ) ) {
			return false;
		}

		// Try JSON decode first (new format).
		$records = json_decode( $json_data, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $records ) ) {
			return $records;
		}

		// Fallback to unserialize for backward compatibility (old format).
		$records = @unserialize( $json_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false !== $records && is_array( $records ) ) {
			return $records;
		}

		return false;
	}

	/**
	 * Calculate the number of changes between two DNS record snapshots
	 * Ignores TTL values as they change frequently
	 *
	 * @param array $current_records  Current snapshot records.
	 * @param array $previous_records Previous snapshot records.
	 * @return array Change breakdown with counts.
	 */
	public function calculate_dns_changes( $current_records, $previous_records ) {
		if ( empty( $previous_records ) ) {
			return array(
				'additions' => 0,
				'removals' => 0,
				'modifications' => 0,
				'total' => 0,
			);
		}

		$additions = 0;
		$removals = 0;
		$modifications = 0;
		$current_record_keys = array();

		// Check for added or modified records.
		foreach ( $current_records as $current_record ) {
			$current_key = $this->get_record_key_without_ttl( $current_record );
			$current_record_keys[] = $current_key;

			$found = false;
			foreach ( $previous_records as $previous_record ) {
				$previous_key = $this->get_record_key_without_ttl( $previous_record );
				if ( $current_key === $previous_key ) {
					$found = true;
					// Check if any values changed (ignoring TTL).
					foreach ( $current_record as $key => $value ) {
						if ( 'ttl' === $key ) {
							continue; // Skip TTL comparison
						}
						if ( isset( $previous_record[ $key ] ) && $previous_record[ $key ] !== $value ) {
							$modifications++;
							break;
						}
					}
					break;
				}
			}

			if ( ! $found ) {
				$additions++; // New record added.
			}
		}

		// Check for removed records.
		foreach ( $previous_records as $previous_record ) {
			$previous_key = $this->get_record_key_without_ttl( $previous_record );
			if ( ! in_array( $previous_key, $current_record_keys ) ) {
				$removals++; // Record removed.
			}
		}

		return array(
			'additions' => $additions,
			'removals' => $removals,
			'modifications' => $modifications,
			'total' => $additions + $removals + $modifications,
		);
	}

	/**
	 * Generate a unique key for a DNS record without TTL
	 *
	 * @param array $record DNS record.
	 * @return string
	 */
	public function get_record_key_without_ttl( $record ) {
		$key_parts = array();

		// Always include type and host.
		$key_parts[] = $record['type'];
		$key_parts[] = isset( $record['host'] ) ? $record['host'] : '';

		// Add type-specific key components.
		switch ( $record['type'] ) {
			case 'A':
				$key_parts[] = isset( $record['ip'] ) ? $record['ip'] : '';
				break;

			case 'AAAA':
				$key_parts[] = isset( $record['ipv6'] ) ? $record['ipv6'] : '';
				break;

			case 'CNAME':
			case 'NS':
			case 'PTR':
				$key_parts[] = isset( $record['target'] ) ? $record['target'] : '';
				break;

			case 'MX':
				$key_parts[] = isset( $record['target'] ) ? $record['target'] : '';
				$key_parts[] = isset( $record['pri'] ) ? $record['pri'] : '';
				break;

			case 'TXT':
				if ( isset( $record['txt'] ) ) {
					$txt_content = is_array( $record['txt'] ) ? $record['txt'] : array( $record['txt'] );
					// Sort to ensure consistent ordering.
					sort( $txt_content );
					$key_parts[] = md5( implode( '|', $txt_content ) );
				}
				break;

			case 'SRV':
				$key_parts[] = isset( $record['target'] ) ? $record['target'] : '';
				$key_parts[] = isset( $record['port'] ) ? $record['port'] : '';
				$key_parts[] = isset( $record['pri'] ) ? $record['pri'] : '';
				$key_parts[] = isset( $record['weight'] ) ? $record['weight'] : '';
				break;

			case 'SOA':
				$key_parts[] = isset( $record['mname'] ) ? $record['mname'] : '';
				$key_parts[] = isset( $record['rname'] ) ? $record['rname'] : '';
				break;

			case 'CAA':
				$key_parts[] = isset( $record['flags'] ) ? $record['flags'] : '';
				$key_parts[] = isset( $record['tag'] ) ? $record['tag'] : '';
				$key_parts[] = isset( $record['value'] ) ? $record['value'] : '';
				break;

			default:
				// For unknown record types, include all fields except type, host, and ttl.
				$other_fields = array();
				foreach ( $record as $field => $value ) {
					if ( 'type' !== $field && 'host' !== $field && 'ttl' !== $field ) {
						$other_fields[] = $field . ':' . ( is_array( $value ) ? implode( ',', $value ) : $value );
					}
				}
				sort( $other_fields );
				$key_parts[] = md5( implode( '|', $other_fields ) );
				break;
		}

		// Create the final key.
		return implode( '-', array_map( 'strval', $key_parts ) );
	}
} 