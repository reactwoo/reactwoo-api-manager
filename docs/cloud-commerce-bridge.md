# ReactWoo Commerce Bridge (in-plugin module)

The store sending side lives in `includes/cloud-commerce/` inside API Manager.
Decision Cloud (`reactwoo-decision-cloud`) is the SaaS receiver only.

Enable on a prepared environment:

```php
define( 'REACTWOO_CLOUD_BRIDGE_ENABLED', true );
```

Without that constant the module files are never required. See
`docs/cloud-bridge-contract.md` and `tests/isolation.php`.

Decision Cloud sign-in is a browser bounce, not a password REST call:

1. Cloud links to `https://reactwoo.com/my-account/?rwcc_open_cloud=1`.
2. If the customer is logged out, WooCommerce shows the store login form. A short-lived intent cookie keeps the query after login (Woo login posts strip query args).
3. If already logged in, the store registers a hashed login claim and redirects to Cloud with `#claim=`.

Cloud never receives the ReactWoo.com password. The My Account dashboard button still uses a WordPress nonce; Cloud cannot mint that nonce, so the query flag without `_wpnonce` is the SSO start.
