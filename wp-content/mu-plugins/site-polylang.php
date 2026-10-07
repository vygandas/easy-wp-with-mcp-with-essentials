<?php
/**
 * Plugin Name: Site Polylang
 * Description: The front-end half of a Polylang setup with the default language unprefixed: the x-default hreflang Polylang will not emit once the default language code is hidden, the content language in the GTM dataLayer, and no language cookie on HTML that ought to be cacheable. Idle unless Polylang is active.
 *
 * The MCP half lives in site-polylang-abilities.php; this file is the front-end
 * part and depends on the URL scheme below, so the two are separate. Nothing
 * here edits site-gtm.php either: that file owns the container and exposes
 * site_gtm_data_layer for precisely this kind of addition, and it has to keep
 * working on a site with no Polylang.
 *
 * The URL scheme this file assumes, set once per environment under
 * Languages -> Settings -> URL modifications: the language code lives in the
 * directory name, it is hidden for the default language, and browser detection
 * is off. The default language (English in the examples below) therefore stays
 * at /<slug>/ and every other language lives under its code, such as /lt/<slug>/
 * for Lithuanian, which means a page's language is a pure
 * function of its path -- the only shape that stays cacheable at the Cloudflare
 * edge, and the reason the two settings below are safe.
 *
 * Filters: site_polylang_x_default.
 */

defined( 'ABSPATH' ) || exit;

/*
 * No language cookie.
 *
 * Polylang defines PLL_COOKIE on plugins_loaded priority 1 and only if nothing
 * got there first, so an mu-plugin, included before any plugin loads, is early
 * enough to switch it off; wp-config.php stays free of a value that is not
 * per-environment.
 *
 * The cookie exists to remember a visitor's choice for the browser-detection
 * redirect on the site root. With detection off and the default language hidden,
 * PLL_Choose_Lang::get_home_language() returns the default language outright and
 * never reads the cookie, so all it does is put a Set-Cookie header on the first
 * HTML response of every session: a cache-buster the day HTML is cached at the
 * edge, and one more row for Cookiebot to classify. Remove this define in the
 * same change that turns browser detection on; the two belong together.
 */
defined( 'PLL_COOKIE' ) || define( 'PLL_COOKIE', false );

/**
 * Supplies the x-default hreflang, which Polylang will not.
 *
 * PLL_Frontend_Filters_Links::wp_head() emits x-default on the front page only,
 * and only while the default language's code is *visible* in its URLs. Hiding
 * that code is the whole point of this URL scheme, so on this site Polylang emits
 * x-default nowhere at all, and Google is left guessing which of two equal
 * alternates to serve a reader it cannot place. Point it at the English URL of
 * the same view, on every translated view rather than only the home page: the
 * default language is the one a reader in an unlisted locale should land on, and
 * there is no language-selector page to point at instead.
 *
 * Polylang only runs this filter when it found more than one translation, so a
 * page with no Lithuanian version still emits no hreflang block and gains no
 * x-default. That is correct: there is nothing to choose between.
 *
 * The keys Polylang builds are bare subtags ('en', 'lt') whenever the primary
 * subtags differ, which they do here; the locale form is checked as well, so
 * adding a second English or a second Lithuanian later degrades to a no-op
 * instead of announcing the wrong x-default.
 *
 * @param mixed $hreflangs URLs keyed by hreflang value.
 * @return mixed
 */
add_filter(
	'pll_rel_hreflang_attributes',
	static function ( $hreflangs ) {
		if ( ! is_array( $hreflangs ) || isset( $hreflangs['x-default'] ) || ! function_exists( 'pll_default_language' ) ) {
			return $hreflangs;
		}

		foreach ( array( (string) pll_default_language( 'slug' ), (string) pll_default_language( 'w3c' ) ) as $key ) {
			if ( '' !== $key && isset( $hreflangs[ $key ] ) ) {
				/**
				 * Filters the URL announced as x-default.
				 *
				 * @param string $url       Default-language URL for this view.
				 * @param array  $hreflangs Every alternate Polylang is about to print.
				 */
				$hreflangs['x-default'] = (string) apply_filters( 'site_polylang_x_default', $hreflangs[ $key ], $hreflangs );
				break;
			}
		}

		return $hreflangs;
	}
);

/**
 * Tells GTM which language the visitor is reading.
 *
 * GTM cannot work this out for itself. GA4's own `language` parameter is the
 * visitor's browser setting, and on a Lithuanian article read by someone whose
 * browser is English the two disagree, which turns "how is the Lithuanian blog
 * doing" into noise. The URL is no substitute either: Lithuanian pages carry a
 * /lt/ prefix but English pages carry nothing, so the only trigger available is
 * "the path does not start with /lt/", which starts lying the first time a third
 * language, an /lt-something/ slug or a language-less system URL appears. This is
 * the same reasoning that already put userRole in the dataLayer: facts the URL
 * does not state belong here.
 *
 * The slug ('en', 'lt') rather than the locale or the display name, because the
 * slug is what the path segment and the hreflang attribute both use, so a GTM
 * report and a Search Console filter line up with no lookup table. Terms are left
 * alone: each language has its own categories, so postCategories reads "Guides"
 * on one page and "Vadovai" on its twin, and the honest fix is to segment by
 * language in GTM rather than to rewrite what the page actually says.
 *
 * Taken from the request rather than from the queried post, so archives, search
 * and the 404 carry it too; on a single view the two agree by construction.
 *
 * @param array $data dataLayer contents.
 * @return array
 */
add_filter(
	'site_gtm_data_layer',
	static function ( array $data ): array {
		if ( ! function_exists( 'pll_current_language' ) || empty( $GLOBALS['polylang'] ) ) {
			return $data;
		}

		// False on any request where Polylang never resolved a language. Say
		// nothing rather than guessing the default: a wrong language is worse
		// than a missing one.
		$slug = pll_current_language( 'slug' );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return $data;
		}

		$data['contentLanguage'] = $slug;

		return $data;
	}
);
