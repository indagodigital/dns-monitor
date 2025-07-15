/**
 * DNS Monitor Admin Scripts
 */
(function ($) {
	'use strict';

	// Main admin object
	var DNSMonitorAdmin = {
		// Track the currently open record panel
		currentOpenPanel: null,
		currentOpenToggle: null,

		// Track if a DNS check was just performed
		dnsCheckPerformed: false,

		// Initialize everything
		init: function () {
			this.bindEvents();
		},

		// Bind additional events
		bindEvents: function () {
			// Use event delegation for all dynamic content
			$(document)
				.on('click', '.dns-toggle-records', this.handleToggleRecords.bind(this))
				.on('keydown', '.dns-toggle-records', this.handleToggleRecordsKeydown.bind(this))
				.on('change', '.dns-changes-dropdown', this.handleChangesDropdown.bind(this))
				.on('click', '.dns-refresh-snapshots', this.refreshSnapshots.bind(this));

			// Add specific listener for HTMX requests
			if (typeof htmx !== 'undefined') {
				document.body.addEventListener('htmx:afterRequest', this.onHTMXAfterRequest.bind(this));
			}
		},

		// Handle HTMX after request to set flags or trigger animations
		onHTMXAfterRequest: function (evt) {
			// Only handle requests to the dns_check endpoint
			if (evt.target.hasAttribute && evt.target.hasAttribute('hx-post')) {
				var postUrl = evt.target.getAttribute('hx-post');
				if (postUrl && postUrl.includes('endpoint=dns_check')) {
					// Determine success/failure based on response content
					var isSuccess = (evt.detail.xhr.responseText || '').indexOf('notice-error') === -1;

					// Show the appropriate status notification in the header
					this.showStatusNotification(isSuccess);

					// If the check was processed successfully, flash the card
					if (isSuccess) {
						this.flashFirstSnapshotCard();
					}
				}
			}
		},

		// Show status notification for DNS check
		showStatusNotification: function (isSuccess) {
			var $statusContainer = $('.dns-monitor-status');
			var $successElement = $statusContainer.find('.dns-status-success');
			var $errorElement = $statusContainer.find('.dns-status-error');

			$successElement.removeClass('dns-status-show');
			$errorElement.removeClass('dns-status-show');

			// Show appropriate notification
			var $targetElement = isSuccess ? $successElement : $errorElement;

			// Force reflow to ensure the class removal takes effect
			$targetElement[0].offsetHeight;

			$targetElement.addClass('dns-status-show');

			setTimeout(function () {
				$targetElement.removeClass('dns-status-show');
			}, 5000);
		},

		// Flash the first snapshot card to draw attention
		flashFirstSnapshotCard: function () {
			var $firstCard = $('.dns-snapshot-card').first();

			if ($firstCard.length > 0) {
				$firstCard.removeClass('dns-card-flash');

				// Force reflow to ensure the class removal takes effect
				$firstCard[0].offsetHeight;
				$firstCard.addClass('dns-card-flash');

				setTimeout(function () {
					$firstCard.removeClass('dns-card-flash');
				}, 2000);
			}
		},

		// Handle keyboard events for toggle records
		handleToggleRecordsKeydown: function (e) {
			// Only handle Enter (13) and Space (32) keys
			if (e.which === 13 || e.which === 32) {
				e.preventDefault();
				this.handleToggleRecords(e);
			}
		},

		// Toggle records handler
		handleToggleRecords: function (e) {
			e.preventDefault();
			var $toggle = $(e.currentTarget);
			var $card = $toggle.closest('.dns-snapshot-card');
			var isOpening = !$card.hasClass('dns-card-expanded');

			// Close any other open cards
			$('.dns-snapshot-card.dns-card-expanded').not($card).each(function () {
				var $otherCard = $(this);
				var $otherToggle = $otherCard.find('.dns-toggle-records');
				$otherToggle.attr('aria-expanded', 'false');
				$otherToggle.find('.dashicons').removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
				$otherCard.removeClass('dns-card-expanded');
			});

			// Toggle the clicked card's state
			$toggle.attr('aria-expanded', isOpening);
			$toggle.find('.dashicons').toggleClass('dashicons-arrow-right dashicons-arrow-down');
			$card.toggleClass('dns-card-expanded');
		},

		// Handle changes dropdown selection
		handleChangesDropdown: function (e) {
			var $dropdown = $(e.currentTarget);
			var selectedValue = $dropdown.val();
			var $container = $dropdown.closest('.dns-changes-dropdown-container');

			// Hide all change details first
			$container.find('.dns-change-details').removeClass('dns-change-details-visible').hide();

			// Only show selected change details if a value is selected
			if (selectedValue && selectedValue !== '') {
				$container.find('.dns-change-details[data-change-type="' + selectedValue + '"]').addClass('dns-change-details-visible').show();
			}
		},

		// Refresh snapshots table
		refreshSnapshots: function () {
			if (typeof DNSMonitorHTMX !== 'undefined' && DNSMonitorHTMX.refreshEndpoint) {
				return DNSMonitorHTMX.refreshEndpoint('refresh_snapshots', '#dns-snapshots-container');
			}
			return false;
		}
	};

	// Initialize when document is ready
	$(document).ready(function () {
		DNSMonitorAdmin.init();
	});

	// Make available globally for debugging
	window.DNSMonitorAdmin = DNSMonitorAdmin;

})(jQuery);