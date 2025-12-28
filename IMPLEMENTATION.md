# ReactWoo API Manager - Implementation Summary

## Overview

The ReactWoo API Manager plugin has been completely rewritten to integrate WooCommerce Subscriptions with the ReactWoo License Server (license.reactwoo.com). The plugin enables automatic license key generation, subscription-status synchronization, and license management through a WordPress admin portal.

## Key Features Implemented

### 1. License Server API Integration
- **File**: `includes/class-license-server-api.php`
- Connects to the license server API
- Methods:
  - `get_packages()` - Fetches available license packages/types
  - `create_license()` - Creates new license keys
  - `update_license_status()` - Updates license status (active/inactive)
  - `get_licenses_by_domain()` - Retrieves licenses for a domain

### 2. Product Meta Fields
- **File**: `includes/class-product-meta.php`
- Adds license package type selection to subscription products
- New "License Settings" tab in product edit page
- Displays package information when selected
- Only shows for subscription product types

### 3. Subscription Lifecycle Management
- **File**: `includes/class-subscription-handler.php`
- **License Generation**: Automatically creates license keys when:
  - Subscription order is completed
  - Subscription status changes to "active"
- **Status Synchronization**:
  - Sets license to "inactive" when subscription is cancelled, expired, or on-hold
  - Reactivates license when subscription returns to "active"
  - Handles payment failures by deactivating licenses
- **Domain Field**:
  - Adds domain input field to checkout
  - Stores domain with order and subscription
  - Validates domain format

### 4. WordPress Admin Portal
- **File**: `admin/class-admin.php`
- **Menu**: "ReactWoo Licenses" in WordPress admin
- **Pages**:
  - **All Licenses**: View all subscriptions with associated licenses
  - **Settings**: Configure license server URL and API key
- **Features**:
  - License key display in subscription list table
  - License synchronization from server
  - Package association for existing licenses
  - Connection testing

### 5. Settings & Configuration
- License server URL configuration (default: https://license.reactwoo.com)
- Optional API key for authenticated requests
- Settings page with connection testing

## File Structure

```
reactwoo-api-manager/
├── woocommerce-api-subscription-bridge.php (Main plugin file)
├── readme.txt
├── includes/
│   ├── class-reactwoo-api-manager.php (Main plugin class)
│   ├── class-license-server-api.php (API client)
│   ├── class-product-meta.php (Product meta fields)
│   └── class-subscription-handler.php (Subscription lifecycle)
└── admin/
    ├── class-admin.php (Admin interface)
    ├── views/
    │   ├── license-manager.php (License list page)
    │   └── settings.php (Settings page)
    └── assets/
        ├── admin.css (Admin styles)
        └── admin.js (Admin scripts)
```

## Configuration Steps

1. **Activate the Plugin**
   - Ensure WooCommerce and WooCommerce Subscriptions are active
   - Activate the ReactWoo API Manager plugin

2. **Configure License Server**
   - Go to ReactWoo Licenses > Settings
   - Enter license server URL (default: https://license.reactwoo.com)
   - Optionally add API key for authenticated requests
   - Test connection to verify setup

3. **Configure Subscription Products**
   - Edit a subscription product
   - Go to "License Settings" tab (or see field in General tab)
   - Select a license package type from the dropdown
   - Save the product

4. **Customer Checkout**
   - Customers will see a "License Domain" field during checkout
   - Domain is required and validated
   - License key is automatically generated after order completion

## API Endpoints Used

The plugin communicates with the license server using these endpoints:

- `GET /api/packages` - Fetch available packages
- `POST /api/licenses` - Create new license
- `PUT /admin/licenses/:id` - Update license status
- `GET /api/licenses/:domain` - Get licenses by domain

## License Key Generation Flow

1. Customer completes checkout with subscription product
2. Order status changes to "completed"
3. Plugin checks if subscription product has license package type selected
4. Plugin retrieves domain from order meta
5. Plugin calls license server API to create license
6. License key and ID stored in subscription meta
7. License key also stored in order meta for reference

## Status Synchronization

- **Subscription → License Status Mapping**:
  - `active` → `active`
  - `cancelled` → `inactive`
  - `expired` → `inactive`
  - `on-hold` → `inactive`
  - Payment failure → `inactive`

## Admin Portal Features

### License Manager Page
- Lists all subscriptions with licenses
- Shows license key, domain, subscription status
- Links to subscription details
- Sync functionality to update licenses from server

### Settings Page
- License server URL configuration
- API key configuration (optional)
- Connection testing

## Data Storage

### Subscription Meta Keys
- `_reactwoo_license_key` - The generated license key
- `_reactwoo_license_id` - License ID from server
- `_reactwoo_license_domain` - Domain associated with license
- `_reactwoo_license_package_id` - Package ID from product

### Order Meta Keys
- `_reactwoo_domain` - Domain from checkout
- `_reactwoo_license_key` - License key (for reference)
- `_reactwoo_license_id` - License ID (for reference)

### Product Meta Keys
- `_reactwoo_license_package_id` - Selected package ID from license server

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- WooCommerce Subscriptions (active)
- PHP 7.4+
- License server accessible at configured URL

## Future Enhancements

Potential improvements that could be added:
- Bulk license operations
- License expiration date management
- Email notifications for license generation
- License usage tracking
- Integration with WooCommerce API Manager plugin
- Support for multiple licenses per subscription
- License transfer functionality

## Notes

- The plugin does not modify WooCommerce Subscriptions core files
- All license data is stored in WordPress meta tables
- License server API responses are logged for debugging
- Domain validation uses basic regex pattern matching
- License status updates are sent to server when subscription status changes

