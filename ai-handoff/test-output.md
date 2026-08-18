# Test output — API Manager identity handoff

**Date:** 2026-08-18

## Commands

```bash
php tests/run.php
php tests/cloud-commerce.php
```

Both completed with all assertions passing.

`php tests/licensing-regression.php` is included by `tests/run.php` and is not a standalone runner (`rw_assert` undefined if invoked directly).

## Coverage confirmed

- Licence masking, status mapping, My Account template, ZIP downloads
- Cloud module isolation when the feature flag is off
- Licence handler still owns WooCommerce subscription/order hooks
- Cloud companion consumes observe-only licence hooks and does not re-hook activation
- Identity UUID, fragment activation URL, login claim registration
- Open Decision Cloud on the dashboard without requiring a Cloud subscription
- Open Decision Cloud does not replace licence UI
- Existing licence includes remain in place
