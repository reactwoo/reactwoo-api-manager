-- OPERATOR ONLY — production ReactWoo.com WooCommerce catalogue bind.
-- Inspected 2026-08-19: parent 3166, variations 3172–3177.
--
-- This file does NOT enable Cloud commerce.
-- Do NOT define REACTWOO_CLOUD_BRIDGE_ENABLED here or in wp-config from this script.
-- Do NOT run against Local. Local bind: php scripts/bind_local_cloud_catalogue.php
--
-- Before running: confirm table prefix is wp_ and that these post IDs still exist.
-- After running: bind Decision Cloud commerce settings in wp-admin
-- (product_starter=3172,3173 etc.) or run merge_production_cloud_settings.php
-- (empty keys only; never overwrites secrets). Prices below are PLAN.md marketing GBP.

-- Parent
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3166 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3166, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3166 AND meta_key='_rw_cloud_product_type');

UPDATE wp_postmeta SET meta_value='yes' WHERE post_id=3166 AND meta_key='_virtual';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3166, '_virtual', 'yes' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3166 AND meta_key='_virtual');

UPDATE wp_postmeta SET meta_value='no' WHERE post_id=3166 AND meta_key='_downloadable';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3166, '_downloadable', 'no' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3166 AND meta_key='_downloadable');

-- Starter monthly 3172
UPDATE wp_postmeta SET meta_value='starter' WHERE post_id=3172 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3172, '_rw_cloud_plan', 'starter' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3172 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='monthly' WHERE post_id=3172 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3172, '_rw_cloud_billing_cycle', 'monthly' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3172 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3172 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3172, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3172 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='39' WHERE post_id=3172 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_postmeta SET meta_value='yes' WHERE post_id=3172 AND meta_key='_virtual';
UPDATE wp_postmeta SET meta_value='no' WHERE post_id=3172 AND meta_key='_downloadable';
UPDATE wp_postmeta SET meta_value='instock' WHERE post_id=3172 AND meta_key='_stock_status';
UPDATE wp_wc_product_meta_lookup SET min_price='39', max_price='39', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3172;

-- Starter annual 3173
UPDATE wp_postmeta SET meta_value='starter' WHERE post_id=3173 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3173, '_rw_cloud_plan', 'starter' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3173 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='annual' WHERE post_id=3173 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3173, '_rw_cloud_billing_cycle', 'annual' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3173 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3173 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3173, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3173 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='390' WHERE post_id=3173 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_wc_product_meta_lookup SET min_price='390', max_price='390', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3173;

-- Growth monthly 3174
UPDATE wp_postmeta SET meta_value='growth' WHERE post_id=3174 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3174, '_rw_cloud_plan', 'growth' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3174 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='monthly' WHERE post_id=3174 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3174, '_rw_cloud_billing_cycle', 'monthly' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3174 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3174 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3174, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3174 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='99' WHERE post_id=3174 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_wc_product_meta_lookup SET min_price='99', max_price='99', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3174;

-- Growth annual 3175
UPDATE wp_postmeta SET meta_value='growth' WHERE post_id=3175 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3175, '_rw_cloud_plan', 'growth' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3175 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='annual' WHERE post_id=3175 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3175, '_rw_cloud_billing_cycle', 'annual' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3175 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3175 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3175, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3175 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='990' WHERE post_id=3175 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_wc_product_meta_lookup SET min_price='990', max_price='990', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3175;

-- Scale monthly 3176
UPDATE wp_postmeta SET meta_value='scale' WHERE post_id=3176 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3176, '_rw_cloud_plan', 'scale' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3176 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='monthly' WHERE post_id=3176 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3176, '_rw_cloud_billing_cycle', 'monthly' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3176 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3176 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3176, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3176 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='249' WHERE post_id=3176 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_wc_product_meta_lookup SET min_price='249', max_price='249', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3176;

-- Scale annual 3177
UPDATE wp_postmeta SET meta_value='scale' WHERE post_id=3177 AND meta_key='_rw_cloud_plan';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3177, '_rw_cloud_plan', 'scale' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3177 AND meta_key='_rw_cloud_plan');
UPDATE wp_postmeta SET meta_value='annual' WHERE post_id=3177 AND meta_key='_rw_cloud_billing_cycle';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3177, '_rw_cloud_billing_cycle', 'annual' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3177 AND meta_key='_rw_cloud_billing_cycle');
UPDATE wp_postmeta SET meta_value='decision_cloud' WHERE post_id=3177 AND meta_key='_rw_cloud_product_type';
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT 3177, '_rw_cloud_product_type', 'decision_cloud' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=3177 AND meta_key='_rw_cloud_product_type');
UPDATE wp_postmeta SET meta_value='2490' WHERE post_id=3177 AND meta_key IN ('_price','_regular_price','_subscription_price');
UPDATE wp_wc_product_meta_lookup SET min_price='2490', max_price='2490', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3177;

UPDATE wp_wc_product_meta_lookup SET min_price='39', max_price='2490', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id=3166;
