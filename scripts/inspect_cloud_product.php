<?php
/**
 * One-shot inspector for the Decision Cloud WooCommerce product.
 * Not part of runtime. Run: php scripts/inspect_cloud_product.php
 */

$mysqli = @new mysqli( '127.0.0.1', 'root', 'root', 'local', 10011 );
if ( $mysqli->connect_error ) {
	fwrite( STDERR, 'DB connect failed: ' . $mysqli->connect_error . PHP_EOL );
	exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

$sql = "SELECT ID, post_title, post_name, post_type, post_parent, post_status
	FROM wp_posts
	WHERE post_type IN ('product','product_variation')
	AND (
		post_title LIKE '%Decision Cloud%'
		OR post_title LIKE '%decision cloud%'
		OR post_name LIKE '%decision-cloud%'
		OR post_name LIKE '%decision_cloud%'
	)
	ORDER BY post_parent, ID";

$result = $mysqli->query( $sql );
if ( ! $result ) {
	fwrite( STDERR, $mysqli->error . PHP_EOL );
	exit( 1 );
}

$rows = array();
while ( $row = $result->fetch_assoc() ) {
	$rows[] = $row;
}

if ( ! $rows ) {
	echo "NO_MATCHING_PRODUCTS\n";
	$fallback = $mysqli->query( "SELECT ID, post_title, post_name, post_type, post_parent, post_status FROM wp_posts WHERE post_type='product' AND post_status IN ('publish','draft','private') ORDER BY ID DESC LIMIT 40" );
	while ( $row = $fallback->fetch_assoc() ) {
		echo implode( "\t", $row ) . PHP_EOL;
	}
	exit( 0 );
}

$ids = array();
foreach ( $rows as $row ) {
	$ids[] = (int) $row['ID'];
	if ( (int) $row['post_parent'] ) {
		$ids[] = (int) $row['post_parent'];
	}
}
$ids = array_values( array_unique( $ids ) );

echo "=== PRODUCTS / VARIATIONS ===\n";
foreach ( $rows as $row ) {
	echo implode( "\t", $row ) . PHP_EOL;
}

$id_list = implode( ',', $ids );
$meta_keys = array(
	'_rw_cloud_plan',
	'_rw_cloud_billing_cycle',
	'_rw_cloud_product_type',
	'_sku',
	'_virtual',
	'_downloadable',
	'_downloadable_files',
	'_subscription_period',
	'_subscription_period_interval',
	'_subscription_price',
	'_regular_price',
	'_price',
	'_product_attributes',
	'attribute_pa_plan',
	'attribute_plan',
	'attribute_pa_billing',
	'attribute_billing',
	'attribute_pa_billing-cycle',
	'attribute_pa_billing_cycle',
	'_variation_description',
);

$meta_sql = "SELECT post_id, meta_key, meta_value FROM wp_postmeta
	WHERE post_id IN ($id_list)
	AND (
		meta_key IN ('" . implode( "','", array_map( array( $mysqli, 'real_escape_string' ), $meta_keys ) ) . "')
		OR meta_key LIKE 'attribute_%'
	)
	ORDER BY post_id, meta_key";
$meta = $mysqli->query( $meta_sql );
echo "\n=== SELECTED META ===\n";
while ( $row = $meta->fetch_assoc() ) {
	$value = $row['meta_value'];
	if ( strlen( $value ) > 400 ) {
		$value = substr( $value, 0, 400 ) . '...';
	}
	echo $row['post_id'] . "\t" . $row['meta_key'] . "\t" . str_replace( array( "\r", "\n" ), ' ', $value ) . PHP_EOL;
}

$term_sql = "SELECT tr.object_id, tt.taxonomy, t.slug, t.name
	FROM wp_term_relationships tr
	INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
	INNER JOIN wp_terms t ON t.term_id = tt.term_id
	WHERE tr.object_id IN ($id_list)
	AND tt.taxonomy IN ('product_type','product_visibility','pa_plan','pa_billing','pa_billing-cycle','pa_billing_cycle','product_cat')
	ORDER BY tr.object_id, tt.taxonomy";
$terms = $mysqli->query( $term_sql );
echo "\n=== TERMS ===\n";
if ( $terms ) {
	while ( $row = $terms->fetch_assoc() ) {
		echo implode( "\t", $row ) . PHP_EOL;
	}
}

$opt = $mysqli->query( "SELECT option_value FROM wp_options WHERE option_name='rwcc_settings' LIMIT 1" );
echo "\n=== RWCC SETTINGS ===\n";
if ( $opt && $row = $opt->fetch_assoc() ) {
	echo $row['option_value'] . PHP_EOL;
} else {
	echo "(none)\n";
}
