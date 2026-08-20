# Cursor output — PLAN.md remaining store phases

**Date:** 2026-08-20
**Status:** done (code + tests; production Cloud commerce still off)

## Changed files

- `includes/class-plugin-download-service.php` — handover + superseded ZIP hiding; Cloud on-hold grace
- `includes/cloud-commerce/class-rwcc-licence-reuse.php` — provision_plan_code filter
- `includes/cloud-commerce/class-rwcc-bootstrap.php` — hook the filter
- `includes/class-subscription-handler.php` — generic `reactwoo_license_provision_plan_code`
- `includes/class-license-server-api.php` — pass `plan_code`
- `scripts/bind_production_cloud_catalogue.sql` — operator meta/prices only
- `tests/cloud-commerce.php`, `tests/e2e-purchase-to-cloud.php`, `tests/licensing-regression.php`, `tests/run.php`
- react-license: package SQL, provision reuse, token/activate rewrite guard, `plan_code`
- Geo Core `docs/architecture/PLAN.md`, `commerce-and-onboarding.md`

## What was not changed

- Production `REACTWOO_CLOUD_BRIDGE_ENABLED` remains off
- No `git push origin` on API Manager
- Native Woo status / final credit mechanic / auto-refunds / FX (§20) still unresolved
- Staging Woo E2E still required
- License DB package row is SQL for operators — not executed here

## Tests executed

- `php tests/run.php` — all passed (including superseded ZIP hiding, Cloud on-hold downloads, provision plan_code filter, isolation)
- `php tests/e2e-purchase-to-cloud.php` — store fixture + §17 handover/overlap matrix ok
- `react-license` Jest `licenseReuse` + `cloudCoverage` — 11 passed

## Remaining

Run `add_reactwoo_decision_cloud_package.sql` on the license DB. Staging Woo E2E. Finished Figma visual design. Do not enable `REACTWOO_CLOUD_BRIDGE_ENABLED` on production.
