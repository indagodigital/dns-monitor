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

			// Listen for our custom event to apply the animation correctly after the swap
			document.body.addEventListener('dnsCheckComplete', function (evt) {
				// evt.target is the #dns-snapshots-container that was just swapped in
				const firstRow = evt.target.querySelector('table tbody tr:first-child');
				if (firstRow) {
					firstRow.classList.add('flash-yellow');
					// Remove the class automatically after the animation finishes
					firstRow.addEventListener('animationend', () => {
						firstRow.classList.remove('flash-yellow');
					}, { once: true });
				}
			});
		},

		// Handle HTMX after request to set flags or trigger animations
		onHTMXAfterRequest: function (evt) {
			// Only handle requests to the dns_check endpoint
			if (evt.target.hasAttribute && evt.target.hasAttribute('hx-post')) {
				var postUrl = evt.target.getAttribute('hx-post');
				if (postUrl && postUrl.includes('endpoint=dns_check')) {
					var status = evt.detail.xhr.getResponseHeader('X-DNS-Monitor-Status');
					var message = evt.detail.xhr.getResponseHeader('X-DNS-Monitor-Message');

					if (status) {
						var isSuccess = status !== 'error';
						this.showStatusNotification(isSuccess, decodeURIComponent(message || ''));
					}
				}
			}
		},

		// Show status notification for DNS check
		showStatusNotification: function (isSuccess, message) {
			var $statusContainer = $('.dns-monitor-status');
			var $successElement = $statusContainer.find('.dns-status-success');
			var $errorElement = $statusContainer.find('.dns-status-error');

			// Set the message
			$successElement.text(message || 'DNS Check Complete!');
			$errorElement.text(message || 'DNS Check Failed!');

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

	/**
	 * Finds the first snapshot card and applies a flash animation to it.
	 * This function is safe to call multiple times.
	 */
	function highlightNewestSnapshot() {
		const container = document.getElementById('dns-snapshots-container');
		if (!container) {
			return;
		}

		const firstCard = container.querySelector('.dns-snapshot-card:first-child');
		if (firstCard) {
			// Remove the class first to allow re-triggering the animation
			firstCard.classList.remove('flash-new');

			// Add the class back after a short delay to ensure the browser registers the change
			setTimeout(() => {
				firstCard.classList.add('flash-new');
			}, 10);
		}
	}

	// Initialize when document is ready
	$(document).ready(function () {
		DNSMonitorAdmin.init();
		// Highlight on initial page load
		highlightNewestSnapshot();
	});

	// Listen for HTMX's afterSwap event to highlight after a refresh
	document.body.addEventListener('htmx:afterSwap', function (event) {
		// Check if the swapped content is our snapshots container
		if (event.detail.target.id === 'dns-snapshots-container') {
			highlightNewestSnapshot();
		}
	});

	// Make available globally for debugging
	window.DNSMonitorAdmin = DNSMonitorAdmin;

})(jQuery);