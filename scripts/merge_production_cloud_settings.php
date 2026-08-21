<?php
/**
 * Merge-only production rwcc_settings catalogue bind.
 *
 * Fills empty product ID keys from the inspected ReactWoo.com catalogue.
 * Never overwrites a non-empty setting. Never writes webhook/handoff/reconcile secrets.
 * Never sets allow_http_local. Refuses Local hosts.
 *
 * Catalogue SQL (bind_production_cloud_catalogue.sql) does not write this option.
 *
 * Production (WP-CLI from the ReactWoo.com WordPress root):
 *   wp eval-file wp-content/plugins/reactwoo-api-manager/scripts/merge_production_cloud_settings.php
 * Dry run:
 *   wp eval-file wp-content/plugins/reactwoo-api-manager/scripts/merge_production_cloud_settings.php -- --dry-run
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run with WP-CLI: wp eval-file scripts/merge_production_cloud_settings.php\n" );
	exit( 1 );
}

if ( ! class_exists( 'RWCC_Settings' ) ) {
	require_once dirname( __DIR__ ) . '/includes/cloud-commerce/class-rwcc-settings.php';
}

$argv_list = array();
if ( isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ) {
	$argv_list = $GLOBALS['argv'];
}
$dry_run = in_array( '--dry-run', $argv_list, true );

$host = '';
if ( function_exists( 'home_url' ) ) {
	$parsed = wp_parse_url( home_url() );
	$host   = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
}
$local_hosts = array( 'localhost', '127.0.0.1', 'reactwoo.local' );
if ( $host === '' || in_array( $host, $local_hosts, true ) || substr( $host, -6 ) === '.local' ) {
	fwrite( STDERR, "Refusing to merge production catalogue IDs on host '{$host}'. Use bind_local_cloud_catalogue.php on Local.\n" );
	exit( 1 );
}

$fill = array(
	'cloud_origin'           => 'https://decision.reactwoo.com',
	'return_origins'         => "https://decision.reactwoo.com\nhttps://reactwoo.com",
	'product_decision_cloud' => '3166',
	'product_starter'        => '3172,3173',
	'product_growth'         => '3174,3175',
	'product_scale'          => '3176,3177',
	'product_geocore_pro'    => '2294',
	'product_geo_commerce'   => '2893',
	'product_geo_optimise'   => '2891',
);

$current = array();
$raw     = get_option( RWCC_Settings::OPTION_KEY, array() );
if ( is_array( $raw ) ) {
	$current = $raw;
}

$merged = RWCC_Settings::merge_empty( $current, $fill );
$before = RWCC_Settings::catalogue_gaps( array_merge( RWCC_Settings::defaults(), $current ) );
$after  = RWCC_Settings::catalogue_gaps( $merged );

echo "host={$host}\n";
echo 'catalogue_gaps_before=' . ( $before ? implode( ',', $before ) : '(none)' ) . "\n";
echo 'catalogue_gaps_after=' . ( $after ? implode( ',', $after ) : '(none)' ) . "\n";
foreach ( RWCC_Settings::catalogue_keys() as $key ) {
	$was = isset( $current[ $key ] ) ? trim( (string) $current[ $key ] ) : '';
	$now = isset( $merged[ $key ] ) ? trim( (string) $merged[ $key ] ) : '';
	$flag = ( $was === '' && $now !== '' ) ? ' filled' : ( $was !== $now ? ' changed' : '' );
	echo "{$key}={$now}{$flag}\n";
}

if ( $dry_run ) {
	echo "dry_run=1 (not saved)\n";
	exit( 0 );
}

RWCC_Settings::save( $merged );
echo "saved=1 secrets_untouched=1\n";
