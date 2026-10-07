<?php
/**
 * Plugin Name: Site Content Abilities
 * Description: Least-privilege WordPress Abilities for AI agents, exposed through the MCP Adapter: describe the content model, list, read, create, update and trash content of any public post type, manage terms in any public taxonomy, sideload media, and write SEO fields when a supported SEO plugin is active.
 * Version: 2.0.0
 *
 * Brand-neutral on purpose. No site name, domain or company appears anywhere in
 * this file: labels and descriptions are built from get_bloginfo() at
 * registration time, and everything else is discovered from the install itself
 * (post types, taxonomies, the active SEO plugin). Drop the file into any
 * WordPress site's mu-plugins directory and the same ability names --
 * site/list-content and friends -- work unchanged, so agent prompts and skills
 * written against one site keep working against the next.
 *
 * Every ability runs as the authenticated WordPress user and re-checks that
 * user's capabilities, so an Editor-level API user can never do more than an
 * Editor can in wp-admin. Nothing here deletes permanently: trash only.
 *
 * Ships to production (unlike local-dev-login.php). Abilities are discovered
 * and executed through the MCP Adapter's meta tools, or directly via
 * /wp-json/wp-abilities/v1/abilities/<name>/run.
 */

defined( 'ABSPATH' ) || exit;

// WordPress 6.9+ ships the Abilities API. On anything older this file is inert.
if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

add_action( 'wp_abilities_api_categories_init', array( 'Site_Content_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'Site_Content_Abilities', 'register' ) );

/**
 * Content abilities for AI agents, with no site-specific knowledge baked in.
 */
final class Site_Content_Abilities {

	/**
	 * Ability namespace. Deliberately generic so every site exposes the same
	 * tool names; change it only if a site must run two ability sets at once.
	 */
	const NS = 'site';

	/** Ability category slug. */
	const CATEGORY = 'site-content';

	/** Post statuses these abilities may set. */
	const STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

	// -----------------------------------------------------------------------
	// Registration.
	// -----------------------------------------------------------------------

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Site content',
				'description' => sprintf(
					'Posts, pages, custom post types, terms, media and SEO fields for %1$s (%2$s).',
					get_bloginfo( 'name' ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				),
			)
		);
	}

	public static function register(): void {
		$readonly = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
		$writes   = array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
		$meta     = static function ( array $annotations ): array {
			return array(
				'public'       => true,
				'show_in_rest' => true,
				'annotations'  => $annotations,
			);
		};

		$types      = self::content_post_types();
		$taxonomies = self::content_taxonomies();

		$post_type = array(
			'type'        => 'string',
			'default'     => self::default_post_type(),
			'enum'        => $types,
			'description' => 'Post type slug. This site has: ' . implode( ', ', $types ) . '.',
		);
		$taxonomy  = array(
			'type'        => 'string',
			'default'     => self::default_taxonomy(),
			'enum'        => $taxonomies,
			'description' => 'Taxonomy slug. This site has: ' . implode( ', ', $taxonomies ) . '.',
		);

		$content_fields = array(
			'title'             => array( 'type' => 'string' ),
			'content'           => array( 'type' => 'string', 'description' => 'Block editor markup (HTML with <!-- wp:... --> comments). Plain HTML also works but loses block editing.' ),
			'excerpt'           => array( 'type' => 'string' ),
			'slug'              => array( 'type' => 'string' ),
			'status'            => array( 'type' => 'string', 'enum' => self::STATUSES, 'description' => 'Publishing needs the publish capability. "future" requires a future date.' ),
			'date'              => array( 'type' => 'string', 'description' => 'Publish date, ISO 8601 or "YYYY-MM-DD HH:MM:SS" in the site timezone.' ),
			'terms'             => array(
				'type'                 => 'object',
				'description'          => 'Terms keyed by taxonomy slug, e.g. {"category": ["News"], "post_tag": [12]}. Values are term names or IDs; unknown names are created when you may create terms. Each taxonomy listed is replaced wholesale, taxonomies left out are untouched. Call ' . self::NS . '/describe-content-model for the taxonomies a post type accepts.',
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'string', 'integer' ) ),
				),
			),
			'categories'        => array( 'type' => 'array', 'items' => array( 'type' => array( 'string', 'integer' ) ), 'description' => 'Shorthand for terms.category.' ),
			'tags'              => array( 'type' => 'array', 'items' => array( 'type' => array( 'string', 'integer' ) ), 'description' => 'Shorthand for terms.post_tag.' ),
			'featured_media_id' => array( 'type' => 'integer', 'description' => 'Attachment ID of an image; 0 removes the featured image. Needs a post type that supports thumbnails.' ),
		);

		$seo = self::seo_provider();
		if ( $seo ) {
			$content_fields['seo'] = array(
				'type'        => 'object',
				'description' => 'SEO fields, written through ' . $seo['label'] . ' (detected on this site).',
				'properties'  => array(
					'title'           => array( 'type' => 'string', 'description' => 'SEO title; the SEO plugin\'s template variables are allowed.' ),
					'description'     => array( 'type' => 'string', 'description' => 'Meta description.' ),
					'focus_keyphrase' => array( 'type' => 'string' ),
				),
			);
		}

		wp_register_ability(
			self::NS . '/describe-content-model',
			array(
				'label'               => 'Describe the site content model',
				'description'         => 'Returns what this particular site offers: its post types with the taxonomies and features each supports, its taxonomies, the statuses these abilities may set, the detected SEO plugin, and which of them the current user may edit or publish. Call this first on an unfamiliar site.',
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback'    => array( self::class, 'describe_content_model' ),
				'permission_callback' => array( self::class, 'can_edit_something' ),
				'meta'                => $meta( $readonly ),
			)
		);

		wp_register_ability(
			self::NS . '/list-content',
			array(
				'label'               => 'List content',
				'description'         => 'Lists items of a post type the user may edit, any status, newest first, with search and pagination. Returns summaries without the full content.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => $post_type,
						'status'    => array( 'type' => 'string', 'default' => 'any', 'description' => 'publish, draft, pending, future, private, trash, or any (everything except trash).' ),
						'search'    => array( 'type' => 'string' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title' ), 'default' => 'date' ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					),
				),
				'execute_callback'    => array( self::class, 'list_content' ),
				'permission_callback' => array( self::class, 'can_edit_type' ),
				'meta'                => $meta( $readonly ),
			)
		);

		wp_register_ability(
			self::NS . '/get-content',
			array(
				'label'               => 'Get one item',
				'description'         => 'Returns one item with its full block content, terms, featured image and SEO fields. Look up by id, or by slug plus post_type.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'slug'      => array( 'type' => 'string' ),
						'post_type' => $post_type,
					),
				),
				'execute_callback'    => array( self::class, 'get_content' ),
				'permission_callback' => array( self::class, 'can_edit_type' ),
				'meta'                => $meta( $readonly ),
			)
		);

		wp_register_ability(
			self::NS . '/create-content',
			array(
				'label'               => 'Create content',
				'description'         => 'Creates an item of a post type. Defaults to a draft; publishing requires the publish capability. Content is block editor markup.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array_merge( array( 'post_type' => $post_type ), $content_fields ),
				),
				'execute_callback'    => array( self::class, 'create_content' ),
				'permission_callback' => array( self::class, 'can_edit_type' ),
				'meta'                => $meta( $writes ),
			)
		);

		wp_register_ability(
			self::NS . '/update-content',
			array(
				'label'               => 'Update content',
				'description'         => 'Updates only the fields provided on an existing item. Send the complete new content when changing content.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $content_fields ),
				),
				'execute_callback'    => array( self::class, 'update_content' ),
				'permission_callback' => array( self::class, 'can_edit_post' ),
				'meta'                => $meta( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
			)
		);

		wp_register_ability(
			self::NS . '/trash-content',
			array(
				'label'               => 'Move an item to the trash',
				'description'         => 'Moves an item to the trash (recoverable in wp-admin for 30 days). There is no permanent delete.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'execute_callback'    => array( self::class, 'trash_content' ),
				'permission_callback' => array( self::class, 'can_delete_post' ),
				'meta'                => $meta( array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ) ),
			)
		);

		wp_register_ability(
			self::NS . '/list-terms',
			array(
				'label'               => 'List terms',
				'description'         => 'Lists terms of a taxonomy including empty ones.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => $taxonomy,
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ),
					),
				),
				'execute_callback'    => array( self::class, 'list_terms' ),
				'permission_callback' => array( self::class, 'can_edit_something' ),
				'meta'                => $meta( $readonly ),
			)
		);

		wp_register_ability(
			self::NS . '/create-term',
			array(
				'label'               => 'Create a term',
				'description'         => 'Creates a term in a taxonomy.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'taxonomy'    => $taxonomy,
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID (hierarchical taxonomies only).' ),
					),
				),
				'execute_callback'    => array( self::class, 'create_term' ),
				'permission_callback' => array( self::class, 'can_manage_terms' ),
				'meta'                => $meta( $writes ),
			)
		);

		wp_register_ability(
			self::NS . '/sideload-media',
			array(
				'label'               => 'Add media from a URL',
				'description'         => 'Downloads a public http(s) file into the media library and returns the attachment. Optionally sets alt text and caption and attaches it to a post.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'               => array( 'type' => 'string', 'format' => 'uri' ),
						'title'             => array( 'type' => 'string' ),
						'alt_text'          => array( 'type' => 'string' ),
						'caption'           => array( 'type' => 'string' ),
						'attach_to_post_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( self::class, 'sideload_media' ),
				'permission_callback' => array( self::class, 'can_upload' ),
				'meta'                => $meta( $writes ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Permission callbacks. Each receives the (already schema-validated) input.
	// -----------------------------------------------------------------------

	public static function can_edit_something( $input = array() ) {
		foreach ( self::content_post_types() as $type ) {
			$pto = get_post_type_object( $type );
			if ( $pto && current_user_can( $pto->cap->edit_posts ) ) {
				return true;
			}
		}
		return self::error( 'forbidden', 'You may not edit content on this site.', 403 );
	}

	public static function can_edit_type( $input = array() ) {
		$pto = self::post_type( self::input( $input ) );
		if ( is_wp_error( $pto ) ) {
			return $pto;
		}
		return current_user_can( $pto->cap->edit_posts )
			? true
			: self::error( 'forbidden', sprintf( 'You may not edit content of type "%s".', $pto->name ), 403 );
	}

	public static function can_edit_post( $input = array() ) {
		$post = self::post_from_id( self::input( $input ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( 'edit_post', $post->ID )
			? true
			: self::error( 'forbidden', 'You may not edit this item.', 403 );
	}

	public static function can_delete_post( $input = array() ) {
		$post = self::post_from_id( self::input( $input ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return current_user_can( 'delete_post', $post->ID )
			? true
			: self::error( 'forbidden', 'You may not trash this item.', 403 );
	}

	public static function can_manage_terms( $input = array() ) {
		$tax = self::taxonomy( self::input( $input ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		return current_user_can( $tax->cap->edit_terms )
			? true
			: self::error( 'forbidden', sprintf( 'You may not manage "%s" terms.', $tax->name ), 403 );
	}

	public static function can_upload( $input = array() ) {
		return current_user_can( 'upload_files' )
			? true
			: self::error( 'forbidden', 'You may not upload files.', 403 );
	}

	// -----------------------------------------------------------------------
	// Execute callbacks.
	// -----------------------------------------------------------------------

	public static function describe_content_model( $input = array() ): array {
		$post_types = array();
		foreach ( self::content_post_types() as $type ) {
			$pto      = get_post_type_object( $type );
			$supports = array_values(
				array_filter(
					array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields', 'page-attributes' ),
					static function ( string $feature ) use ( $type ): bool {
						return post_type_supports( $type, $feature );
					}
				)
			);

			$post_types[] = array(
				'slug'         => $pto->name,
				'label'        => $pto->labels->name,
				'hierarchical' => (bool) $pto->hierarchical,
				'taxonomies'   => array_values( array_intersect( get_object_taxonomies( $pto->name ), self::content_taxonomies() ) ),
				'supports'     => $supports,
				'can_edit'     => current_user_can( $pto->cap->edit_posts ),
				'can_publish'  => current_user_can( $pto->cap->publish_posts ),
			);
		}

		$taxonomies = array();
		foreach ( self::content_taxonomies() as $name ) {
			$tax          = get_taxonomy( $name );
			$taxonomies[] = array(
				'slug'             => $tax->name,
				'label'            => $tax->labels->name,
				'hierarchical'     => (bool) $tax->hierarchical,
				'post_types'       => array_values( (array) $tax->object_type ),
				'can_assign'       => current_user_can( $tax->cap->assign_terms ),
				'can_create_terms' => current_user_can( $tax->cap->edit_terms ),
			);
		}

		$seo = self::seo_provider();

		return array(
			'site'        => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'language'    => str_replace( '_', '-', get_locale() ),
				'timezone'    => wp_timezone_string(),
			),
			'post_types'  => $post_types,
			'taxonomies'  => $taxonomies,
			'statuses'    => self::STATUSES,
			'seo'         => $seo
				? array( 'provider' => $seo['id'], 'label' => $seo['label'], 'fields' => array_keys( $seo['fields'] ) )
				: null,
			'media'       => array( 'can_upload' => current_user_can( 'upload_files' ) ),
			'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
		);
	}

	public static function list_content( $input = array() ) {
		$input = self::input( $input );
		$pto   = self::post_type( $input );
		if ( is_wp_error( $pto ) ) {
			return $pto;
		}
		$status  = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';
		$allowed = array_merge( self::STATUSES, array( 'trash' ) );
		if ( 'any' !== $status && ! in_array( $status, $allowed, true ) ) {
			return self::error( 'bad_status', 'Unknown status "' . $status . '".', 400 );
		}
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$orderby  = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'modified', 'title' ), true ) ? $input['orderby'] : 'date';
		$order    = isset( $input['order'] ) && 'asc' === strtolower( (string) $input['order'] ) ? 'ASC' : 'DESC';

		$query = new WP_Query(
			array(
				'post_type'           => $pto->name,
				'post_status'         => 'any' === $status ? self::STATUSES : $status,
				's'                   => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => $orderby,
				'order'               => $order,
				'ignore_sticky_posts' => true,
			)
		);

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'items'       => array_map(
				static function ( WP_Post $post ): array {
					return self::format_post( $post, false );
				},
				$query->posts
			),
		);
	}

	public static function get_content( $input = array() ) {
		$input = self::input( $input );
		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$pto = self::post_type( $input );
			if ( is_wp_error( $pto ) ) {
				return $pto;
			}
			$found = get_posts(
				array(
					'name'        => sanitize_title( (string) $input['slug'] ),
					'post_type'   => $pto->name,
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);
			$post  = $found[0] ?? null;
		} else {
			return self::error( 'missing_lookup', 'Provide id, or slug with post_type.', 400 );
		}
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			return self::error( 'not_found', 'No such item.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::error( 'forbidden', 'You may not read this item.', 403 );
		}
		return self::format_post( $post, true );
	}

	public static function create_content( $input = array() ) {
		$input = self::input( $input );
		$pto   = self::post_type( $input );
		if ( is_wp_error( $pto ) ) {
			return $pto;
		}
		if ( empty( $input['title'] ) || ! is_string( $input['title'] ) ) {
			return self::error( 'missing_title', 'title is required.', 400 );
		}
		$postarr = self::apply_fields( $input, array( 'post_type' => $pto->name, 'post_status' => 'draft' ), $pto );
		if ( is_wp_error( $postarr ) ) {
			return $postarr;
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 500 ) );
			return $id;
		}
		$relations = self::apply_relations( (int) $id, $input );
		if ( is_wp_error( $relations ) ) {
			$relations->add_data( array( 'post_id' => (int) $id, 'note' => 'The item was created; only the related fields failed.' ) );
			return $relations;
		}
		return self::format_post( get_post( (int) $id ), true );
	}

	public static function update_content( $input = array() ) {
		$input = self::input( $input );
		$post  = self::post_from_id( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$pto     = get_post_type_object( $post->post_type );
		$postarr = self::apply_fields( $input, array( 'ID' => $post->ID ), $pto );
		if ( is_wp_error( $postarr ) ) {
			return $postarr;
		}
		if ( count( $postarr ) > 1 ) {
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 500 ) );
				return $result;
			}
		}
		$relations = self::apply_relations( $post->ID, $input );
		if ( is_wp_error( $relations ) ) {
			return $relations;
		}
		return self::format_post( get_post( $post->ID ), true );
	}

	public static function trash_content( $input = array() ) {
		$post = self::post_from_id( self::input( $input ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'trash' === $post->post_status ) {
			return array( 'id' => $post->ID, 'status' => 'trash', 'note' => 'Already in the trash.' );
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return self::error( 'trash_failed', 'WordPress refused to trash this item.', 500 );
		}
		return array( 'id' => $post->ID, 'status' => 'trash', 'title' => $post->post_title );
	}

	public static function list_terms( $input = array() ) {
		$input = self::input( $input );
		$tax   = self::taxonomy( $input );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax->name,
				'hide_empty' => false,
				'search'     => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
				'number'     => isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 100,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms->add_data( array( 'status' => 500 ) );
			return $terms;
		}
		return array( 'taxonomy' => $tax->name, 'items' => array_map( array( self::class, 'format_term' ), $terms ) );
	}

	public static function create_term( $input = array() ) {
		$input = self::input( $input );
		$tax   = self::taxonomy( $input );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( empty( $input['name'] ) || ! is_string( $input['name'] ) ) {
			return self::error( 'missing_name', 'name is required.', 400 );
		}
		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( ! empty( $input['parent'] ) && is_taxonomy_hierarchical( $tax->name ) ) {
			$args['parent'] = (int) $input['parent'];
		}
		$result = wp_insert_term( sanitize_text_field( $input['name'] ), $tax->name, $args );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return self::format_term( get_term( (int) $result['term_id'], $tax->name ) );
	}

	public static function sideload_media( $input = array() ) {
		$input = self::input( $input );
		$url   = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return self::error( 'bad_url', 'url must be a public http(s) URL.', 400 );
		}
		$post_id = isset( $input['attach_to_post_id'] ) ? (int) $input['attach_to_post_id'] : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return self::error( 'forbidden', 'You may not attach media to that post.', 403 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// download_url() uses wp_safe_remote_get(), which refuses private/loopback hosts.
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			$tmp->add_data( array( 'status' => 400 ) );
			return $tmp;
		}
		$filename = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $filename ) {
			$filename = 'upload';
		}
		if ( '' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : false;
			foreach ( wp_get_mime_types() as $exts => $type ) {
				if ( $mime && $type === $mime ) {
					$filename .= '.' . explode( '|', $exts )[0];
					break;
				}
			}
		}
		$id = media_handle_sideload(
			array( 'name' => $filename, 'tmp_name' => $tmp ),
			$post_id,
			isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$id->add_data( array( 'status' => 400 ) );
			return $id;
		}
		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}
		if ( ! empty( $input['caption'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => sanitize_text_field( (string) $input['caption'] ) ) );
		}
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'        => (int) $id,
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => get_post_mime_type( $id ),
			'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'title'     => get_the_title( $id ),
		);
	}

	// -----------------------------------------------------------------------
	// Discovery. Everything site-specific is learned here, never assumed.
	// -----------------------------------------------------------------------

	/**
	 * Public post types an agent may author, most familiar first.
	 *
	 * @return string[]
	 */
	private static function content_post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pto ) {
			if ( 'attachment' === $pto->name ) {
				continue;
			}
			$types[] = $pto->name;
		}
		$first = array_values( array_intersect( array( 'post', 'page' ), $types ) );
		$types = array_values( array_unique( array_merge( $first, $types ) ) );

		/**
		 * Filters the post types these abilities expose.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return array_values( (array) apply_filters( 'site_abilities_post_types', $types ) );
	}

	/**
	 * Public taxonomies an agent may use, most familiar first.
	 *
	 * @return string[]
	 */
	private static function content_taxonomies(): array {
		$names = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$names[] = $tax->name;
		}
		$first = array_values( array_intersect( array( 'category', 'post_tag' ), $names ) );
		$names = array_values( array_unique( array_merge( $first, $names ) ) );

		/**
		 * Filters the taxonomies these abilities expose.
		 *
		 * @param string[] $names Taxonomy slugs.
		 */
		return array_values( (array) apply_filters( 'site_abilities_taxonomies', $names ) );
	}

	private static function default_post_type(): string {
		$types = self::content_post_types();
		return in_array( 'post', $types, true ) ? 'post' : ( $types[0] ?? 'post' );
	}

	private static function default_taxonomy(): string {
		$names = self::content_taxonomies();
		return in_array( 'category', $names, true ) ? 'category' : ( $names[0] ?? 'category' );
	}

	/**
	 * The SEO plugin in use, or null when none is recognised.
	 *
	 * Keeps the seo field out of the schema and out of the output entirely on a
	 * site with no SEO plugin, rather than writing meta nothing will ever read.
	 *
	 * @return array{id:string,label:string,fields:array<string,string>}|null
	 */
	private static function seo_provider(): ?array {
		static $provider = false;
		if ( false !== $provider ) {
			return $provider;
		}

		$candidates = array(
			array(
				'id'     => 'yoast',
				'label'  => 'Yoast SEO',
				'active' => defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ),
				'fields' => array(
					'title'           => '_yoast_wpseo_title',
					'description'     => '_yoast_wpseo_metadesc',
					'focus_keyphrase' => '_yoast_wpseo_focuskw',
				),
			),
			array(
				'id'     => 'rank-math',
				'label'  => 'Rank Math',
				'active' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
				'fields' => array(
					'title'           => 'rank_math_title',
					'description'     => 'rank_math_description',
					'focus_keyphrase' => 'rank_math_focus_keyword',
				),
			),
			array(
				'id'     => 'seopress',
				'label'  => 'SEOPress',
				'active' => defined( 'SEOPRESS_VERSION' ),
				'fields' => array(
					'title'           => '_seopress_titles_title',
					'description'     => '_seopress_titles_desc',
					'focus_keyphrase' => '_seopress_analysis_target_kw',
				),
			),
		);

		$found = null;
		foreach ( $candidates as $candidate ) {
			if ( $candidate['active'] ) {
				unset( $candidate['active'] );
				$found = $candidate;
				break;
			}
		}

		/**
		 * Filters the detected SEO plugin and its post meta keys.
		 *
		 * Return null to drop the seo field entirely, or an array with id,
		 * label and a fields map of title/description/focus_keyphrase to meta
		 * keys to support an SEO plugin this file does not know about.
		 *
		 * @param array|null $found Detected provider.
		 */
		$provider = apply_filters( 'site_abilities_seo_provider', $found );
		return $provider;
	}

	// -----------------------------------------------------------------------
	// Plumbing.
	// -----------------------------------------------------------------------

	private static function input( $input ): array {
		return is_array( $input ) ? $input : array();
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( 'site_abilities_' . $code, $message, array( 'status' => $status ) );
	}

	/** @return WP_Post_Type|WP_Error */
	private static function post_type( array $input ) {
		$types = self::content_post_types();
		$type  = isset( $input['post_type'] ) && is_string( $input['post_type'] )
			? sanitize_key( $input['post_type'] )
			: self::default_post_type();
		if ( ! in_array( $type, $types, true ) ) {
			return self::error( 'bad_post_type', sprintf( 'Unknown or unsupported post type "%1$s". This site has: %2$s.', $type, implode( ', ', $types ) ), 400 );
		}
		return get_post_type_object( $type );
	}

	/** @return WP_Taxonomy|WP_Error */
	private static function taxonomy( array $input ) {
		$names = self::content_taxonomies();
		$name  = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] )
			? sanitize_key( $input['taxonomy'] )
			: self::default_taxonomy();
		if ( ! in_array( $name, $names, true ) ) {
			return self::error( 'bad_taxonomy', sprintf( 'Unknown or unsupported taxonomy "%1$s". This site has: %2$s.', $name, implode( ', ', $names ) ), 400 );
		}
		return get_taxonomy( $name );
	}

	/** @return WP_Post|WP_Error */
	private static function post_from_id( array $input ) {
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			return self::error( 'not_found', 'No such item.', 404 );
		}
		return $post;
	}

	/** @return array|WP_Error */
	private static function apply_fields( array $input, array $postarr, ?WP_Post_Type $pto ) {
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' ) as $key => $field ) {
			if ( array_key_exists( $key, $input ) && is_string( $input[ $key ] ) ) {
				$postarr[ $field ] = $input[ $key ];
			}
		}
		if ( isset( $input['status'] ) ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return self::error( 'bad_status', 'status must be one of: ' . implode( ', ', self::STATUSES ) . '.', 400 );
			}
			if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ( ! $pto || ! current_user_can( $pto->cap->publish_posts ) ) ) {
				return self::error( 'forbidden', 'You may not publish this content type; save it as draft or pending instead.', 403 );
			}
			$postarr['post_status'] = $status;
		}
		if ( ! empty( $input['date'] ) ) {
			$dt = date_create( (string) $input['date'], wp_timezone() );
			if ( ! $dt ) {
				return self::error( 'bad_date', 'date could not be parsed.', 400 );
			}
			$dt->setTimezone( wp_timezone() );
			$postarr['post_date']     = $dt->format( 'Y-m-d H:i:s' );
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
		}
		return $postarr;
	}

	/** @return true|WP_Error */
	private static function apply_relations( int $post_id, array $input ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::error( 'not_found', 'No such item.', 404 );
		}

		$requested = array();
		if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
			foreach ( $input['terms'] as $taxonomy => $terms ) {
				if ( is_array( $terms ) ) {
					$requested[ sanitize_key( (string) $taxonomy ) ] = $terms;
				}
			}
		}
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $key => $taxonomy ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$requested[ $taxonomy ] = $input[ $key ];
			}
		}

		$attached = get_object_taxonomies( $post->post_type );
		foreach ( $requested as $taxonomy => $terms ) {
			if ( ! in_array( $taxonomy, $attached, true ) ) {
				return self::error(
					'bad_taxonomy',
					sprintf(
						'Post type "%1$s" has no "%2$s" taxonomy. It accepts: %3$s.',
						$post->post_type,
						$taxonomy,
						$attached ? implode( ', ', $attached ) : 'none'
					),
					400
				);
			}
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
				return self::error( 'forbidden', sprintf( 'You may not assign "%s" terms.', $taxonomy ), 403 );
			}
			$ids = self::resolve_terms( $terms, $taxonomy );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$set = wp_set_post_terms( $post_id, $ids, $taxonomy );
			if ( is_wp_error( $set ) ) {
				$set->add_data( array( 'status' => 500 ) );
				return $set;
			}
		}

		if ( isset( $input['featured_media_id'] ) ) {
			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				return self::error( 'no_thumbnail_support', sprintf( 'Post type "%s" does not support featured images.', $post->post_type ), 400 );
			}
			$media_id = (int) $input['featured_media_id'];
			if ( $media_id <= 0 ) {
				delete_post_thumbnail( $post_id );
			} elseif ( ! wp_attachment_is_image( $media_id ) ) {
				return self::error( 'bad_media', 'featured_media_id must be an image attachment.', 400 );
			} else {
				set_post_thumbnail( $post_id, $media_id );
			}
		}

		if ( isset( $input['seo'] ) && is_array( $input['seo'] ) ) {
			$seo = self::seo_provider();
			if ( ! $seo ) {
				return self::error( 'no_seo_plugin', 'This site has no SEO plugin these abilities recognise, so seo fields cannot be stored.', 400 );
			}
			foreach ( $seo['fields'] as $key => $meta_key ) {
				if ( array_key_exists( $key, $input['seo'] ) && is_string( $input['seo'][ $key ] ) ) {
					update_post_meta( $post_id, $meta_key, sanitize_text_field( $input['seo'][ $key ] ) );
				}
			}
		}

		return true;
	}

	/** @return int[]|WP_Error */
	private static function resolve_terms( array $terms, string $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
				$found = get_term( (int) $term, $taxonomy );
				if ( ! $found instanceof WP_Term ) {
					return self::error( 'bad_term', sprintf( 'No %1$s with ID %2$d.', $taxonomy, (int) $term ), 400 );
				}
				$ids[] = $found->term_id;
				continue;
			}
			$name  = sanitize_text_field( (string) $term );
			$found = get_term_by( 'name', $name, $taxonomy );
			if ( ! $found ) {
				$found = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			}
			if ( $found instanceof WP_Term ) {
				$ids[] = $found->term_id;
				continue;
			}
			if ( ! current_user_can( $tax->cap->edit_terms ) ) {
				return self::error( 'forbidden', sprintf( '%1$s "%2$s" does not exist and you may not create terms.', $tax->labels->singular_name, $name ), 403 );
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				$created->add_data( array( 'status' => 400 ) );
				return $created;
			}
			$ids[] = (int) $created['term_id'];
		}
		return $ids;
	}

	private static function format_term( WP_Term $term ): array {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
			'description' => $term->description,
		);
	}

	private static function format_post( WP_Post $post, bool $full ): array {
		$terms = array();
		foreach ( array_intersect( get_object_taxonomies( $post->post_type ), self::content_taxonomies() ) as $taxonomy ) {
			$assigned           = get_the_terms( $post, $taxonomy );
			$terms[ $taxonomy ] = is_array( $assigned ) ? array_values( array_map( array( self::class, 'format_term' ), $assigned ) ) : array();
		}

		$thumb_id = (int) get_post_thumbnail_id( $post );
		$data     = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'link'           => get_permalink( $post ),
			'edit_link'      => get_edit_post_link( $post->ID, 'raw' ),
			'date'           => get_post_time( DATE_ATOM, false, $post ),
			'modified'       => get_post_modified_time( DATE_ATOM, false, $post ),
			'author'         => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'excerpt'        => $post->post_excerpt,
			'terms'          => $terms,
			'featured_image' => $thumb_id ? array( 'id' => $thumb_id, 'url' => wp_get_attachment_image_url( $thumb_id, 'full' ) ) : null,
		);

		$seo = self::seo_provider();
		if ( $seo ) {
			$data['seo'] = array();
			foreach ( $seo['fields'] as $key => $meta_key ) {
				$data['seo'][ $key ] = (string) get_post_meta( $post->ID, $meta_key, true );
			}
		}

		if ( $full ) {
			$data['content']    = $post->post_content;
			$data['word_count'] = str_word_count( wp_strip_all_tags( $post->post_content ) );
		}

		return $data;
	}
}
