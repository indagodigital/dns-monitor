/**
 * DNS Monitor Admin Scripts
 */
( function( $ ) {
	'use strict';

	// Main admin object
	var DNSMonitorAdmin = {
		// Track the currently open record panel
		currentOpenPanel: null,
		currentOpenToggle: null,
		
		// Track if a DNS check was just performed
		dnsCheckPerformed: false,

		// Initialize everything
		init: function() {
			this.initializeToggleRecords();
			this.initializeHTMXIntegration();
			this.initializeChangesDropdown();
			this.bindEvents();
		},

		// Initialize toggle records functionality
		initializeToggleRecords: function() {
			$( document ).off( 'click', '.dns-toggle-records' );
			$( document ).off( 'keydown', '.dns-toggle-records' );
			$( document ).on( 'click', '.dns-toggle-records', this.handleToggleRecords.bind( this ) );
			$( document ).on( 'keydown', '.dns-toggle-records', this.handleToggleRecordsKeydown.bind( this ) );
		},

		// Initialize changes dropdown functionality
		initializeChangesDropdown: function() {
			$( document ).off( 'change', '.dns-changes-dropdown' );
			$( document ).on( 'change', '.dns-changes-dropdown', this.handleChangesDropdown.bind( this ) );
			
			// Ensure all dropdown details are hidden by default
			$( '.dns-change-details' ).removeClass( 'dns-change-details-visible' ).hide();
			
			// Reset all dropdown selections to default
			$( '.dns-changes-dropdown' ).val( '' );
		},

		// Initialize HTMX integration
		initializeHTMXIntegration: function() {
			// Listen for HTMX content updates triggered by htmx-helpers.js
			$( document ).on( 'dns-monitor:htmx-content-updated', this.onHTMXContentUpdated.bind( this ) );

			// Add specific listener for DNS check completion to trigger refresh
			if ( typeof htmx !== 'undefined' ) {
				document.body.addEventListener( 'htmx:afterRequest', this.onHTMXAfterRequestForRefresh.bind( this ) );
			}
		},

		// Bind additional events
		bindEvents: function() {
			// Handle manual refresh button if present
			$( document ).on( 'click', '.dns-refresh-snapshots', this.refreshSnapshots.bind( this ) );
		},

		// Handle HTMX content updates
		onHTMXContentUpdated: function( event, target ) {
			// Re-initialize toggle functionality for new content
			this.initializeToggleRecords();
			this.initializeChangesDropdown();
			
			// Show success message if DNS check completed
			var $notifications = $( target ).find( '.dns-monitor-notification' );
			if ( $notifications.length > 0 && typeof DNSMonitorHTMX !== 'undefined' ) {
				DNSMonitorHTMX.showNotificationsFromHTML( $notifications );
			}

			// Admin-specific handling for DNS check results
			if ( target.id === 'dns-check-results' ) {
				// Scroll to updated content if it's below the fold
				target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}

			// Flash the first snapshot card when snapshots list is reloaded after DNS check
			if ( this.dnsCheckPerformed && ( target.id === 'dns-snapshots-container' || $( target ).find( '#dns-monitor-snapshots-list' ).length > 0 ) ) {
				this.flashFirstSnapshotCard();
				this.dnsCheckPerformed = false; // Reset the flag
			}
		},

		// Handle HTMX after request specifically for refresh detection
		onHTMXAfterRequestForRefresh: function( evt ) {
			// Only handle requests to the dns_check endpoint
			if ( evt.target.hasAttribute && evt.target.hasAttribute( 'hx-post' ) ) {
				var postUrl = evt.target.getAttribute( 'hx-post' );
				if ( postUrl && postUrl.includes( 'endpoint=dns_check' ) ) {
					// Determine success/failure based on response content, not just HTTP status
					var isSuccess = this.isDnsCheckSuccessful( evt );
					
					// Show status notification based on actual DNS check result
					this.showStatusNotification( isSuccess );
					
					// If successful, mark that a DNS check was performed and refresh
					if ( isSuccess ) {
						this.dnsCheckPerformed = true; // Set flag to trigger flash animation
						this.refreshSnapshots();
					}
				}
			}
		},

		// Determine if DNS check was successful based on response content
		isDnsCheckSuccessful: function( evt ) {
			// If HTTP request failed, it's definitely not successful
			if ( ! evt.detail.successful ) {
				return false;
			}
			
			// Check the response content for error indicators
			var responseText = evt.detail.xhr.responseText;
			if ( responseText ) {
				// Look for error notification in the response
				if ( responseText.includes( 'notice-error' ) ) {
					return false;
				}
				// Success or warning notifications indicate successful DNS check
				if ( responseText.includes( 'notice-success' ) || responseText.includes( 'notice-warning' ) ) {
					return true;
				}
			}
			
			// Default to successful if HTTP request succeeded and no error indicators found
			return true;
		},

		// Show status notification for DNS check
		showStatusNotification: function( isSuccess ) {
			var $statusContainer = $( '.dns-monitor-status' );
			var $successElement = $statusContainer.find( '.dns-status-success' );
			var $errorElement = $statusContainer.find( '.dns-status-error' );

			$successElement.removeClass( 'dns-status-show' );
			$errorElement.removeClass( 'dns-status-show' );
			
			// Show appropriate notification
			var $targetElement = isSuccess ? $successElement : $errorElement;
			
			// Force reflow to ensure the class removal takes effect
			$targetElement[0].offsetHeight;

			$targetElement.addClass( 'dns-status-show' );

			setTimeout( function() {
				$targetElement.removeClass( 'dns-status-show' );
			}, 5000 );
		},

		// Flash the first snapshot card to draw attention
		flashFirstSnapshotCard: function() {
			var $firstCard = $( '.dns-snapshot-card' ).first();
			
			if ( $firstCard.length > 0 ) {
				$firstCard.removeClass( 'dns-card-flash' );
				
				// Force reflow to ensure the class removal takes effect
				$firstCard[0].offsetHeight;
				$firstCard.addClass( 'dns-card-flash' );

				setTimeout( function() {
					$firstCard.removeClass( 'dns-card-flash' );
				}, 2000 );
			}
		},

		// Handle keyboard events for toggle records
		handleToggleRecordsKeydown: function( e ) {
			// Only handle Enter (13) and Space (32) keys
			if ( e.which === 13 || e.which === 32 ) {
				e.preventDefault();
				this.handleToggleRecords( e );
			}
		},

		// Toggle records handler
		handleToggleRecords: function( e ) {
			e.preventDefault();

			var $this = $( e.currentTarget );
			var recordId = $this.data( 'record-id' );
			var $content = $( '#dns-record-content-' + recordId );
			var $icon = $this.find( '.dashicons' );
			var $card = $this.closest( '.dns-snapshot-card' );

			// Check if this panel is already open
			var isThisPanelOpen = $card.hasClass( 'dns-card-expanded' );

			// Close other open panels
			if ( this.currentOpenPanel && this.currentOpenPanel.get( 0 ) !== $content.get( 0 ) ) {
				// Reset the previous toggle header and card
				if ( this.currentOpenToggle ) {
					this.currentOpenToggle.find( '.dashicons' )
						.removeClass( 'dashicons-arrow-down' )
						.addClass( 'dashicons-arrow-right' );
					this.currentOpenToggle.attr( 'aria-expanded', 'false' );
					
					// Remove expanded class from previous card
					this.currentOpenToggle.closest( '.dns-snapshot-card' ).removeClass( 'dns-card-expanded' );
				}
			}

			// Toggle the clicked panel
			if ( isThisPanelOpen ) {
				// Close this panel
				$icon.removeClass( 'dashicons-arrow-down' ).addClass( 'dashicons-arrow-right' );
				$this.attr( 'aria-expanded', 'false' );
				$card.removeClass( 'dns-card-expanded' );

				this.currentOpenPanel = null;
				this.currentOpenToggle = null;
			} else {
				// Open this panel
				$icon.removeClass( 'dashicons-arrow-right' ).addClass( 'dashicons-arrow-down' );
				$this.attr( 'aria-expanded', 'true' );
				$card.addClass( 'dns-card-expanded' );

				this.currentOpenPanel = $content;
				this.currentOpenToggle = $this;
			}
		},

		// Handle changes dropdown selection
		handleChangesDropdown: function( e ) {
			var $dropdown = $( e.currentTarget );
			var selectedValue = $dropdown.val();
			var $container = $dropdown.closest( '.dns-changes-dropdown-container' );
			
			// Hide all change details first
			$container.find( '.dns-change-details' ).removeClass( 'dns-change-details-visible' ).hide();
			
			// Only show selected change details if a value is selected
			if ( selectedValue && selectedValue !== '' ) {
				$container.find( '.dns-change-details[data-change-type="' + selectedValue + '"]' ).addClass( 'dns-change-details-visible' ).show();
			}
		},

		// Refresh snapshots table
		refreshSnapshots: function() {
			if ( typeof DNSMonitorHTMX !== 'undefined' && DNSMonitorHTMX.refreshEndpoint ) {
				return DNSMonitorHTMX.refreshEndpoint( 'refresh_snapshots', '#dns-snapshots-container' );
			}
			return false;
		}
	};

	// Initialize when document is ready
	$( document ).ready( function() {
		DNSMonitorAdmin.init();
	} );

	// Make available globally for debugging
	window.DNSMonitorAdmin = DNSMonitorAdmin;

} )( jQuery ); 