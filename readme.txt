=== ReactWoo API Manager ===
Contributors: reactwoo
Tags: woocommerce, api, subscription, license
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Integrates WooCommerce Subscriptions with the ReactWoo License Server for secure license key generation and management.

== Description ==

ReactWoo API Manager is a bridge plugin that connects WooCommerce Subscriptions with the ReactWoo License Server (license.reactwoo.com). It allows you to:

* Select license types from your license server when creating subscription products
* Automatically generate secure license keys when subscriptions are created
* Sync subscription status with license status (cancel licenses on payment failures)
* Manage licenses through a WordPress admin portal
* Associate existing licenses with packages

== Features ==

* **License Type Selection**: Choose license package types from your license server when creating subscription products
* **Automatic License Generation**: License keys are automatically generated when subscriptions are activated
* **Status Synchronization**: License status automatically updates when subscription status changes (cancelled, expired, payment failures)
* **Domain Management**: Collect and store domain information with each subscription/license
* **Admin Portal**: View and manage all licenses from WordPress admin
* **Package Association**: Associate licenses with packages if not already set

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/reactwoo-api-manager` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to ReactWoo Licenses > Settings and configure your license server URL and API key.
4. Create or edit a subscription product and select a license package type.

== Requirements ==

* WooCommerce (5.0 or higher)
* WooCommerce Subscriptions (active)
* License Server at license.reactwoo.com (or your configured server)

== Frequently Asked Questions ==

= How do I configure the license server? =

Go to ReactWoo Licenses > Settings in WordPress admin and enter your license server URL and optional API key.

= Where do I select the license type for a product? =

When editing a subscription product, you'll see a new "License Package Type" field in the product data metabox. Select the license type you want to associate with that subscription product.

= When are license keys generated? =

License keys are automatically generated when:
* A subscription order is completed
* A subscription status changes to "active"

= What happens when a subscription payment fails? =

The associated license is automatically set to "inactive" status on the license server.

= Can I manually manage licenses? =

Yes, go to ReactWoo Licenses > All Licenses to view all licenses associated with subscriptions. You can also sync licenses from the server.

== Changelog ==

= 2.0.0 =
* Complete rewrite with new architecture
* Added license type selection for subscription products
* Automatic license key generation
* Subscription status synchronization
* Admin portal for license management
* Domain field on checkout
* Package association functionality

= 1.0.0 =
* Initial release
