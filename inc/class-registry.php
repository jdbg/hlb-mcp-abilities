<?php
/**
 * Ability registry — the single source of truth for every ability this plugin can expose.
 *
 * Each entry drives three consumers: the settings UI, the Abilities API registration
 * loop, and the MCP server tool list. Handlers are thin adapters over core WP APIs.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

use HLB\MCP\Handlers\Comments;
use HLB\MCP\Handlers\Content;
use HLB\MCP\Handlers\Media;
use HLB\MCP\Handlers\Site;
use HLB\MCP\Handlers\Users;
use HLB\MCP\Handlers\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative catalogue of abilities and their categories.
 */
class Registry {

	/**
	 * Cached ability definitions.
	 *
	 * @var array<string,array>|null
	 */
	private $abilities = null;

	/**
	 * Ability categories, keyed by id.
	 *
	 * @return array<string,string> id => label
	 */
	public function categories() {
		return [
			'content-read'  => __( 'Content — read', 'hlb-mcp-abilities' ),
			'content-write' => __( 'Content — write', 'hlb-mcp-abilities' ),
			'media'         => __( 'Media', 'hlb-mcp-abilities' ),
			'comments'      => __( 'Comments', 'hlb-mcp-abilities' ),
			'users'         => __( 'Users', 'hlb-mcp-abilities' ),
			'site'          => __( 'Site & diagnostics', 'hlb-mcp-abilities' ),
			'woocommerce'   => __( 'WooCommerce', 'hlb-mcp-abilities' ),
		];
	}

	/**
	 * All ability definitions, keyed by ability id.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		if ( null !== $this->abilities ) {
			return $this->abilities;
		}

		$string  = [ 'type' => 'string' ];
		$integer = [ 'type' => 'integer' ];
		$boolean = [ 'type' => 'boolean' ];

		$defs = [

			/* ----------------------------------------------------------- Content: read */

			'hlb/get-post' => [
				'label'       => __( 'Get post', 'hlb-mcp-abilities' ),
				'description' => __( 'Retrieve a single post or page by ID or slug.', 'hlb-mcp-abilities' ),
				'category'    => 'content-read',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'get_post' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'id'        => $integer + [ 'description' => __( 'Post ID.', 'hlb-mcp-abilities' ) ],
						'slug'      => $string + [ 'description' => __( 'Post slug (used if ID omitted).', 'hlb-mcp-abilities' ) ],
						'post_type' => $string + [ 'default' => 'post' ],
					],
				],
			],

			'hlb/list-posts' => [
				'label'       => __( 'List posts', 'hlb-mcp-abilities' ),
				'description' => __( 'Query posts by type, status, author, and date with pagination.', 'hlb-mcp-abilities' ),
				'category'    => 'content-read',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'list_posts' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'post_type' => $string + [ 'default' => 'post' ],
						'status'    => $string + [ 'default' => 'publish' ],
						'author'    => $integer,
						'search'    => $string,
						'per_page'  => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'      => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			'hlb/search-content' => [
				'label'       => __( 'Search content', 'hlb-mcp-abilities' ),
				'description' => __( 'Full-text search across public post types.', 'hlb-mcp-abilities' ),
				'category'    => 'content-read',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'search_content' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'query' ],
					'properties' => [
						'query'    => $string + [ 'description' => __( 'Search terms.', 'hlb-mcp-abilities' ) ],
						'per_page' => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			'hlb/get-taxonomies' => [
				'label'       => __( 'Get taxonomies & terms', 'hlb-mcp-abilities' ),
				'description' => __( 'List categories, tags, and custom taxonomy terms.', 'hlb-mcp-abilities' ),
				'category'    => 'content-read',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'get_taxonomies' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'taxonomy' => $string + [ 'description' => __( 'Limit to a taxonomy (e.g. category, post_tag).', 'hlb-mcp-abilities' ) ],
					],
				],
			],

			/* ---------------------------------------------------------- Content: write */

			'hlb/create-post' => [
				'label'       => __( 'Create post', 'hlb-mcp-abilities' ),
				'description' => __( 'Create a new post or page (draft by default).', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'edit_posts',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => false,
				],
				'handler'     => [ Content::class, 'create_post' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'title' ],
					'properties' => [
						'title'     => $string,
						'content'   => $string,
						'excerpt'   => $string,
						'status'    => $string + [
							'default' => 'draft',
							'enum' => [ 'draft', 'pending', 'publish', 'private' ],
						],
						'post_type' => $string + [ 'default' => 'post' ],
					],
				],
			],

			'hlb/update-post' => [
				'label'       => __( 'Update post', 'hlb-mcp-abilities' ),
				'description' => __( 'Update the title, content, excerpt, or status of an existing post.', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'edit_posts',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => false,
				],
				'handler'     => [ Content::class, 'update_post' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'      => $integer,
						'title'   => $string,
						'content' => $string,
						'excerpt' => $string,
						'status'  => $string + [ 'enum' => [ 'draft', 'pending', 'publish', 'private' ] ],
					],
				],
			],

			'hlb/set-post-status' => [
				'label'       => __( 'Set post status', 'hlb-mcp-abilities' ),
				'description' => __( 'Transition a post between draft, pending, publish, private, or trash.', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'edit_posts',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'set_post_status' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id', 'status' ],
					'properties' => [
						'id'     => $integer,
						'status' => $string + [ 'enum' => [ 'draft', 'pending', 'publish', 'private', 'trash' ] ],
					],
				],
			],

			'hlb/delete-post' => [
				'label'       => __( 'Delete post', 'hlb-mcp-abilities' ),
				'description' => __( 'Trash a post, or permanently delete it when force is true.', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'delete_posts',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => true,
					'idempotent' => false,
				],
				'handler'     => [ Content::class, 'delete_post' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'    => $integer,
						'force' => $boolean + [
							'default' => false,
							'description' => __( 'Permanently delete instead of trashing.', 'hlb-mcp-abilities' ),
						],
					],
				],
			],

			'hlb/assign-terms' => [
				'label'       => __( 'Assign terms', 'hlb-mcp-abilities' ),
				'description' => __( 'Set categories, tags, or custom terms on a post.', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'edit_posts',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Content::class, 'assign_terms' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id', 'taxonomy', 'terms' ],
					'properties' => [
						'id'       => $integer,
						'taxonomy' => $string,
						'terms'    => [
							'type' => 'array',
							'items' => $string,
							'description' => __( 'Term slugs, names, or IDs.', 'hlb-mcp-abilities' ),
						],
						'append'   => $boolean + [ 'default' => false ],
					],
				],
			],

			'hlb/create-term' => [
				'label'       => __( 'Create term', 'hlb-mcp-abilities' ),
				'description' => __( 'Create a category, tag, or custom taxonomy term.', 'hlb-mcp-abilities' ),
				'category'    => 'content-write',
				'capability'  => 'manage_categories',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => false,
				],
				'handler'     => [ Content::class, 'create_term' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'taxonomy', 'name' ],
					'properties' => [
						'taxonomy'    => $string,
						'name'        => $string,
						'slug'        => $string,
						'description' => $string,
						'parent'      => $integer,
					],
				],
			],

			/* ------------------------------------------------------------------ Media */

			'hlb/list-media' => [
				'label'       => __( 'List media', 'hlb-mcp-abilities' ),
				'description' => __( 'Query the media library with pagination.', 'hlb-mcp-abilities' ),
				'category'    => 'media',
				'capability'  => 'upload_files',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Media::class, 'list_media' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'mime_type' => $string + [ 'description' => __( 'Filter by MIME type, e.g. image.', 'hlb-mcp-abilities' ) ],
						'per_page'  => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'      => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			'hlb/upload-media' => [
				'label'       => __( 'Upload media', 'hlb-mcp-abilities' ),
				'description' => __( 'Upload an image or file to the media library from a URL.', 'hlb-mcp-abilities' ),
				'category'    => 'media',
				'capability'  => 'upload_files',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => false,
					'idempotent' => false,
				],
				'handler'     => [ Media::class, 'upload_media' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'url' ],
					'properties' => [
						'url'     => $string + [
							'format' => 'uri',
							'description' => __( 'Source URL to sideload.', 'hlb-mcp-abilities' ),
						],
						'title'   => $string,
						'alt'     => $string,
						'post_id' => $integer + [ 'description' => __( 'Attach to this post.', 'hlb-mcp-abilities' ) ],
					],
				],
			],

			/* --------------------------------------------------------------- Comments */

			'hlb/list-comments' => [
				'label'       => __( 'List comments', 'hlb-mcp-abilities' ),
				'description' => __( 'List comments, optionally filtered by post or status.', 'hlb-mcp-abilities' ),
				'category'    => 'comments',
				'capability'  => 'moderate_comments',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Comments::class, 'list_comments' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'  => $integer,
						'status'   => $string + [
							'default' => 'approve',
							'enum' => [ 'approve', 'hold', 'spam', 'trash', 'all' ],
						],
						'per_page' => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			'hlb/moderate-comment' => [
				'label'       => __( 'Moderate comment', 'hlb-mcp-abilities' ),
				'description' => __( 'Approve, unapprove, spam, or trash a comment.', 'hlb-mcp-abilities' ),
				'category'    => 'comments',
				'capability'  => 'moderate_comments',
				'default'     => false,
				'annotations' => [
					'readonly' => false,
					'destructive' => true,
					'idempotent' => true,
				],
				'handler'     => [ Comments::class, 'moderate_comment' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id', 'action' ],
					'properties' => [
						'id'     => $integer,
						'action' => $string + [ 'enum' => [ 'approve', 'unapprove', 'spam', 'trash' ] ],
					],
				],
			],

			/* ------------------------------------------------------------------ Users */

			'hlb/get-current-user' => [
				'label'       => __( 'Get current user', 'hlb-mcp-abilities' ),
				'description' => __( 'Identity and capabilities of the authenticated MCP caller.', 'hlb-mcp-abilities' ),
				'category'    => 'users',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Users::class, 'get_current_user' ],
				'input_schema' => [
					'type' => 'object',
					'properties' => [],
				],
			],

			'hlb/list-users' => [
				'label'       => __( 'List users', 'hlb-mcp-abilities' ),
				'description' => __( 'List site users (contains personal data — off by default).', 'hlb-mcp-abilities' ),
				'category'    => 'users',
				'capability'  => 'list_users',
				'default'     => false,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Users::class, 'list_users' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'role'     => $string,
						'search'   => $string,
						'per_page' => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			/* ------------------------------------------------------ Site & diagnostics */

			'hlb/get-site-info' => [
				'label'       => __( 'Get site info', 'hlb-mcp-abilities' ),
				'description' => __( 'Site name, URL, WordPress version, language, and timezone.', 'hlb-mcp-abilities' ),
				'category'    => 'site',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Site::class, 'get_site_info' ],
				'input_schema' => [
					'type' => 'object',
					'properties' => [],
				],
			],

			'hlb/get-active-theme' => [
				'label'       => __( 'Get active theme', 'hlb-mcp-abilities' ),
				'description' => __( 'The active theme, its version, and whether it is a block theme.', 'hlb-mcp-abilities' ),
				'category'    => 'site',
				'capability'  => 'read',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Site::class, 'get_active_theme' ],
				'input_schema' => [
					'type' => 'object',
					'properties' => [],
				],
			],

			'hlb/list-active-plugins' => [
				'label'       => __( 'List active plugins', 'hlb-mcp-abilities' ),
				'description' => __( 'Active plugins with name and version (admin diagnostics).', 'hlb-mcp-abilities' ),
				'category'    => 'site',
				'capability'  => 'activate_plugins',
				'default'     => true,
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Site::class, 'list_active_plugins' ],
				'input_schema' => [
					'type' => 'object',
					'properties' => [],
				],
			],

			'hlb/list-sites' => [
				'label'       => __( 'List network sites', 'hlb-mcp-abilities' ),
				'description' => __( 'List the subsites in the multisite network so a `site` argument can target them (network mode).', 'hlb-mcp-abilities' ),
				'category'    => 'site',
				'capability'  => 'manage_sites',
				'default'     => true,
				'condition'   => [ Site::class, 'network_available' ],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ Site::class, 'list_sites' ],
				'input_schema' => [
					'type' => 'object',
					'properties' => [],
				],
			],

			/* ------------------------------------------------------------ WooCommerce */

			'hlb/wc-list-products' => [
				'label'       => __( 'List products (WooCommerce)', 'hlb-mcp-abilities' ),
				'description' => __( 'Query WooCommerce products with pagination.', 'hlb-mcp-abilities' ),
				'category'    => 'woocommerce',
				'capability'  => 'read',
				'default'     => true,
				'condition'   => [ WooCommerce::class, 'is_active' ],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ WooCommerce::class, 'list_products' ],
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'search'   => $string,
						'status'   => $string + [ 'default' => 'publish' ],
						'per_page' => $integer + [
							'default' => 10,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => $integer + [
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
			],

			'hlb/wc-get-order' => [
				'label'       => __( 'Get order (WooCommerce)', 'hlb-mcp-abilities' ),
				'description' => __( 'Fetch a WooCommerce order by ID (contains personal data — off by default).', 'hlb-mcp-abilities' ),
				'category'    => 'woocommerce',
				'capability'  => 'edit_shop_orders',
				'default'     => false,
				'condition'   => [ WooCommerce::class, 'is_active' ],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
				'handler'     => [ WooCommerce::class, 'get_order' ],
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [ 'id' => $integer ],
				],
			],
		];

		/**
		 * Filter the full ability catalogue before it is used.
		 *
		 * @param array<string,array> $defs Ability definitions keyed by id.
		 */
		$this->abilities = apply_filters( 'hlb_mcp_abilities', $defs );

		return $this->abilities;
	}

	/**
	 * Abilities whose environment condition is satisfied on the current site.
	 *
	 * @return array<string,array>
	 */
	public function available() {
		return array_filter(
			$this->all(),
			static function ( $def ) {
				if ( empty( $def['condition'] ) ) {
					return true;
				}
				return (bool) call_user_func( $def['condition'] );
			}
		);
	}

	/**
	 * A single ability definition, or null.
	 *
	 * @param string $id Ability id.
	 * @return array|null
	 */
	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Ability ids enabled by default (read-only set + anything flagged default true).
	 *
	 * @return string[]
	 */
	public function default_enabled_ids() {
		$ids = [];
		foreach ( $this->available() as $id => $def ) {
			if ( ! empty( $def['default'] ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * All valid ability ids (unconditioned; used to sanitize saved input).
	 *
	 * @return string[]
	 */
	public function all_ids() {
		return array_keys( $this->all() );
	}
}
