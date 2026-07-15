<?php
/**
 * Registers enabled abilities with the WordPress Abilities API.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the registry to wp_register_ability() / wp_register_ability_category().
 */
class Abilities {

	const CATEGORY_PREFIX = 'hlb-mcp-';

	/**
	 * Ability registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings resolver.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Ability registry.
	 * @param Settings $settings Settings resolver.
	 */
	public function __construct( Registry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API not present (WP < 6.9 without the feature plugin).
		}
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register a category per registry group.
	 *
	 * @return void
	 */
	public function register_categories() {
		foreach ( $this->registry->categories() as $id => $label ) {
			wp_register_ability_category(
				self::CATEGORY_PREFIX . $id,
				[
					'label' => $label,
					// WP 7.0's WP_Ability_Category requires a non-empty description;
					// without it the category (and every ability using it) fails to register.
					'description' => sprintf(
						/* translators: %s: category label. */
						__( '%s abilities exposed to MCP clients by HLB MCP Abilities.', 'hlb-mcp-abilities' ),
						$label
					),
				]
			);
		}
	}

	/**
	 * Register each enabled ability.
	 *
	 * @return void
	 */
	public function register_abilities() {
		$enabled = $this->settings->enabled_ids();

		foreach ( $enabled as $id ) {
			$def = $this->registry->get( $id );
			if ( null === $def ) {
				continue;
			}
			wp_register_ability( $id, $this->build_args( $id, $def ) );
		}
	}

	/**
	 * Assemble the wp_register_ability() args for one ability.
	 *
	 * @param string $id  Ability id.
	 * @param array  $def Registry definition.
	 * @return array
	 */
	private function build_args( $id, array $def ) {
		$capability = isset( $def['capability'] ) ? $def['capability'] : 'manage_options';
		$handler    = $def['handler'];
		$self       = $this;
		$network    = $this->network_context();

		$args = [
			'label'               => $def['label'],
			'description'         => $def['description'],
			'category'            => self::CATEGORY_PREFIX . $def['category'],
			'execute_callback'    => function ( $input = [] ) use ( $handler, $network, $self ) {
				$input = is_array( $input ) ? $input : [];
				if ( $network && ! empty( $input['site'] ) ) {
					$blog_id = $self->resolve_blog_id( $input['site'] );
					if ( ! $blog_id ) {
						return new \WP_Error( 'hlb_mcp_invalid_site', __( 'Unknown target site.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
					}
					switch_to_blog( $blog_id );
					$result = call_user_func( $handler, $input );
					restore_current_blog();
					return $result;
				}
				return call_user_func( $handler, $input );
			},
			'permission_callback' => function ( $input = [] ) use ( $capability, $network, $self ) {
				$input = is_array( $input ) ? $input : [];
				if ( $network && ! empty( $input['site'] ) ) {
					$blog_id = $self->resolve_blog_id( $input['site'] );
					if ( ! $blog_id ) {
						return false;
					}
					switch_to_blog( $blog_id );
					$allowed = current_user_can( $capability );
					restore_current_blog();
					return $allowed;
				}
				return current_user_can( $capability );
			},
			'meta'                => [
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
				'annotations'  => isset( $def['annotations'] ) ? $def['annotations'] : [
					'readonly'    => null,
					'destructive' => null,
					'idempotent'  => null,
				],
			],
		];

		if ( isset( $def['input_schema'] ) ) {
			$args['input_schema'] = $def['input_schema'];
		}
		if ( isset( $def['output_schema'] ) ) {
			$args['output_schema'] = $def['output_schema'];
		}

		// In network mode, expose a `site` argument on every ability except the site
		// listing itself, so a single main-site server can target any subsite.
		if ( $network && 'hlb/list-sites' !== $id ) {
			if ( ! isset( $args['input_schema'] ) || ! is_array( $args['input_schema'] ) ) {
				$args['input_schema'] = [
					'type'       => 'object',
					'properties' => [],
				];
			}
			$args['input_schema']['properties']['site'] = [
				'type'        => 'string',
				'description' => __( 'Target subsite: blog ID, path slug, or domain. Omit to act on the main site.', 'hlb-mcp-abilities' ),
			];
		}

		return $args;
	}

	/**
	 * Whether the current request should expose the network (site-targeting) surface.
	 *
	 * @return bool
	 */
	private function network_context() {
		return is_multisite() && is_main_site() && $this->settings->is_network_mode();
	}

	/**
	 * Resolve a site reference (blog ID, path slug, or domain) to a blog ID.
	 *
	 * @param int|string $site Site reference.
	 * @return int Blog ID, or 0 if not found.
	 */
	public function resolve_blog_id( $site ) {
		if ( is_numeric( $site ) ) {
			$id = (int) $site;
			return get_site( $id ) ? $id : 0;
		}

		$site = trim( (string) $site, '/' );
		if ( '' === $site ) {
			return 0;
		}

		$id = get_id_from_blogname( $site );
		if ( $id ) {
			return (int) $id;
		}

		foreach ( get_sites( [ 'number' => 500 ] ) as $blog ) {
			if ( trim( $blog->path, '/' ) === $site || $blog->domain === $site ) {
				return (int) $blog->blog_id;
			}
		}

		return 0;
	}
}
