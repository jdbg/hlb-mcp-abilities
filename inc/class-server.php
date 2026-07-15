<?php
/**
 * Registers the per-site MCP server with the MCP Adapter.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Projects the enabled abilities onto an MCP REST server named hlb_{sitename}.
 */
class Server {

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
		add_action( 'mcp_adapter_init', [ $this, 'create_server' ] );
	}

	/**
	 * Create the MCP server for this site.
	 *
	 * @param object $adapter The MCP adapter instance (WP\MCP\Core\McpAdapter).
	 * @return void
	 */
	public function create_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$tools = $this->settings->enabled_ids();
		if ( empty( $tools ) ) {
			return; // Nothing enabled — do not register an empty server.
		}

		$transports = $this->transports();
		if ( empty( $transports ) ) {
			return;
		}

		$slug = $this->settings->server_slug();

		$adapter->create_server(
			$slug,                                // server_id.
			$slug,                                // server_route_namespace.
			Settings::ROUTE,                      // server_route  → /wp-json/{slug}/mcp.
			$this->settings->server_display_name(),
			__( 'HLB MCP Abilities — curated WordPress abilities for third-party agents.', 'hlb-mcp-abilities' ),
			HLB_MCP_VERSION,
			$transports,
			$this->error_handler(),
			$this->observability_handler(),
			$tools                                // Ability ids exposed as MCP tools.
		);
	}

	/**
	 * Available transport classes.
	 *
	 * @return string[]
	 */
	private function transports() {
		$candidates = [
			'\\WP\\MCP\\Transport\\HttpTransport',
		];
		$transports = [];
		foreach ( $candidates as $class ) {
			if ( class_exists( $class ) ) {
				$transports[] = $class;
			}
		}

		/**
		 * Filter the MCP transport classes used for the HLB server.
		 *
		 * @param string[] $transports Fully-qualified transport class names.
		 */
		return apply_filters( 'hlb_mcp_transports', $transports );
	}

	/**
	 * Resolve an available error handler class (required by create_server).
	 *
	 * @return string|null
	 */
	private function error_handler() {
		foreach ( [
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler',
		] as $class ) {
			if ( class_exists( $class ) ) {
				return $class;
			}
		}
		return null;
	}

	/**
	 * Resolve an available observability handler class.
	 *
	 * @return string|null
	 */
	private function observability_handler() {
		$class = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		return class_exists( $class ) ? $class : null;
	}
}
