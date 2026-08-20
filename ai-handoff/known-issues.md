# Known issues — Store identity handoff

## Do not retry

- Do not re-hook WooCommerce subscription activation / order completed for Cloud. Licence handler owns those; Cloud consumes observe-only actions.
- Do not send the raw activation token in `POST /api/v1/identity/claims`. Hash only.
- Do not put the raw claim in the query string. Use `#claim=`.
- Do not use email as the Cloud identity primary key.
- Do not authenticate Decision Cloud from a WooCommerce webhook.
- Do not send `intended_role: member` on returning-login claims. Login must not demote an existing owner.
- Do not require `_wpnonce` on Cloud-originated `rwcc_open_cloud=1` links. Cloud cannot mint a WordPress nonce. Verify nonce only when present.

## Open

- Production SSH `origin` still `Permission denied`. **2.1.11** is on GitHub (`v2.1.11`) and in `reactwoo-api-manager-2.1.11.zip`. Confirm wp-admin Version if the Cloud bounce does not run.
- Identity UUID for WP user 13 **exists** (`_rw_cloud_identity_subject`). Do not use user id `13` as Cloud `--subject`.
- Team invitations are not issued from My Account. Cloud accepts purpose `invite`.
- `php tests/licensing-regression.php` is not a standalone file; run `php tests/run.php`.
- Canonical cutover plan: Decision Cloud `docs/identity-production-cutover.md`.
