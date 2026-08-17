# Known issues — Store identity handoff

## Do not retry

- Do not re-hook WooCommerce subscription activation / order completed for Cloud. Licence handler owns those; Cloud consumes observe-only actions.
- Do not send the raw activation token in `POST /api/v1/identity/claims`. Hash only.
- Do not put the raw claim in the query string. Use `#claim=`.
- Do not use email as the Cloud identity primary key.
- Do not authenticate Decision Cloud from a WooCommerce webhook.

## Open

- Team invitations are not issued from My Account. Cloud accepts purpose `invite`. Next task.
- Production deploy and Aplenty owner UUID confirmation are blocked on operator action.
- `php tests/licensing-regression.php` is not a standalone file; run `php tests/run.php`.
