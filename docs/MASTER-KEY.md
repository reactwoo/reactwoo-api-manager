# Master key configuration

Provisioning uses `X-RW-Master-Key` against the ReactWoo licence server.

## Required

In `wp-config.php` (never in the database or Git):

```php
define( 'REACTWOO_LICENSE_MASTER_KEY', 'your-rotated-secret' );
```

If the constant is missing, provision and subscription sync fail safely and an admin notice is shown.

## Rotation

The previous key was committed in Git history and must be treated as compromised:

1. Rotate `RW_MASTER_KEY` on `license.reactwoo.com`.
2. Set the new value as `REACTWOO_LICENSE_MASTER_KEY` on the storefront.
3. Deploy API Manager 2.1.0+.
4. Optionally rewrite Git history to remove the old secret after rotation.
