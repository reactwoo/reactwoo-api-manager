# ReactWoo Commerce Bridge (in-plugin module)

The store sending side lives in `includes/cloud-commerce/` inside API Manager.
Decision Cloud (`reactwoo-decision-cloud`) is the SaaS receiver only.

Enable on a prepared environment:

```php
define( 'REACTWOO_CLOUD_BRIDGE_ENABLED', true );
```

Without that constant the module files are never required. See
`docs/cloud-bridge-contract.md` and `tests/isolation.php`.
