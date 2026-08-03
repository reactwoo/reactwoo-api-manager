=== ReactWoo API Manager ===
Contributors: reactwoo
Tags: woocommerce, subscriptions, license, reactwoo
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Integrates WooCommerce Subscriptions with the ReactWoo License Server and provides a secure My Account Products & licences experience.

== Description ==

ReactWoo API Manager provisions and syncs subscription licences with the ReactWoo license server, and exposes a customer-facing Products & licences My Account endpoint with masked keys and authenticated copy.

== Installation ==

1. Upload the `reactwoo-api-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Define `REACTWOO_LICENSE_MASTER_KEY` in `wp-config.php` (server-side secret only).
4. Configure the license server URL under WooCommerce → ReactWoo License Manager.

== Changelog ==

= 2.1.0 =
* Secure Products & licences My Account experience (masked keys, REST copy).
* Master key read from `REACTWOO_LICENSE_MASTER_KEY` only.
* Customer account service + packaging zip script.

= 2.0.0 =
* Initial packaged release.
