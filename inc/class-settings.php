<?php
/**
 * Settings store, enabled-set resolver, and MCP server naming.
 *
 * The resolver (enabled_ids) is the single source of truth consumed by both the
 * Abilities registration loop and the MCP server tool list — so an ability is never
 * registered without being exposed, or vice versa.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes plugin options and resolves the effective enabled ability set.
 */
class Settings {

	const NETWORK_OPTION = 'hlb_mcp_network';
	const SITE_OPTION    = 'hlb_mcp_site';
	const ROUTE          = 'mcp';

	/**
	 * Ability registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Ability registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/* --------------------------------------------------------------------- Resolver */

	/**
	 * The effective enabled ability ids for the current site.
	 *
	 * Multisite: use the network default unless this subsite overrides.
	 * Single site: use the site option.
	 * Always intersected with currently-available abilities.
	 *
	 * @return string[]
	 */
	public function enabled_ids() {
		if ( is_multisite() ) {
			$site = $this->site_config();
			if ( empty( $site['override'] ) ) {
				$ids = $this->network_enabled_ids();
			} else {
				$ids = isset( $site['enabled'] ) ? (array) $site['enabled'] : [];
			}
		} else {
			$site = $this->site_config();
			$ids  = isset( $site['enabled'] ) ? (array) $site['enabled'] : $this->registry->default_enabled_ids();
		}

		$available = array_keys( $this->registry->available() );
		$ids       = array_values( array_intersect( $ids, $available ) );

		/**
		 * Filter the resolved enabled ability ids for the current site.
		 *
		 * @param string[] $ids      Enabled ability ids.
		 * @param Settings $settings Settings instance.
		 */
		return apply_filters( 'hlb_mcp_enabled_ids', $ids, $this );
	}

	/**
	 * Network-level enabled ability ids (falls back to registry defaults).
	 *
	 * @return string[]
	 */
	public function network_enabled_ids() {
		$config = get_site_option( self::NETWORK_OPTION, [] );
		if ( isset( $config['enabled'] ) ) {
			return (array) $config['enabled'];
		}
		return $this->registry->default_enabled_ids();
	}

	/**
	 * Raw per-site config array.
	 *
	 * @return array{override?:bool,enabled?:string[]}
	 */
	public function site_config() {
		$config = get_option( self::SITE_OPTION, [] );
		return is_array( $config ) ? $config : [];
	}

	/**
	 * Whether this subsite overrides the network default.
	 *
	 * @return bool
	 */
	public function is_override() {
		$config = $this->site_config();
		return ! empty( $config['override'] );
	}

	/**
	 * Whether network mode is enabled.
	 *
	 * In network mode the main site's MCP server can target any subsite via a `site`
	 * argument, so subsites do not each need to be registered with the client.
	 *
	 * @return bool
	 */
	public function is_network_mode() {
		if ( ! is_multisite() ) {
			return false;
		}
		$config = get_site_option( self::NETWORK_OPTION, [] );
		return ! empty( $config['network_mode'] );
	}

	/* ------------------------------------------------------------------------ Writes */

	/**
	 * Persist the network default enabled set.
	 *
	 * @param string[] $ids          Ability ids.
	 * @param bool     $network_mode Whether to enable network mode.
	 * @return void
	 */
	public function save_network( array $ids, $network_mode = false ) {
		update_site_option(
			self::NETWORK_OPTION,
			[
				'enabled'      => $this->sanitize_ids( $ids ),
				'network_mode' => (bool) $network_mode,
			]
		);
	}

	/**
	 * Persist the per-site config.
	 *
	 * @param bool     $override Whether the subsite overrides the network default.
	 * @param string[] $ids      Ability ids (only meaningful when override is true).
	 * @return void
	 */
	public function save_site( $override, array $ids ) {
		update_option(
			self::SITE_OPTION,
			[
				'override' => (bool) $override,
				'enabled'  => $this->sanitize_ids( $ids ),
			]
		);
	}

	/**
	 * Build the sanitized per-site config array without persisting it.
	 *
	 * Pure — used by the Settings API sanitize_callback in Admin, which returns a
	 * value for WordPress to persist itself rather than writing the option directly.
	 *
	 * @param bool     $override Whether this site overrides network defaults.
	 * @param string[] $ids      Candidate ability ids.
	 * @return array{override:bool,enabled:string[]}
	 */
	public function sanitize_site_config( $override, array $ids ) {
		if ( ! is_multisite() ) {
			$override = true;
		}
		return [
			'override' => (bool) $override,
			'enabled'  => $this->sanitize_ids( $ids ),
		];
	}

	/**
	 * Seed the network default once, without clobbering an existing value.
	 *
	 * @return void
	 */
	public function seed_network_defaults() {
		if ( false === get_site_option( self::NETWORK_OPTION, false ) ) {
			$this->save_network( $this->registry->default_enabled_ids() );
		}
	}

	/**
	 * Seed the per-site config once, without clobbering an existing value.
	 *
	 * @return void
	 */
	public function seed_site_defaults() {
		if ( false === get_option( self::SITE_OPTION, false ) ) {
			// On multisite, a fresh subsite inherits (override off) by default.
			$this->save_site( false, $this->registry->default_enabled_ids() );
		}
	}

	/**
	 * Reduce an arbitrary id list to valid, known ability ids.
	 *
	 * @param string[] $ids Candidate ids.
	 * @return string[]
	 */
	public function sanitize_ids( array $ids ) {
		$valid = $this->registry->all_ids();
		$ids   = array_map( 'sanitize_text_field', $ids );
		return array_values( array_intersect( $ids, $valid ) );
	}

	/* ------------------------------------------------------------- Server naming */

	/**
	 * Sanitize a hostname/path fragment into an id-safe slug segment.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function slugify( $value ) {
		$value = strtolower( trim( (string) $value, '/' ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( (string) $value, '_' );
	}

	/**
	 * The MCP server id / REST namespace for the current site.
	 *
	 * Single / main site:  hlb_{maindomain}
	 * Subsite:             hlb_{maindomain}_{subsiteslug}
	 *
	 * @return string
	 */
	public function server_slug() {
		if ( ! is_multisite() ) {
			$slug = 'hlb_' . $this->slugify( wp_parse_url( home_url(), PHP_URL_HOST ) );
		} else {
			$main = 'hlb_' . $this->slugify( wp_parse_url( network_home_url(), PHP_URL_HOST ) );
			$slug = is_main_site() ? $main : $main . '_' . $this->slugify( $this->subsite_slug() );
		}

		/**
		 * Filter the MCP server slug (used for the server id and REST namespace).
		 *
		 * @param string   $slug     Computed slug.
		 * @param Settings $settings Settings instance.
		 */
		return apply_filters( 'hlb_mcp_server_slug', $slug, $this );
	}

	/**
	 * The subsite discriminator (subdomain label or path segment).
	 *
	 * @return string
	 */
	private function subsite_slug() {
		if ( is_subdomain_install() ) {
			$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );        // e.g. site-a.example.com.
			$main  = (string) wp_parse_url( network_home_url(), PHP_URL_HOST ); // e.g. example.com.
			$label = preg_replace( '/\.' . preg_quote( $main, '/' ) . '$/', '', $host );
			return '' !== $label ? $label : (string) get_current_blog_id();
		}

		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );            // e.g. /site-a/.
		$path = trim( $path, '/' );
		return '' !== $path ? $path : (string) get_current_blog_id();
	}

	/**
	 * Human-facing display name for the MCP server.
	 *
	 * @return string
	 */
	public function server_display_name() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		/* translators: %s: site host. */
		return sprintf( __( 'HLB MCP — %s', 'hlb-mcp-abilities' ), $host );
	}

	/**
	 * The public MCP endpoint URL for the current site.
	 *
	 * @return string
	 */
	public function endpoint_url() {
		return rest_url( $this->server_slug() . '/' . self::ROUTE );
	}
}
