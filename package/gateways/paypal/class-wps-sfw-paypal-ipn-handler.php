<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://wpswings.com/
 * @since      1.0.0
 *
 * @package    Subscriptions_For_Woocommerce
 * @subpackage Subscriptions_For_Woocommerce/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! class_exists( 'WPS_Sfw_PayPal_IPN_Handler' ) ) {

	/**
	 * The public-facing functionality of the plugin.
	 *
	 * Defines the paypal functionality
	 *
	 * @package    Subscriptions_For_Woocommerce
	 * @subpackage Subscriptions_For_Woocommerce/public
	 * @author     WP Swings <webmaster@wpswings.com>
	 */
	class WPS_Sfw_PayPal_IPN_Handler extends WC_Gateway_Paypal_IPN_Handler {

			/**
			 * The transaction type of paypal.
			 *
			 * @since    1.0.0
			 * @access   private
			 * @var      string    $wps_transaction_types    transaction type of paypal.
			 */
			private $wps_transaction_types = array(
				'subscr_signup',
				'subscr_payment',
				'subscr_cancel',
				'subscr_eot',
				'subscr_failed',
				'recurring_payment_suspended_due_to_max_failed_payment',
			);

			/**
			 * Constructor
			 *
			 * Initialize plugin and registers actions and filters to be used
			 *
			 * @since  1.0.0
			 * @author     WP Swings <webmaster@wpswings.com>
			 *
			 * @param bool   $sandbox sandbox.
			 * @param string $receiver_email receiver_email.
			 */
			public function __construct( $sandbox = false, $receiver_email = '' ) {

				$this->receiver_email = $receiver_email;
				$this->sandbox        = $sandbox;
			}

			/**
			 * This function is used to check valid response.
			 *
			 * @name wps_sfw_valid_response
			 * @param string $wps_transaction_details wps_transaction_details.
			 * @since    1.0.1
			 */
			public function wps_sfw_valid_response( $wps_transaction_details ) {
				global $wpdb;

				$wps_transaction_details = stripslashes_deep( $wps_transaction_details );

				if ( ! $this->wps_validate_transaction_type( $wps_transaction_details['txn_type'] ) ) {
					return;
				}

				if ( ! empty( $wps_transaction_details['custom'] ) ) {
					$order = $this->get_paypal_order( $wps_transaction_details['custom'] );
				} elseif ( ! empty( $wps_transaction_details['invoice'] ) ) {
					$order = wc_get_order( substr( $wps_transaction_details['invoice'], strrpos( $wps_transaction_details['invoice'], '-' ) + 1 ) );
				}

				if ( isset( $order ) ) {

					$order_id = $order->get_id();
					WC_Gateway_Paypal::log( 'WPS - Found order #' . $order_id );

					$wps_transaction_details['payment_status'] = strtolower( $wps_transaction_details['payment_status'] );

					WC_Gateway_Paypal::log( 'WPS - Txn Type: ' . $wps_transaction_details['txn_type'] );

					$this->wps_sfw_process_ipn_request( $order, $wps_transaction_details );

				} else {
					WC_Gateway_Paypal::log( 'WPS - Order Not Found.' );
				}
			}

			/**
			 * This function is used to validate transaction type.
			 *
			 * @name wps_validate_transaction_type
			 * @param string $txn_type txn_type.
			 * @since    1.0.1
			 */
			private function wps_validate_transaction_type( $txn_type ) {
				if ( in_array( strtolower( $txn_type ), $this->wps_get_transaction_types() ) ) {
					return true;
				} else {
					return false;
				}
			}

			/**
			 * This function is used to get transaction type.
			 *
			 * @name wps_get_transaction_types
			 * @since    1.0.1
			 */
			private function wps_get_transaction_types() {
				return $this->wps_transaction_types;
			}

			/**
			 * This function is used to process paypal response.
			 *
			 * @name wps_sfw_process_ipn_request
			 * @param object $order order.
			 * @param array  $wps_transaction_details wps_transaction_details.
			 * @since    1.0.1
			 */
			private function wps_sfw_process_ipn_request( $order, $wps_transaction_details ) {

				if ( isset( $wps_transaction_details['mc_currency'] ) ) {
					$this->validate_currency( $order, $wps_transaction_details['mc_currency'] );
				}
				WC_Gateway_Paypal::log( 'WPS - currency validation successfull' );
				if ( isset( $wps_transaction_details['receiver_email'] ) ) {
					$this->validate_receiver_email( $order, $wps_transaction_details['receiver_email'] );
				}

				WC_Gateway_Paypal::log( 'WPS - Email validation successfull' );
				$this->save_paypal_meta_data( $order, $wps_transaction_details );
				$this->wps_paypal_ipn_request( $order, $wps_transaction_details );
			}

			/**
			 * This function is used to process paypal inp request.
			 *
			 * @name wps_paypal_ipn_request
			 * @param object $order order.
			 * @param array  $wps_transaction_details wps_transaction_details.
			 * @since    1.0.1
			 */
			private function wps_paypal_ipn_request( $order, $wps_transaction_details ) {
				// show the data in log file.
				WC_Gateway_Paypal::log( 'WPS - Transaction log payment:' . wc_print_r( $wps_transaction_details, true ) );
				$wps_order_statuses = array( 'on-hold', 'pending', 'failed', 'cancelled', 'wc-wps_renewal' );
				$wps_order_info = $this->wps_sfw_get_order_info( $wps_transaction_details );
				if ( $order->get_order_key() != $wps_order_info['order_key'] ) {
					WC_Gateway_Paypal::log( 'WPS - Order keys not matching' );
					return;
				}
				$order_id = $order->get_id();
				// check if the transaction has been processed.
				$wps_order_transaction_id = wps_sfw_get_meta_data( $order_id, '_wps_paypal_transaction_ids', true );
				$wps_order_transactions   = $this->wps_sfw_validate_transaction( $wps_order_transaction_id, $wps_transaction_details );

				if ( $wps_order_transactions ) {
					wps_sfw_update_meta_data( $order_id, '_wps_paypal_transaction_ids', $order_transactions );
				} else {
					WC_Gateway_Paypal::log( 'WPS - Transaction ID already processed' );
					return;
				}

				$wps_order_has_susbcription = wps_sfw_get_meta_data( $order_id, 'wps_sfw_order_has_subscription', true );

				if ( 'yes' != $wps_order_has_susbcription ) {
					WC_Gateway_Paypal::log( 'WPS - Not a valid Subscription' );
					return;
				}

				$wps_subscription_id = wps_sfw_get_meta_data( $order_id, 'wps_subscription_id', true );

				if ( empty( $wps_subscription_id ) ) {

					WC_Gateway_Paypal::log( 'WPS - IPN subscription payment error - ' . $order_id . ' haven\'t subscriptions' );
					return;

				}
				/*check for valid subscription*/
				if ( ! wps_sfw_check_valid_subscription( $wps_subscription_id ) ) {
					WC_Gateway_Paypal::log( 'WPS - IPN subscription payment error - ' . $order_id . ' haven\'t valid subscriptions' );
					return;
				}

				$subscription = wc_get_order( $wps_subscription_id );

				switch ( $wps_transaction_details['txn_type'] ) {
					case 'subscr_signup':
						$args = array(
							'wps_subscriber_id'         => $wps_transaction_details['subscr_id'],
							'wps_subscriber_first_name' => $wps_transaction_details['first_name'],
							'wps_subscriber_last_name'  => $wps_transaction_details['last_name'],
							'wps_subscriber_address'    => $wps_transaction_details['payer_email'],
						);
						$this->wps_sfw_save_post_data( $order->get_id(), $args );
						$order->add_order_note( __( 'IPN subscription started', 'subscriptions-for-woocommerce' ) );

						if ( isset( $wps_transaction_details['mc_amount1'] ) && 0 == $wps_transaction_details['mc_amount1'] ) {
							$order->payment_complete( $wps_transaction_details['txn_id'] );
						}

						$args = array(
							'wps_sfw_paypal_transaction_id'        => $wps_transaction_details['txn_id'],
							'wps_sfw_paypal_subscriber_id'         => $wps_transaction_details['subscr_id'],

						);
						$this->wps_sfw_save_post_data( $wps_subscription_id, $args );

						break;
					case 'subscr_payment':
						// show the data in log file.
						WC_Gateway_Paypal::log( 'WPS - Transaction log for subscr_payment:' . wc_print_r( $wps_transaction_details, true ) );
						if ( 'completed' == strtolower( $wps_transaction_details['payment_status'] ) ) {

							$wps_order_transactions = wps_sfw_get_meta_data( $wps_subscription_id, '_wps_paypal_transaction_ids', true );
							$wps_order_transactions    = $this->wps_sfw_validate_transaction( $wps_order_transactions, $wps_transaction_details );
							if ( $wps_order_transactions ) {
								wps_sfw_update_meta_data( $wps_subscription_id, '_wps_paypal_transaction_ids', $wps_order_transactions );
							} else {
								WC_Gateway_Paypal::log( 'WPS - Transaction ID Error' );
								return;
							}
							$wps_pending_order    = false;
							$wps_renewal_order = $subscription->wps_renewal_subscription_order;
							WC_Gateway_Paypal::log( 'WPS - Renewal Order ID:' . $wps_renewal_order );
							if ( intval( $wps_renewal_order ) ) {
								$wps_pending_order = wc_get_order( $wps_renewal_order );
							}

							if ( isset( $wps_transaction_details['mc_gross'] ) ) {
								if ( $wps_pending_order ) {
									$this->wps_paypal_validate_amount( $wps_pending_order, $wps_transaction_details['mc_gross'] );
								} elseif ( $order->has_status( $wps_order_statuses ) ) {
									$this->wps_paypal_validate_amount( $order, $wps_transaction_details['mc_gross'] );
								}
							}
							if ( isset( $wps_transaction_details['subscr_id'] ) ) {
								$wps_sub_id = $wps_transaction_details['subscr_id'];
							} elseif ( isset( $wps_transaction_details['recurring_payment_id'] ) ) {
								$wps_sub_id = $wps_transaction_details['recurring_payment_id'];
							}

							WC_Gateway_Paypal::log( 'WPS - Subscription Status:' . ( $subscription->wps_subscription_status ) );

							if ( 'pending' == $subscription->wps_subscription_status || ( ! $wps_pending_order && $order->has_status( $wps_order_statuses ) ) ) {

								$args = array(
									'wps_subscriber_id'        => $wps_sub_id,
									'wps_subscriber_first_name' => $wps_transaction_details['first_name'],
									'wps_subscriber_last_name' => $wps_transaction_details['last_name'],
									'wps_subscriber_address'   => $wps_transaction_details['payer_email'],
									'wps_subscriber_payment_type' => wc_clean( $wps_transaction_details['payment_type'] ),
								);

								$this->wps_sfw_save_post_data( $order->get_id(), $args );
								$order->add_order_note( __( 'IPN subscription payment completed.', 'subscriptions-for-woocommerce' ) );
								$order->payment_complete( $wps_transaction_details['txn_id'] );

							} elseif ( $wps_pending_order ) {
									$args = array(
										'wps_subscriber_id'        => $wps_sub_id,
										'wps_subscriber_first_name' => $wps_transaction_details['first_name'],
										'wps_subscriber_last_name' => $wps_transaction_details['last_name'],
										'wps_subscriber_address'   => $wps_transaction_details['payer_email'],
										'wps_subscriber_payment_type' => wc_clean( $wps_transaction_details['payment_type'] ),
									);

									$this->wps_sfw_save_post_data( $wps_pending_order->get_id(), $args );

									$wps_pending_order->add_order_note( __( 'IPN subscription payment completed.', 'subscriptions-for-woocommerce' ) );
									$wps_pending_order->payment_complete( $wps_transaction_details['txn_id'] );

							} else {

								$wps_renewal_order = $this->wps_sfw_create_renewal_order_for_paypal( $wps_subscription_id );

								if ( ! $wps_renewal_order ) {
									WC_Gateway_Paypal::log( 'WPS - Renewal Order Creation failed' );
									return;
								}

								if ( isset( $wps_transaction_details['mc_gross'] ) ) {
									$this->wps_paypal_validate_amount( $wps_renewal_order, $wps_transaction_details['mc_gross'] );
								}

								$args = array(
									'wps_subscriber_id'        => $wps_sub_id,
									'wps_subscriber_first_name' => $wps_transaction_details['first_name'],
									'wps_subscriber_last_name' => $wps_transaction_details['last_name'],
									'wps_subscriber_address'   => $wps_transaction_details['payer_email'],
									'wps_subscriber_payment_type' => wc_clean( $wps_transaction_details['payment_type'] ),
								);

								$this->wps_sfw_save_post_data( $wps_renewal_order->get_id(), $args );

								$wps_renewal_order->add_order_note( __( 'IPN subscription payment completed.', 'subscriptions-for-woocommerce' ) );
								$wps_renewal_order->payment_complete( $wps_transaction_details['txn_id'] );

							}
								$args = array(
									'wps_sfw_paypal_transaction_id'        => $wps_transaction_details['txn_id'],
									'wps_sfw_paypal_subscriber_id'         => $wps_sub_id,

								);
								$this->wps_sfw_save_post_data( $wps_subscription_id, $args );

								WC_Gateway_Paypal::log( 'WPS - Subscription successfull' );
						}

						break;
					case 'subscr_cancel':
						$wps_subscriber_id = $this->wps_sfw_get_paypal_susbcriber_id( $wps_subscription_id );
						if ( $wps_subscriber_id != $wps_transaction_details['subscr_id'] ) {
							WC_Gateway_Paypal::log( 'IPN subscription cancellation request ignored ' . $wps_subscription_id );
						} else {
							wps_sfw_update_meta_data( $wps_subscription_id, 'wps_subscription_status', 'cancelled' );
							$order->add_order_note( __( 'WPS-IPN subscription cancelled for this order.', 'subscriptions-for-woocommerce' ) );
							WC_Gateway_Paypal::log( 'IPN subscription cancelled for subscription ' . $wps_subscription_id );

						}
						break;
					case 'subscr_eot': // Subscription ended.
						WC_Gateway_Paypal::log( 'WPS-IPN EOT request ignored for subscription ' . $wps_subscription_id );
						break;
					case 'subscr_failed': // Subscription sign up failed.
					case 'recurring_payment_suspended_due_to_max_failed_payment':
						$wps_subscriber_id = $this->wps_sfw_get_paypal_susbcriber_id( $wps_subscription_id );
						if ( $wps_subscriber_id != $wps_transaction_details['subscr_id'] ) {
							WC_Gateway_Paypal::log( 'IPN subscription cancellation request ignored ' . $wps_subscription_id );
						} else {

							$wps_order_transactions = wps_sfw_get_meta_data( $wps_subscription_id, '_wps_paypal_transaction_ids', true );
							$wps_order_transactions    = $this->wps_sfw_validate_transaction( $wps_order_transactions, $wps_transaction_details );
							if ( $wps_order_transactions ) {
								wps_sfw_update_meta_data( $wps_subscription_id, '_wps_paypal_transaction_ids', $wps_order_transactions );
							} else {
								WC_Gateway_Paypal::log( 'WPS - Transaction ID Error' );
								return;
							}

							$wps_pending_order    = false;
							$wps_renewal_order = $subscription->wps_renewal_subscription_order;
							WC_Gateway_Paypal::log( 'WPS - Renewal Order ID:' . $wps_renewal_order );
							if ( intval( $wps_renewal_order ) ) {
								$wps_pending_order = wc_get_order( $wps_renewal_order );
							}
							if ( $wps_pending_order ) {

								$wps_pending_order->add_order_note( sprintf( __( 'WPS - IPN Failed payment for  %$ ', 'subscriptions-for-woocommerce' ), $wps_subscription_id ) );

							} else {
								$wps_renewal_order = $this->wps_sfw_create_renewal_order_for_paypal( $wps_subscription_id );
								if ( ! $wps_renewal_order ) {
									WC_Gateway_Paypal::log( 'WPS - Renewal Order Creation failed' );
									return;
								}
								$wps_renewal_order->add_order_note( sprintf( __( 'WPS - IPN Failed payment for  %$ ', 'subscriptions-for-woocommerce' ), $wps_subscription_id ) );
							}
							$subscription->add_order_note( __( 'WPS - IPN Failed payment', 'subscriptions-for-woocommerce' ) );
							WC_Gateway_Paypal::log( 'WPS - IPN Failed payment:' . $subscription );

							break;
						}
				}
			}

			/**
			 * This function is used to validate transaction.
			 *
			 * @name wps_sfw_validate_transaction
			 * @param array $wps_transaction_ids wps_transaction_ids.
			 * @param array $wps_transaction_details wps_transaction_details.
			 * @since    1.0.1
			 */
			private function wps_sfw_validate_transaction( $wps_transaction_ids, $wps_transaction_details ) {

				$wps_transaction_ids = empty( $wps_transaction_ids ) ? array() : $wps_transaction_ids;
				// check if the ipn request as been processed.
				if ( isset( $wps_transaction_details['txn_id'] ) ) {
					$transaction_id = $wps_transaction_details['txn_id'] . '-' . $wps_transaction_details['txn_type'];

					if ( isset( $wps_transaction_details['payment_status'] ) ) {
						$transaction_id .= '-' . $wps_transaction_details['payment_status'];
					}
					if ( empty( $wps_transaction_ids ) || ! in_array( $transaction_id, $wps_transaction_ids ) ) {
						$wps_transaction_ids[] = $transaction_id;
					} else {

						WC_Gateway_Paypal::log( 'paypal', 'WPS - Subscription IPN Error: IPN ' . $transaction_id . ' message has already been correctly handled.' );
						return false;
					}
				} elseif ( isset( $wps_transaction_details['ipn_track_id'] ) ) {
					$track_id = $wps_transaction_details['txn_type'] . '-' . $wps_transaction_details['ipn_track_id'];
					if ( empty( $wps_transaction_ids ) || ! in_array( $track_id, $wps_transaction_ids ) ) {
						$wps_transaction_ids[] = $track_id;
					} else {

						WC_Gateway_Paypal::log( 'paypal', 'WPS - Subscription IPN Error: IPN ' . $track_id . ' message has already been correctly handled.' );
						return false;
					}
				}

				return $wps_transaction_ids;
			}

			/**
			 * This function is used to save data.
			 *
			 * @name wps_sfw_save_post_data
			 * @param int   $order_id order_id.
			 * @param array $args args.
			 * @since    1.0.1
			 */
			private function wps_sfw_save_post_data( $order_id, $args ) {
				if ( isset( $order_id ) && ! empty( $order_id ) && ! empty( $args ) && is_array( $args ) ) {
					foreach ( $args as $key => $value ) {
						wps_sfw_update_meta_data( $order_id, $key, $value );
					}
				}
			}

			/**
			 * This function is used to get order info.
			 *
			 * @name wps_sfw_get_order_info
			 * @param array $args args.
			 * @since    1.0.1
			 */
			private function wps_sfw_get_order_info( $args ) {
				return isset( $args['custom'] ) ? json_decode( $args['custom'], true ) : false;
			}

			/**
			 * This function is used to validate amount.
			 *
			 * @name wps_paypal_validate_amount
			 * @param object $wps_order wps_order.
			 * @param int    $wps_amount wps_amount.
			 * @since    1.0.1
			 */
			private function wps_paypal_validate_amount( $wps_order, $wps_amount ) {
				if ( wc_format_decimal( $wps_order->get_total(), 2 ) != wc_format_decimal( $wps_amount, 2 ) ) {
					WC_Gateway_Paypal::log( 'Amounts not matching: ' . $wps_amount );
					$wps_order->update_status( 'on-hold' );
					return;
				}
			}

			/**
			 * This function is used to get subscriber id.
			 *
			 * @name wps_sfw_get_paypal_susbcriber_id
			 * @param int $subscription_id subscription_id.
			 * @since    1.0.1
			 */
			private function wps_sfw_get_paypal_susbcriber_id( $subscription_id ) {
				$wps_subscriber_id = '';
				if ( isset( $subscription_id ) && ! empty( $subscription_id ) ) {
					$wps_subscriber_id = wps_sfw_get_meta_data( $subscription_id, 'wps_sfw_paypal_subscriber_id', true );
				}
				return $wps_subscriber_id;
			}

			/**
			 * This function is used to create renewal order.
			 *
			 * @name wps_sfw_create_renewal_order_for_paypal
			 * @param int $subscription_id subscription_id.
			 * @since    1.0.1
			 */
			private function wps_sfw_create_renewal_order_for_paypal( $subscription_id ) {
				$wps_renew_order = false;
				WC_Gateway_Paypal::log( 'WPS - Renewal Order result' . $subscription_id );
				if ( wps_sfw_check_valid_subscription( $subscription_id ) ) {
					WC_Gateway_Paypal::log( 'WPS - Renewal Order result1:' . $subscription_id );
					$current_time = current_time( 'timestamp' );

					if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
						$subscription = new WPS_Subscription( $subscription_id );
					} else {
						$subscription = get_post( $subscription_id );
					}
					// show the data in log file.
					WC_Gateway_Paypal::log( 'WPS - Renewal Order subscription:' . wc_print_r( $subscription_id, true ) );

					$parent_order_id  = $subscription->wps_parent_order;
					WC_Gateway_Paypal::log( 'WPS - Renewal Order result parent order:' . $parent_order_id );

					$parent_order = wc_get_order( $parent_order_id );
					$billing_details = $parent_order->get_address( 'billing' );
					$shipping_details = $parent_order->get_address( 'shipping' );
					$parent_order_currency = $parent_order->get_currency();
					$new_status = 'wc-wps_renewal';

					$user_id = $parent_order->get_user_id();
					$product_id = wps_sfw_get_meta_data( $subscription_id, 'product_id', true );
					$product_qty = wps_sfw_get_meta_data( $subscription_id, 'product_qty', true );
					$payment_method = $subscription->get_payment_method();
					$payment_method_title = $subscription->get_payment_method_title();

					$wps_old_payment_method = $parent_order->get_payment_method();
					$args = array(
						'status'      => $new_status,
						'customer_id' => $user_id,
					);
					$wps_new_order = wc_create_order( $args );
					$wps_new_order->set_currency( $parent_order_currency );

					$_product = wc_get_product( $product_id );

					$total = 0;
					$tax_total = 0;
					$variations = array();

					$item_id = $wps_new_order->add_product(
						$_product,
						$product_qty
					);
					$wps_new_order->update_taxes();
					$wps_new_order->calculate_totals();
					$order_id = $wps_new_order->get_id();
					WC_Gateway_Paypal::log( 'WPS - Renewal Order result order_id:' . $order_id );

					$wps_new_order->set_payment_method( $payment_method );
					$wps_new_order->set_payment_method_title( $payment_method_title );

					$wps_new_order->set_address( $billing_details, 'billing' );
					$wps_new_order->set_address( $shipping_details, 'shipping' );
					wps_sfw_update_meta_data( $order_id, 'wps_sfw_renewal_order', 'yes' );
					wps_sfw_update_meta_data( $order_id, 'wps_sfw_subscription', $subscription_id );
					wps_sfw_update_meta_data( $order_id, 'wps_sfw_parent_order_id', $parent_order_id );

					do_action( 'wps_sfw_renewal_order_creation', $wps_new_order, $subscription_id );

					/*if trial period enable*/
					if ( '' == $wps_old_payment_method ) {
						$parent_order_id = $subscription_id;
					}
					/*update next payment date*/
					$wps_next_payment_date = wps_sfw_next_payment_date( $subscription_id, $current_time, 0 );

					wps_sfw_update_meta_data( $subscription_id, 'wps_next_payment_date', $wps_next_payment_date );
					do_action( 'wps_sfw_other_payment_gateway_renewal', $wps_new_order, $susbcription_id, $payment_method );
					return $wps_new_order;
				}
				WC_Gateway_Paypal::log( 'WPS - Renewal Order result  failed:' . $subscription_id );
				return $wps_renew_order;
			}
	}
}
