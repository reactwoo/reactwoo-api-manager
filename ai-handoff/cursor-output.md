# Cursor output — PLAN.md remaining store phases

**Date:** 2026-08-19
**Status:** needs-review (code + tests done; production Cloud commerce still off)

## Changed files

- `includes/cloud-commerce/class-rwcc-entitlement-handover.php` — no-gap handover phases and Cloud vs individual downloads
- `includes/cloud-commerce/class-rwcc-licence-reuse.php` — conservative §20.7 reuse/deny/mint/none
- `includes/cloud-commerce/class-rwcc-overlap.php` — state-6 credit quote, no auto-refund
- `includes/cloud-commerce/class-rwcc-bootstrap.php` — require new classes
- `tests/cloud-commerce.php` — handover, reuse, currency mismatch, overlap quote, §17 named checks
- `scripts/validate_cloud_catalogue.php` — read-only Local catalogue check
- `docs/cloud-commerce-bridge.md`
- Decision Cloud `src/services/upgrade.js` + `tests/upgrade.test.js` — `credit_owner: store`
- `react-license/utils/licenseReuse.js` + `tests/licenseReuse.test.js`
- Geo Core `docs/architecture/PLAN.md`, `commerce-and-onboarding.md`
- Figma file `BZFmgpDMSm0OMtnC19lNQ4` page **09 Decision Cloud commerce** (§16 wireframes)

## What was not changed

- Production `REACTWOO_CLOUD_BRIDGE_ENABLED` remains off
- No `git push origin` on API Manager
- Native Woo status / final credit mechanic (§20) still unresolved
- Staging Woo E2E still required

## Tests executed

- `php tests/cloud-commerce.php` — all passed (handover, reuse, currency mismatch, overlap quote, non-taxable fee)
- `php scripts/validate_cloud_catalogue.php` — OK (Local parent 3166 / 3172–3177)
- Decision Cloud `node --test tests/upgrade.test.js tests/plans.test.js tests/billing.test.js tests/woocommerceAdapter.test.js` — 37 passed
- `react-license` Jest `licenseReuse` + `cloudCoverage` — passed

## Remaining

Production catalogue bind. Staging Woo E2E. Finished Figma visual design on [09 Decision Cloud commerce](https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=327-2). Do not enable `REACTWOO_CLOUD_BRIDGE_ENABLED` on production.
