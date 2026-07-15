<?php
/**
 * WooCommerce ability handlers (registered only when WooCommerce is active).
 *
 * @package HLB\MCP
 */

namespace HLB\MCP\Handlers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product and order reads.
 */
class WooCommerce {

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * List products.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_products( array $input ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'hlb_mcp_unavailable', __( 'WooCommerce is not active.', 'hlb-mcp-abilities' ), [ 'status' => 501 ] );
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = [
			'limit'   => $per_page,
			'page'    => $page,
			'status'  => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish',
			'return'  => 'objects',
			'paginate' => true,
		];
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$results = wc_get_products( $args );
		$items   = [];
		foreach ( $results->products as $product ) {
			$items[] = [
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'sku'         => $product->get_sku(),
				'price'       => $product->get_price(),
				'stock_status' => $product->get_stock_status(),
				'permalink'   => $product->get_permalink(),
			];
		}

		return [
			'items'       => $items,
			'total'       => (int) $results->total,
			'total_pages' => (int) $results->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		];
	}

	/**
	 * Get a single order.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_order( array $input ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'hlb_mcp_unavailable', __( 'WooCommerce is not active.', 'hlb-mcp-abilities' ), [ 'status' => 501 ] );
		}

		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order ) {
			return new WP_Error( 'hlb_mcp_not_found', __( 'Order not found.', 'hlb-mcp-abilities' ), [ 'status' => 404 ] );
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			];
		}

		return [
			'id'          => $order->get_id(),
			'status'      => $order->get_status(),
			'total'       => $order->get_total(),
			'currency'    => $order->get_currency(),
			'date'        => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'customer_id' => $order->get_customer_id(),
			'billing_email' => $order->get_billing_email(),
			'items'       => $items,
		];
	}
}
