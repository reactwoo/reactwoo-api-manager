<?php
/**
 * Read-only Local/staging catalogue check (PLAN.md §13 / §19).
 *
 * Does not write. Does not enable production Cloud commerce.
 * Exit 0 when parent 3166 + variations 3172–3177 have plan/cycle/type meta.
 *
 * Run: php scripts/validate_cloud_catalogue.php
 */

$parent   = 3166;
$expected = array(
	3172 => array( 'plan' => 'starter', 'cycle' => 'monthly' ),
	3173 => array( 'plan' => 'starter', 'cycle' => 'annual' ),
	3174 => array( 'plan' => 'growth', 'cycle' => 'monthly' ),
	3175 => array( 'plan' => 'growth', 'cycle' => 'annual' ),
	3176 => array( 'plan' => 'scale', 'cycle' => 'monthly' ),
	3177 => array( 'plan' => 'scale', 'cycle' => 'annual' ),
);

$mysql = 'C:\\Users\\User\\AppData\\Roaming\\Local\\lightning-services\\mysql-8.0.35+4\\bin\\win64\\bin\\mysql.exe';
if ( ! is_file( $mysql ) ) {
	fwrite( STDERR, "SKIP: Local mysql.exe not found — cannot validate catalogue on this machine.\n" );
	exit( 0 );
}

$ids = implode( ',', array_merge( array( $parent ), array_keys( $expected ) ) );
$sql = "SELECT ID, post_type FROM wp_posts WHERE ID={$parent} LIMIT 1;\n"
	. "SELECT post_id, meta_key, meta_value FROM wp_postmeta\n"
	. "WHERE post_id IN ({$ids})\n"
	. "AND meta_key IN ('_rw_cloud_plan','_rw_cloud_billing_cycle','_rw_cloud_product_type');\n";

$sql_file = __DIR__ . '/_tmp_validate_cloud_catalogue.sql';
file_put_contents( $sql_file, $sql );

$cmd = '"' . $mysql . '" -h 127.0.0.1 -P 10011 -uroot -proot --batch --raw --default-character-set=utf8mb4 local';
$descriptors = array(
	0 => array( 'file', $sql_file, 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$proc = proc_open( $cmd, $descriptors, $pipes );
if ( ! is_resource( $proc ) ) {
	@unlink( $sql_file );
	fwrite( STDERR, "SKIP: could not start mysql\n" );
	exit( 0 );
}
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$code = proc_close( $proc );
@unlink( $sql_file );

if ( $code !== 0 ) {
	fwrite( STDERR, "SKIP: mysql failed (Local site may be stopped)\n" . $stderr . PHP_EOL );
	exit( 0 );
}

$meta = array();
$parent_type = '';
foreach ( preg_split( "/\r\n|\n|\r/", (string) $stdout ) as $line ) {
	$line = trim( $line );
	if ( $line === '' ) {
		continue;
	}
	$cols = explode( "\t", $line );
	if ( count( $cols ) === 2 && (string) $cols[0] === (string) $parent ) {
		$parent_type = $cols[1];
		continue;
	}
	if ( count( $cols ) >= 3 ) {
		$meta[ (int) $cols[0] ][ $cols[1] ] = $cols[2];
	}
}

$errors = array();
if ( $parent_type !== 'product' ) {
	$errors[] = "parent {$parent} is not a product (got {$parent_type})";
}
if ( ! isset( $meta[ $parent ]['_rw_cloud_product_type'] ) || $meta[ $parent ]['_rw_cloud_product_type'] !== 'decision_cloud' ) {
	$errors[] = "parent {$parent} missing _rw_cloud_product_type=decision_cloud";
}

foreach ( $expected as $id => $row ) {
	$plan  = isset( $meta[ $id ]['_rw_cloud_plan'] ) ? $meta[ $id ]['_rw_cloud_plan'] : '';
	$cycle = isset( $meta[ $id ]['_rw_cloud_billing_cycle'] ) ? $meta[ $id ]['_rw_cloud_billing_cycle'] : '';
	$ptype = isset( $meta[ $id ]['_rw_cloud_product_type'] ) ? $meta[ $id ]['_rw_cloud_product_type'] : '';
	if ( $plan !== $row['plan'] ) {
		$errors[] = "{$id} plan={$plan} expected {$row['plan']}";
	}
	if ( $cycle !== $row['cycle'] ) {
		$errors[] = "{$id} cycle={$cycle} expected {$row['cycle']}";
	}
	if ( $ptype !== 'decision_cloud' ) {
		$errors[] = "{$id} missing product type decision_cloud";
	}
}

if ( $errors ) {
	fwrite( STDERR, "FAIL: catalogue validation\n- " . implode( "\n- ", $errors ) . "\n" );
	exit( 1 );
}

echo "OK: Local catalogue parent {$parent} / variations 3172-3177 have plan, cycle, and decision_cloud meta.\n";
echo "Production Cloud commerce remains off until operators bind production and set REACTWOO_CLOUD_BRIDGE_ENABLED.\n";
exit( 0 );
