<?php
/**
 * Plugin Name:       HLB MCP Abilities
 * Plugin URI:        https://hlebarov.com/
 * Description:       Exposes a curated, admin-controlled set of WordPress Abilities to the MCP Adapter so third-party tools and AI agents can interact with the site over MCP. Multisite-ready with network defaults and per-subsite overrides.
 * Version:           1.0.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Hlebarov.com
 * Author URI:        https://hlebarov.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hlb-mcp-abilities
 * Network:           false
 *
 * @package HLB\MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'HLB_MCP_VERSION', '1.0.1' );
define( 'HLB_MCP_FILE', __FILE__ );
define( 'HLB_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'HLB_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'HLB_MCP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the HLB\MCP namespace.
 *
 * HLB\MCP\Registry            -> inc/class-registry.php
 * HLB\MCP\Handlers\Content    -> inc/handlers/class-content.php
 *
 * @param string $class_name Fully-qualified class name being loaded.
 * @return void
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'HLB\\MCP\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$base     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';
		$sub      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path     = HLB_MCP_DIR . 'inc/' . $sub . $file;

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Lifecycle hooks must be registered at top level.
register_activation_hook( __FILE__, [ '\HLB\MCP\Plugin', 'on_activation' ] );

// Boot.
\HLB\MCP\Plugin::instance();
