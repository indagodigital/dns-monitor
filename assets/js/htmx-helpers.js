/**
 * DNS Monitor HTMX Helpers
 * 
 * This file provides additional functionality and utilities for HTMX in DNS Monitor.
 */

(function($) {
    'use strict';

    // Namespace for DNS Monitor HTMX functionality
    window.DNSMonitorHTMX = {
        
        /**
         * Initialize HTMX helpers
         */
        init: function() {
            this.setupEventListeners();
            this.setupCustomIndicators();
            this.setupNotifications();
        },

        /**
         * Set up global event listeners for HTMX
         */
        setupEventListeners: function() {
            // Handle successful responses
            document.body.addEventListener('htmx:afterSwap', function(evt) {
                // Re-initialize any WordPress admin scripts that might be needed
                if (typeof wp !== 'undefined' && wp.hooks) {
                    wp.hooks.doAction('dns_monitor_htmx_after_swap', evt);
                }
                
                // Trigger custom event for other scripts to hook into
                $(document).trigger('dns-monitor:htmx-content-updated', [evt.target]);
            });

            // Handle network errors (responseError is handled by PHP config)
            document.body.addEventListener('htmx:sendError', function(evt) {
                DNSMonitorHTMX.showNotification('Network error. Please check your connection and try again.', 'error');
            });
        },

        /**
         * Set up custom loading indicators
         * Primary handler for button state management across the plugin
         */
        setupCustomIndicators: function() {
            // Handle button loading states using the expected data attributes
            document.body.addEventListener('htmx:beforeRequest', function(evt) {
                const target = evt.target;
                
                if (target.tagName === 'BUTTON' && target.hasAttribute('data-loading-text')) {
                    target.setAttribute('data-original-text', target.textContent);
                    target.textContent = target.getAttribute('data-loading-text');
                    target.disabled = true;
                }
                
                // Add spinner to cards with htmx attributes (non-button elements)
                if (target.closest('.dns-monitor-card') && target.tagName !== 'BUTTON') {
                    DNSMonitorHTMX.showCardLoading(target.closest('.dns-monitor-card'));
                }
            });

            document.body.addEventListener('htmx:afterRequest', function(evt) {
                const target = evt.target;
                
                // Reset button state after request (success or failure)
                if (target.tagName === 'BUTTON' && target.hasAttribute('data-original-text')) {
                    target.textContent = target.getAttribute('data-original-text');
                    target.removeAttribute('data-original-text');
                    target.disabled = false;
                }
                
                // Hide card loading for non-button elements
                if (target.closest('.dns-monitor-card') && target.tagName !== 'BUTTON') {
                    DNSMonitorHTMX.hideCardLoading(target.closest('.dns-monitor-card'));
                }
            });

            // Handle button state reset on errors - primary handler for button state management
            document.body.addEventListener('htmx:responseError', function(evt) {
                const target = evt.target;
                if (target.tagName === 'BUTTON' && target.hasAttribute('data-original-text')) {
                    target.textContent = target.getAttribute('data-original-text');
                    target.removeAttribute('data-original-text');
                    target.disabled = false;
                }
            });
        },

        /**
         * Set up notification system
         */
        setupNotifications: function() {
            // Check if notification container already exists (from admin page)
            let container = document.getElementById('dns-monitor-notifications');
            
            if (!container) {
                // Create notification container if it doesn't exist
                container = document.createElement('div');
                container.id = 'dns-monitor-notifications';
                container.className = 'dns-monitor-notifications';
                document.body.appendChild(container);
            } else {
                // Ensure existing container has proper class
                if (!container.classList.contains('dns-monitor-notifications')) {
                    container.classList.add('dns-monitor-notifications');
                }
            }
        },

        /**
         * Show loading state for cards
         * 
         * @param {HTMLElement} card
         */
        showCardLoading: function(card) {
            card.classList.add('dns-monitor-card-loading');
            
            // Add overlay with spinner
            const overlay = document.createElement('div');
            overlay.className = 'dns-monitor-loading-overlay';
            overlay.innerHTML = '<div class="spinner is-active"></div>';
            card.appendChild(overlay);
        },

        /**
         * Hide loading state for cards
         * 
         * @param {HTMLElement} card
         */
        hideCardLoading: function(card) {
            card.classList.remove('dns-monitor-card-loading');
            
            // Remove overlay
            const overlay = card.querySelector('.dns-monitor-loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        },

        /**
         * Show notification to user
         * 
         * @param {string} message
         * @param {string} type - 'success', 'error', 'warning', 'info'
         * @param {number} duration - Auto-hide duration in milliseconds (0 = no auto-hide)
         */
        showNotification: function(message, type = 'info', duration = 5000) {
            const container = document.getElementById('dns-monitor-notifications');
            if (!container) return;

            const notification = document.createElement('div');
            notification.className = `dns-monitor-notification notice notice-${type} is-dismissible`;
            
            notification.innerHTML = `
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            `;

            // Add dismiss functionality
            const dismissBtn = notification.querySelector('.notice-dismiss');
            dismissBtn.addEventListener('click', function() {
                DNSMonitorHTMX.hideNotification(notification);
            });

            container.appendChild(notification);

            // Auto-hide after duration
            if (duration > 0) {
                setTimeout(function() {
                    DNSMonitorHTMX.hideNotification(notification);
                }, duration);
            }

            return notification;
        },

        /**
         * Show notifications from HTML elements
         * 
         * @param {jQuery|NodeList|Array} notifications - Notification elements to process
         */
        showNotificationsFromHTML: function(notifications) {
            // Handle both jQuery objects and NodeLists/Arrays
            const elements = notifications.jquery ? notifications.get() : Array.from(notifications);
            
            elements.forEach(function(notification) {
                const $notification = $(notification);
                let type = 'info';
                
                // Determine notification type from classes
                if ($notification.hasClass('notice-success')) {
                    type = 'success';
                } else if ($notification.hasClass('notice-error')) {
                    type = 'error';
                } else if ($notification.hasClass('notice-warning')) {
                    type = 'warning';
                }

                // Show the notification using our centralized system
                DNSMonitorHTMX.showNotification($notification.text(), type, 5000);
            });
        },

        /**
         * Hide notification
         * 
         * @param {HTMLElement} notification
         */
        hideNotification: function(notification) {
            notification.style.opacity = '0';
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        },

        /**
         * Refresh content using a specific endpoint
         * 
         * @param {string} endpoint - API endpoint to call
         * @param {string} containerSelector - CSS selector for container to refresh
         * @returns {boolean} - True if refresh was triggered, false if failed
         */
        refreshEndpoint: function(endpoint, containerSelector) {
            const $container = $(containerSelector);
            
            if ($container.length === 0 || typeof htmx === 'undefined') {
                return false;
            }
            
            // Find existing HTMX element inside container
            const htmxElement = $container.find('[hx-get]')[0];
            
            if (htmxElement) {
                // Use existing HTMX setup
                htmx.trigger(htmxElement, 'load');
                return true;
            } else {
                // Fallback: create direct HTMX request
                const url = new URL(dnsMonitorHtmx.ajaxUrl);
                url.searchParams.set('action', 'dns_monitor_htmx');
                url.searchParams.set('endpoint', endpoint);
                url.searchParams.set('_wpnonce', dnsMonitorHtmx.nonce);
                
                htmx.ajax('GET', url.toString(), {
                    target: containerSelector,
                    swap: 'innerHTML'
                });
                return true;
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        DNSMonitorHTMX.init();
    });

})(jQuery); 