# Cloud isolation and rollback

## Production facts

| Item | Value |
|------|--------|
| Plugin | ReactWoo API Manager |
| Version constant | `REACTWOO_API_MANAGER_VERSION` **2.1.10** |
| Cloud module | `includes/cloud-commerce/` (in-plugin, flag-gated) |
| Deployment remote | `origin` = `ssh://reactwoo@reactwoo.com/home/reactwoo/public_html/wp-content/plugins/reactwoo-api-manager` |
| Backup remote | `github` = `https://github.com/reactwoo/reactwoo-api-manager.git` |

Do **not** `git push origin` until staging purchase + licence activation pass.

## Isolation (default)

1. API Manager always loads licensing, downloads and My Account.
2. Cloud PHP loads only when `define( 'REACTWOO_CLOUD_BRIDGE_ENABLED', true );` is set.
3. Empty product mappings are a second safeguard, not the primary gate.

## Rollback

1. Remove `REACTWOO_CLOUD_BRIDGE_ENABLED` from `wp-config.php` (or set it false).
2. Existing licences, downloads, subscriptions and My Account continue.
3. Before origin push, rollback is: do not deploy.

## Staging checklist (required before origin)

- [ ] Staging is a copy of ReactWoo.com, not Local
- [ ] Flag unset
- [ ] Standalone paid purchase creates `_reactwoo_license_key`
- [ ] ZIP download from My Account works
- [ ] Activate / deactivate domain on the licence server
- [ ] Renewal, failed payment, cancel, refund behave as today
- [ ] Free product / order with no Cloud meta has no `rw_cloud_*` keys
