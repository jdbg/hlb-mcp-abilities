<?php
/**
 * Uninstall cleanup for HLB MCP Abilities.
 *
 * Removes the network default option and every per-site option row, on both
 * single-site and multisite installs. Leaves no orphan data.
 *
 * @package HLB\MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

const HLB_MCP_NETWORK_OPTION = 'hlb_mcp_network';
const HLB_MCP_SITE_OPTION    = 'hlb_mcp_site';

if ( is_multisite() ) {
	delete_site_option( HLB_MCP_NETWORK_OPTION );

	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( HLB_MCP_SITE_OPTION );
		restore_current_blog();
	}
} else {
	delete_option( HLB_MCP_SITE_OPTION );
}
