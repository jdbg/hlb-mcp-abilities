<?php
/**
 * Media ability handlers.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Media library operations.
 */
class Media {

	/**
	 * List media library items.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_media( array $input ) {
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		];
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
		}

		$query = new WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $att ) {
			$items[] = [
				'id'        => $att->ID,
				'title'     => get_the_title( $att ),
				'mime_type' => $att->post_mime_type,
				'url'       => wp_get_attachment_url( $att->ID ),
				'alt'       => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'date'      => $att->post_date_gmt,
			];
		}

		return [
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		];
	}

	/**
	 * Sideload a file from a URL into the media library.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function upload_media( array $input ) {
		$url = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'A valid URL is required.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}
		if ( ! self::host_allowed( $url ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'This host is not allowed for media uploads.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot attach media to this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$desc = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null;

		$attachment_id = media_sideload_image( $url, $post_id, $desc, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}

		return [
			'id'  => (int) $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		];
	}

	/**
	 * Whether a URL's host passes the (optional) upload allow-list.
	 *
	 * `wp_http_validate_url()` already blocks loopback and private-range hosts;
	 * this is an additional, opt-in restriction for sites that want to limit
	 * sideloading to known origins. Empty (the default) means unrestricted.
	 *
	 * @param string $url Source URL.
	 * @return bool
	 */
	private static function host_allowed( $url ) {
		/**
		 * Filter the hostnames `upload-media` may sideload from.
		 *
		 * @param string[] $hosts Allowed hostnames, e.g. [ 'cdn.example.com' ]. Empty allows any host.
		 */
		$allowed = apply_filters( 'hlb_mcp_upload_media_allowed_hosts', [] );
		if ( empty( $allowed ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && in_array( strtolower( $host ), array_map( 'strtolower', $allowed ), true );
	}
}
