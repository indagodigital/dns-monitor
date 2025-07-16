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
	 * Instance of this class
	 *
	 * @var DNS_Monitor_DB
	 */
	protected static $instance = null;

	/**
	 * Table prefix
	 *
	 * @var string
	 */
	private $table_prefix;

	/**
	 * Constructor
	 */
	private function __construct() {
		global $wpdb;
		$this->table_prefix = $wpdb->prefix . 'dns_';
	}

	/**
	 * Get the singleton instance of this class
	 *
	 * @return DNS_Monitor_DB
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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
			snapshot_changes INT(11) NOT NULL DEFAULT 0,
			snapshot_additions INT(11) NOT NULL DEFAULT 0,
			snapshot_removals INT(11) NOT NULL DEFAULT 0,
			snapshot_modifications INT(11) NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY ID (ID),
			KEY idx_created_at (created_at)
		) ENGINE=InnoDB $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table_prefix}records (
			ID INT(11) NOT NULL auto_increment,
			snapshot_id INT(11) NOT NULL,
			record_host VARCHAR(255) NOT NULL,
			record_type VARCHAR(255) NOT NULL,
			record_data LONGTEXT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY ID (ID),
			KEY snapshot_id (snapshot_id),
			KEY idx_snapshot_type_host (snapshot_id, record_type, record_host),
			KEY idx_type_host (record_type, record_host),
			KEY idx_record_type (record_type),
			FOREIGN KEY (snapshot_id) REFERENCES {$this->table_prefix}snapshots(ID) ON DELETE CASCADE
		) ENGINE=InnoDB $charset_collate;";

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
	 * @param int $page Page number (1-indexed).
	 * @param int $per_page Records per page.
	 * @return array
	 */
	public function get_snapshots( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$page = max( 1, intval( $page ) );
		$per_page = max( 1, min( 100, intval( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_prefix}snapshots 
				ORDER BY created_at DESC 
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Get a single snapshot by ID
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return object|null Snapshot object or null if not found.
	 */
	public function get_snapshot( $snapshot_id ) {
		global $wpdb;

		if ( empty( $snapshot_id ) ) {
			return null;
		}

		$snapshot_id = intval( $snapshot_id );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_prefix}snapshots 
				WHERE ID = %d",
				$snapshot_id
			)
		);
	}

	/**
	 * Get the previous snapshot before a given snapshot
	 *
	 * @param int $current_snapshot_id Current snapshot ID.
	 * @return object|null Previous snapshot object or null if not found.
	 */
	public function get_previous_snapshot( $current_snapshot_id ) {
		global $wpdb;

		if ( empty( $current_snapshot_id ) ) {
			return null;
		}

		$current_snapshot_id = intval( $current_snapshot_id );

		// Get the creation time of the current snapshot
		$current_snapshot = $this->get_snapshot( $current_snapshot_id );
		if ( ! $current_snapshot ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_prefix}snapshots 
				WHERE created_at < %s 
				ORDER BY created_at DESC 
				LIMIT 1",
				$current_snapshot->created_at
			)
		);
	}

	/**
	 * Add new snapshot
	 *
	 * @param array|int $changes   Change breakdown array or total changes (for backward compatibility).
	 * @return int|false
	 */
	public function add_snapshot( $changes = 0 ) {
		global $wpdb;

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
		if ( $count >= 11 ) {
			// Delete the oldest snapshot record(s) to make room for the new one.
			// The foreign key constraint with ON DELETE CASCADE will automatically 
			// remove related records from the dns_records table.
			$wpdb->query(
				"DELETE FROM {$this->table_prefix}snapshots 
				ORDER BY created_at ASC 
				LIMIT " . ( $count - 10 )
			);
		}

		$result = $wpdb->insert(
			$this->table_prefix . 'snapshots',
			array(
				'snapshot_changes' => $total_changes,
				'snapshot_additions' => $additions,
				'snapshot_removals' => $removals,
				'snapshot_modifications' => $modifications,
			),
			array( '%d', '%d', '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Delete a snapshot by ID
	 *
	 * @param int $snapshot_id Snapshot ID to delete.
	 * @return bool Success status.
	 */
	public function delete_snapshot( $snapshot_id ) {
		global $wpdb;

		if ( empty( $snapshot_id ) ) {
			return false;
		}

		$snapshot_id = intval( $snapshot_id );

		// Delete the snapshot. The foreign key constraint with ON DELETE CASCADE
		// will automatically delete all related records from the dns_records table.
		$result = $wpdb->delete(
			$this->table_prefix . 'snapshots',
			array( 'ID' => $snapshot_id ),
			array( '%d' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Create a snapshot and its records atomically within a transaction
	 *
	 * @param array $records DNS records array.
	 * @param array|int $changes Change breakdown array or total changes.
	 * @return int|false Snapshot ID on success, false on failure.
	 */
	public function create_snapshot_with_records( $records, $changes = 0 ) {
		global $wpdb;

		// Validate input
		if ( empty( $records ) || ! is_array( $records ) ) {
			return false;
		}

		// Check if the database supports transactions
		$supports_transactions = $this->check_transaction_support();
		if ( ! $supports_transactions ) {
			// Fallback to non-transactional method for older MySQL/databases
			error_log( 'DNS Monitor: Database does not support transactions, falling back to non-atomic operation' );
			return $this->create_snapshot_fallback( $records, $changes );
		}

		// Start transaction
		$transaction_started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $transaction_started ) {
			error_log( 'DNS Monitor: Failed to start database transaction' );
			return false;
		}

		try {
			// First, create the snapshot
			$snapshot_id = $this->add_snapshot( $changes );
			
			if ( false === $snapshot_id ) {
				// Rollback on snapshot creation failure
				$wpdb->query( 'ROLLBACK' );
				error_log( 'DNS Monitor: Failed to create snapshot, rolling back transaction' );
				return false;
			}

			// Then, add the records
			$records_success = $this->add_records( $snapshot_id, $records );
			
			if ( false === $records_success ) {
				// Rollback on records creation failure
				$wpdb->query( 'ROLLBACK' );
				error_log( 'DNS Monitor: Failed to add records for snapshot ID ' . $snapshot_id . ', rolling back transaction' );
				return false;
			}

			// Commit the transaction if both operations succeeded
			$commit_result = $wpdb->query( 'COMMIT' );
			if ( false === $commit_result ) {
				error_log( 'DNS Monitor: Failed to commit transaction for snapshot ID ' . $snapshot_id );
				return false;
			}

			return $snapshot_id;

		} catch ( Exception $e ) {
			// Rollback on any exception
			$wpdb->query( 'ROLLBACK' );
			error_log( 'DNS Monitor: Exception during transaction: ' . $e->getMessage() );
			return false;
		} catch ( Error $e ) {
			// Rollback on any fatal error
			$wpdb->query( 'ROLLBACK' );
			error_log( 'DNS Monitor: Fatal error during transaction: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if the database supports transactions
	 *
	 * @return bool True if transactions are supported, false otherwise.
	 */
	private function check_transaction_support() {
		global $wpdb;

		// Check MySQL version and engine support
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( empty( $mysql_version ) ) {
			return false;
		}

		// Extract version number (e.g., "8.0.25" from "8.0.25-log")
		preg_match( '/^(\d+\.\d+)/', $mysql_version, $matches );
		$version = isset( $matches[1] ) ? floatval( $matches[1] ) : 0;

		// MySQL 5.0+ supports transactions with InnoDB
		if ( $version >= 5.0 ) {
			// Check if tables use InnoDB engine (which supports transactions)
			$engine = $wpdb->get_var( 
				$wpdb->prepare( 
					"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
					DB_NAME,
					$this->table_prefix . 'snapshots'
				)
			);
			
			return strtolower( $engine ) === 'innodb';
		}

		return false;
	}

	/**
	 * Fallback method for creating snapshot and records without transactions
	 *
	 * @param array $records DNS records array.
	 * @param array|int $changes Change breakdown array or total changes.
	 * @return int|false Snapshot ID on success, false on failure.
	 */
	private function create_snapshot_fallback( $records, $changes = 0 ) {
		// Create the snapshot first
		$snapshot_id = $this->add_snapshot( $changes );
		
		if ( false === $snapshot_id ) {
			return false;
		}

		// Then add the records
		$records_success = $this->add_records( $snapshot_id, $records );
		
		if ( false === $records_success ) {
			// If records failed, try to clean up the snapshot
			// Note: Without transactions, this cleanup might also fail
			$this->delete_snapshot( $snapshot_id );
			error_log( 'DNS Monitor: Failed to add records, attempted to clean up snapshot ID ' . $snapshot_id );
			return false;
		}

		return $snapshot_id;
	}

	/**
	 * Add DNS records to the records table
	 *
	 * @param int   $snapshot_id ID of the snapshot.
	 * @param array $records     Array of DNS records.
	 * @return bool Success status.
	 */
	public function add_records( $snapshot_id, $records ) {
		global $wpdb;

		if ( empty( $snapshot_id ) || empty( $records ) || ! is_array( $records ) ) {
			return false;
		}

		$snapshot_id = intval( $snapshot_id );

		// Prepare batch insert.
		$values = array();
		$placeholders = array();

		foreach ( $records as $record ) {
			if ( ! isset( $record['type'] ) ) {
				continue; // Skip invalid records.
			}

			$record_host = isset( $record['host'] ) ? $record['host'] : '';
			$record_type = $record['type'];
			$record_data = $this->get_record_data_value( $record );

			$values[] = $snapshot_id;
			$values[] = $record_host;
			$values[] = $record_type;
			$values[] = $record_data;

			$placeholders[] = '(%d, %s, %s, %s)';
		}

		if ( empty( $placeholders ) ) {
			return false;
		}

		$sql = "INSERT INTO {$this->table_prefix}records (snapshot_id, record_host, record_type, record_data) VALUES " . implode( ', ', $placeholders );

		$result = $wpdb->query( $wpdb->prepare( $sql, ...$values ) );

		return false !== $result;
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

	/**
	 * Get the data value for a DNS record (based on get_record_primary_value logic)
	 *
	 * @param array $record DNS record.
	 * @return string Data value for the record.
	 */
	private function get_record_data_value( $record ) {
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
				// Include priority and target.
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

	/**
	 * Get individual records for a snapshot
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array|false Array of record objects or false on failure.
	 */
	public function get_snapshot_records( $snapshot_id ) {
		global $wpdb;

		if ( empty( $snapshot_id ) ) {
			return false;
		}

		$snapshot_id = intval( $snapshot_id );

		// Use optimized query that leverages the idx_snapshot_type_host composite index
		// Order matches the index columns for maximum performance
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_prefix}records 
				WHERE snapshot_id = %d 
				ORDER BY snapshot_id, record_type, record_host",
				$snapshot_id
			)
		);

		return $records !== false ? $records : false;
	}

	/**
	 * Get records by type across all snapshots (leverages idx_record_type index)
	 *
	 * @param string $record_type DNS record type (A, AAAA, CNAME, etc.).
	 * @param int    $limit       Maximum number of records to return.
	 * @return array|false Array of record objects or false on failure.
	 */
	public function get_records_by_type( $record_type, $limit = 100 ) {
		global $wpdb;

		if ( empty( $record_type ) ) {
			return false;
		}

		$record_type = sanitize_text_field( $record_type );
		$limit = max( 1, min( 1000, intval( $limit ) ) );

		// Leverages idx_record_type index for fast filtering
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_prefix}records 
				WHERE record_type = %s 
				ORDER BY record_type, record_host 
				LIMIT %d",
				$record_type,
				$limit
			)
		);

		return $records !== false ? $records : false;
	}

	/**
	 * Get records for a specific host across all snapshots (leverages idx_type_host index)
	 *
	 * @param string $record_host DNS record host.
	 * @param string $record_type Optional DNS record type filter.
	 * @param int    $limit       Maximum number of records to return.
	 * @return array|false Array of record objects or false on failure.
	 */
	public function get_records_by_host( $record_host, $record_type = '', $limit = 100 ) {
		global $wpdb;

		if ( empty( $record_host ) ) {
			return false;
		}

		$record_host = sanitize_text_field( $record_host );
		$limit = max( 1, min( 1000, intval( $limit ) ) );

		if ( ! empty( $record_type ) ) {
			$record_type = sanitize_text_field( $record_type );
			// Leverages idx_type_host composite index for optimal performance
			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_prefix}records 
					WHERE record_type = %s AND record_host = %s 
					ORDER BY record_type, record_host, created_at DESC 
					LIMIT %d",
					$record_type,
					$record_host,
					$limit
				)
			);
		} else {
			// Uses idx_type_host index with host filtering
			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_prefix}records 
					WHERE record_host = %s 
					ORDER BY record_type, record_host, created_at DESC 
					LIMIT %d",
					$record_host,
					$limit
				)
			);
		}

		return $records !== false ? $records : false;
	}

	/**
	 * Convert individual records from database format to DNS record format
	 *
	 * @param array $db_records Array of database record objects.
	 * @return array Array of DNS records in the original format.
	 */
	public function convert_db_records_to_dns_format( $db_records ) {
		if ( empty( $db_records ) || ! is_array( $db_records ) ) {
			return array();
		}

		$dns_records = array();

		foreach ( $db_records as $db_record ) {
			// Create a basic DNS record structure
			$dns_record = array(
				'host' => $db_record->record_host,
				'type' => $db_record->record_type,
			);

			// Parse the record_data back into the appropriate fields based on type
			$this->parse_record_data_to_dns_record( $dns_record, $db_record->record_data );

			$dns_records[] = $dns_record;
		}



		return $dns_records;
	}

	/**
	 * Parse record data back into DNS record fields
	 *
	 * @param array  &$dns_record DNS record array (passed by reference).
	 * @param string $record_data Record data string.
	 */
	private function parse_record_data_to_dns_record( &$dns_record, $record_data ) {
		$type = $dns_record['type'];

		switch ( $type ) {
			case 'A':
				$dns_record['ip'] = $record_data;
				break;

			case 'AAAA':
				$dns_record['ipv6'] = $record_data;
				break;

			case 'CNAME':
			case 'NS':
			case 'PTR':
				$dns_record['target'] = $record_data;
				break;

			case 'MX':
				// Parse priority|target format
				$parts = explode( '|', $record_data, 2 );
				if ( count( $parts ) === 2 ) {
					$dns_record['pri'] = intval( ltrim( $parts[0], '0' ) ?: '0' );
					$dns_record['target'] = $parts[1];
				}
				break;

			case 'TXT':
				// Parse pipe-separated values back to array or string
				if ( strpos( $record_data, '|' ) !== false ) {
					$dns_record['txt'] = explode( '|', $record_data );
				} else {
					$dns_record['txt'] = $record_data;
				}
				break;

			default:
				// For unknown types, try to parse as key:value pairs
				if ( strpos( $record_data, ':' ) !== false && strpos( $record_data, '|' ) !== false ) {
					$pairs = explode( '|', $record_data );
					foreach ( $pairs as $pair ) {
						$kv = explode( ':', $pair, 2 );
						if ( count( $kv ) === 2 ) {
							$key = $kv[0];
							$value = $kv[1];
							// Convert comma-separated values back to arrays
							if ( strpos( $value, ',' ) !== false ) {
								$dns_record[ $key ] = explode( ',', $value );
							} else {
								$dns_record[ $key ] = $value;
							}
						}
					}
				} else {
					// Single value fallback
					if ( ! isset( $dns_record['target'] ) && ! isset( $dns_record['ip'] ) && ! isset( $dns_record['ipv6'] ) ) {
						$dns_record['target'] = $record_data;
					}
				}
				break;
		}
	}
}