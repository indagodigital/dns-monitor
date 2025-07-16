<?php
/**
 * Notifications handling for DNS Monitor
 *
 * @package DNS_Monitor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications handling class.
 */
class DNS_Monitor_Notifications {
	/**
	 * Instance of this class
	 *
	 * @var DNS_Monitor_Notifications
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance of this class
	 *
	 * @return DNS_Monitor_Notifications
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Send notification about DNS changes
	 *
	 * @param string $domain_name      Domain name.
	 * @param array  $current_records  Current DNS records (optional).
	 * @param array  $previous_records Previous DNS records (optional).
	 * @return bool
	 */
	public function send_change_notification( $domain_name, $current_records = null, $previous_records = null ) {
		$notification_emails = $this->get_notification_emails();
		$site_name           = get_bloginfo( 'name' );

		$subject = "DNS Change Detected for {$domain_name}";

		$message = "DNS Monitor has detected a change in DNS records for {$domain_name}.\n\n";

		// Include change summary if records are provided.
		if ( $current_records && $previous_records ) {
			$change_summary = $this->generate_change_summary( $current_records, $previous_records );
			if ( ! empty( $change_summary ) ) {
				$message .= "SUMMARY OF CHANGES:\n";
				$message .= str_repeat( '=', 50 ) . "\n";
				$message .= $change_summary . "\n";
				$message .= str_repeat( '=', 50 ) . "\n\n";
			}
		}

		$message .= "Please review the changes and take appropriate action if needed.\n\n";
		$message .= "You can view the detailed breakdown of changes in your WordPress admin panel.\n\n";
		$message .= "This is an automated notification from DNS Monitor plugin.";

		$sent = false;
		foreach ( $notification_emails as $email ) {
			$result = wp_mail( $email, $subject, $message );
			if ( $result ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Generate a human-readable summary of DNS record changes
	 *
	 * @param array $current_records  Current DNS records.
	 * @param array $previous_records Previous DNS records.
	 * @return string
	 */
	private function generate_change_summary( $current_records, $previous_records ) {
		$summary  = '';
		$added    = array();
		$removed  = array();
		$modified = array();

		// Create lookup arrays for easier comparison.
		$current_lookup  = array();
		$previous_lookup = array();

		// Build current records lookup.
		foreach ( $current_records as $record ) {
			$key                    = self::get_record_key_without_ttl( $record );
			$current_lookup[ $key ] = $record;
		}

		// Build previous records lookup.
		foreach ( $previous_records as $record ) {
			$key                     = self::get_record_key_without_ttl( $record );
			$previous_lookup[ $key ] = $record;
		}

		// Find added records.
		foreach ( $current_lookup as $key => $record ) {
			if ( ! isset( $previous_lookup[ $key ] ) ) {
				$added[] = $this->format_record_for_summary( $record );
			}
		}

		// Find removed records.
		foreach ( $previous_lookup as $key => $record ) {
			if ( ! isset( $current_lookup[ $key ] ) ) {
				$removed[] = $this->format_record_for_summary( $record );
			}
		}

		// Find modified records (same key but different values).
		foreach ( $current_lookup as $key => $current_record ) {
			if ( isset( $previous_lookup[ $key ] ) ) {
				$previous_record = $previous_lookup[ $key ];
				$changes         = array();

				foreach ( $current_record as $field => $value ) {
					if ( 'ttl' === $field ) {
						continue; // Skip TTL comparison
					}
					if ( isset( $previous_record[ $field ] ) && $previous_record[ $field ] !== $value ) {
						$changes[] = "{$field}: '{$previous_record[ $field ]}' → '{$value}'";
					}
				}

				if ( ! empty( $changes ) ) {
					$modified[] = $this->format_record_for_summary( $current_record ) . "\n  Changes: " . implode( ', ', $changes );
				}
			}
		}

		// Build summary.
		if ( ! empty( $added ) ) {
			$summary .= 'ADDED RECORDS (' . count( $added ) . "):\n";
			foreach ( $added as $record ) {
				$summary .= '• ' . $record . "\n";
			}
			$summary .= "\n";
		}

		if ( ! empty( $removed ) ) {
			$summary .= 'REMOVED RECORDS (' . count( $removed ) . "):\n";
			foreach ( $removed as $record ) {
				$summary .= '• ' . $record . "\n";
			}
			$summary .= "\n";
		}

		if ( ! empty( $modified ) ) {
			$summary .= 'MODIFIED RECORDS (' . count( $modified ) . "):\n";
			foreach ( $modified as $record ) {
				$summary .= '• ' . $record . "\n";
			}
			$summary .= "\n";
		}

		if ( empty( $added ) && empty( $removed ) && empty( $modified ) ) {
			$summary = "Changes detected but no specific differences identified.\n";
		}

		return $summary;
	}

	/**
	 * Generate a unique key for a DNS record without TTL
	 *
	 * @param array $record DNS record.
	 * @return string
	 */
	public static function get_record_key_without_ttl( $record ) {
        $key_parts = array();

        // The unique key for an 'A' record is its host/domain name.
        $key_parts[] = 'A';
        $key_parts[] = isset( $record['host'] ) ? $record['host'] : '';

        // Create the final key.
        return implode( '-', array_map( 'strval', $key_parts ) );
    }

	/**
	 * Format a DNS record for summary display
	 *
	 * @param array $record DNS record.
	 * @return string
	 */
	private function format_record_for_summary( $record ) {
        $ip = isset( $record['ip'] ) ? $record['ip'] : '';
        return "A Record: {$ip}";
    }

	/**
	 * Send notification about plugin activation
	 *
	 * @param string $domain The current site domain being monitored.
	 * @return bool
	 */
	public function send_activation_notification( $domain = '' ) {
		$notification_emails = $this->get_notification_emails();
		$site_name           = get_bloginfo( 'name' );
		$site_url            = DNS_MONITOR_SITE_URL;

		$subject = "DNS Monitor activated for {$site_url}";

		$message = "The DNS Monitor plugin has been successfully activated on {$site_name} ({$site_url}).\n\n";

		if ( $domain ) {
			$message .= "DNS monitoring is now active for your site's domain: {$domain}\n\n";
		}

		$message .= "Key features:\n";
		$message .= "• Automatic DNS monitoring and change detection\n";
		$message .= "• Email notifications when DNS changes are detected\n";
		$message .= "• Historical snapshots of DNS records\n\n";

		$message .= "You can view DNS snapshots and manage settings in your WordPress admin panel under 'DNS Monitor'.\n\n";
		$message .= "This is an automated notification from DNS Monitor plugin.";

		$sent = false;
		foreach ( $notification_emails as $email ) {
			$result = wp_mail( $email, $subject, $message );
			if ( $result ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Send notification about plugin deactivation
	 *
	 * @return bool
	 */
	public function send_deactivation_notification() {
		$notification_emails = $this->get_notification_emails();
		$site_name           = get_bloginfo( 'name' );
		$site_url            = DNS_MONITOR_SITE_URL;

		$subject = "DNS Monitor Plugin Deactivated on {$site_name}";

		$message = "The DNS Monitor plugin has been deactivated on {$site_name} ({$site_url}).\n";
		$message .= "DNS monitoring and automated checks have been stopped.\n\n";
		$message .= "Note: Your existing DNS monitoring data has not been affected by this action.\n";
		$message .= "If you need to resume DNS monitoring, simply reactivate the plugin from your WordPress admin panel.\n\n";
		$message .= "This is an automated notification from DNS Monitor plugin.";

		$sent = false;
		foreach ( $notification_emails as $email ) {
			$result = wp_mail( $email, $subject, $message );
			if ( $result ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Get notification email addresses
	 *
	 * @return array
	 */
	private function get_notification_emails() {
		$notification_emails = get_option( 'dns_monitor_notification_email', get_option( 'admin_email' ) );

		// Convert to array if it's a single email.
		if ( ! is_array( $notification_emails ) ) {
			$notification_emails = array_filter( array_map( 'trim', explode( ',', $notification_emails ) ) );
			if ( empty( $notification_emails ) ) {
				$notification_emails = array( get_option( 'admin_email' ) );
			}
		}

		return $notification_emails;
	}
}