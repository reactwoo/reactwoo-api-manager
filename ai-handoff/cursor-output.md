# Cursor output — Store identity / activation handoff

**Status:** needs-review  
**Date:** 2026-08-17  
**Production authentication complete:** no (Cloud still needs deploy, Aplenty backfill, token rotation)

## Confirmed root cause (Cloud; this repo supplies identity)

Decision Cloud treated `PORTAL_API_TOKEN` as a global admin secret and created “My organisation” from empty browser storage. The Store already owned WooCommerce identity and the Commerce Bridge. This pass adds a stable issuer/subject and signed claim registration without replacing licences.

## Repositories inspected

- `reactwoo-api-manager` — confirmed owner of accounts, licences, Commerce Bridge
- `reactwoo-decision-cloud` — Cloud sessions and tenant auth
- `react-license` / `reactwoo-api` — not the account-handoff owner

## Authentication contract (Store)

- Issuer: `https://reactwoo.com` (`RWCC_Identity::ISSUER`)
- Subject: UUID in user meta `_rw_cloud_identity_subject`, generated once, never email
- WooCommerce customer ID remains commerce metadata
- Returning user: signed-in WordPress session → `RWCC_Identity_Client::issue_login()` → fragment URL on Decision Cloud
- Webhooks still deliver entitlements only; they do not create a browser session

## Activation-claim contract (Store)

On qualifying paid Cloud order/subscription:

1. Resolve identity subject.
2. Mint high-entropy claim; store **hash** locally (existing claims service).
3. `POST {cloud}/api/v1/identity/claims` with HMAC signature (`purpose`, `issuer`, `subject`, `exp`, `nonce`, `hash`). Raw token is not in the JSON body.
4. Email / My Account link uses `#claim=` so the raw token is not in query logs.

Returning login uses purpose `login` and the same registration path.

## Session implementation

Sessions are issued by Decision Cloud after exchange. This plugin does not store Cloud session tokens.

## Organisation authorisation coverage

Not owned here. Cloud tenant middleware authorises memberships.

## Aplenty backfill

Owned by Decision Cloud `scripts/backfill_owner.js`. Store must supply the verified identity UUID from the ReactWoo.com user record. Do not infer ownership from email.

## Files changed

- Added: `includes/cloud-commerce/class-rwcc-identity.php`, `class-rwcc-identity-client.php`
- Modified: bootstrap, account, claims, lifecycle, order-meta, payload, settings, urls, `tests/cloud-commerce.php`
- Isolation/docs/readme/admin view may already be in the working tree from prior Cloud companion work

## Tests added / extended

`tests/cloud-commerce.php`:

- Identity subject is not email or numeric user id; generated once and reused
- Activation URL uses fragment, not query
- Webhook payload includes identity subject
- Returning login registers hash-only signed claim
- My Account offers Open Decision Cloud without replacing licence UI
- Existing API Manager licence includes remain in place

## Commands run

```bash
php tests/run.php
php tests/cloud-commerce.php
```

All passed. Licence regression contracts ran inside `tests/run.php`.

## Production configuration

- `REACTWOO_CLOUD_BRIDGE_ENABLED` on ReactWoo.com
- Cloud origin + `RWCC_HANDOFF_SECRET` matching Decision Cloud `REACTWOO_HANDOFF_SECRET`
- Do not expose WooCommerce REST credentials to the browser

## Token rotation

The exposed **Decision Cloud** portal token must be rotated after Cloud deploy. This plugin does not store that token.

## Licensing / downloads / My Account

Unchanged owners:

- `class-subscription-handler.php`
- `class-license-server-api.php`
- `class-plugin-download-service.php`
- `class-customer-account-service.php`
- `templates/myaccount/license.php`

Cloud identity is additive. Isolation tests confirm Cloud classes are not loaded when the Cloud flag is off.

## Remaining work

1. Deploy API Manager (authorised separately).
2. Confirm production user meta `_rw_cloud_identity_subject` for the Aplenty owner.
3. Verify “Open Decision Cloud” against deployed Cloud.
4. Team invitations: not implemented. Next task. Do not add a parallel membership system here.
