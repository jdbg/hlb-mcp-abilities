<?php
/**
 * SEOPress ability handlers (registered only when SEOPress is active).
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Per-post SEO meta reads and writes, stored in SEOPress's documented postmeta keys.
 */
class SEOPress {

	/**
	 * Ability input field => [ postmeta key, sanitizer ] for the plain string fields.
	 *
	 * @var array<string,array>
	 */
	const FIELD_MAP = [
		'title'               => [ '_seopress_titles_title', 'sanitize_text_field' ],
		'description'         => [ '_seopress_titles_desc', 'sanitize_textarea_field' ],
		'canonical_url'       => [ '_seopress_robots_canonical', 'esc_url_raw' ],
		'focus_keyword'       => [ '_seopress_analysis_target_kw', 'sanitize_text_field' ],
		'og_title'            => [ '_seopress_social_fb_title', 'sanitize_text_field' ],
		'og_description'      => [ '_seopress_social_fb_desc', 'sanitize_textarea_field' ],
		'og_image_url'        => [ '_seopress_social_fb_img', 'esc_url_raw' ],
		'twitter_title'       => [ '_seopress_social_twitter_title', 'sanitize_text_field' ],
		'twitter_description' => [ '_seopress_social_twitter_desc', 'sanitize_textarea_field' ],
		'twitter_image_url'   => [ '_seopress_social_twitter_img', 'esc_url_raw' ],
	];

	/**
	 * Whether SEOPress is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Get a post's SEOPress meta.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_meta( array $input ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'hlb_mcp_unavailable', __( 'SEOPress is not active.', 'hlb-mcp-abilities' ), [ 'status' => 501 ] );
		}

		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		// Per-object read check: the coarse `read` permission_callback does not gate
		// unpublished content. Return 404 (not 403) so we don't disclose existence.
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		return self::shape_meta( $id );
	}

	/**
	 * Update a post's SEOPress meta.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_meta( array $input ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'hlb_mcp_unavailable', __( 'SEOPress is not active.', 'hlb-mcp-abilities' ), [ 'status' => 501 ] );
		}

		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		foreach ( self::FIELD_MAP as $field => $meta ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			list( $meta_key, $sanitizer ) = $meta;
			$value = call_user_func( $sanitizer, $input[ $field ] );
			if ( '' === $value ) {
				delete_post_meta( $id, $meta_key );
			} else {
				update_post_meta( $id, $meta_key, $value );
			}
		}

		if ( array_key_exists( 'noindex', $input ) ) {
			self::set_flag( $id, '_seopress_robots_index', $input['noindex'] );
		}
		if ( array_key_exists( 'nofollow', $input ) ) {
			self::set_flag( $id, '_seopress_robots_follow', $input['nofollow'] );
		}

		return self::shape_meta( $id );
	}

	/**
	 * Store a SEOPress yes/unset boolean flag.
	 *
	 * @param int    $id       Post ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $enabled  Whether the flag is set.
	 * @return void
	 */
	private static function set_flag( $id, $meta_key, $enabled ) {
		if ( $enabled ) {
			update_post_meta( $id, $meta_key, 'yes' );
		} else {
			delete_post_meta( $id, $meta_key );
		}
	}

	/**
	 * Shape a post's SEOPress meta into a plain array.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private static function shape_meta( $id ) {
		$data = [ 'id' => $id ];
		foreach ( self::FIELD_MAP as $field => $meta ) {
			$data[ $field ] = get_post_meta( $id, $meta[0], true );
		}
		$data['noindex']  = 'yes' === get_post_meta( $id, '_seopress_robots_index', true );
		$data['nofollow'] = 'yes' === get_post_meta( $id, '_seopress_robots_follow', true );
		return $data;
	}
}
