<?php
/**
 * Self-update checks against GitHub Releases.
 *
 * This plugin is not on wordpress.org, so core's default update check (which only
 * queries the wordpress.org API) never fires for it. The `Update URI` header in the
 * plugin file routes core's update check through the `update_plugins_{host}` filter
 * instead; this class answers that filter from the latest GitHub release.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Answers core's plugin-update check from the latest GitHub release.
 */
class Updater {

	const REPO      = 'jdbg/hlb-mcp-abilities';
	const REPO_URL  = 'https://github.com/jdbg/hlb-mcp-abilities';
	const API_URL   = 'https://api.github.com/repos/jdbg/hlb-mcp-abilities/releases/latest';
	const CACHE_KEY = 'hlb_mcp_update_check';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register the update-check and plugin-information filters.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
	}

	/**
	 * Answer core's update check with the latest GitHub release, if newer.
	 *
	 * @param array|false $update      Existing update data, or false.
	 * @param array       $plugin_data Result of get_plugin_data() for the plugin file.
	 * @param string      $plugin_file Plugin basename relative to the plugins directory.
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( HLB_MCP_BASENAME !== $plugin_file || is_array( $update ) ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $update;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );
		if ( ! version_compare( $remote_version, HLB_MCP_VERSION, '>' ) ) {
			return $update;
		}

		$package = $this->zip_asset_url( $release );
		if ( ! $package ) {
			return $update;
		}

		return [
			'id'           => self::REPO_URL,
			'slug'         => dirname( HLB_MCP_BASENAME ),
			'plugin'       => $plugin_file,
			'new_version'  => $remote_version,
			'url'          => self::REPO_URL,
			'package'      => $package,
			'requires_php' => '7.4',
		];
	}

	/**
	 * Answer the "View version X.Y.Z details" popup from the latest GitHub release.
	 *
	 * @param false|object|array $result The result object/array, or false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( HLB_MCP_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'HLB MCP Abilities',
			'slug'          => $args->slug,
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://hlebarov.com/">Hlebarov.com</a>',
			'homepage'      => self::REPO_URL,
			'sections'      => [
				'description' => wp_kses_post( wpautop( $release['body'] ?? '' ) ),
			],
			'download_link' => $this->zip_asset_url( $release ),
		];
	}

	/**
	 * Fetch (and cache) the latest GitHub release, if any.
	 *
	 * @return array|null Decoded release data, or null if unavailable.
	 */
	private function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			self::API_URL,
			[
				'headers' => [ 'Accept' => 'application/vnd.github+json' ],
				'timeout' => 10,
			]
		);

		$body = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			// Cache the miss too, so a rate limit or outage doesn't hammer the API.
			set_site_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		set_site_transient( self::CACHE_KEY, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * The .zip asset URL attached to a release, if one exists.
	 *
	 * @param array $release Decoded GitHub release data.
	 * @return string
	 */
	private function zip_asset_url( $release ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && ! empty( $asset['name'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
				return $asset['browser_download_url'];
			}
		}

		return '';
	}
}
