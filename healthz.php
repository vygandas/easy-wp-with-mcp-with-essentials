<?php
/**
 * Health probe for your platform's health check. Loads only wpdb (SHORTINIT) and
 * returns 200 when PHP runs and the database accepts connections. A failed
 * connection makes wp-load exit with a 500 before we get here.
 */
define( 'SHORTINIT', true );
require __DIR__ . '/wp-load.php';

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Cache-Control: no-store' );

global $wpdb;
if ( $wpdb instanceof wpdb && $wpdb->check_connection( false ) ) {
	echo 'ok';
	exit;
}
http_response_code( 503 );
echo 'database unavailable';
