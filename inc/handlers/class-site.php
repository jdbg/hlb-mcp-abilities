<?php
/**
 * Site & diagnostics ability handlers.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use HLB\MCP\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only site information.
 */
class Site {

	/**
	 * Whether the network site-listing ability is available on this request.
	 *
	 * Only on the main site of a multisite network with network mode enabled.
	 *
	 * @return bool
	 */
	public static function network_available() {
		if ( ! is_multisite() || ! is_main_site() ) {
			return false;
		}
		$config = get_site_option( Settings::NETWORK_OPTION, [] );
		return ! empty( $config['network_mode'] );
	}

	/**
	 * List the subsites in the network (network mode).
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_sites( array $input ) {
		if ( ! is_multisite() ) {
			return new WP_Error( 'hlb_mcp_unavailable', __( 'This is not a multisite network.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		$items = [];
		foreach ( get_sites( [ 'number' => 500 ] ) as $blog ) {
			$id      = (int) $blog->blog_id;
			$items[] = [
				'blog_id' => $id,
				'name'    => get_blog_option( $id, 'blogname' ),
				'url'     => get_home_url( $id ),
				'path'    => trim( $blog->path, '/' ),
				'domain'  => $blog->domain,
				'is_main' => (int) get_main_site_id() === $id,
			];
		}

		return [
			'sites' => $items,
			'count' => count( $items ),
		];
	}

	/**
	 * Basic site information.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function get_site_info( array $input ) {
		return [
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'url'          => home_url(),
			'admin_email'  => get_bloginfo( 'admin_email' ),
			'wp_version'   => get_bloginfo( 'version' ),
			'language'     => get_bloginfo( 'language' ),
			'timezone'     => wp_timezone_string(),
			'is_multisite' => is_multisite(),
			'blog_id'      => get_current_blog_id(),
		];
	}

	/**
	 * Active theme details.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function get_active_theme( array $input ) {
		$theme = wp_get_theme();
		return [
			'name'        => $theme->get( 'Name' ),
			'stylesheet'  => $theme->get_stylesheet(),
			'version'     => $theme->get( 'Version' ),
			'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'is_block_theme' => wp_is_block_theme(),
			'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
		];
	}

	/**
	 * Active plugins with name and version.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function list_active_plugins( array $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
			$active  = array_unique( array_merge( $active, $network ) );
		}

		$items = [];
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$items[] = [
					'name'    => $all[ $file ]['Name'],
					'version' => $all[ $file ]['Version'],
					'file'    => $file,
				];
			}
		}

		return [
			'items' => $items,
			'count' => count( $items ),
		];
	}
}
