<?php
/**
 * Soft integration with the Frontend Gatekeeper plugin.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Tags outbound URLs with Frontend Gatekeeper's access parameter.
 *
 * Frontend Gatekeeper (https://hwp.bg/frontend-gatekeeper/) hides a site's
 * public frontend behind a URL parameter, 404-ing anonymous requests that
 * lack it. MCP tool calls are unaffected — REST API requests are already
 * exempt from the gate — but a permalink or site URL an ability *returns* to
 * an agent would 404 if the agent then fetches it directly. Tagging those
 * URLs here keeps them followable, with nothing to configure in this plugin:
 * it's a no-op unless Frontend Gatekeeper is active and its gate is on.
 */
class Gatekeeper {

	/**
	 * Append Frontend Gatekeeper's access parameter to a URL, if applicable.
	 *
	 * @param string $url URL to tag.
	 * @return string
	 */
	public static function link( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! function_exists( 'fronga_append_access_param' ) ) {
			return $url;
		}

		return fronga_append_access_param( $url );
	}

	/**
	 * Whether Frontend Gatekeeper is active on this site.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'fronga_append_access_param' );
	}
}
