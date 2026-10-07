<?php
/**
 * Plugin Name: Site Polylang Abilities
 * Description: Teaches the site/* content abilities about Polylang: create-content, update-content and create-term take a language and a translation link, list-content and list-terms filter by language, and every read reports the language and the sibling translations. Inert unless Polylang is active with languages defined.
 * Version: 1.0.0
 *
 * WHY THIS FILE EXISTS
 *
 * site-abilities.php discovers what a site offers with
 * get_post_types( array( 'public' => true ) ) and
 * get_taxonomies( array( 'public' => true ) ). Polylang registers both of its
 * bookkeeping taxonomies with 'public' => false -- `language` in
 * src/translated-post.php and src/translatable-object.php, `post_translations`
 * in src/translated-object.php -- so neither is visible to those abilities. An
 * agent publishing through MCP can therefore neither say what language a post is
 * in nor link an English post to its Lithuanian translation, and worse than
 * being unable to say: PLL_CRUD_Posts runs in REST too and its save_post handler
 * stamps every post that arrives without a language with the DEFAULT language.
 * A Lithuanian article published over MCP would silently become an English one,
 * with nothing in any response admitting it.
 *
 * WHY NOT THE OBVIOUS FIX
 *
 * Adding `language` to the site_abilities_taxonomies filter looks like the seam
 * and is a trap. Terms there are assigned with wp_set_post_terms(), which writes
 * the term relationship and stops, while PLL_Translated_Object::set_language()
 * additionally pulls the object out of its old translation group and bumps
 * Polylang's object cache. Skipping that leaves a post whose `language` term
 * says `lt` inside a translation group whose serialised description still lists
 * it as the `en` member -- and the read path validates translations with context
 * 'display', which deliberately does not re-check languages, so the lie is never
 * noticed. Two further hazards: resolve_terms() creates any term it cannot find
 * by name, and a stray term in the `language` taxonomy is how Polylang stores a
 * language, so one typo grows a half-built language with no metas; and that
 * taxonomy is registered with no `capabilities` argument, so its edit_terms cap
 * falls back to manage_categories, which an Editor has. `language` therefore
 * stays hidden and every write in this file goes through Polylang's own API.
 *
 * WHY IT IS A SEPARATE FILE
 *
 * site-abilities.php must stay copyable to the next site, and a Polylang bridge
 * is not universal: most installs have no Polylang. This file is the same bargain
 * one step further out -- nothing in it names a site, a company or a domain, so
 * it can be dropped beside site-abilities.php on any Polylang install and the
 * same tool names keep working.
 *
 * The seam used here is core's own: WP_Abilities_Registry::register() applies
 * `wp_register_ability_args` before the ability is constructed, which is enough
 * to widen an input schema and wrap an execute callback in one place. That
 * matters because the language has to be applied at a specific moment during a
 * write, not merely reported afterwards, and no output-only filter can do that.
 *
 * WHAT WOULD MAKE IT UNNECESSARY
 *
 * Not Polylang making `language` public -- the bookkeeping problem above would
 * remain. What retires this file is Polylang shipping abilities of its own, or
 * WordPress growing a core notion of content language that site-abilities.php
 * could discover generically.
 *
 * Filters: site_polylang_abilities_enabled, site_polylang_languages.
 */

defined( 'ABSPATH' ) || exit;

// The Abilities API arrived in WordPress 6.9. On anything older site-abilities.php
// is inert as well, so there is nothing here to bridge.
if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

add_filter( 'wp_register_ability_args', array( 'Site_Polylang_Abilities', 'extend_ability' ), 10, 2 );

/**
 * Bridges Polylang and the site/* content abilities.
 */
final class Site_Polylang_Abilities {

	/** Abilities this file wraps. Everything else passes through untouched. */
	const ABILITIES = array(
		'site/describe-content-model',
		'site/list-content',
		'site/get-content',
		'site/create-content',
		'site/update-content',
		'site/list-terms',
		'site/create-term',
	);

	/** Language to stamp on the post site/create-content is inserting. One shot. */
	private static ?string $pending_post_language = null;

	/** Language to stamp on the term site/create-term is inserting. One shot. */
	private static ?string $pending_term_language = null;

	/**
	 * Language every implicit choice made during one content write must follow.
	 *
	 * Sticky for the whole of a create-content or update-content call, unlike the
	 * two one-shot values above, because a single call can make several of those
	 * choices: the default category, and one term per name the agent sent that does
	 * not exist yet. Set by begin_content_write(), cleared by end_content_write().
	 */
	private static ?string $content_language = null;

	/** Language constraining the WP_Query site/list-content is about to run. */
	private static ?string $pending_post_filter = null;

	/** Language constraining the WP_Term_Query site/list-terms is about to run. */
	private static ?string $pending_term_filter = null;

	// -----------------------------------------------------------------------
	// Registration.
	// -----------------------------------------------------------------------

	/**
	 * Widens the input schema and wraps the execute callback of the abilities above.
	 *
	 * Runs for every ability registered on the site, core's and other plugins'
	 * included, so it gets out of the way immediately for anything it does not own.
	 *
	 * @param mixed  $args Ability registration arguments.
	 * @param string $name Ability name, namespace included.
	 * @return mixed
	 */
	public static function extend_ability( $args, $name ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || ! in_array( $name, self::ABILITIES, true ) ) {
			return $args;
		}

		/**
		 * Filters whether this bridge attaches itself to an ability.
		 *
		 * @param bool   $enabled Whether to wrap the ability.
		 * @param string $name    Ability name.
		 */
		if ( ! apply_filters( 'site_polylang_abilities_enabled', true, $name ) ) {
			return $args;
		}

		$extra = self::extra_properties( $name );
		if ( $extra && isset( $args['input_schema']['properties'] ) && is_array( $args['input_schema']['properties'] ) ) {
			$args['input_schema']['properties'] = array_merge( $args['input_schema']['properties'], $extra );
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original                 = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = array() ) use ( $original, $name ) {
				return self::run( $name, $original, is_array( $input ) ? $input : array() );
			};
		}

		return $args;
	}

	/**
	 * The input properties this file adds, per ability.
	 *
	 * The schema is discovery sugar. Every value is validated again at execution
	 * time against the live language list, which is the check that protects the
	 * data; the schema only has to be right often enough for an agent reading the
	 * tool definition to guess correctly. On a site with no languages nothing is
	 * advertised at all, so the abilities look exactly as site-abilities.php
	 * registered them -- but the execute wrapper stays attached either way, because
	 * a registry built before Polylang finished booting would otherwise lose the
	 * read side as well, and none of those schemas forbid extra properties.
	 *
	 * @param string $name Ability name.
	 * @return array<string, array<string, mixed>>
	 */
	private static function extra_properties( string $name ): array {
		$languages = self::languages();
		$slugs     = array_keys( $languages );

		if ( ! $slugs ) {
			return array();
		}

		$default = self::default_slug();
		$suffix  = ' This site has: ' . implode( ', ', $slugs ) . '.'
			. ( '' !== $default ? ' Omitted means "' . $default . '", the default language.' : '' );

		$language = array(
			'type'        => 'string',
			'enum'        => $slugs,
			'description' => 'Content language, as a language code.' . $suffix,
		);
		$filter   = array(
			'type'        => 'string',
			'enum'        => $slugs,
			'description' => 'Return only items in this language. Omitted returns every language.'
				. ' This site has: ' . implode( ', ', $slugs ) . '.',
		);

		switch ( $name ) {
			case 'site/create-content':
				return array(
					'language'       => $language,
					'translation_of' => array(
						'type'        => 'integer',
						'description' => 'ID of an existing item this one translates. Both must be the same post type and different languages. Send language as well, or the item is created in the default language and the link is refused.',
					),
				);

			case 'site/update-content':
				$language['description'] .= ' Changing it takes the item out of its current translation group, and is applied before the rest of the update so that any terms sent in the same call are stored in the new language. Refused when the item is already grouped with another item in that language; unlink it first. If the rest of the update then fails, the error says the language was applied.';
				return array(
					'language'       => $language,
					'translation_of' => array(
						'type'        => 'integer',
						'description' => 'ID of an existing item this one translates, or 0 to unlink this item from its translation group. Unlinking leaves the other items in the group linked to one another.',
					),
				);

			case 'site/create-term':
				return array(
					'language'       => $language,
					'translation_of' => array(
						'type'        => 'integer',
						'description' => 'ID of an existing term in the same taxonomy that this term translates. Send language as well.',
					),
				);

			case 'site/list-content':
			case 'site/list-terms':
				return array( 'language' => $filter );
		}

		return array();
	}

	// -----------------------------------------------------------------------
	// Execution.
	// -----------------------------------------------------------------------

	/**
	 * Dispatches to the per-ability wrapper.
	 *
	 * @param string   $name     Ability name.
	 * @param callable $original The ability's own execute callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function run( string $name, callable $original, array $input ) {
		switch ( $name ) {
			case 'site/create-content':
				return self::create_content( $original, $input );

			case 'site/update-content':
				return self::update_content( $original, $input );

			case 'site/list-content':
				return self::list_content( $original, $input );

			case 'site/get-content':
				return self::enrich_post( $original( $input ) );

			case 'site/list-terms':
				return self::list_terms( $original, $input );

			case 'site/create-term':
				return self::create_term( $original, $input );

			case 'site/describe-content-model':
				return self::describe( $original( $input ) );
		}

		return $original( $input );
	}

	/**
	 * site/create-content: stamp the language during the insert, link afterwards.
	 *
	 * The ordering is the whole difficulty, and it has two halves.
	 *
	 * The first is the language itself. create_content() inserts the post and only
	 * then assigns terms, and Polylang's set_object_terms handler rewrites every
	 * assigned term into the post's language, creating a translated term when none
	 * exists. A language applied after the ability returned would leave a Lithuanian
	 * post carrying English categories. Hooking save_post at priority 9 puts the
	 * language in place before Polylang's own save_post at priority 10 assigns the
	 * default one, and before any term the ability was asked for is written.
	 *
	 * The second is everything wp_insert_post() decides on its own, before save_post
	 * exists to be hooked. In WordPress 7.1's wp-includes/post.php the default
	 * category is chosen at line 4720 and written at line 5055, while save_post does
	 * not fire until line 5286 -- so at the moment the term relationship is created
	 * the post still has no language, PLL_CRUD_Posts::set_object_terms() hits its
	 * `if ( empty( $lang ) ) return;` guard at crud-posts.php:170 and leaves it
	 * alone, and apply_relations() never revisits a taxonomy the caller did not
	 * name. A Lithuanian post created with no categories therefore ended up filed
	 * under the English default one, appearing in the English category archive and
	 * feed with nothing in the response admitting it. Polylang's own correction,
	 * PLL_Default_Term::option_default_term() (default-term.php:79), cannot help:
	 * it is gated on `isset( $this->curlang )` and an MCP JSON-RPC request carries
	 * no language, so PLL_REST_Request::$curlang stays null. begin_content_write()
	 * therefore filters the option itself, which fixes the choice at its source
	 * rather than repairing the relationship afterwards -- and covers
	 * wp_publish_post() as well, which re-applies the same options at post.php:5435
	 * and 5437. A core update that moves those lines does not break this (the hook
	 * names are what it depends on), but it does invalidate the trace above, so
	 * re-read them before trusting this comment.
	 *
	 * @param callable $original Ability callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function create_content( callable $original, array $input ) {
		$language = self::requested_language( $input );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		$target = self::requested_link( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		// Up front, not after the insert: an agent reading a WP_Error assumes nothing
		// happened, and the post-hoc check below only reaches this conclusion once a
		// draft in the wrong language already exists to be retried into a second one.
		if ( null !== $language ) {
			$post_type = self::insert_post_type( $input );
			if ( '' !== $post_type && ! self::translates_post_type( $post_type ) ) {
				return self::error(
					'not_translated',
					sprintf( 'This site does not translate the post type "%s", so its items have no language. Enable it under Languages -> Settings -> Custom post types first, or leave the language field out.', $post_type ),
					400
				);
			}

			self::$pending_post_language = $language;
			add_action( 'save_post', array( self::class, 'stamp_post_language' ), 9, 2 );
			self::begin_content_write( $language );
		}

		try {
			$result = $original( $input );
		} finally {
			remove_action( 'save_post', array( self::class, 'stamp_post_language' ), 9 );
			self::$pending_post_language = null;
			self::end_content_write();
		}

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['id'] ) ) {
			return $result;
		}
		$id = (int) $result['id'];

		// Verified rather than assumed: the stamp is silent when Polylang does not
		// translate this post type, and an agent told nothing would publish a whole
		// series of articles in the wrong language.
		if ( null !== $language && (string) pll_get_post_language( $id ) !== $language ) {
			return self::error(
				'language_not_set',
				sprintf(
					'The item was created as ID %1$d, but its language could not be set to "%2$s": this site does not translate the post type "%3$s". Enable it under Languages -> Settings -> Custom post types, then set the language with update-content.',
					$id,
					$language,
					(string) ( $result['type'] ?? '' )
				),
				400,
				array( 'post_id' => $id )
			);
		}

		if ( null !== $target ) {
			$linked = self::link( 'post', $id, $target );
			if ( is_wp_error( $linked ) ) {
				// Mirrors how create_content() itself reports a partial success.
				return self::with_data(
					$linked,
					array(
						'post_id' => $id,
						'note'    => 'The item was created; only the translation link failed.',
					)
				);
			}
		}

		return self::enrich_post( $result );
	}

	/**
	 * site/update-content: the ID is known up front, so the language is set before
	 * the ability runs rather than through a hook. That also covers the call that
	 * changes nothing but terms, where wp_update_post() is never reached.
	 *
	 * Writing the language early is unavoidable -- terms sent in the same call have
	 * to land in the new language, and Polylang decides that at the moment they are
	 * assigned -- but it is also destructive: PLL_Translated_Object::set_language()
	 * (translated-object.php:89) re-saves the translation group, which for a post
	 * that had one means tearing it down. Everything that can refuse the call is
	 * therefore decided before that write, against the state as it is now: the post
	 * exists, its type is translated, the language being asked for is not already
	 * held by another member of its own group, and the whole of the translation
	 * target is checked by validate_link() rather than by link() after the fact.
	 * The earlier ordering let a call that ended in a 404, a 400 or a 409 still
	 * convert the post and destroy a translation pair on its way to the error.
	 *
	 * Two writes still precede the ability's own validation and cannot be moved
	 * behind it: the unlink and the language. When the ability then fails, the
	 * error carries a note saying which of them landed, the way create_content()
	 * reports a post it created but could not link.
	 *
	 * @param callable $original Ability callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function update_content( callable $original, array $input ) {
		$language = self::requested_language( $input );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		$target = self::requested_link( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! self::ready() ) {
			return $original( $input );
		}

		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			// Nothing of ours applies to an item that is not there. When the caller
			// asked for a language or a link, say so in our own vocabulary; otherwise
			// let the ability raise its identical not_found and stay out of the way.
			return ( null !== $language || null !== $target )
				? self::error( 'not_found', 'No such item.', 404 )
				: $original( $input );
		}

		if ( null !== $language && ! self::translates_post_type( $post->post_type ) ) {
			return self::error(
				'not_translated',
				sprintf( 'This site does not translate the post type "%s", so its items have no language. Enable it under Languages -> Settings -> Custom post types first.', $post->post_type ),
				400
			);
		}

		$current = self::language_of( 'post', $post->ID );
		$after   = null !== $language ? $language : $current;

		// A language the post's own group already holds. Polylang would accept this
		// and quietly resolve the clash by dropping the post out of the group, which
		// leaves it claiming a sibling that is in the same language as itself.
		if ( null !== $language && 0 !== $target ) {
			$own = self::translations_of( 'post', $post->ID );
			if ( isset( $own[ $language ] ) && $own[ $language ] !== $post->ID ) {
				return self::error(
					'language_taken',
					sprintf(
						'This item is already grouped with item %1$d as the "%2$s" translation, so it cannot become "%2$s" itself. Unlink it first with translation_of: 0, or update item %1$d instead.',
						$own[ $language ],
						$language
					),
					409
				);
			}
		}

		/*
		 * Anything that detaches this post from its translation group rewrites the
		 * description every other member points at, so it needs their permission --
		 * the same bargain validate_unlink() strikes for an explicit unlink. Two
		 * cases reach commit_link()'s detach without going through the unlink
		 * branch: a language change (a post cannot keep a group slot named after
		 * the language it just left) and a link to a new target (commit_link()
		 * detaches before it links, in both branches). Validate both here, against
		 * the language the post has NOW, because that is the group being rewritten.
		 */
		$will_detach = ( null !== $language && $current !== $language )
			|| ( null !== $target && $target > 0 );

		if ( $will_detach && '' !== $current ) {
			$detach = self::validate_unlink( 'post', $post->ID, $current );
			if ( is_wp_error( $detach ) ) {
				return $detach;
			}
		}

		if ( 0 === $target && '' === $current && '' !== $after ) {
			// Nothing to unlink: an item with no language is in no group, and the
			// language this call is about to give it leaves it in a group of one
			// anyway. Refusing here would make "set the language and stand alone",
			// which is a reasonable thing to ask for, impossible to say.
			$target = null;
		}

		if ( null !== $target ) {
			// An unlink is validated against the language the post has now; a link is
			// validated against the language it will have once this call is done.
			$link_language = 0 === $target ? $current : $after;
			if ( '' === $link_language ) {
				return self::error( 'no_language', 'This item has no language yet. Set language before linking a translation.', 400 );
			}
			$check = self::validate_link( 'post', $post->ID, $link_language, $target );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		// Nothing below this line can be refused on the state it was given.
		$unlinked = false;
		if ( 0 === $target ) {
			self::commit_link( 'post', $post->ID, $current, 0 );
			$unlinked = true;
		}
		if ( null !== $language ) {
			/*
			 * Detach BEFORE the language moves, under the language the post has now.
			 * pll_set_post_language() re-saves the group through
			 * PLL_Translated_Object::set_language() (translated-object.php:89-106),
			 * which re-inserts the post under its new slug and leaves the old slot
			 * pointing at it; on a group of three that also drags an uninvolved
			 * sibling along. Detaching first makes the move mean one thing: the post
			 * stands alone in its new language, and a caller that wants it grouped
			 * says so with translation_of in the same call. commit_link() run after
			 * the change cannot clean the old slot, because by then the post no
			 * longer knows which slot it occupied.
			 */
			if ( ! $unlinked && '' !== $current && $current !== $language ) {
				self::commit_link( 'post', $post->ID, $current, 0 );
				$unlinked = true;
			}
			pll_set_post_language( $post->ID, $language );
		}

		if ( '' !== $after ) {
			self::begin_content_write( $after );
		}
		try {
			$result = $original( $input );
		} finally {
			self::end_content_write();
		}

		if ( is_wp_error( $result ) ) {
			return self::note_applied( $result, $post->ID, $language, $unlinked );
		}
		if ( ! is_array( $result ) ) {
			return $result;
		}

		if ( null !== $target && $target > 0 ) {
			// Re-validated rather than committed blind: validate_link() ran before the
			// ability did, and the ability may have moved something since.
			$linked = self::link( 'post', $post->ID, $target );
			if ( is_wp_error( $linked ) ) {
				return self::note_applied( $linked, $post->ID, $language, $unlinked, 'The item was updated; only the translation link failed.' );
			}
		}

		return self::enrich_post( $result );
	}

	/**
	 * site/list-content: constrain the ability's WP_Query, then annotate the rows.
	 *
	 * list_content() builds its own WP_Query, so pre_get_posts is the only way in.
	 * The hook is attached for the duration of that one call and the pending value
	 * is consumed by the first query it sees, so nothing else in the request can
	 * pick it up.
	 *
	 * @param callable $original Ability callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function list_content( callable $original, array $input ) {
		$language = self::requested_language( $input );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( null !== $language ) {
			self::$pending_post_filter = $language;
			add_action( 'pre_get_posts', array( self::class, 'filter_post_query' ), 5 );
		}

		try {
			$result = $original( $input );
		} finally {
			remove_action( 'pre_get_posts', array( self::class, 'filter_post_query' ), 5 );
			self::$pending_post_filter = null;
		}

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
			return $result;
		}

		$result['items'] = array_map( array( self::class, 'enrich_post' ), $result['items'] );
		if ( null !== $language ) {
			$result['language'] = $language;
		}

		return $result;
	}

	/**
	 * site/list-terms: same shape, through the term query instead.
	 *
	 * Polylang honours a `lang` argument on any WP_Term_Query, in any context, so
	 * there is no need to build a clause by hand here. It must not be forced onto
	 * a query that already carries one: Polylang sets `lang` to the empty string
	 * for the internal hierarchy queries, and overwriting that breaks the parent
	 * lookup on a hierarchical taxonomy.
	 *
	 * @param callable $original Ability callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function list_terms( callable $original, array $input ) {
		$language = self::requested_language( $input );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( null !== $language ) {
			self::$pending_term_filter = $language;
			add_filter( 'get_terms_args', array( self::class, 'filter_term_query' ), 20, 2 );
		}

		try {
			$result = $original( $input );
		} finally {
			remove_filter( 'get_terms_args', array( self::class, 'filter_term_query' ), 20 );
			self::$pending_term_filter = null;
		}

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
			return $result;
		}

		$result['items'] = array_map( array( self::class, 'enrich_term' ), $result['items'] );
		if ( null !== $language ) {
			$result['language'] = $language;
		}

		return $result;
	}

	/**
	 * site/create-term: stamp the language during the insert, link afterwards.
	 *
	 * Two hooks, for two different jobs. create_term at priority 1 puts the
	 * language in place before Polylang's own handler at 999 assigns the default
	 * one. pll_inserted_term_language tells Polylang which language the term is
	 * going into while it is deciding the slug, which is how a Lithuanian
	 * "Naujienos" keeps the slug "naujienos" and a Lithuanian "News" beside an
	 * English one becomes "news-lt" instead of WordPress's "news-2".
	 *
	 * @param callable $original Ability callback.
	 * @param array    $input    Validated input.
	 * @return mixed
	 */
	private static function create_term( callable $original, array $input ) {
		$language = self::requested_language( $input );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		$target = self::requested_link( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( null !== $language ) {
			self::$pending_term_language = $language;
			add_action( 'create_term', array( self::class, 'stamp_term_language' ), 1, 3 );
			add_filter( 'pll_inserted_term_language', array( self::class, 'inserted_term_language' ) );
		}

		try {
			$result = $original( $input );
		} finally {
			remove_action( 'create_term', array( self::class, 'stamp_term_language' ), 1 );
			remove_filter( 'pll_inserted_term_language', array( self::class, 'inserted_term_language' ) );
			self::$pending_term_language = null;
		}

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['id'] ) ) {
			return $result;
		}
		$id = (int) $result['id'];

		if ( null !== $language && (string) pll_get_term_language( $id ) !== $language ) {
			return self::error(
				'language_not_set',
				sprintf( 'The term was created as ID %1$d, but its language could not be set to "%2$s": this site does not translate that taxonomy. Enable it under Languages -> Settings -> Taxonomies.', $id, $language ),
				400,
				array( 'term_id' => $id )
			);
		}

		if ( null !== $target ) {
			$linked = self::link( 'term', $id, $target );
			if ( is_wp_error( $linked ) ) {
				$linked->add_data(
					array(
						'term_id' => $id,
						'note'    => 'The term was created; only the translation link failed.',
					)
				);
				return $linked;
			}
		}

		return self::enrich_term( $result );
	}

	/**
	 * site/describe-content-model: tell an agent the site is bilingual at all.
	 *
	 * This is the discovery call, so it is where the languages have to announce
	 * themselves; an agent reading only the schemas would otherwise have to guess
	 * which post types and taxonomies actually carry a language.
	 *
	 * @param mixed $result The ability's own output.
	 * @return mixed
	 */
	private static function describe( $result ) {
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return $result;
		}

		if ( ! self::ready() ) {
			$result['languages'] = null;
			return $result;
		}

		$post_types = array();
		foreach ( (array) ( $result['post_types'] ?? array() ) as $post_type ) {
			if ( isset( $post_type['slug'] ) && self::translates_post_type( (string) $post_type['slug'] ) ) {
				$post_types[] = (string) $post_type['slug'];
			}
		}

		$taxonomies = array();
		foreach ( (array) ( $result['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( isset( $taxonomy['slug'] ) && self::translates_taxonomy( (string) $taxonomy['slug'] ) ) {
				$taxonomies[] = (string) $taxonomy['slug'];
			}
		}

		$result['languages'] = array(
			'provider'              => 'polylang',
			'default'               => self::default_slug(),
			'list'                  => array_values( self::languages() ),
			'translated_post_types' => $post_types,
			'translated_taxonomies' => $taxonomies,
		);

		return $result;
	}

	// -----------------------------------------------------------------------
	// Hook callbacks. Public only because WordPress has to be able to call them.
	// -----------------------------------------------------------------------

	/**
	 * Stamps the language on the post site/create-content has just inserted.
	 *
	 * One shot: the pending value is cleared before the write, so a plugin that
	 * inserts a second post from inside save_post cannot inherit it.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being saved.
	 */
	public static function stamp_post_language( $post_id, $post ): void {
		if ( null === self::$pending_post_language || ! $post instanceof WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$language                    = self::$pending_post_language;
		self::$pending_post_language = null;

		if ( self::translates_post_type( $post->post_type ) ) {
			pll_set_post_language( (int) $post_id, $language );
		}
	}

	/**
	 * Stamps the language on a term one of these abilities has just inserted.
	 *
	 * Serves two callers with different lifetimes. site/create-term inserts exactly
	 * one term, so its value is one shot and consumed here. A content write can
	 * insert several -- one per term name the agent sent that does not exist yet --
	 * so its value is sticky and survives until end_content_write() clears it.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function stamp_term_language( $term_id, $tt_id, $taxonomy ): void {
		$language = self::$pending_term_language ?? self::$content_language;
		if ( null === $language ) {
			return;
		}

		self::$pending_term_language = null;

		if ( self::translates_taxonomy( (string) $taxonomy ) ) {
			pll_set_term_language( (int) $term_id, $language );
		}
	}

	/**
	 * Tells Polylang which language the term being inserted belongs to, so its
	 * slug is chosen against the terms in that language rather than against all
	 * of them.
	 *
	 * @param mixed $language Language Polylang resolved for itself, usually null.
	 * @return mixed
	 */
	public static function inserted_term_language( $language ) {
		$slug = self::$pending_term_language ?? self::$content_language;
		if ( null === $slug || ! self::ready() ) {
			return $language;
		}

		$found = PLL()->model->get_language( $slug );

		return $found instanceof PLL_Language ? $found : $language;
	}

	/**
	 * Points a taxonomy's default term at its translation, for the duration of one
	 * content write.
	 *
	 * Filters the option rather than repairing the relationship afterwards, because
	 * the relationship is written while the post still has no language and is
	 * therefore invisible to the Polylang handler that would otherwise translate
	 * it. The ordering note on create_content() has the core line numbers.
	 *
	 * @param mixed $term_id Default term ID as stored, or as Polylang left it.
	 * @return mixed
	 */
	public static function filter_default_term( $term_id ) {
		// Reading the translations runs a term query, and a term query is a long way
		// of code to trust never to read this option back. The guard costs nothing
		// and turns a hypothetical fatal recursion into the untranslated answer.
		static $resolving = false;

		if ( $resolving || null === self::$content_language || ! self::ready() ) {
			return $term_id;
		}

		$id = (int) $term_id;
		if ( $id <= 0 ) {
			return $term_id;
		}

		$resolving = true;
		try {
			// A default term with no twin in this language is a site configuration
			// problem, not a reason to fail a write: leaving the original is what
			// Polylang's own filter does from the same position.
			$translations = self::translations_of( 'term', $id );
		} finally {
			$resolving = false;
		}

		return $translations[ self::$content_language ] ?? $term_id;
	}

	/**
	 * Constrains site/list-content's query to one language.
	 *
	 * An explicit tax_query rather than Polylang's `lang` query var, because
	 * WP_Query re-parses tax_query after pre_get_posts for every non-singular
	 * query, which makes this survive whatever parse_query() already decided.
	 *
	 * @param WP_Query $query Query about to run.
	 */
	public static function filter_post_query( $query ): void {
		if ( null === self::$pending_post_filter || ! $query instanceof WP_Query ) {
			return;
		}

		$language                  = self::$pending_post_filter;
		self::$pending_post_filter = null;

		$tax_query   = $query->get( 'tax_query' );
		$tax_query   = is_array( $tax_query ) ? $tax_query : array();
		$tax_query[] = array(
			'taxonomy' => 'language',
			'field'    => 'slug',
			'terms'    => array( $language ),
			'operator' => 'IN',
		);

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Constrains site/list-terms' query to one language.
	 *
	 * @param array $args       Term query arguments.
	 * @param mixed $taxonomies Queried taxonomies.
	 * @return array
	 */
	public static function filter_term_query( $args, $taxonomies = array() ): array {
		$args = is_array( $args ) ? $args : array();

		// isset(), not empty(): Polylang writes an empty string here to switch its
		// own filter off for the internal hierarchy queries, and that decision wins.
		if ( null === self::$pending_term_filter || isset( $args['lang'] ) ) {
			return $args;
		}

		$args['lang'] = self::$pending_term_filter;

		return $args;
	}

	// -----------------------------------------------------------------------
	// Scoped hooks. The abilities call these in pairs around one write, so that
	// everything WordPress or the ability decides implicitly during it -- a
	// default term, a term that has to be invented from a name -- is decided in
	// the language the content is going into rather than in the site's default.
	// -----------------------------------------------------------------------

	/**
	 * Attaches the hooks that make one content write language-aware.
	 *
	 * Always paired with end_content_write() in a finally block: a language left
	 * hanging here would follow every later insert in the same request.
	 *
	 * @param string $language Language slug the content is going into.
	 */
	private static function begin_content_write( string $language ): void {
		self::$content_language = $language;

		add_action( 'create_term', array( self::class, 'stamp_term_language' ), 1, 3 );
		add_filter( 'pll_inserted_term_language', array( self::class, 'inserted_term_language' ) );

		// Priority 20, after PLL_Default_Term::option_default_term() at 10, so that
		// what it resolved from the request's language does not beat what this call
		// explicitly asked for. Idempotent when it resolved the same term.
		foreach ( self::default_term_options() as $option ) {
			add_filter( 'option_' . $option, array( self::class, 'filter_default_term' ), 20 );
		}
	}

	/**
	 * Detaches them again. Safe to call when nothing was attached.
	 */
	private static function end_content_write(): void {
		if ( null === self::$content_language ) {
			return;
		}

		foreach ( self::default_term_options() as $option ) {
			remove_filter( 'option_' . $option, array( self::class, 'filter_default_term' ), 20 );
		}

		remove_filter( 'pll_inserted_term_language', array( self::class, 'inserted_term_language' ) );
		remove_action( 'create_term', array( self::class, 'stamp_term_language' ), 1 );

		self::$content_language = null;
	}

	/**
	 * The option names WordPress reads a default term out of, for the translated
	 * taxonomies on this site.
	 *
	 * Two spellings, because core has two: `default_category` for the category
	 * taxonomy (wp-includes/post.php:4720 and 5435) and `default_term_{taxonomy}`
	 * for any other taxonomy registered with a `default_term` (post.php:5080 and
	 * 5437). Polylang filters only the first of them -- PLL_Default_Term::add_hooks()
	 * skips every taxonomy but `category` -- so the second is unhandled on any site
	 * that has one, and translating both here costs nothing.
	 *
	 * @return string[]
	 */
	private static function default_term_options(): array {
		$options = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy || ! self::translates_taxonomy( $taxonomy->name ) ) {
				continue;
			}
			if ( 'category' === $taxonomy->name ) {
				$options[] = 'default_category';
				continue;
			}
			if ( ! empty( $taxonomy->default_term ) ) {
				$options[] = 'default_term_' . $taxonomy->name;
			}
		}

		return $options;
	}

	// -----------------------------------------------------------------------
	// Input.
	// -----------------------------------------------------------------------

	/**
	 * The post type site/create-content is about to insert into.
	 *
	 * Only needed to answer "does this site translate that type" before the insert
	 * rather than after it. When the caller named a type, that is the answer. When
	 * it did not, site-abilities.php's default_post_type() prefers `post` and falls
	 * back to the first public type, and guessing the fallback here would couple
	 * this file to that file's discovery order for no gain -- so an unnamed type
	 * that is not `post` returns '' and the check is simply skipped, leaving the
	 * post-hoc verification in create_content() to catch it as it always did.
	 *
	 * @param array $input Validated input.
	 */
	private static function insert_post_type( array $input ): string {
		if ( isset( $input['post_type'] ) && is_string( $input['post_type'] ) ) {
			$type = sanitize_key( $input['post_type'] );
			// An unregistered type is the ability's error to report, not ours: it
			// answers with the list of types this site has, which is more use.
			return post_type_exists( $type ) ? $type : '';
		}

		return post_type_exists( 'post' ) ? 'post' : '';
	}

	/**
	 * The requested language slug, or null when none was asked for.
	 *
	 * @param array $input Validated input.
	 * @return string|null|WP_Error
	 */
	private static function requested_language( array $input ) {
		if ( ! array_key_exists( 'language', $input ) ) {
			return null;
		}

		$slug = sanitize_key( (string) $input['language'] );
		if ( '' === $slug ) {
			return null;
		}

		if ( ! self::ready() ) {
			return self::error( 'no_languages', 'This site has no language plugin active, so content has no language. Leave the language field out.', 400 );
		}

		$languages = self::languages();
		if ( ! isset( $languages[ $slug ] ) ) {
			return self::error(
				'bad_language',
				sprintf( 'Unknown language "%1$s". This site has: %2$s.', $slug, implode( ', ', array_keys( $languages ) ) ),
				400
			);
		}

		return $slug;
	}

	/**
	 * The requested translation target: an ID, 0 to unlink, null when the field
	 * was not sent at all.
	 *
	 * @param array $input Validated input.
	 * @return int|null|WP_Error
	 */
	private static function requested_link( array $input ) {
		if ( ! array_key_exists( 'translation_of', $input ) ) {
			return null;
		}

		if ( ! self::ready() ) {
			return self::error( 'no_languages', 'This site has no language plugin active, so there are no translations to link. Leave the translation_of field out.', 400 );
		}

		$target = (int) $input['translation_of'];
		if ( $target < 0 ) {
			return self::error( 'bad_translation_of', 'translation_of must be an item ID, or 0 to unlink.', 400 );
		}

		return $target;
	}

	// -----------------------------------------------------------------------
	// Writes. Everything goes through Polylang's public API and never through
	// wp_set_object_terms(), so the translation group and the language cache stay
	// consistent with the language term.
	// -----------------------------------------------------------------------

	/**
	 * Links one object into another's translation group, or unlinks it.
	 *
	 * Validation and the write are separate calls because update-content has to run
	 * the first before it makes a destructive change of its own and the second only
	 * after; everywhere else the two happen together and this is the whole operation.
	 *
	 * @param string $kind   'post' or 'term'.
	 * @param int    $id     Object being linked.
	 * @param int    $target Object to link it to, or 0 to unlink.
	 * @return true|WP_Error
	 */
	private static function link( string $kind, int $id, int $target ) {
		$language = self::language_of( $kind, $id );
		if ( '' === $language ) {
			return self::error( 'no_language', 'This item has no language yet. Set language before linking a translation.', 400 );
		}

		$check = self::validate_link( $kind, $id, $language, $target );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		self::commit_link( $kind, $id, $language, $target );

		return true;
	}

	/**
	 * Every reason a link or an unlink can be refused, decided before anything is
	 * written.
	 *
	 * Polylang is forgiving exactly where it should be strict: save_translations()
	 * silently drops any entry whose object is not really in the language it is
	 * filed under, so a mistaken link looks like a success and does nothing. Every
	 * condition is therefore checked here and reported as an error an agent can act
	 * on.
	 *
	 * The language is a parameter rather than something this reads off $id, because
	 * update-content validates the link against the language the object is about to
	 * be given, not the one it still has.
	 *
	 * @param string $kind     'post' or 'term'.
	 * @param int    $id       Object being linked.
	 * @param string $language Language $id will be in when the link is committed.
	 * @param int    $target   Object to link it to, or 0 to unlink.
	 * @return true|WP_Error
	 */
	private static function validate_link( string $kind, int $id, string $language, int $target ) {
		if ( 0 === $target ) {
			return self::validate_unlink( $kind, $id, $language );
		}

		if ( $target === $id ) {
			return self::error( 'self_translation', 'An item cannot be a translation of itself.', 400 );
		}

		$check = 'post' === $kind ? self::check_post_target( $id, $target ) : self::check_term_target( $id, $target );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$other_language = self::language_of( $kind, $target );
		if ( '' === $other_language ) {
			return self::error( 'no_language', sprintf( 'Item %d has no language, so nothing can be linked to it.', $target ), 400 );
		}
		if ( $other_language === $language ) {
			return self::error(
				'same_language',
				sprintf( 'Item %1$d is also in "%2$s". A translation group holds one item per language.', $target, $language ),
				400
			);
		}

		$group = self::translations_of( $kind, $target );
		if ( isset( $group[ $language ] ) && $group[ $language ] !== $id ) {
			return self::error(
				'language_taken',
				sprintf(
					'Item %1$d already has a "%2$s" translation (item %3$d). Unlink that one first with translation_of: 0.',
					$target,
					$language,
					$group[ $language ]
				),
				409
			);
		}

		return true;
	}

	/**
	 * Unlinking rewrites the group the other members share, so it needs their
	 * permission the way linking needs the target's.
	 *
	 * Detaching an object cannot be done without writing to the description that
	 * lives on the group's term, which every member points at -- Polylang has no
	 * per-member record to clear. The linking branch refuses a target the caller
	 * cannot edit; refusing on the same terms here closes the gap where an Author
	 * unlinks the post they own and, with it, edits the Editor's translation of it.
	 *
	 * @param string $kind     'post' or 'term'.
	 * @param int    $id       Object being unlinked.
	 * @param string $language Language $id is in.
	 * @return true|WP_Error
	 */
	private static function validate_unlink( string $kind, int $id, string $language ) {
		foreach ( self::group_without( $kind, $id, $language ) as $slug => $other ) {
			if ( 'post' === $kind ) {
				if ( ! current_user_can( 'edit_post', $other ) ) {
					return self::error(
						'forbidden',
						sprintf( 'Unlinking this item rewrites the translation group it shares with item %1$d ("%2$s"), which you may not edit.', $other, $slug ),
						403
					);
				}
				continue;
			}

			$term = get_term( $other );
			$tax  = $term instanceof WP_Term ? get_taxonomy( $term->taxonomy ) : null;
			if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
				return self::error(
					'forbidden',
					sprintf( 'Unlinking this term rewrites the translation group it shares with term %1$d ("%2$s"), which you may not edit.', $other, $slug ),
					403
				);
			}
		}

		return true;
	}

	/**
	 * Performs the link or the unlink. Assumes validate_link() has passed.
	 *
	 * Both branches detach $id from whatever group it is in before doing anything
	 * else, because PLL_Translated_Object::save_translations() does not do it for
	 * them. Handed a map that omits a former member, it calls delete_translation()
	 * on that member (translated-object.php:168-172), which unsets the member's slug
	 * from the shared serialised description and stops -- it never removes the
	 * member's row in the `post_translations`/`term_translations` taxonomy. The
	 * member therefore keeps pointing at a description it is no longer named in, and
	 * because PLL_Translated_Object::get_translations() merges the object's own slug
	 * back in when it reads that description, the member reports the objects still
	 * listed there as its translations.
	 *
	 * Worked through on a two-language group {en:4, lt:5}, unlinking 5 with the
	 * single one-entry save this used to do: the description ends as {lt:5}, post 4
	 * keeps its row, and pll_get_post_translations(4) answers {en:4, lt:5} -- post 4
	 * still claims the post that was just unlinked from it. On a three-language
	 * group {en:4, lt:5, de:6} both 4 and 6 lose each other and both still point at
	 * 5. The order below is what fixes it and the only order that does: saving the
	 * one-entry map first leaves the remaining members' rows in place for the second
	 * save to find, which then deletes the exhausted group term and builds them a
	 * fresh one. Doing the two saves the other way round deletes the group the first
	 * save had just created.
	 *
	 * @param string $kind     'post' or 'term'.
	 * @param int    $id       Object being linked.
	 * @param string $language Language $id is in.
	 * @param int    $target   Object to link it to, or 0 to unlink.
	 */
	private static function commit_link( string $kind, int $id, string $language, int $target ): void {
		// A group of one is how Polylang says "not translated".
		$rest = self::group_without( $kind, $id, $language );
		self::save_translations( $kind, array( $language => $id ) );
		if ( $rest ) {
			self::save_translations( $kind, $rest );
		}

		if ( 0 === $target ) {
			return;
		}

		$group          = self::translations_of( $kind, $target );
		$other_language = self::language_of( $kind, $target );

		// The group already contains the target under its own slug, but say so
		// explicitly: the save takes the first entry as the anchor, and an empty
		// map would quietly save nothing.
		if ( '' !== $other_language ) {
			$group[ $other_language ] = $target;
		}
		$group[ $language ] = $id;

		self::save_translations( $kind, $group );
	}

	/**
	 * The rest of an object's translation group: every member but the object itself.
	 *
	 * Keyed by language slug, so it can be handed straight back to
	 * pll_save_*_translations() as a group in its own right.
	 *
	 * @param string $kind     'post' or 'term'.
	 * @param int    $id       Object to leave out.
	 * @param string $language Slug $id occupies.
	 * @return array<string, int>
	 */
	private static function group_without( string $kind, int $id, string $language ): array {
		$rest = array();

		foreach ( self::translations_of( $kind, $id ) as $slug => $member ) {
			if ( $slug === $language || $member === $id ) {
				continue;
			}
			$rest[ $slug ] = $member;
		}

		return $rest;
	}

	/**
	 * @param int $id     Post being linked.
	 * @param int $target Post to link it to.
	 * @return true|WP_Error
	 */
	private static function check_post_target( int $id, int $target ) {
		$other = get_post( $target );
		if ( ! $other instanceof WP_Post || 'attachment' === $other->post_type ) {
			return self::error( 'not_found', sprintf( 'No item with ID %d.', $target ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $other->ID ) ) {
			return self::error( 'forbidden', 'You may not edit the item you are linking to.', 403 );
		}

		$post = get_post( $id );
		if ( $post instanceof WP_Post && $post->post_type !== $other->post_type ) {
			return self::error(
				'type_mismatch',
				sprintf( 'A "%1$s" cannot be a translation of a "%2$s".', $post->post_type, $other->post_type ),
				400
			);
		}

		return true;
	}

	/**
	 * @param int $id     Term being linked.
	 * @param int $target Term to link it to.
	 * @return true|WP_Error
	 */
	private static function check_term_target( int $id, int $target ) {
		$term  = get_term( $id );
		$other = get_term( $target );
		if ( ! $other instanceof WP_Term ) {
			return self::error( 'not_found', sprintf( 'No term with ID %d.', $target ), 404 );
		}
		if ( $term instanceof WP_Term && $term->taxonomy !== $other->taxonomy ) {
			return self::error(
				'type_mismatch',
				sprintf( 'A "%1$s" term cannot be a translation of a "%2$s" term.', $term->taxonomy, $other->taxonomy ),
				400
			);
		}

		$taxonomy = $other->taxonomy;
		$tax      = get_taxonomy( $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
			return self::error( 'forbidden', sprintf( 'You may not manage "%s" terms.', $taxonomy ), 403 );
		}

		return true;
	}

	/**
	 * @param string $kind         'post' or 'term'.
	 * @param array  $translations Object IDs keyed by language slug.
	 */
	private static function save_translations( string $kind, array $translations ): void {
		if ( 'post' === $kind ) {
			pll_save_post_translations( $translations );
			return;
		}
		pll_save_term_translations( $translations );
	}

	// -----------------------------------------------------------------------
	// Reads.
	// -----------------------------------------------------------------------

	/**
	 * Adds language and translations to one formatted post.
	 *
	 * Sibling IDs are always reported, because an agent that cannot see that a
	 * translation exists will write a second one. Everything else about the sibling
	 * is gated on the same capability the read abilities use.
	 *
	 * @param mixed $item One item as site-abilities.php formatted it.
	 * @return mixed
	 */
	private static function enrich_post( $item ) {
		if ( is_wp_error( $item ) || ! is_array( $item ) || empty( $item['id'] ) || ! self::ready() ) {
			return $item;
		}

		$id                   = (int) $item['id'];
		$item['language']     = self::describe_language( (string) pll_get_post_language( $id ) );
		$item['translations'] = array();

		foreach ( (array) pll_get_post_translations( $id ) as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;
			if ( $translation_id === $id ) {
				continue;
			}
			$translation = get_post( $translation_id );
			if ( ! $translation instanceof WP_Post ) {
				continue;
			}

			$row = array(
				'id'       => $translation_id,
				'language' => (string) $slug,
			);
			if ( current_user_can( 'edit_post', $translation_id ) ) {
				$row['title']  = $translation->post_title;
				$row['status'] = $translation->post_status;
				$row['link']   = get_permalink( $translation );
			}

			$item['translations'][ (string) $slug ] = $row;
		}

		return $item;
	}

	/**
	 * Adds language and translations to one formatted term.
	 *
	 * IDs only, unlike posts: an agent picking a term is choosing something to
	 * assign, and the ID is the whole answer. A post's sibling needs a title
	 * because the agent is deciding whether to write one.
	 *
	 * @param mixed $item One term as site-abilities.php formatted it.
	 * @return mixed
	 */
	private static function enrich_term( $item ) {
		if ( is_wp_error( $item ) || ! is_array( $item ) || empty( $item['id'] ) || ! self::ready() ) {
			return $item;
		}

		$id                   = (int) $item['id'];
		$item['language']     = self::describe_language( (string) pll_get_term_language( $id ) );
		$item['translations'] = array();

		foreach ( (array) pll_get_term_translations( $id ) as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;
			if ( $translation_id !== $id ) {
				$item['translations'][ (string) $slug ] = $translation_id;
			}
		}

		return $item;
	}

	/**
	 * @param string $slug Language slug, or '' when the object has none.
	 * @return array<string, mixed>|null
	 */
	private static function describe_language( string $slug ): ?array {
		$languages = self::languages();
		return ( '' !== $slug && isset( $languages[ $slug ] ) ) ? $languages[ $slug ] : null;
	}

	/**
	 * @param string $kind 'post' or 'term'.
	 * @param int    $id   Object ID.
	 */
	private static function language_of( string $kind, int $id ): string {
		return 'post' === $kind
			? (string) pll_get_post_language( $id )
			: (string) pll_get_term_language( $id );
	}

	/**
	 * @param string $kind 'post' or 'term'.
	 * @param int    $id   Object ID.
	 * @return array<string, int>
	 */
	private static function translations_of( string $kind, int $id ): array {
		$translations = 'post' === $kind
			? pll_get_post_translations( $id )
			: pll_get_term_translations( $id );

		return array_map( 'intval', (array) $translations );
	}

	// -----------------------------------------------------------------------
	// Discovery.
	// -----------------------------------------------------------------------

	/**
	 * Whether Polylang is loaded, bootstrapped and has languages.
	 *
	 * The $GLOBALS check is not belt and braces: PLL() returns that global
	 * unguarded, and Polylang only fills it once it has chosen a context class on
	 * plugins_loaded, so the pll_* functions can exist at a moment when calling one
	 * of them reads a property off null.
	 */
	private static function ready(): bool {
		return function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_set_post_language' )
			&& ! empty( $GLOBALS['polylang'] );
	}

	/**
	 * @param string $post_type Post type slug.
	 */
	private static function translates_post_type( string $post_type ): bool {
		return self::ready()
			&& function_exists( 'pll_is_translated_post_type' )
			&& (bool) pll_is_translated_post_type( $post_type );
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 */
	private static function translates_taxonomy( string $taxonomy ): bool {
		return self::ready()
			&& function_exists( 'pll_is_translated_taxonomy' )
			&& (bool) pll_is_translated_taxonomy( $taxonomy );
	}

	/**
	 * The configured languages, keyed by slug.
	 *
	 * Built from three passes over pll_languages_list() rather than from
	 * PLL()->model, so the discovery path depends only on the documented API. The
	 * result is cached for the request, but an empty result never is: the abilities
	 * registry can be built before Polylang is ready, and caching "no languages"
	 * then would blind everything that runs later in the same request.
	 *
	 * @return array<string, array{slug:string,name:string,locale:string,is_default:bool}>
	 */
	private static function languages(): array {
		static $cache = array();
		if ( ! empty( $cache ) ) {
			return $cache;
		}
		if ( ! self::ready() ) {
			return array();
		}

		$slugs   = array_values( (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
		$names   = array_values( (array) pll_languages_list( array( 'fields' => 'name' ) ) );
		$locales = array_values( (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
		$default = self::default_slug();

		$languages = array();
		foreach ( $slugs as $index => $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$languages[ $slug ] = array(
				'slug'       => $slug,
				'name'       => isset( $names[ $index ] ) ? (string) $names[ $index ] : $slug,
				'locale'     => isset( $locales[ $index ] ) ? (string) $locales[ $index ] : '',
				'is_default' => $slug === $default,
			);
		}

		/**
		 * Filters the languages these abilities expose.
		 *
		 * @param array $languages Languages keyed by slug.
		 */
		$languages = (array) apply_filters( 'site_polylang_languages', $languages );

		if ( ! empty( $languages ) ) {
			$cache = $languages;
		}

		return $languages;
	}

	private static function default_slug(): string {
		if ( ! self::ready() || ! function_exists( 'pll_default_language' ) ) {
			return '';
		}
		return (string) pll_default_language();
	}

	// -----------------------------------------------------------------------
	// Plumbing. Error codes carry the same prefix as the abilities they extend,
	// so an agent sees one error vocabulary rather than two.
	// -----------------------------------------------------------------------

	/**
	 * @param string $code    Error code, without the shared prefix.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $data    Extra error data.
	 */
	private static function error( string $code, string $message, int $status, array $data = array() ): WP_Error {
		return new WP_Error( 'site_abilities_' . $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Adds to an error's data without dropping what is already in it.
	 *
	 * WP_Error::add_data() replaces the data for a code rather than extending it, so
	 * the obvious one-liner silently throws away the `status` the ability set and
	 * the transport falls back to 500. Every error these abilities return carries
	 * one, so every addition goes through here.
	 *
	 * @param WP_Error $error Error to annotate.
	 * @param array    $extra Keys to add.
	 */
	private static function with_data( WP_Error $error, array $extra ): WP_Error {
		$data   = $error->get_error_data();
		$merged = array_merge( is_array( $data ) ? $data : array(), $extra );

		/*
		 * The note has to go in the MESSAGE, not beside it in the data. The MCP
		 * transport throws the data away: mcp-adapter's ExecuteAbilityAbility
		 * returns array( 'success' => false, 'error' => $result->get_error_message() )
		 * and nothing else (wp-content/plugins/mcp-adapter/includes/Abilities/
		 * ExecuteAbilityAbility.php:138). An agent reading a failed tool call would
		 * otherwise be told only that the call failed, while a language change or an
		 * unlink had already landed -- which is the precise situation this helper
		 * exists to stop being silent. The data is still merged, because an
		 * in-process caller does see it and post_id is easier to use than to parse.
		 *
		 * A fresh WP_Error is the only way to amend a message; WP_Error has no
		 * setter. These are single-code errors raised by the abilities, so carrying
		 * the primary code and message across loses nothing. If that ever stops
		 * being true, this flattens a multi-code error and would need revisiting.
		 */
		$message = $error->get_error_message();
		if ( isset( $extra['note'] ) && '' !== $extra['note'] ) {
			$message = rtrim( $message ) . ' ' . $extra['note'];
		}

		return new WP_Error( $error->get_error_code(), $message, $merged );
	}

	/**
	 * Records, on an error, the writes update_content() had already committed when
	 * it hit that error.
	 *
	 * An agent reading a failed tool call assumes nothing happened, and for the two
	 * writes that have to precede the ability's own validation that assumption is
	 * wrong. Naming them turns a silent half-applied call into one the agent can
	 * finish or undo, which is the same bargain create_content() strikes when it
	 * creates a post it then cannot link.
	 *
	 * @param WP_Error    $error    Error about to be returned.
	 * @param int         $id       Post ID.
	 * @param string|null $language Language that was applied, or null.
	 * @param bool        $unlinked Whether the post was unlinked first.
	 * @param string      $prefix   Leading sentence, when the ability itself succeeded.
	 */
	private static function note_applied( WP_Error $error, int $id, ?string $language, bool $unlinked, string $prefix = '' ): WP_Error {
		$applied = array();
		if ( $unlinked ) {
			$applied[] = 'it was unlinked from its translation group';
		}
		if ( null !== $language ) {
			$applied[] = sprintf( 'its language was set to "%s"', $language );
		}

		if ( ! $applied ) {
			return '' === $prefix ? $error : self::with_data( $error, array( 'post_id' => $id, 'note' => $prefix ) );
		}

		$note = '' === $prefix
			? sprintf( 'The update failed, but before it did, %s.', implode( ' and ', $applied ) )
			: sprintf( '%1$s Before that, %2$s.', $prefix, implode( ' and ', $applied ) );

		return self::with_data(
			$error,
			array(
				'post_id' => $id,
				'note'    => $note . ' The item is in that state now, so read it back before retrying.',
			)
		);
	}
}
