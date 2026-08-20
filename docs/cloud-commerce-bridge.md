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

## Catalogue (single variable subscription)

Inspected production product (2026-08-19): parent **3166**, variations **3172–3177** (Starter/Growth/Scale × monthly/annual). Bind comma-separated IDs in Decision Cloud commerce settings. Set `_rw_cloud_plan`, `_rw_cloud_billing_cycle`, and `_rw_cloud_product_type=decision_cloud` on variations. Do not attach satellite plugin ZIPs to variations; My Account uses `ReactWoo_Plugin_Download_Service::build_synthetic_files()` so each entitled plugin appears as `Name — Included with Decision Cloud`.

Local ReactWoo (after the 2026-08-19 production restore) can be bound with `php scripts/bind_local_cloud_catalogue.php`. That writes Local prices/meta/settings only. Validate without writing: `php scripts/validate_cloud_catalogue.php`.

## Upgrade credit at checkout

When `REACTWOO_CLOUD_BRIDGE_ENABLED` is on, `RWCC_Checkout_Credit` shows the upgrade summary before confirm: included plugins, renewals that will stop, separately billed products, and remaining-term credit. Covered individuals with unexplained missing period/amount data **block** full-price Cloud checkout. Eligible credit is applied as a non-taxable negative cart fee (`Upgrade credit`), capped at the Cloud cart line total excluding tax (PLAN.md §20). Audit is stored on the order as `_rwcc_upgrade_credit` / `_rwcc_upgrade_credit_audit`.

## Downgrade selection

Cloud subscription details in My Account include **Cancel or downgrade Decision Cloud**. The customer must confirm. They can keep Geo Core Pro, Geo Commerce, Geo Optimise (any combination), or **no paid plugins**. The store records `_rwcc_downgrade` with start = Cloud paid-through date and `charge_now=false`. Reactivating Cloud cancels that schedule. Signed handoffs `rw_action=cancel` and `rw_action=downgrade` land on this form.

## Overlap correction

**WooCommerce → Decision Cloud → Cloud overlap** looks up a Cloud subscription ID. If covered individuals are still renewing (state 6), an operator can confirm and stop those renewals. History is kept. Remaining-term amounts are quoted for finance (`refund: false`). Refunds are not automatic.

## Scheduled individual subscriptions

Confirmed downgrade selections are materialized as **pending** WooCommerce Subscriptions with `start_date` = Cloud paid-through (`RWCC_Scheduled_Subscription`). ISO-8601 values from `gmdate( 'c' )` are converted to MySQL UTC datetimes before `wcs_create_subscription`. Nothing is charged at confirm time. Cloud reactivation cancels those pending individuals.

Live Local Woo E2E (no payment): `php scripts/live_local_woo_e2e.php` (Local host only). Fixture E2E: `php tests/e2e-purchase-to-cloud.php`.

## Entitlement handover

`RWCC_Entitlement_Handover` keeps Cloud downloads on the Cloud subscription while Cloud is live (including a scheduled downgrade). `ReactWoo_Plugin_Download_Service` hides ZIPs on superseded individuals and still allows Cloud `on-hold` (payment grace). After Cloud ends, selected individuals or free Geo Core apply. Activation failure keeps standalone access. Local configuration is never wiped.

## Licence reuse

`RWCC_Licence_Reuse` does not treat a Cloud key as an individual plugin key. The store passes `plan_code` through a generic provision filter. The license server reuses a historical individual key after Cloud ends, and refuses `as_individual` rewrite of a live Cloud key.

## Production catalogue (operator)

`scripts/bind_production_cloud_catalogue.sql` sets variation meta and PLAN marketing prices on parent **3166** / **3172–3177**. It does **not** enable `REACTWOO_CLOUD_BRIDGE_ENABLED`. Run only after confirming those post IDs on production. Local bind remains `php scripts/bind_local_cloud_catalogue.php`.
