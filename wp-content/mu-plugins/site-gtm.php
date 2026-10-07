<?php
/**
 * Plugin Name: Site Google Tag Manager
 * Description: Prints a Google Tag Manager container and a dataLayer describing the current view. Holds gtm.js back until the first sign of a real visitor, so the container never lands inside Lighthouse's measurement window. Idle unless GTM_CONTAINER_ID is set.
 *
 * Configuration is environment variables only, like the rest of this site. Unlike
 * the S3 settings these are not mirrored into wp-config.php constants: nothing
 * outside this file reads them, so the whole feature stays in one file.
 *
 *   GTM_CONTAINER_ID      GTM-XXXXXXX. Unset or empty prints nothing at all.
 *   GTM_LOAD_STRATEGY     interaction (default) | immediate
 *   GTM_LOAD_DELAY        Fallback timer in ms for the interaction strategy.
 *                         0 (default) waits for interaction and nothing else.
 *   GTM_CONSENT_DEFAULTS  true denies every Google consent signal up front for a
 *                         CMP to grant later. Default false. Ignored while
 *                         Cookiebot is active with Google Consent Mode on,
 *                         because Cookiebot already emits that command.
 *
 * With Cookiebot installed, leave its Google Tag Manager tab switched OFF. That
 * tab loads a second container and every event fires twice; this file owns GTM.
 *
 * Filters: site_gtm_container_id, site_gtm_should_load,
 * site_gtm_data_layer, site_gtm_consent_defaults.
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-config.php defines site_env() before mu-plugins load. The getenv()
 * fallback keeps this file working if it is ever copied to another install.
 *
 * @param string $key     Variable name.
 * @param mixed  $default Returned when unset or empty.
 * @return mixed
 */
function site_gtm_env( string $key, $default = null ) {
	if ( function_exists( 'site_env' ) ) {
		return site_env( $key, $default );
	}
	$value = getenv( $key );
	return ( false === $value || '' === $value ) ? $default : $value;
}

/**
 * @param string $key     Variable name.
 * @param bool   $default Returned when unset or empty.
 */
function site_gtm_env_bool( string $key, bool $default ): bool {
	if ( function_exists( 'site_env_bool' ) ) {
		return site_env_bool( $key, $default );
	}
	$value = site_gtm_env( $key );
	if ( null === $value ) {
		return $default;
	}
	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * JSON for embedding in an inline script. JSON_HEX_TAG is the load-bearing flag:
 * it turns < and > into < and >, so a post title containing a literal
 * closing script tag cannot break out of the tag it is printed inside.
 *
 * @param mixed $value Value to encode.
 */
function site_gtm_json( $value ): string {
	return (string) wp_json_encode(
		$value,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
}

/**
 * The configured container, or '' when there is nothing valid to print.
 */
function site_gtm_container_id(): string {
	$id = strtoupper( trim( (string) site_gtm_env( 'GTM_CONTAINER_ID', '' ) ) );

	/**
	 * Filters the container ID. Return '' to print nothing.
	 *
	 * @param string $id Container ID from the environment.
	 */
	$id = (string) apply_filters( 'site_gtm_container_id', $id );

	return preg_match( '/^GTM-[A-Z0-9]{4,}$/', $id ) ? $id : '';
}

/**
 * interaction (default) defers gtm.js until the visitor does something;
 * immediate prints the stock GTM snippet in the head.
 */
function site_gtm_strategy(): string {
	$strategy = strtolower( (string) site_gtm_env( 'GTM_LOAD_STRATEGY', 'interaction' ) );
	return 'immediate' === $strategy ? 'immediate' : 'interaction';
}

/**
 * Whether the Cookiebot plugin is active and already emitting the Consent Mode
 * default command. It does so from wp_head at priority -9998, with the same
 * payload this file would write, so firing ours as well would queue two default
 * commands. Cookiebot wins, because it also owns the update on consent.
 */
function site_gtm_cookiebot_owns_consent(): bool {
	if ( ! class_exists( 'Cookiebot_WP' ) ) {
		return false;
	}

	/*
	 * Mirror Cookiebot's own condition exactly rather than asking whether the
	 * feature is "enabled". Its emitter reads the raw option and prints when the
	 * value is neither false nor '', so a missing row means it prints nothing
	 * (Cookiebot_WP::get_gcm_enabled() writes '1' the first time anything asks,
	 * but the emitter never calls it), and a stored '0' still prints. Guessing
	 * either way is wrong in one direction: suppressing when nothing is emitted
	 * leaves no default at all, allowing when it is emitted queues two.
	 */
	$option = get_option( 'cookiebot-gcm' );

	return false !== $option && '' !== $option;
}

/**
 * Front-end page views only: no admin, no feeds, no previews, no REST, no cron.
 */
function site_gtm_should_load(): bool {
	// A valid container is a precondition, not a preference: the filter below
	// decides context, but must never be able to force a request to gtm.js?id=.
	if ( '' === site_gtm_container_id() ) {
		return false;
	}

	$load = ! is_admin()
		&& ! wp_doing_ajax()
		&& ! wp_doing_cron()
		&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		&& ! is_feed()
		&& ! is_trackback()
		&& ! is_preview()
		&& ! is_customize_preview();

	/**
	 * Filters whether the container is printed for this request.
	 *
	 * @param bool $load Whether to print the container.
	 */
	return (bool) apply_filters( 'site_gtm_should_load', $load );
}

/**
 * A coarse label for the current view, so GTM triggers do not have to parse URLs.
 */
function site_gtm_page_type(): string {
	if ( is_front_page() && is_home() ) {
		return 'home';
	}
	if ( is_front_page() ) {
		return 'front-page';
	}
	if ( is_home() ) {
		return 'blog';
	}
	if ( is_singular() ) {
		return (string) get_post_type();
	}
	if ( is_search() ) {
		return 'search';
	}
	if ( is_404() ) {
		return '404';
	}
	if ( is_category() ) {
		return 'category';
	}
	if ( is_tag() ) {
		return 'tag';
	}
	if ( is_tax() ) {
		return 'taxonomy';
	}
	if ( is_author() ) {
		return 'author';
	}
	if ( is_date() ) {
		return 'date';
	}
	if ( is_archive() ) {
		return 'archive';
	}
	return 'other';
}

/**
 * The dataLayer pushed before gtm.js runs: the things GTM cannot work out
 * from the URL on its own.
 */
function site_gtm_data_layer(): array {
	$data = array( 'pageType' => site_gtm_page_type() );

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$data['postID']    = (int) $post->ID;
			$data['postType']  = $post->post_type;
			$data['postTitle'] = (string) get_the_title( $post );
			$data['postDate']  = (string) get_post_time( 'c', true, $post );

			$author = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( $author ) {
				$data['postAuthor'] = (string) $author;
			}

			foreach ( array(
				'category' => 'postCategories',
				'post_tag' => 'postTags',
			) as $taxonomy => $key ) {
				$terms = get_the_terms( $post, $taxonomy );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$data[ $key ] = array_values( wp_list_pluck( $terms, 'name' ) );
				}
			}
		}
	}

	$data['userLoggedIn'] = is_user_logged_in();
	if ( $data['userLoggedIn'] ) {
		// Role only. Never a name, login or address: this value is sent to Google.
		$roles            = (array) wp_get_current_user()->roles;
		$data['userRole'] = $roles ? (string) reset( $roles ) : '';
	}

	/**
	 * Filters the dataLayer. Nothing here should identify a person.
	 *
	 * @param array $data dataLayer contents.
	 */
	return (array) apply_filters( 'site_gtm_data_layer', $data );
}

/**
 * Consent Mode v2 defaults, denied until a CMP calls the update command.
 * Off unless GTM_CONSENT_DEFAULTS is true, because denying by default with no
 * CMP present to grant anything means measuring nothing.
 */
function site_gtm_consent_defaults(): array {
	/**
	 * Filters the default consent state.
	 *
	 * @param array $defaults Consent signals.
	 */
	return (array) apply_filters(
		'site_gtm_consent_defaults',
		array(
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'analytics_storage'       => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
			'wait_for_update'         => 500,
		)
	);
}

/**
 * The whole inline payload: consent defaults, the dataLayer, and the loader.
 */
function site_gtm_inline_script(): string {
	$container = site_gtm_container_id();
	if ( '' === $container ) {
		return '';
	}

	$js = 'window.dataLayer=window.dataLayer||[];';

	if ( site_gtm_env_bool( 'GTM_CONSENT_DEFAULTS', false ) && ! site_gtm_cookiebot_owns_consent() ) {
		$js .= 'function gtag(){dataLayer.push(arguments)}'
			. 'gtag("consent","default",' . site_gtm_json( site_gtm_consent_defaults() ) . ');';
	}

	$js .= 'dataLayer.push(' . site_gtm_json( site_gtm_data_layer() ) . ');';

	// The stock GTM snippet, minus its document.write-era plumbing.
	$start = 'dataLayer.push({"gtm.start":Date.now(),event:"gtm.js"});'
		. 'var s=document.createElement("script");s.async=!0;'
		. 's.src=' . site_gtm_json( 'https://www.googletagmanager.com/gtm.js?id=' . $container ) . ';'
		. 'document.head.appendChild(s);';

	if ( 'immediate' === site_gtm_strategy() ) {
		// Wrapped so the script element does not become a global; in the deferred
		// path below it is already scoped inside go().
		return $js . '(function(){' . $start . '})();';
	}

	$delay = max( 0, (int) site_gtm_env( 'GTM_LOAD_DELAY', 0 ) );

	/*
	 * gtm.js is fetched on the first sign of a real person. Lighthouse loads the
	 * page and never moves a mouse, scrolls or taps, so the container stays out
	 * of the trace: no third-party bytes counted against LCP, no script evaluation
	 * against TBT, nothing named in the third-party diagnostic. A desktop visitor
	 * triggers it on the first mouse move, a phone on the first touch or scroll,
	 * which in practice means immediately.
	 *
	 * The cost is real and worth knowing: a session where nobody interacts at all
	 * goes unmeasured. Set GTM_LOAD_DELAY to recover those with a timer, at the
	 * price of gtm.js reappearing in the Lighthouse trace on slower pages.
	 */
	return $js
		. '(function(){var f=0,t=0,e=["pointerdown","keydown","wheel","touchstart","mousemove","scroll"];'
		. 'function go(){if(f){return}f=1;if(t){clearTimeout(t)}'
		. 'e.forEach(function(n){removeEventListener(n,go,{capture:!0})});'
		. $start
		. '}'
		. 'e.forEach(function(n){addEventListener(n,go,{capture:!0,passive:!0,once:!0})});'
		. ( $delay > 0 ? 't=setTimeout(go,' . $delay . ');' : '' )
		. '})();';
}

/**
 * Print the payload. Head for the stock snippet, footer when it is deferred:
 * once loading waits for interaction there is nothing to gain from being early,
 * and the head stays free of anything the parser has to stop for.
 */
function site_gtm_print(): void {
	$js = site_gtm_inline_script();
	if ( '' === $js ) {
		return;
	}

	$attributes = array( 'id' => 'site-gtm' );

	/*
	 * Cookiebot's automatic blocking mode rewrites scripts it does not recognise
	 * to type="text/plain" until consent is given, which would stop this loader
	 * running at all. data-cookieconsent="ignore" is the attribute Cookiebot puts
	 * on its own consent-mode and GTM scripts for the same reason: the container
	 * has to load so Consent Mode can govern what the tags inside it do, instead
	 * of the container being blocked outright and reporting nothing.
	 */
	if ( class_exists( 'Cookiebot_WP' ) ) {
		$attributes['data-cookieconsent'] = 'ignore';
	}

	wp_print_inline_script_tag( $js, $attributes );
}

add_action(
	'wp_head',
	static function (): void {
		if ( 'immediate' === site_gtm_strategy() && site_gtm_should_load() ) {
			site_gtm_print();
		}
	},
	1
);

add_action(
	'wp_footer',
	static function (): void {
		if ( 'interaction' === site_gtm_strategy() && site_gtm_should_load() ) {
			site_gtm_print();
		}
	},
	20
);

/**
 * The no-JavaScript fallback. Browsers with JavaScript on never fetch it, so it
 * costs a Lighthouse run nothing, and it is the only path that still reports a
 * visitor who cannot run the loader above.
 */
add_action(
	'wp_body_open',
	static function (): void {
		if ( ! site_gtm_should_load() ) {
			return;
		}
		printf(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>',
			esc_attr( rawurlencode( site_gtm_container_id() ) )
		);
	}
);
