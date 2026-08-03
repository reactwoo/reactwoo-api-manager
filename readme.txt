=== ReactWoo API Manager ===
Contributors: reactwoo
Tags: woocommerce, subscriptions, license, reactwoo
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrates WooCommerce Subscriptions with the ReactWoo License Server and provides a secure My Account Products & licences experience.

== Description ==

ReactWoo API Manager provisions and syncs subscription licences with the ReactWoo license server, and exposes a customer-facing Products & licences My Account endpoint with masked keys and authenticated copy.

== Installation ==

1. Upload the `reactwoo-api-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Set the shared API Key under WooCommerce → ReactWoo License Manager → Settings.
4. Configure the license server URL on the same settings screen.

== Changelog ==

= 2.1.6 =
* My Account plugin ZIP downloads for entitled subscriptions via ReactWoo API store-download.
* Product meta `_reactwoo_plugin_slug` and Settings fields for updates API URL + store download token.

= 2.1.5 =
* Register `license` in WooCommerce query vars so `is_wc_endpoint_url()` works.

= 2.1.4 =
* Disable automatic My Account root redirect (loop stop).
* Always-on account redirect logging to PHP error_log and WooCommerce logs.

= 2.1.3 =
* Stop frontend rewrite flushes and unsafe account redirects that could hang My Account.
* Keep Dashboard until licence rewrites are ready.

= 2.1.2 =
* Use the existing Settings API Key for provisioning auth (no separate wp-config master key required).

= 2.1.1 =
* Fix My Account redirect loop when the license rewrite endpoint is not flushed.
* Flush rewrite rules once on version upgrade.

= 2.1.0 =
* Secure Products & licences My Account experience (masked keys, REST copy).
* Master key read from `REACTWOO_LICENSE_MASTER_KEY` only.
* Customer account service + packaging zip script.

= 2.0.0 =
* Initial packaged release.
