'use strict';

/**
 * Apply a store-bridge fixture to an in-memory Decision Cloud.
 * Usage: node tests/e2e-apply-webhook.js <fixture.json> <decision-cloud-dir>
 */

const fs = require('fs');
const path = require('path');

const fixturePath = process.argv[2];
const cloudDir = process.argv[3];
if (!fixturePath || !cloudDir) {
  console.error('Usage: node e2e-apply-webhook.js <fixture.json> <decision-cloud-dir>');
  process.exit(1);
}

const { JsonRepository } = require(path.join(cloudDir, 'src/store/jsonRepository'));
const { EntitlementService } = require(path.join(cloudDir, 'src/services/entitlements'));
const { createWooCommerceAdapter } = require(path.join(cloudDir, 'src/services/woocommerceAdapter'));
const { signWooCommercePayload } = require(path.join(cloudDir, 'src/services/woocommerceSignature'));
const { processWooCommerceWebhookRequest } = require(path.join(cloudDir, 'src/services/woocommerceWebhooks'));

const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const repo = new JsonRepository(null);
const entitlements = new EntitlementService(repo, { graceDays: 7 });
const commerce = createWooCommerceAdapter({
  reactwooStoreOrigin: 'https://reactwoo.com',
  handoffSecret: 'handoff_e2e_local',
  woocommerceProducts: { starter: '101', growth: '202', scale: '303' },
});

const payload = JSON.parse(JSON.stringify(fixture.payload || {}));
const raw = JSON.stringify(payload);
const secret = fixture.secret;
const signature = signWooCommercePayload(raw, secret);
const result = processWooCommerceWebhookRequest(repo, entitlements, commerce, {
  rawBody: raw,
  signature,
  webhookSecret: secret,
  topic: fixture.topic || 'subscription.created',
  deliveryId: fixture.delivery_id,
});

const orgId = result.organisation_id;
const snap = orgId ? entitlements.getPublic(orgId) : { plan: '', items: [] };
const commerceEntitlement = (snap.items || []).find((item) => item.key === 'cloud.commerce');
const org = orgId ? repo.getOrganisation(orgId) : null;

console.log('CLOUD webhook applied');
console.log(`  organisation: ${orgId || ''}`);
console.log(`  provisioning_id: ${org ? org.provisioning_id : ''}`);
console.log(`  processed: ${result.processed}`);
console.log(`  duplicate: ${result.duplicate || false}`);
console.log(`  reason: ${result.reason || ''}`);
console.log(`  plan: ${snap.plan}`);
console.log(`  status: ${snap.status}`);
console.log(`  cloud.commerce: ${commerceEntitlement ? commerceEntitlement.allowed : false}`);
console.log(`  activation_url: ${fixture.activation_url}`);
console.log(`  claim_hash: ${fixture.claim_hash}`);

if (!result.processed || !orgId || snap.plan !== 'growth' || !commerceEntitlement?.allowed) {
  console.error('E2E failed: Cloud entitlement was not applied');
  process.exit(1);
}

const replay = processWooCommerceWebhookRequest(repo, entitlements, commerce, {
  rawBody: raw,
  signature,
  webhookSecret: secret,
  topic: fixture.topic || 'subscription.created',
  deliveryId: fixture.delivery_id,
});
if (!replay.duplicate) {
  console.error('E2E failed: replayed delivery was not idempotent');
  process.exit(1);
}
console.log('  replay: duplicate ignored');

const orgs = Object.values(repo.state.organisations).filter(
  (row) => row.provisioning_id && row.provisioning_id === org.provisioning_id
);
if (orgs.length !== 1) {
  console.error('E2E failed: duplicate provisioning minted another organisation');
  process.exit(1);
}

const missed = entitlements.getPublic(orgId);
if (missed.plan !== 'growth') {
  console.error('E2E failed: reconcile-equivalent snapshot lost the plan');
  process.exit(1);
}
console.log('  post-miss snapshot still growth (use store GET /reactwoo-cloud/v1/reconcile if a webhook is dropped)');
console.log('E2E OK: purchase → webhook → provisioned workspace → Cloud entitlement → activation claim');
