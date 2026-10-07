<?php
/**
 * WordPress configuration, driven entirely by environment variables.
 *
 * Local:   docker compose injects .env into the container.
 * Hosting: set the same variables in your platform's environment settings.
 *
 * This file contains no secrets and is committed. Do not hardcode values here.
 */

if ( ! function_exists( 'site_env' ) ) {
	/**
	 * Read an environment variable, treating '' as unset.
	 *
	 * @param string $key     Variable name.
	 * @param mixed  $default Returned when the variable is unset or empty.
	 * @return mixed
	 */
	function site_env( string $key, $default = null ) {
		$value = getenv( $key );
		return ( false === $value || '' === $value ) ? $default : $value;
	}
}

if ( ! function_exists( 'site_env_bool' ) ) {
	function site_env_bool( string $key, bool $default ): bool {
		$value = site_env( $key );
		if ( null === $value ) {
			return $default;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------
$site_env_type = site_env( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'WP_ENVIRONMENT_TYPE', $site_env_type );
$site_is_production = ( 'production' === $site_env_type );

// ---------------------------------------------------------------------------
// Database
// Accepts a single URL (MYSQL_URL or DATABASE_URL: mysql://user:pass@host:port/db)
// or the individual DB_* variables.
// ---------------------------------------------------------------------------
$site_db_url = site_env( 'MYSQL_URL', site_env( 'DATABASE_URL' ) );
if ( $site_db_url ) {
	$site_db = parse_url( $site_db_url );
	define( 'DB_NAME', ltrim( $site_db['path'] ?? '', '/' ) );
	define( 'DB_USER', urldecode( $site_db['user'] ?? '' ) );
	define( 'DB_PASSWORD', urldecode( $site_db['pass'] ?? '' ) );
	define( 'DB_HOST', ( $site_db['host'] ?? 'localhost' ) . ( isset( $site_db['port'] ) ? ':' . $site_db['port'] : '' ) );
} else {
	define( 'DB_NAME', site_env( 'DB_NAME', 'wordpress' ) );
	define( 'DB_USER', site_env( 'DB_USER', 'wordpress' ) );
	define( 'DB_PASSWORD', site_env( 'DB_PASSWORD', '' ) );
	define( 'DB_HOST', site_env( 'DB_HOST', 'db' ) . ':' . site_env( 'DB_PORT', '3306' ) );
}
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = site_env( 'DB_TABLE_PREFIX', 'wp_' );

// ---------------------------------------------------------------------------
// Salts: required, no fallback. Generate at https://api.wordpress.org/secret-key/1.1/salt/
// ---------------------------------------------------------------------------
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $site_salt ) {
	$site_salt_value = site_env( $site_salt );
	if ( null === $site_salt_value ) {
		http_response_code( 500 );
		exit( 'Missing required environment variable: ' . $site_salt );
	}
	define( $site_salt, $site_salt_value );
}

// ---------------------------------------------------------------------------
// URLs and HTTPS behind a TLS-terminating proxy (local Caddy, or your host's
// load balancer). The header is trusted as-is, so only expose the container
// through a proxy that sets it.
// ---------------------------------------------------------------------------
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

$site_home = site_env( 'WP_HOME' );
if ( $site_home ) {
	define( 'WP_HOME', rtrim( $site_home, '/' ) );
	define( 'WP_SITEURL', rtrim( site_env( 'WP_SITEURL', WP_HOME ), '/' ) );
	define( 'FORCE_SSL_ADMIN', 0 === strpos( WP_HOME, 'https://' ) );
}

// ---------------------------------------------------------------------------
// Debugging
// ---------------------------------------------------------------------------
define( 'WP_DEBUG', site_env_bool( 'WP_DEBUG', ! $site_is_production ) );
define( 'WP_DEBUG_LOG', WP_DEBUG );
define( 'WP_DEBUG_DISPLAY', WP_DEBUG && ! $site_is_production );
define( 'SCRIPT_DEBUG', site_env_bool( 'SCRIPT_DEBUG', false ) );
@ini_set( 'display_errors', WP_DEBUG_DISPLAY ? '1' : '0' );

// ---------------------------------------------------------------------------
// Filesystem policy. Production containers have an ephemeral filesystem:
// anything installed or updated through wp-admin would vanish on redeploy,
// so code changes only ever arrive via git.
// ---------------------------------------------------------------------------
define( 'FS_METHOD', 'direct' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', site_env_bool( 'DISALLOW_FILE_MODS', $site_is_production ) );
define( 'AUTOMATIC_UPDATER_DISABLED', $site_is_production );

// ---------------------------------------------------------------------------
// Media storage. Uploads go straight to an S3-compatible bucket through the
// S3 Uploads plugin (stream wrapper, so thumbnails and AVIF copies land there
// too); nothing is written under wp-content/uploads. Without S3_UPLOADS_BUCKET
// the plugin stays idle and uploads fall back to the local, ephemeral disk.
// Endpoint, path style and ACL are applied by mu-plugins/site-media-storage.php.
// ---------------------------------------------------------------------------
$site_s3_bucket = site_env( 'S3_UPLOADS_BUCKET' );
if ( $site_s3_bucket ) {
	define( 'S3_UPLOADS_BUCKET', $site_s3_bucket );
	define( 'S3_UPLOADS_REGION', site_env( 'S3_UPLOADS_REGION', 'auto' ) );
	define( 'S3_UPLOADS_KEY', site_env( 'S3_UPLOADS_KEY', '' ) );
	define( 'S3_UPLOADS_SECRET', site_env( 'S3_UPLOADS_SECRET', '' ) );
	// Public base URL of the bucket (custom domain, CDN, or provider URL); objects live below it under /uploads/.
	$site_s3_url = site_env( 'S3_UPLOADS_BUCKET_URL' );
	if ( $site_s3_url ) {
		define( 'S3_UPLOADS_BUCKET_URL', rtrim( $site_s3_url, '/' ) );
	}
	// S3 API endpoint for non-AWS providers (R2, MinIO, ...). Leave empty for AWS itself.
	// Providers show the endpoint with the bucket on the end (R2's "S3 API" field does); the SDK
	// adds the bucket itself, so strip it or every key gets a doubled "bucket/" prefix.
	$site_s3_endpoint = rtrim( (string) site_env( 'S3_UPLOADS_ENDPOINT', '' ), '/' );
	$site_s3_bucket_name = strtok( $site_s3_bucket, '/' );
	if ( $site_s3_endpoint && str_ends_with( $site_s3_endpoint, '/' . $site_s3_bucket_name ) ) {
		$site_s3_endpoint = substr( $site_s3_endpoint, 0, -strlen( '/' . $site_s3_bucket_name ) );
	}
	if ( $site_s3_endpoint ) {
		define( 'S3_UPLOADS_ENDPOINT', $site_s3_endpoint );
	}
	// Path-style requests (endpoint/bucket/key) for providers without per-bucket hostnames, MinIO included.
	define( 'S3_UPLOADS_PATH_STYLE', site_env_bool( 'S3_UPLOADS_PATH_STYLE', false ) );
	// Canned ACL sent with each object; "none" omits the header for providers that reject it.
	define( 'S3_UPLOADS_OBJECT_ACL', site_env( 'S3_UPLOADS_OBJECT_ACL', 'public-read' ) );
	// Media filenames are unique per upload, so long-lived caching is safe.
	define( 'S3_UPLOADS_HTTP_CACHE_CONTROL', 365 * 24 * 60 * 60 );
}

define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
define( 'WP_POST_REVISIONS', 10 );
define( 'EMPTY_TRASH_DAYS', 30 );

// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
