# ReactWoo support runbook

Procedures for the support team on reactwoo.com. Keep each section short and current.

## Chargebacks and disputes (Maintenance bundles and licences, v1)

Agreed for v1 on 11 Nov 2026: chargebacks and disputes are handled by hand.

### What happens automatically

- Nothing. There is no automatic hook for disputes or chargebacks. WooCommerce has no "disputed" order status on reactwoo.com, and neither the Revolut gateway plugin nor ReactWoo API Manager listens for dispute or chargeback events.
- A dispute therefore leaves the order, the subscription and every licence key active until support acts.

### What support does when a Revolut dispute or chargeback notice arrives

1. **Find the order.** Use the Revolut order or payment ID, the amount and the customer email from the notice. In WordPress admin go to WooCommerce → Orders and search by email or order number. Open the linked subscription from the order's "Related orders" box.
2. **Fully refund or cancel it in WooCommerce.**
   - If the money has gone back to the customer through the chargeback, record a **full refund** on the order with **Refund manually** (do not use the Revolut refund button, or the customer is refunded twice).
   - A full refund revokes every included licence key straight away: each key is marked `refunded` and stays refunded. From API Manager 2.1.20 the full refund also cancels the linked subscription, so no renewal is scheduled. On 2.1.19 or earlier, cancel the subscription by hand as well.
   - If the dispute is still open and no money has moved, cancel the subscription instead (Subscriptions → the subscription → Cancel). That moves the keys to `cancelled`. Record the full refund later if the bank rules against us.
   - Never use a partial refund for a chargeback. Partial refunds do not revoke keys or cancel the subscription.
3. **Check the keys on license.reactwoo.com.** In the licence admin, search the customer's licences (by subscription, email or maintained domain). Every key from that order must show `refunded` (or `cancelled` if you only cancelled). If one still shows `active`, set it to `refunded` there and tell the developers, because the store did not sync it.
4. **Add an order note.** On the order, add a private note such as: "Revolut chargeback/dispute <reference> received <date>. Full refund recorded manually / subscription cancelled. Keys checked on license.reactwoo.com: all refunded. <your name>".
5. **Reply to Revolut** with the evidence they ask for (order, service website, licence activation history) if we are contesting it.

### Notes

- Refunded keys are locked. If the customer later buys again, they get new keys; the refunded ones are never reactivated.
- Separately purchased licences on the same account are not affected by a bundle refund. Check whether the dispute also covers those orders and repeat the steps for each one.
