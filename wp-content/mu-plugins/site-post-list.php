<?php
/**
 * Plugin Name: Site Post List
 * Description: Replaces Twenty Twenty-Five's full-content query loop with an excerpt list, so the Blog page, archives, the author page and search results list articles instead of printing each one in full. Also available in the inserter as "List of posts (excerpts)".
 */

defined( 'ABSPATH' ) || exit;

// The two sentences the pattern shows to a reader. English is the source text;
// on a multilingual site they are translated at render time, see below.
const SITE_POST_LIST_MORE_TEXT  = 'Read the article';
const SITE_POST_LIST_NO_RESULTS = 'Nothing here yet.';

// ---------------------------------------------------------------------------
// Twenty Twenty-Five's query-loop pattern (used by its Blog, archive, index and
// search templates) prints every post's full content, which turns every one of
// those URLs into a wall of complete articles. Re-register that slug, after the
// theme has, with an excerpt list; our own slug is the same list, in the inserter.
// ---------------------------------------------------------------------------
add_action(
	'init',
	static function (): void {
		site_post_list_register( SITE_POST_LIST_MORE_TEXT, SITE_POST_LIST_NO_RESULTS );

		// Offer the two sentences to whatever handles translation, if anything does.
		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( 'Post list: read more link', SITE_POST_LIST_MORE_TEXT, 'Site' );
			pll_register_string( 'Post list: no results', SITE_POST_LIST_NO_RESULTS, 'Site' );
		}
	},
	20
);

/*
 * Both sentences are baked into pattern markup, and the pattern is registered on
 * init -- before Polylang has resolved the request's language, which it only does
 * at parse_query. Calling pll__() up there would freeze the English version into
 * every page, so the front end re-registers the theme's slug once the language is
 * known. core/pattern reads the registry at render time, so the late registration
 * is the one that renders, while the init registration keeps the inserter variant
 * working inside the block editor's REST context, where this hook never fires.
 */
add_action(
	'wp',
	static function (): void {
		if ( is_admin() || ! function_exists( 'pll__' ) ) {
			return;
		}
		site_post_list_register(
			(string) pll__( SITE_POST_LIST_MORE_TEXT ),
			(string) pll__( SITE_POST_LIST_NO_RESULTS ),
			array( 'twentytwentyfive/template-query-loop' )
		);
	},
	1
);

/**
 * Registers the excerpt list under the given pattern slugs.
 *
 * @param string   $more_text  Label on the excerpt's read-more link.
 * @param string   $no_results Sentence shown when the query finds nothing.
 * @param string[] $slugs      Pattern slugs to register.
 */
function site_post_list_register(
	string $more_text,
	string $no_results,
	array $slugs = array( 'site/post-list', 'twentytwentyfive/template-query-loop' )
): void {
	$content = site_post_list_pattern_markup( $more_text, $no_results );

	foreach ( $slugs as $slug ) {
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $slug ) ) {
			unregister_block_pattern( $slug );
		}
		register_block_pattern(
			$slug,
			array(
				'title'       => 'List of posts (excerpts)',
				'description' => 'Date, title and excerpt per post, with pagination. Inherits the current query.',
				'categories'  => array( 'query' ),
				'blockTypes'  => array( 'core/query' ),
				'inserter'    => 'site/post-list' === $slug,
				'content'     => $content,
			)
		);
	}
}

/**
 * The pattern markup.
 *
 * The read-more label sits inside a block attribute, so it is JSON-encoded rather
 * than HTML-escaped; the no-results sentence is body text and is escaped as such.
 *
 * @param string $more_text  Label on the excerpt's read-more link.
 * @param string $no_results Sentence shown when the query finds nothing.
 */
function site_post_list_pattern_markup(
	string $more_text = SITE_POST_LIST_MORE_TEXT,
	string $no_results = SITE_POST_LIST_NO_RESULTS
): string {
	$template = <<<'HTML'
<!-- wp:query {"queryId":10,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true,"taxQuery":null,"parents":[]},"layout":{"type":"default"}} -->
<div class="wp-block-query">
	<!-- wp:post-template {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"default"}} -->
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"},"blockGap":"var:preset|spacing|30"},"border":{"bottom":{"color":"var:preset|color|accent-6","width":"1px"},"top":[],"right":[],"left":[]}},"layout":{"type":"default"}} -->
		<div class="wp-block-group" style="border-bottom-color:var(--wp--preset--color--accent-6);border-bottom-width:1px;padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
			<!-- wp:post-date {"isLink":true,"fontSize":"small"} /-->
			<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"x-large"} /-->
			<!-- wp:post-excerpt {"excerptLength":40,"moreText":%1$s,"fontSize":"medium"} /-->
		</div>
		<!-- /wp:group -->
	<!-- /wp:post-template -->

	<!-- wp:query-no-results -->
	<!-- wp:paragraph {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50"}}}} -->
	<p style="padding-top:var(--wp--preset--spacing--50)">%2$s</p>
	<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|70"}}},"layout":{"type":"default"}} -->
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--70)">
		<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} -->
			<!-- wp:query-pagination-previous /-->
			<!-- wp:query-pagination-numbers /-->
			<!-- wp:query-pagination-next /-->
		<!-- /wp:query-pagination -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:query -->
HTML;

	return sprintf(
		$template,
		(string) wp_json_encode( $more_text, JSON_UNESCAPED_UNICODE ),
		esc_html( $no_results )
	);
}
