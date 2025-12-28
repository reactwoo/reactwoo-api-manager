/**
 * Admin JavaScript
 *
 * @package ReactWoo_API_Manager
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Copy license key to clipboard
        $('.reactwoo-license-manager').on('click', '.copy-license-key', function(e) {
            e.preventDefault();
            var licenseKey = $(this).data('license-key');
            var tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(licenseKey).select();
            document.execCommand('copy');
            tempInput.remove();
            
            // Show feedback
            var button = $(this);
            var originalText = button.text();
            button.text('Copied!');
            setTimeout(function() {
                button.text(originalText);
            }, 2000);
        });

        // Confirm sync action
        $('#sync-licenses-form').on('submit', function(e) {
            if (!confirm('Are you sure you want to sync licenses from the server? This may take a moment.')) {
                e.preventDefault();
                return false;
            }
        });

        // Confirm match licenses action
        $('#match-licenses-form').on('submit', function(e) {
            if (!confirm('This will match all licenses from the server with your WooCommerce subscriptions. This may take a moment. Continue?')) {
                e.preventDefault();
                return false;
            }
        });
    });
})(jQuery);

