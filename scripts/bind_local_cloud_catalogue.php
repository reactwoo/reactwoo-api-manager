<?php
/**
 * Local-only bind of Decision Cloud catalogue (PLAN.md §3.0 / §19).
 *
 * Writes variation meta, PLAN marketing prices, and rwcc_settings on the Local
 * ReactWoo site (127.0.0.1:10011). Does not touch production. Does not wipe secrets.
 *
 * Run: php scripts/bind_local_cloud_catalogue.php
 */

$parent = 3166;
$variations = array(
	3172 => array( 'plan' => 'starter', 'cycle' => 'monthly', 'price' => '39' ),
	3173 => array( 'plan' => 'starter', 'cycle' => 'annual', 'price' => '390' ),
	3174 => array( 'plan' => 'growth', 'cycle' => 'monthly', 'price' => '99' ),
	3175 => array( 'plan' => 'growth', 'cycle' => 'annual', 'price' => '990' ),
	3176 => array( 'plan' => 'scale', 'cycle' => 'monthly', 'price' => '249' ),
	3177 => array( 'plan' => 'scale', 'cycle' => 'annual', 'price' => '2490' ),
);

$mysql = 'C:\\Users\\User\\AppData\\Roaming\\Local\\lightning-services\\mysql-8.0.35+4\\bin\\win64\\bin\\mysql.exe';
if ( ! is_file( $mysql ) ) {
	fwrite( STDERR, "mysql.exe not found at {$mysql}\n" );
	exit( 1 );
}

/**
 * @param int    $post_id Post id.
 * @param string $key     Meta key.
 * @param string $value   Meta value.
 * @return string
 */
function rw_upsert_meta_sql( $post_id, $key, $value ) {
	$post_id = (int) $post_id;
	$key_sql = "'" . str_replace( "'", "''", $key ) . "'";
	$val_sql = "'" . str_replace( "'", "''", $value ) . "'";
	return "UPDATE wp_postmeta SET meta_value={$val_sql} WHERE post_id={$post_id} AND meta_key={$key_sql};\n"
		. "INSERT INTO wp_postmeta (post_id, meta_key, meta_value)\n"
		. "SELECT {$post_id}, {$key_sql}, {$val_sql} FROM DUAL\n"
		. "WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id={$post_id} AND meta_key={$key_sql});\n";
}

$sql  = "SELECT ID FROM wp_posts WHERE ID={$parent} AND post_type='product';\n";
$sql .= rw_upsert_meta_sql( $parent, '_rw_cloud_product_type', 'decision_cloud' );
$sql .= rw_upsert_meta_sql( $parent, '_virtual', 'yes' );
$sql .= rw_upsert_meta_sql( $parent, '_downloadable', 'no' );
$sql .= rw_upsert_meta_sql( $parent, '_price', '39' );
$sql .= rw_upsert_meta_sql( $parent, '_min_variation_price', '39' );
$sql .= rw_upsert_meta_sql( $parent, '_max_variation_price', '2490' );
$sql .= rw_upsert_meta_sql( $parent, '_min_variation_regular_price', '39' );
$sql .= rw_upsert_meta_sql( $parent, '_max_variation_regular_price', '2490' );

foreach ( $variations as $id => $row ) {
	$sql .= rw_upsert_meta_sql( $id, '_rw_cloud_plan', $row['plan'] );
	$sql .= rw_upsert_meta_sql( $id, '_rw_cloud_billing_cycle', $row['cycle'] );
	$sql .= rw_upsert_meta_sql( $id, '_rw_cloud_product_type', 'decision_cloud' );
	$sql .= rw_upsert_meta_sql( $id, '_price', $row['price'] );
	$sql .= rw_upsert_meta_sql( $id, '_regular_price', $row['price'] );
	$sql .= rw_upsert_meta_sql( $id, '_subscription_price', $row['price'] );
	$sql .= rw_upsert_meta_sql( $id, '_virtual', 'yes' );
	$sql .= rw_upsert_meta_sql( $id, '_downloadable', 'no' );
	$sql .= rw_upsert_meta_sql( $id, '_stock_status', 'instock' );
	$sql .= "UPDATE wp_wc_product_meta_lookup SET min_price='{$row['price']}', max_price='{$row['price']}', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id={$id};\n";
}
$sql .= "UPDATE wp_wc_product_meta_lookup SET min_price='39', max_price='2490', stock_status='instock', `virtual`=1, downloadable=0 WHERE product_id={$parent};\n";

$settings = array(
	'cloud_origin'           => 'http://127.0.0.1:3040',
	'webhook_url'            => '',
	'webhook_secret'         => '',
	'handoff_secret'         => '',
	'reconcile_token'        => '',
	'claim_ttl_sec'          => 1800,
	'replay_window_sec'      => 300,
	'return_origins'         => "http://127.0.0.1:3040\nhttp://reactwoo.local",
	'product_decision_cloud' => '3166',
	'product_starter'        => '3172,3173',
	'product_growth'         => '3174,3175',
	'product_scale'          => '3176,3177',
	'product_geocore_pro'    => '2294',
	'product_geo_commerce'   => '2893',
	'product_geo_optimise'   => '2891',
	'activation_path'        => '/activate',
	'allow_http_local'       => true,
);
$serial = serialize( $settings );
$serial_sql = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "''" ), $serial ) . "'";
$sql .= "DELETE FROM wp_options WHERE option_name='rwcc_settings';\n";
$sql .= "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('rwcc_settings', {$serial_sql}, 'no');\n";

$sql_file = __DIR__ . '/_tmp_bind_local_cloud.sql';
file_put_contents( $sql_file, $sql );

$cmd = '"' . $mysql . '" -h 127.0.0.1 -P 10011 -uroot -proot --default-character-set=utf8mb4 local';
$descriptors = array(
	0 => array( 'file', $sql_file, 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$proc = proc_open( $cmd, $descriptors, $pipes );
if ( ! is_resource( $proc ) ) {
	fwrite( STDERR, "Failed to start mysql\n" );
	exit( 1 );
}
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$code = proc_close( $proc );
@unlink( $sql_file );
@unlink( __DIR__ . '/_tmp_rwcc_settings.php' );

if ( $code !== 0 ) {
	fwrite( STDERR, $stderr . PHP_EOL );
	exit( $code );
}

echo "Bound Decision Cloud catalogue on Local:\n";
echo "  parent 3166 type=decision_cloud\n";
echo "  variations 3172-3177 plan/cycle/prices\n";
echo "  rwcc_settings product_starter=3172,3173 product_growth=3174,3175 product_scale=3176,3177\n";
echo "  individuals Geo Core Pro=2294 Geo Commerce=2893 Geo Optimise=2891\n";
echo "This is Local only. Do not copy these prices/settings to production blindly.\n";
if ( $stdout !== '' ) {
	echo $stdout;
}
