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

		// Initialize everything
		init: function() {
			this.initializeToggleRecords();
			this.initializeHTMXIntegration();
			this.bindEvents();
		},

		// Initialize toggle records functionality
		initializeToggleRecords: function() {
			$( document ).off( 'click', '.dns-toggle-records' );
			$( document ).on( 'click', '.dns-toggle-records', this.handleToggleRecords.bind( this ) );
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
		},

		// Handle HTMX after request specifically for refresh detection
		onHTMXAfterRequestForRefresh: function( evt ) {
			// Only handle successful requests to the dns_check endpoint
			if ( evt.detail.successful && evt.target.hasAttribute && evt.target.hasAttribute( 'hx-post' ) ) {
				var postUrl = evt.target.getAttribute( 'hx-post' );
				if ( postUrl && postUrl.includes( 'endpoint=dns_check' ) ) {
					// Wait a moment for the database to update, then refresh
					setTimeout( this.refreshSnapshots.bind( this ), 1000 );
				}
			}
		},

		// Toggle records handler
		handleToggleRecords: function( e ) {
			e.preventDefault();

			var $this = $( e.currentTarget );
			var recordId = $this.data( 'record-id' );
			var $content = $( '#dns-record-content-' + recordId );
			var $icon = $this.find( '.dashicons' );

			// Check if this panel is already open
			var isThisPanelOpen = $content.is( ':visible' );

			// Close other open panels
			if ( this.currentOpenPanel && this.currentOpenPanel.get( 0 ) !== $content.get( 0 ) ) {
				this.currentOpenPanel.slideUp( 200 );

				// Reset the previous toggle button
				if ( this.currentOpenToggle ) {
					this.currentOpenToggle.find( '.dashicons' )
						.removeClass( 'dashicons-arrow-down' )
						.addClass( 'dashicons-arrow-right' );
					this.currentOpenToggle.attr( 'aria-expanded', 'false' );
					this.currentOpenToggle.find( '.toggle-text' ).text( 'View Records' );
				}
			}

			// Toggle the clicked panel
			var self = this;
			$content.slideToggle( 300, function() {
				var isVisible = $content.is( ':visible' );

				if ( isVisible ) {
					$icon.removeClass( 'dashicons-arrow-right' ).addClass( 'dashicons-arrow-down' );
					$this.attr( 'aria-expanded', 'true' );

					self.currentOpenPanel = $content;
					self.currentOpenToggle = $this;
				} else {
					$icon.removeClass( 'dashicons-arrow-down' ).addClass( 'dashicons-arrow-right' );
					$this.attr( 'aria-expanded', 'false' );

					self.currentOpenPanel = null;
					self.currentOpenToggle = null;
				}
			} );

			// Update the text
			var $text = $this.find( '.toggle-text' );
			if ( ! isThisPanelOpen ) {
				$text.text( 'Hide Records' );
			} else {
				$text.text( 'View Records' );
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