<?php
/**
 * Fired during plugin activation
 *
 * @link       https://wpswings.com/
 * @since      1.0.0
 *
 * @package    Subscriptions_For_Woocommerce
 * @subpackage Subscriptions_For_Woocommerce/package/rest-api/version1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! class_exists( 'Subscriptions_For_Woocommerce_Api_Process' ) ) {

	/**
	 * The plugin API class.
	 *
	 * This is used to define the functions and data manipulation for custom endpoints.
	 *
	 * @since      1.0.0
	 * @package    Subscriptions_For_Woocommerce
	 * @subpackage Subscriptions_For_Woocommerce/package/rest-api/version1
	 * @author     WP Swings <wpswings.com>
	 */
	class Subscriptions_For_Woocommerce_Api_Process {

		/**
		 * Initialize the class and set its properties.
		 *
		 * @since    1.0.0
		 */
		public function __construct() {
		}

		/**
		 * Define the function to process data for custom endpoint.
		 *
		 * @since    1.0.0
		 * @param   Array $wsp_request  data of requesting headers and other information.
		 * @return  Array $wps_wsp_rest_response    returns processed data and status of operations.
		 */
		public function wps_sfw_default_process( $wsp_request ) {
			$wps_wsp_rest_response = array();

			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$args = array(
					'numberposts' => -1,
					'return' => 'ids',
					'post_type'   => 'wps_subscriptions',
					'meta_query' => array(
						array(
							'key'   => 'wps_customer_id',
							'compare' => 'EXISTS',
						),
					),
				);
				$wps_subscriptions = wc_get_orders( $args );
			} else {
				$args = array(
					'numberposts' => -1,
					'post_type'   => 'wps_subscriptions',
					'post_status' => 'wc-wps_renewal',
					'meta_query' => array(
						array(
							'key'   => 'wps_customer_id',
							'compare' => 'EXISTS',
						),
					),
				);
				$wps_subscriptions = get_posts( $args );
			}

			$wps_subscriptions_data = array();
			if ( isset( $wps_subscriptions ) && ! empty( $wps_subscriptions ) && is_array( $wps_subscriptions ) ) {
				foreach ( $wps_subscriptions as $key => $value ) {
					if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
						$subcription_id = $value;
					} else {
						$subcription_id = $value->ID;
					}

					$parent_order_id   = wps_sfw_get_meta_data( $subcription_id, 'wps_parent_order', true );
					$wps_subscription_status   = wps_sfw_get_meta_data( $subcription_id, 'wps_subscription_status', true );
					$product_name   = wps_sfw_get_meta_data( $subcription_id, 'product_name', true );
					$wps_recurring_total   = wps_sfw_get_meta_data( $subcription_id, 'wps_recurring_total', true );

					$wps_wsp_number   = wps_sfw_get_meta_data( $subcription_id, 'wps_sfw_subscription_number', true );
					$wps_wsp_interval   = wps_sfw_get_meta_data( $subcription_id, 'wps_sfw_subscription_interval', true );

					$wps_next_payment_date   = wps_sfw_get_meta_data( $subcription_id, 'wps_next_payment_date', true );
					$wps_susbcription_end   = wps_sfw_get_meta_data( $subcription_id, 'wps_susbcription_end', true );

					$wps_customer_id   = wps_sfw_get_meta_data( $subcription_id, 'wps_customer_id', true );
					$user = get_user_by( 'id', $wps_customer_id );

					$user_nicename = isset( $user->user_nicename ) ? $user->user_nicename : '';
					$wps_subscriptions_data[] = array(
						'subscription_id'           => $subcription_id,
						'parent_order_id'           => $parent_order_id,
						'status'                    => $wps_subscription_status,
						'product_name'              => $product_name,
						'recurring_amount'          => $wps_recurring_total,
						'wps_wsp_per_number'        => $wps_wsp_number,
						'wps_wsp_interval'          => $wps_wsp_interval,
						'user_name'                 => $user_nicename,
						'next_payment_date'         => wps_sfw_get_the_wordpress_date_format( $wps_next_payment_date ),
						'subscriptions_expiry_date' => wps_sfw_get_the_wordpress_date_format( $wps_susbcription_end ),
					);
				}
			}

			// Write your custom code here.

			$wps_wsp_rest_response['code'] = 200;
			$wps_wsp_rest_response['status'] = 'success';
			$wps_wsp_rest_response['data'] = $wps_subscriptions_data;
			return $wps_wsp_rest_response;
		}
	}
}
