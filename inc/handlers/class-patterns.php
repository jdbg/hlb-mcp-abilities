<?php
/**
 * Pattern ability handlers (user-created patterns and the registered catalogue).
 *
 * Each method is a thin adapter over core WP APIs. Input is a validated array,
 * output is a plain array or WP_Error.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * User pattern (wp_block) CRUD, plus read-only access to code-registered patterns.
 */
class Patterns {

	/**
	 * Shape a wp_block post into a compact array.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private static function shape_pattern( $post ) {
		$sync  = get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
		$terms = get_the_terms( $post, 'wp_pattern_category' );

		return [
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'slug'       => $post->post_name,
			'status'     => $post->post_status,
			'synced'     => 'unsynced' !== $sync,
			'categories' => is_array( $terms ) ? wp_list_pluck( $terms, 'slug' ) : [],
			'date'       => $post->post_date_gmt,
			'modified'   => $post->post_modified_gmt,
		];
	}

	/**
	 * Assign a pattern category, allowing privileged users to create a new one.
	 *
	 * WordPress creates missing terms when wp_set_object_terms() receives a string. Check the
	 * taxonomy's capabilities first so pattern authors cannot create categories
	 * merely by assigning one to a pattern.
	 *
	 * @param int    $post_id  Pattern post ID.
	 * @param string $category Pattern category name or slug.
	 * @return true|WP_Error
	 */
	private static function set_pattern_category( $post_id, $category ) {
		$taxonomy = get_taxonomy( 'wp_pattern_category' );
		$category = sanitize_text_field( $category );

		if ( ! $taxonomy ) {
			return new WP_Error( 'hlb_mcp_failed', __( 'Pattern categories are unavailable.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
		}
		if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot assign pattern categories.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}
		if ( '' === $category ) {
			$result = wp_set_object_terms( $post_id, [], 'wp_pattern_category' );
			return is_wp_error( $result ) ? $result : true;
		}

		$term = get_term_by( 'slug', sanitize_title( $category ), 'wp_pattern_category' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $category, 'wp_pattern_category' );
		}
		if ( ! $term && ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot create pattern categories.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$result = wp_set_object_terms( $post_id, $term ? (int) $term->term_id : $category, 'wp_pattern_category' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * List user-created patterns.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_patterns( array $input ) {
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = [
			'post_type'      => 'wp_block',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		];
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}
		if ( ! empty( $input['category'] ) ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'wp_pattern_category',
					'field'    => 'slug',
					'terms'    => sanitize_title( $input['category'] ),
				],
			];
		}

		$query = new WP_Query( $args );
		return [
			'items'       => array_map( [ __CLASS__, 'shape_pattern' ], $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		];
	}

	/**
	 * Get a single pattern, including its content.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_pattern( array $input ) {
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Pattern not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Pattern not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		$data            = self::shape_pattern( $post );
		$data['content'] = $post->post_content;
		return $data;
	}

	/**
	 * Create a user pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_pattern( array $input ) {
		$type_obj = get_post_type_object( 'wp_block' );
		if ( ! $type_obj || ! current_user_can( $type_obj->cap->create_posts ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot create patterns.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$postarr = [
			'post_type'    => 'wp_block',
			'post_title'   => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '',
			'post_content' => isset( $input['content'] ) ? $input['content'] : '',
			'post_status'  => 'publish',
		];

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( array_key_exists( 'synced', $input ) && ! $input['synced'] ) {
			update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		}
		if ( ! empty( $input['category'] ) ) {
			$result = self::set_pattern_category( $id, $input['category'] );
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $id, true );
				return $result;
			}
		}

		return self::shape_pattern( get_post( $id ) );
	}

	/**
	 * Update a user pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_pattern( array $input ) {
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Pattern not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit this pattern.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$postarr = [ 'ID' => $id ];
		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = $input['content'];
		}
		if ( count( $postarr ) > 1 ) {
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'synced', $input ) ) {
			if ( $input['synced'] ) {
				delete_post_meta( $id, 'wp_pattern_sync_status' );
			} else {
				update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
			}
		}
		if ( isset( $input['category'] ) ) {
			$result = self::set_pattern_category( $id, $input['category'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return self::shape_pattern( get_post( $id ) );
	}

	/**
	 * Delete a user pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_pattern( array $input ) {
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$force = ! empty( $input['force'] );
		$post  = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Pattern not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot delete this pattern.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'hlb_mcp_failed', __( 'Could not delete the pattern.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
		}
		return [
			'id'      => $id,
			'deleted' => true,
			'forced'  => $force,
		];
	}

	/**
	 * List code-registered block patterns (theme/plugin patterns, not posts).
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_registered_patterns( array $input ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return [ 'items' => [] ];
		}

		$category = ! empty( $input['category'] ) ? sanitize_title( $input['category'] ) : '';
		$items    = [];

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$categories = isset( $pattern['categories'] ) ? (array) $pattern['categories'] : [];
			if ( '' !== $category && ! in_array( $category, $categories, true ) ) {
				continue;
			}
			$items[] = [
				'name'        => $pattern['name'],
				'title'       => isset( $pattern['title'] ) ? $pattern['title'] : '',
				'description' => isset( $pattern['description'] ) ? $pattern['description'] : '',
				'categories'  => $categories,
				'source'      => isset( $pattern['source'] ) ? $pattern['source'] : 'plugin',
			];
		}

		return [ 'items' => $items ];
	}
}
