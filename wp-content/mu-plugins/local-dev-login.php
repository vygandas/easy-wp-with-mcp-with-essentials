<?php
/**
 * Plugin Name: Local Dev Auto-Login
 * Description: Signs the administrator in when a browser navigates to /wp-login.php?local-login=<LOCAL_LOGIN_TOKEN>. Works only inside the local docker compose stack and is never shipped to production.
 *
 * Why: local testing, by you or by Claude driving Chrome, should never
 * require typing the admin password.
 *
 * Perimeter, in order of importance:
 *  1. .dockerignore excludes this file from the production image, so a
 *     deployed site never has it. Locally it arrives through the bind mount.
 *  2. docker-compose.yml sets WP_LOCAL_STACK=1 on the wordpress service.
 *     That marker lives only in the compose file, never in .env, so copying
 *     .env to another environment cannot carry it.
 *  3. WP_ENVIRONMENT_TYPE must be "local", and both the configured site URL
 *     and the requested Host must be local names.
 *  4. The URL must carry LOCAL_LOGIN_TOKEN from .env.
 *  5. The request must look like a top-level browser navigation
 *     (Sec-Fetch-Mode: navigate, Sec-Fetch-Dest: document). This only stops
 *     cross-site images, fetches and iframes fired from a browser; a scripted
 *     client can send these headers, so it is not a security boundary.
 *
 * The network boundary is docker-compose.yml publishing ports on 127.0.0.1 only.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'login_init', 'site_local_dev_login' );

function site_local_dev_login(): void {
	if ( ! isset( $_GET['local-login'] ) ) {
		return;
	}

	// Only the plain login screen. Never hijack logout, lost/reset password or
	// any other wp-login.php action (login_init fires before their dispatch).
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
	if ( 'login' !== $action || isset( $_GET['key'] ) ) {
		return;
	}

	// Guards 2 and 3: silently inert anywhere but the local compose stack.
	if ( '1' !== getenv( 'WP_LOCAL_STACK' ) || 'local' !== wp_get_environment_type() ) {
		return;
	}
	// wp-login.php and wp-admin live at site_url(), so that is where the cookies must land.
	$site_host    = site_local_dev_login_host( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
	$request_host = site_local_dev_login_host( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	if ( ! site_local_dev_login_is_local_host( $site_host ) || ! site_local_dev_login_is_local_host( $request_host ) ) {
		return;
	}

	// Guard 4: token. WordPress magic-quotes $_GET during bootstrap, hence wp_unslash().
	$expected = (string) getenv( 'LOCAL_LOGIN_TOKEN' );
	if ( strlen( $expected ) < 16 ) {
		site_local_dev_login_refuse( 'Set LOCAL_LOGIN_TOKEN (16+ URL-safe characters, e.g. "openssl rand -hex 16") in .env and run "docker compose up -d".', 500 );
	}
	$given = is_string( $_GET['local-login'] ) ? wp_unslash( $_GET['local-login'] ) : '';
	if ( ! hash_equals( $expected, $given ) ) {
		site_local_dev_login_refuse( 'Wrong or missing local-login token. Use the value of LOCAL_LOGIN_TOKEN from .env, or run docker/login.ps1.', 403 );
	}

	// Guard 5: top-level navigation only.
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
		|| 'navigate' !== ( $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '' )
		|| 'document' !== ( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' )
	) {
		site_local_dev_login_refuse( 'Local auto-login only works as a top-level browser navigation.', 403 );
	}

	$redirect_to = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] )
		? wp_unslash( $_GET['redirect_to'] )
		: admin_url();

	// Auth cookies are scoped to the request host. Reached through an alternate
	// local name (127.0.0.1, easy-wp.test)? Hop to the canonical host first
	// so the cookies land where wp-admin will look for them.
	if ( $request_host !== $site_host ) {
		$url = wp_login_url() . '?local-login=' . rawurlencode( $given );
		if ( isset( $_GET['redirect_to'] ) ) {
			$url .= '&redirect_to=' . rawurlencode( $redirect_to );
		}
		wp_safe_redirect( $url );
		exit;
	}

	$login = (string) getenv( 'LOCAL_LOGIN_USER' );
	if ( '' !== $login ) {
		$user = get_user_by( 'login', $login ) ?: get_user_by( 'email', $login );
		if ( ! $user ) {
			site_local_dev_login_refuse( sprintf( 'LOCAL_LOGIN_USER "%s" does not exist.', $login ), 500 );
		}
	} else {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
			)
		);
		$user   = $admins[0] ?? null;
		if ( ! $user ) {
			site_local_dev_login_refuse( 'No administrator account exists yet; run the WordPress install first.', 500 );
		}
	}

	// Always (re)issue the cookies: an existing session for another account,
	// or a stale auth cookie, must not short-circuit the admin sign-in.
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( $redirect_to );
	exit;
}

/** Lower-case hostname without port or IPv6 brackets. */
function site_local_dev_login_host( string $host ): string {
	$host = strtolower( trim( $host ) );
	if ( str_starts_with( $host, '[' ) ) {
		$end = strpos( $host, ']' );
		return false === $end ? $host : substr( $host, 1, $end - 1 );
	}
	return (string) preg_replace( '/:\d+$/', '', $host );
}

function site_local_dev_login_is_local_host( string $host ): bool {
	return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
		|| str_ends_with( $host, '.localhost' )
		|| str_ends_with( $host, '.test' );
}

function site_local_dev_login_refuse( string $message, int $code ): void {
	wp_die( esc_html( $message ), 'Local auto-login refused', array( 'response' => $code ) );
}
