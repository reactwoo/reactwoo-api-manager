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

        // Handle package selection and update price field (for product edit pages)
        $('#_reactwoo_license_package_id').on('change', function() {
            var packageId = $(this).val();
            var $priceField = $('#_regular_price');
            var $subscriptionPriceField = $('#_subscription_price');
            
            if (packageId && packageId !== '') {
                // Fetch package details via AJAX
                if (typeof reactwooApiManager !== 'undefined') {
                    $.ajax({
                        url: reactwooApiManager.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'reactwoo_get_package_price',
                            package_id: packageId,
                            nonce: reactwooApiManager.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.price) {
                                var price = parseFloat(response.data.price);
                                
                                if (price > 0) {
                                    // Update regular price field
                                    if ($priceField.length) {
                                        $priceField.val(price.toFixed(2)).trigger('change');
                                    }
                                    
                                    // Update subscription price field if it exists
                                    if ($subscriptionPriceField.length) {
                                        $subscriptionPriceField.val(price.toFixed(2)).trigger('change');
                                    }
                                    
                                    // Trigger WooCommerce price update
                                    if (typeof $().trigger !== 'undefined') {
                                        $priceField.trigger('woocommerce_update_price');
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
    });
})(jQuery);

