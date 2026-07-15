<?php
/**
 * Comment ability handlers.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Comment listing and moderation.
 */
class Comments {

	/**
	 * List comments.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_comments( array $input ) {
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$status   = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'approve';

		$args = [
			'number' => $per_page,
			'paged'  => $page,
			'status' => 'all' === $status ? '' : $status,
		];
		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = (int) $input['post_id'];
		}

		$comments = get_comments( $args );
		$items    = [];
		foreach ( $comments as $c ) {
			$items[] = [
				'id'          => (int) $c->comment_ID,
				'post_id'     => (int) $c->comment_post_ID,
				'author'      => $c->comment_author,
				'author_email' => $c->comment_author_email,
				'date'        => $c->comment_date_gmt,
				'content'     => $c->comment_content,
				'approved'    => $c->comment_approved,
			];
		}

		return [
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Moderate a comment.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function moderate_comment( array $input ) {
		$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$action = isset( $input['action'] ) ? sanitize_key( $input['action'] ) : '';
		if ( ! $id || ! get_comment( $id ) ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Comment not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error( 'hlb_mcp_forbidden', __( 'You cannot moderate this comment.', 'hlb-mcp-abilities' ), [ 'status' => 403 ] );
		}

		switch ( $action ) {
			case 'approve':
				$ok = wp_set_comment_status( $id, 'approve' );
				break;
			case 'unapprove':
				$ok = wp_set_comment_status( $id, 'hold' );
				break;
			case 'spam':
				$ok = wp_spam_comment( $id );
				break;
			case 'trash':
				$ok = wp_trash_comment( $id );
				break;
			default:
				return new WP_Error( 'hlb_mcp_invalid_input', __( 'Unknown action.', 'hlb-mcp-abilities' ), [ 'status' => 400 ] );
		}

		if ( ! $ok ) {
			return new WP_Error( 'hlb_mcp_failed', __( 'Moderation action failed.', 'hlb-mcp-abilities' ), [ 'status' => 500 ] );
		}
		return [
			'id' => $id,
			'action' => $action,
			'success' => true,
		];
	}
}
