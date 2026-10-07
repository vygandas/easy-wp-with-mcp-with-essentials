<?php
/**
 * Plugin Name: Site Defaults
 * Description: Site-wide defaults that must hold in every environment without database steps. Today: comments, pingbacks and trackbacks are off everywhere, regardless of per-post settings.
 *
 * Ships to production. Because these are filters, the Discussion settings
 * screen and the editor's Discussion panel show the values but changing them
 * there has no effect; change this file instead.
 */

defined( 'ABSPATH' ) || exit;

// New content starts closed, so the editor shows the real state.
add_filter( 'pre_option_default_comment_status', static fn() => 'closed' );
add_filter( 'pre_option_default_ping_status', static fn() => 'closed' );

// Existing content is closed too, whatever its stored status says, so no
// per-post database step is needed when this ships.
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );

// Nothing already in the database is shown, and the count reads zero.
add_filter( 'comments_array', static fn() => array(), 20 );
add_filter( 'get_comments_number', static fn() => 0, 20 );

// No comment feeds: /comments/feed/ and per-post comment feeds return 404.
add_filter( 'feed_links_show_comments_feed', '__return_false' );
add_filter( 'feed_links_show_posts_feed', '__return_true' );
add_action(
	'template_redirect',
	static function (): void {
		if ( is_comment_feed() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
);

// Direct posts to wp-comments-post.php are rejected before anything is stored.
add_filter(
	'pre_comment_on_post',
	static function (): void {
		wp_die( esc_html__( 'Comments are closed.' ), '', array( 'response' => 403 ) );
	}
);

// Keep the comment REST route read-only for anonymous clients: creation is
// blocked, existing rows stay reachable to logged-in users who can moderate.
add_filter(
	'rest_pre_insert_comment',
	static fn() => new WP_Error( 'rest_comments_closed', __( 'Comments are closed.' ), array( 'status' => 403 ) )
);

// Admin: no Comments menu and no admin-bar counter. The Discussion settings
// screen stays reachable for the other options it holds.
add_action(
	'admin_menu',
	static function (): void {
		remove_menu_page( 'edit-comments.php' );
	}
);
add_action(
	'admin_bar_menu',
	static function ( WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'comments' );
	},
	999
);
