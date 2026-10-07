<?php
/**
 * Plugin Name: Site Content Styles
 * Description: Article-body styling for post and page content wherever it renders on the front end (single views, the Blog page, author and other archives): section rhythm, links, quotes, tables, code, media, and the optional key-takeaways and table-of-contents boxes. Ships site-content.css from this directory.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( is_admin() ) {
			return;
		}
		$path = __DIR__ . '/site-content.css';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_style(
			'site-content',
			WPMU_PLUGIN_URL . '/site-content.css',
			array( 'global-styles' ),
			(string) filemtime( $path )
		);
	},
	20
);
