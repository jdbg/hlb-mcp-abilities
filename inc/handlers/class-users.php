<?php
/**
 * User ability handlers.
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

defined( 'ABSPATH' ) || exit;

/**
 * User identity and listing.
 */
class Users {

	/**
	 * Identity and capabilities of the authenticated caller.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function get_current_user( array $input ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return [ 'authenticated' => false ];
		}

		return [
			'authenticated' => true,
			'id'            => $user->ID,
			'username'      => $user->user_login,
			'display_name'  => $user->display_name,
			'email'         => $user->user_email,
			'roles'         => array_values( $user->roles ),
			'capabilities'  => array_values( array_keys( array_filter( $user->allcaps ) ) ),
		];
	}

	/**
	 * List site users.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_users( array $input ) {
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = [
			'number' => $per_page,
			'paged'  => $page,
			'fields' => [ 'ID', 'user_login', 'display_name', 'user_email' ],
		];
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = sanitize_key( $input['role'] );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		$query = new \WP_User_Query( $args );
		$items = [];
		foreach ( (array) $query->get_results() as $u ) {
			$items[] = [
				'id'           => (int) $u->ID,
				'username'     => $u->user_login,
				'display_name' => $u->display_name,
				'email'        => $u->user_email,
			];
		}

		return [
			'items'    => $items,
			'total'    => (int) $query->get_total(),
			'page'     => $page,
			'per_page' => $per_page,
		];
	}
}
