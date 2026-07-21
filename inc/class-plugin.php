<?php
/**
 * Main plugin orchestrator.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything together on the appropriate hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Ability registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings resolver / store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Retrieve the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: build collaborators and register hooks.
	 */
	private function __construct() {
		$this->registry = new Registry();
		$this->settings = new Settings( $this->registry );

		add_action( 'plugins_loaded', [ $this, 'boot' ] );
	}

	/**
	 * Boot once all plugins are available.
	 *
	 * @return void
	 */
	public function boot() {
		load_plugin_textdomain( 'hlb-mcp-abilities', false, dirname( HLB_MCP_BASENAME ) . '/languages' );

		// Self-update checks against GitHub Releases (this plugin isn't on wordpress.org).
		( new Updater() )->hooks();

		// Admin settings UI + dependency management (install/activate the MCP Adapter).
		if ( is_admin() ) {
			( new Admin( $this->registry, $this->settings ) )->hooks();
			( new Dependency() )->hooks();
		}

		// Abilities registration (always, so wp-abilities/v1 + Command Palette see them).
		( new Abilities( $this->registry, $this->settings ) )->hooks();

		// MCP server projection — only if the MCP Adapter is present.
		if ( $this->adapter_available() ) {
			( new Server( $this->registry, $this->settings ) )->hooks();
		}
	}

	/**
	 * Whether the MCP Adapter library/plugin is loaded.
	 *
	 * @return bool
	 */
	public function adapter_available() {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Accessor for the registry.
	 *
	 * @return Registry
	 */
	public function registry() {
		return $this->registry;
	}

	/**
	 * Accessor for the settings store.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Activation handler. Seeds defaults without clobbering existing config.
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 * @return void
	 */
	public static function on_activation( $network_wide = false ) {
		$plugin   = self::instance();
		$settings = $plugin->settings();

		if ( is_multisite() && $network_wide ) {
			$settings->seed_network_defaults();
			return;
		}

		$settings->seed_site_defaults();
	}
}
