# ReactWoo Commerce Bridge (Sprint 2)

Isolated module inside the **ReactWoo.com store** plugin (`reactwoo-api-manager`).  
It is not part of Decision Cloud. WooCommerce store logic must not live in the Cloud repository.

Figma acceptance (Cloud screens not implemented this sprint):  
[06 — Activation, Upgrade & Site Connection](https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=310-1796)

## Product → plan mapping

Internal plans: `starter` | `growth` | `scale`.

1. Product or variation meta `_rw_cloud_plan`
2. Settings fallbacks: Starter / Growth / Scale product IDs (`rwcc_settings`)
3. Filter `rwcc_plan_for_product`

Variation IDs win over parent product IDs.

## Order / subscription meta

| Key | Purpose |
|-----|---------|
| `rw_cloud_org` | Existing Cloud organisation (from signed handoff) |
| `rw_cloud_plan` | Internal plan |
| `rw_cloud_provisioning_id` | Stable key so retries never mint a second org |
| `rw_cloud_identity_user` | WordPress / ReactWoo customer id |
| `rw_cloud_identity_email` | Billing email |
| `rw_cloud_claim_hash` | HMAC of the single-use activation token |
| `rw_cloud_claim_expires` | Unix expiry |
| `rw_cloud_claim_used` | Consumed flag |
| `_reactwoo_cloud_product_id` | Catalogue id Cloud already reads |

## Activation claims

- Default TTL **1800 seconds** (30 minutes — Figma “Check your email”)
- Stored hashed (`HMAC-SHA256`); plaintext returned once in the activation URL
- Single-use; retry revokes the unused token and issues a new one with the **same** `rw_cloud_provisioning_id`
- If the workspace is already provisioned, retry does not create another organisation
- URL: `{cloud_origin}/activate?claim={token}`
- Cloud verifies the hash with `REACTWOO_HANDOFF_SECRET` (must match `RWCC_HANDOFF_SECRET`)
- Webhook `meta_data` includes `rw_cloud_claim_hash` and `rw_cloud_claim_expires` so Cloud can provision before the customer opens the link

## Signed webhooks

`POST {cloud_origin}/api/v1/billing/webhooks/woocommerce`

| Header | Value |
|--------|--------|
| `X-WC-Webhook-Signature` | `base64(HMAC-SHA256(raw body, secret))` |
| `X-WC-Webhook-Topic` | `subscription.created` / `updated` / `order.refunded` |
| `X-WC-Webhook-Delivery-ID` | UUID |

Payload includes WooCommerce subscription fields Cloud already parses, plus:

```json
"rwcc": {
  "event": "activation|renewal|plan_switch|payment_failure|cancellation|expiry|refund",
  "delivery_id": "uuid",
  "timestamp": 1710000000,
  "replay_window_sec": 300
}
```

Duplicate delivery IDs are not re-sent. Timestamps older than the replay window are dropped.

## REST (namespace `reactwoo-cloud/v1`)

| Method | Path | Auth |
|--------|------|------|
| GET | `/handoff/checkout` | Signed `rw_*` query (Cloud → store) |
| GET | `/handoff/upgrade` | Signed `rw_*` query |
| GET | `/handoff/subscription` | Signed `rw_*` query |
| GET | `/handoff/invoices` | Signed `rw_*` query |
| GET | `/handoff/payment-method` | Signed `rw_*` query |
| GET | `/reconcile?subscription_id=` or `organisation_id=` | `Authorization: Bearer {RWCC_RECONCILE_TOKEN}` |
| POST | `/activation/retry` | Logged-in customer + subscription ownership |

Return URLs are allowlisted. Arbitrary redirects are rejected even if the HMAC is valid.

Query-string handoffs on `/checkout/` and `/my-account/` (Sprint 1 Cloud URLs) are verified the same way.

## WordPress hooks

- `woocommerce_checkout_update_order_meta` / Store API equivalent
- `woocommerce_order_status_completed` / `processing`
- `woocommerce_subscription_status_active`
- `woocommerce_subscription_renewal_payment_complete`
- `woocommerce_subscription_payment_failed` / `status_on-hold`
- `woocommerce_subscription_status_cancelled` / `expired` / `updated`
- `woocommerce_subscriptions_switch_completed`
- `woocommerce_order_refunded`
- `rwcc_claim_issued`, `rwcc_webhook_delivered`, `rwcc_webhook_failed`
- `rwcc_plan_for_product`, `rwcc_webhook_payload`

## Secrets (server-side only)

Prefer `wp-config.php` constants over the admin form:

| Constant / option | Matches Cloud |
|-------------------|---------------|
| `RWCC_CLOUD_ORIGIN` | Activation + default webhook URL |
| `RWCC_WEBHOOK_URL` | `POST /api/v1/billing/webhooks/woocommerce` |
| `RWCC_WEBHOOK_SECRET` | `WOOCOMMERCE_WEBHOOK_SECRET` |
| `RWCC_HANDOFF_SECRET` | `REACTWOO_HANDOFF_SECRET` |
| `RWCC_RECONCILE_TOKEN` | Cloud Sprint 6 reconcile client |

WooCommerce REST consumer keys are **not** used and must not be localised to JavaScript.

## Tests

```bash
php tests/run.php
php tests/e2e-purchase-to-cloud.php
```

E2E applies the signed store payload to an in-memory Decision Cloud when `REACTWOO_DECISION_CLOUD_DIR` is set (defaults to the local wooalisync plugin path).
