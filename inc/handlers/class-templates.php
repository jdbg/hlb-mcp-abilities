<?php
/**
 * Site Editor template & template-part ability handlers.
 *
 * Each method is a thin adapter over core's block-template APIs. Input is a
 * validated array, output is a plain array or WP_Error.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Template and template-part operations (wp_template / wp_template_part).
 */
class Templates {

	/**
	 * Whether the active theme supports the Site Editor's block templates.
	 *
	 * @return bool
	 */
	public static function block_theme_active() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Shape a WP_Block_Template into a compact array.
	 *
	 * @param \WP_Block_Template $template Template object.
	 * @return array
	 */
	private static function shape_template( $template ) {
		return [
			'id'             => $template->id,
			'slug'           => $template->slug,
			'theme'          => $template->theme,
			'type'           => $template->type,
			'title'          => $template->title,
			'description'    => $template->description,
			'status'         => $template->status,
			'source'         => $template->source,
			'is_custom'      => ! empty( $template->is_custom ),
			'has_theme_file' => ! empty( $template->has_theme_file ),
			'wp_id'          => empty( $template->wp_id ) ? null : (int) $template->wp_id,
			'area'           => isset( $template->area ) ? $template->area : null,
		];
	}

	/**
	 * Normalize the requested template type.
	 *
	 * @param array $input Ability input.
	 * @return string
	 */
	private static function normalize_type( array $input ) {
		$type = isset( $input['template_type'] ) ? sanitize_key( $input['template_type'] ) : 'wp_template';
		return in_array( $type, [ 'wp_template', 'wp_template_part' ], true ) ? $type : 'wp_template';
	}

	/**
	 * List templates or template parts.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_templates( array $input ) {
		$type  = self::normalize_type( $input );
		$query = [];
		if ( 'wp_template_part' === $type && ! empty( $input['area'] ) ) {
			$query['area'] = sanitize_key( $input['area'] );
		}

		$templates = get_block_templates( $query, $type );
		return [ 'items' => array_map( [ __CLASS__, 'shape_template' ], $templates ) ];
	}

	/**
	 * Get a single template or template part, including its content.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_template( array $input ) {
		$type = self::normalize_type( $input );
		$id   = isset( $input['id'] ) ? sanitize_text_field( $input['id'] ) : '';
		if ( '' === $id ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'Provide a template id.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		$template = get_block_template( $id, $type );
		if ( ! $template ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Template not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		$data            = self::shape_template( $template );
		$data['content'] = $template->content;
		return $data;
	}

	/**
	 * Create a new custom template or template part.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_template( array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit templates.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$type = self::normalize_type( $input );
		$slug = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'Provide a slug.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		$theme    = get_stylesheet();
		$existing = get_block_template( $theme . '//' . $slug, $type );
		if ( $existing && ! empty( $existing->wp_id ) ) {
			return new WP_Error( 'hlb_mcp_conflict', __( 'A template with this slug already exists.', 'hlb-mcp-abilities' ), [ 'status' => 409 ] );
		}

		$postarr = [
			'post_type'    => $type,
			'post_name'    => $slug,
			'post_title'   => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $slug,
			'post_content' => isset( $input['content'] ) ? $input['content'] : '',
			'post_excerpt' => isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '',
			'post_status'  => 'publish',
			'tax_input'    => [ 'wp_theme' => [ $theme ] ],
		];

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( 'wp_template_part' === $type && ! empty( $input['area'] ) ) {
			wp_set_post_terms( $id, [ sanitize_key( $input['area'] ) ], 'wp_template_part_area' );
		}

		$template = get_block_template( $theme . '//' . $slug, $type );
		return $template ? self::shape_template( $template ) : [
			'id'    => $theme . '//' . $slug,
			'wp_id' => $id,
		];
	}

	/**
	 * Update a template or template part's title, content, or description.
	 *
	 * A theme-provided template that has not yet been customized has no post
	 * row; the first edit inserts one (the customization), later edits update it.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_template( array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit templates.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$type     = self::normalize_type( $input );
		$id       = isset( $input['id'] ) ? sanitize_text_field( $input['id'] ) : '';
		$template = $id ? get_block_template( $id, $type ) : null;
		if ( ! $template ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Template not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		$customizing = empty( $template->wp_id );
		$postarr     = $customizing ? [
			'post_type'    => $type,
			'post_name'    => $template->slug,
			'post_status'  => 'publish',
			'post_title'   => $template->title,
			'post_content' => $template->content,
			'tax_input'    => [ 'wp_theme' => [ $template->theme ] ],
		] : [ 'ID' => $template->wp_id ];

		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = $input['content'];
		}
		if ( isset( $input['description'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $input['description'] );
		}

		$result = $customizing
			? wp_insert_post( wp_slash( $postarr ), true )
			: wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_block_template( $template->theme . '//' . $template->slug, $type );
		return $updated ? self::shape_template( $updated ) : null;
	}

	/**
	 * Delete a template or template part.
	 *
	 * If it is a customized theme template, this reverts it to the theme file
	 * (the customization post row is removed); a fully custom template is
	 * deleted outright.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_template( array $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit templates.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$type     = self::normalize_type( $input );
		$id       = isset( $input['id'] ) ? sanitize_text_field( $input['id'] ) : '';
		$template = $id ? get_block_template( $id, $type ) : null;
		if ( ! $template ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Template not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( empty( $template->wp_id ) ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'This template is not customized; there is nothing to delete.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		$result = wp_delete_post( $template->wp_id, true );
		if ( ! $result ) {
			return new WP_Error( 'hlb_mcp_failed', __( 'Could not delete the template.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
		}
		return [
			'id'                      => $id,
			'deleted'                 => true,
			'reverted_to_theme_file'  => ! empty( $template->has_theme_file ),
		];
	}
}
