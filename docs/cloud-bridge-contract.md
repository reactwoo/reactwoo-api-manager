# Cloud Commerce Bridge contract (API Manager)

The ReactWoo.com sending side is an isolated module under
`includes/cloud-commerce/`. `reactwoo-decision-cloud` remains the SaaS
receiver. There is no standalone WordPress companion plugin.

## Feature gate

Primary gate: `REACTWOO_CLOUD_BRIDGE_ENABLED` in `wp-config.php`.

- Unset or `false`: no Cloud PHP is required, no `RWCC_*` classes, no Cloud
  hooks, REST routes, scheduled actions or migrations.
- `true`: `RWCC_Bootstrap::init()` loads the module after licence services.

API Manager does **not** define the constant. Empty Cloud product mappings are
a second safeguard (`not_cloud_product`) after the flag is on.

`reactwoo_api_manager_supports( 'companion-v1' )` is a version marker only.

## Observe-only actions

These fire **after** the existing licence path. The Cloud module may listen
when enabled. Listeners must not generate, replace, or revoke licence keys.

| Action | When |
|--------|------|
| `reactwoo_api_manager_loaded` | Licence, download and My Account services finished loading |
| `reactwoo_license_generated` | `$subscription, $order, $license` after a key is stored |
| `reactwoo_license_status_synced` | `$subscription, $old_status, $new_status` after licence-server sync |
| `reactwoo_license_renewed` | `$subscription, $renewal_order` after renewal meta is saved |
| `reactwoo_license_payment_failed` | `$subscription` after the licence is marked inactive |

## What always-loaded code must never do

- Require `includes/cloud-commerce/*` except inside the flag gate
- Register `reactwoo-cloud/v1` from licence classes
- Stamp `rw_cloud_*` from `ReactWoo_Subscription_Handler`
- Replace licence generation, downloads or My Account
