# Shared API key (provisioning auth)

API Manager authenticates provision/sync with the **same Settings → API Key** used for other licence-server calls (`reactwoo_api_key`).

It is sent as the `X-RW-Master-Key` header.

## Storefront

WooCommerce → ReactWoo License Manager → Settings → **API Key**

Optional override (not required):

```php
define( 'REACTWOO_LICENSE_MASTER_KEY', '…' );
```

## Licence server

Accepts either:

- `WOOCOMMERCE_API_KEY` (preferred shared key), or
- `RW_MASTER_KEY`

Set the storefront API Key to match one of those env values.
