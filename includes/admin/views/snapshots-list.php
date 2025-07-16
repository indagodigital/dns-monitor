<?php
/**
 * Template for displaying the list of snapshots.
 *
 * @package DNS_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( empty( $snapshots ) ) {
	?>
	<div class="dns-monitor-content-loading">
		<p><?php esc_html_e( 'No snapshots found. Click "Check DNS Now" to create your first snapshot.', 'dns-monitor' ); ?></p>
	</div>
	<?php
	return;
}
?>

<div id="dns-monitor-snapshots-list" class="dns-snapshots-list">
	<?php
	// Limit to 10 snapshots using a for loop to maintain proper previous snapshot references
	$total_snapshots = count( $snapshots );
	$max_snapshots   = min( 10, $total_snapshots );

	for ( $index = 0; $index < $max_snapshots; $index++ ) {
		$snapshot = $snapshots[ $index ];

		// Get records from the dns_records table instead of JSON data
		$records = $this->get_records_for_snapshot( $snapshot->ID, $db );
		if ( false === $records ) {
			continue; // Skip snapshots with no records
		}

		// Get the changes breakdown from the database
		$changes_count = isset( $snapshot->snapshot_changes ) ? intval( $snapshot->snapshot_changes ) : 0;
		$additions     = isset( $snapshot->snapshot_additions ) ? intval( $snapshot->snapshot_additions ) : 0;
		$removals      = isset( $snapshot->snapshot_removals ) ? intval( $snapshot->snapshot_removals ) : 0;
		$modifications = isset( $snapshot->snapshot_modifications ) ? intval( $snapshot->snapshot_modifications ) : 0;

		// Get the previous record for comparison (for display purposes)
		$previous_record  = null;
		$previous_records = array();
		if ( $index < $total_snapshots - 1 ) {
			$previous_record  = $snapshots[ $index + 1 ];
			$previous_records = $this->get_records_for_snapshot( $previous_record->ID, $db );
			if ( false === $previous_records ) {
				$previous_records = array(); // Handle missing data gracefully
			}
		}

		// Add CSS class for highlighting cards with changes
		$card_class = $changes_count > 0 ? 'dns-changes-detected' : '';
		$card_class .= ( 0 === $index ) ? ' dns-card-expanded' : ''; // Expand the first card by default
		?>
		<div class="dns-snapshot-card <?php echo esc_attr( $card_class ); ?>" data-snapshot-id="<?php echo esc_attr( $snapshot->ID ); ?>">
			
			<!-- Card header with date and changes summary (clickable) -->
			<div class="dns-snapshot-card-header dns-toggle-records" data-record-id="<?php echo esc_attr( $snapshot->ID ); ?>" aria-expanded="false" aria-controls="dns-record-content-<?php echo esc_attr( $snapshot->ID ); ?>" role="button" tabindex="0">
				<div class="dns-snapshot-info">
					<div class="dns-snapshot-date"><?php echo esc_html( $this->format_wp_date( $snapshot->created_at ) ); ?></div>
					
					<div class="dns-snapshot-badges">
						<?php if ( $changes_count > 0 ) : ?>
							<?php if ( $additions > 0 ) : ?>
								<span class="dns-badge dns-badge-addition" title="<?php esc_attr_e( 'Additions', 'dns-monitor' ); ?>"></span>
							<?php endif; ?>
							<?php if ( $modifications > 0 ) : ?>
								<span class="dns-badge dns-badge-modification" title="<?php esc_attr_e( 'Modifications', 'dns-monitor' ); ?>"></span>
							<?php endif; ?>
							<?php if ( $removals > 0 ) : ?>
								<span class="dns-badge dns-badge-removal" title="<?php esc_attr_e( 'Removals', 'dns-monitor' ); ?>"></span>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div> <!-- End snapshot info -->
			</div> <!-- End card header -->
			
			<!-- Card content (initially hidden) -->
			<div class="dns-snapshot-card-content" id="dns-record-content-<?php echo esc_attr( $snapshot->ID ); ?>">
				<?php echo $this->render_snapshot_comparison_admin( $snapshot, $previous_record, $records, $previous_records, $db ); ?>
			</div>
			
		</div> <!-- End card -->
		<?php
	}
	?>
</div> <!-- End list -->
