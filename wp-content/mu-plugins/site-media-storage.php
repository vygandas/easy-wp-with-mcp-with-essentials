<?php
/**
 * Plugin Name: Site Media Storage
 * Description: Connects the S3 Uploads plugin to any S3-compatible bucket: loads its vendored AWS SDK, applies the custom endpoint, path style and ACL policy from wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

// S3 Uploads expects the AWS SDK to be autoloaded before it runs; the SDK is vendored
// inside the plugin directory (Composer, trimmed to the S3 service). mu-plugins load first.
$site_s3_autoload = WP_PLUGIN_DIR . '/s3-uploads/vendor/autoload.php';
if ( ! class_exists( 'Aws\\S3\\S3Client' ) && is_readable( $site_s3_autoload ) ) {
	require_once $site_s3_autoload;
}
unset( $site_s3_autoload );

/**
 * Production without a bucket means uploads land on the ephemeral container disk
 * and vanish on the next deploy (it happened on 2026-09-06). Say so on every admin screen.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( 'production' !== wp_get_environment_type() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( ! defined( 'S3_UPLOADS_BUCKET' ) ) {
			$why = 'the S3_UPLOADS_* environment variables are not set';
		} elseif ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$why = 'the S3 Uploads plugin is not active';
		} else {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>Media storage is off:</strong> %s. Anything uploaded now goes to the container disk and is lost on the next deploy. See CLAUDE.md, section Media storage.</p></div>',
			esc_html( $why )
		);
	}
);

/**
 * Non-AWS providers need an explicit endpoint, and some (MinIO) need path-style requests.
 */
add_filter(
	's3_uploads_s3_client_params',
	static function ( array $params ): array {
		if ( defined( 'S3_UPLOADS_ENDPOINT' ) && S3_UPLOADS_ENDPOINT ) {
			$params['endpoint'] = S3_UPLOADS_ENDPOINT;
		}
		if ( defined( 'S3_UPLOADS_PATH_STYLE' ) && S3_UPLOADS_PATH_STYLE ) {
			$params['use_path_style_endpoint'] = true;
		}
		return $params;
	}
);

/**
 * S3_UPLOADS_OBJECT_ACL=none: send no x-amz-acl header at all. Providers such as
 * Cloudflare R2 have no per-object ACLs; visibility comes from the bucket instead.
 */
add_filter(
	's3_uploads_putObject_params',
	static function ( array $params ): array {
		if ( defined( 'S3_UPLOADS_OBJECT_ACL' ) && 'none' === S3_UPLOADS_OBJECT_ACL ) {
			unset( $params['ACL'] );
		}
		return $params;
	}
);
