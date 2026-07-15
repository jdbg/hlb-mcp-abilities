<?php
/**
 * Content ability handlers (posts, pages, taxonomies, terms).
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
 * Post and taxonomy operations.
 */
class Content {

	/**
	 * Shape a WP_Post into a compact array.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private static function shape_post( $post ) {
		return [
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'type'      => $post->post_type,
			'author'    => (int) $post->post_author,
			'date'      => $post->post_date_gmt,
			'modified'  => $post->post_modified_gmt,
			'excerpt'   => get_the_excerpt( $post ),
			'link'      => get_permalink( $post ),
		];
	}

	/**
	 * Get a single post by id or slug.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_post( array $input ) {
		$type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';

		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$posts = get_posts(
				[
					'name'        => sanitize_title( $input['slug'] ),
					'post_type'   => $type,
					'post_status' => 'any',
					'numberposts' => 1,
				]
			);
			$post = $posts ? $posts[0] : null;
		} else {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'Provide an id or slug.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		if ( ! $post || ( $type && $post->post_type !== $type ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		// Per-object read check: the coarse `read` permission_callback does not gate
		// unpublished content. Return 404 (not 403) so we don't disclose existence.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		$data                = self::shape_post( $post );
		$data['content_raw'] = $post->post_content;
		return $data;
	}

	/**
	 * Query posts.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_posts( array $input ) {
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';

		// Only users who can edit posts may enumerate non-public statuses (draft, private,
		// pending, trash). Everyone else is forced to public content.
		$public_statuses = get_post_stati( [ 'public' => true ] );
		if ( ! in_array( $status, $public_statuses, true ) && ! current_user_can( 'edit_posts' ) ) {
			$status = 'publish';
		}

		$args = [
			'post_type'      => isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post',
			'post_status'    => $status,
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
		];
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		return self::run_query( $args );
	}

	/**
	 * Full-text search across public post types.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function search_content( array $input ) {
		$args = [
			's'              => isset( $input['query'] ) ? sanitize_text_field( $input['query'] ) : '',
			'post_type'      => array_values( get_post_types( [ 'public' => true ] ) ),
			'post_status'    => 'publish',
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
		];
		return self::run_query( $args );
	}

	/**
	 * Run a WP_Query and return a paginated envelope.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	private static function run_query( array $args ) {
		$query = new WP_Query( $args );
		$items = array_map( [ __CLASS__, 'shape_post' ], $query->posts );

		return [
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) $args['paged'],
			'per_page'    => (int) $args['posts_per_page'],
		];
	}

	/**
	 * List taxonomies and their terms.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_taxonomies( array $input ) {
		$only = ! empty( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
		$taxes = $only ? [ $only ] : get_taxonomies( [ 'public' => true ] );
		$out   = [];

		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				[
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 200,
				]
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$out[ $tax ] = array_map(
				static function ( $t ) {
					return [
						'id'    => $t->term_id,
						'name'  => $t->name,
						'slug'  => $t->slug,
						'count' => $t->count,
						'parent' => $t->parent,
					];
				},
				$terms
			);
		}

		return [ 'taxonomies' => $out ];
	}

	/**
	 * Create a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_post( array $input ) {
		$postarr = [
			'post_title'   => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '',
			'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
			'post_status'  => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft',
			'post_type'    => isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post',
		];

		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::shape_post( get_post( $id ) );
	}

	/**
	 * Update a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_post( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( ! $id || ! get_post( $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$postarr = [ 'ID' => $id ];
		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $input['content'] );
		}
		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}
		if ( isset( $input['status'] ) ) {
			$postarr['post_status'] = sanitize_key( $input['status'] );
		}

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::shape_post( get_post( $id ) );
	}

	/**
	 * Transition a post's status.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_post_status( array $input ) {
		$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : '';
		if ( ! $id || ! get_post( $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		if ( 'trash' === $status ) {
			$result = wp_trash_post( $id );
			if ( ! $result ) {
				return new WP_Error( 'hlb_mcp_failed', __( 'Could not trash the post.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
			}
		} else {
			$result = wp_update_post( [
				'ID' => $id,
				'post_status' => $status,
			], true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return self::shape_post( get_post( $id ) );
	}

	/**
	 * Trash or permanently delete a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_post( array $input ) {
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$force = ! empty( $input['force'] );
		if ( ! $id || ! get_post( $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot delete this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'hlb_mcp_failed', __( 'Could not delete the post.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
		}
		return [
			'id' => $id,
			'deleted' => true,
			'forced' => $force,
		];
	}

	/**
	 * Assign terms to a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function assign_terms( array $input ) {
		$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
		$terms    = isset( $input['terms'] ) ? (array) $input['terms'] : [];
		$append   = ! empty( $input['append'] );

		if ( ! $id || ! get_post( $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Post not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'Unknown taxonomy.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot edit this post.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		$result = wp_set_object_terms( $id, $terms, $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [
			'id' => $id,
			'taxonomy' => $taxonomy,
			'term_ids' => array_map( 'intval', $result ),
		];
	}

	/**
	 * Create a taxonomy term.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_term( array $input ) {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
		$name     = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'hlb_mcp_invalid_input', __( 'Unknown taxonomy.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		$args = [];
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( ! empty( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [
			'term_id' => (int) $result['term_id'],
			'taxonomy' => $taxonomy,
		];
	}

	/**
	 * Normalize per_page input.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	private static function per_page( array $input ) {
		$n = isset( $input['per_page'] ) ? (int) $input['per_page'] : 10;
		return max( 1, min( 100, $n ) );
	}

	/**
	 * Normalize page input.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	private static function page( array $input ) {
		$n = isset( $input['page'] ) ? (int) $input['page'] : 1;
		return max( 1, $n );
	}
}
