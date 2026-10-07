<?php
/**
 * Site theme functions.
 *
 * Child of Twenty Twenty-Five. Deliberately minimal: the parent supplies every
 * template, pattern, block style and design token, and this file exists only to
 * give overrides somewhere to live. Keep it that way — a rule that fits in
 * theme.json, a template file or a per-block stylesheet does not belong here.
 *
 * @package Site
 */

defined( 'ABSPATH' ) || exit;

/**
 * Child stylesheet, loaded after the parent's.
 *
 * The parent enqueues itself as 'twentytwentyfive-style' through
 * get_parent_theme_file_uri(), so it still loads under a child theme; this
 * declares that handle as a dependency so cascade order is not a coincidence.
 * Versioned by file mtime, so a deploy never serves a stale copy and the
 * version never has to be bumped by hand.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$path = get_stylesheet_directory() . '/style.css';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_style(
			'site-style',
			get_stylesheet_uri(),
			array( 'twentytwentyfive-style' ),
			(string) filemtime( $path )
		);
	},
	20
);

/**
 * Same stylesheet inside the editor, so the canvas matches the front end.
 * add_editor_style() resolves child-first, so this picks up our file and leaves
 * the parent's editor stylesheet in place.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		add_editor_style( 'style.css' );
	}
);

/*
 * Per-block CSS goes here, one call per block, so a stylesheet only loads on
 * pages where that block actually renders:
 *
 *     add_action( 'init', static function (): void {
 *         wp_enqueue_block_style(
 *             'core/quote',
 *             array(
 *                 'handle' => 'site-quote',
 *                 'src'    => get_stylesheet_directory_uri() . '/assets/blocks/quote.css',
 *                 'path'   => get_stylesheet_directory() . '/assets/blocks/quote.css',
 *             )
 *         );
 *     } );
 *
 * Custom block styles (the style variations in the block sidebar) go through
 * register_block_style() on 'init'; the parent registers 'checkmark-list' that
 * way and is worth copying as a model.
 */
