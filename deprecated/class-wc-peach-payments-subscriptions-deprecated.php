<?php
/**
 * Peach Payments Gateway Deprecated
 *
 *
 * @class      WC_Peach_Payments_Subscriptions_Deprecated
 * @extends    WC_Peach_Payments_Subscriptions
 * @package    WC_Peach_Payments
 * @author     LightSpeed
 */
class WC_Peach_Payments_Subscriptions_Deprecated extends WC_Peach_Payments_Subscriptions {

	function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'remove_renewal_order_meta' ), 10, 4 );
			add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', array( &$this, 'update_failing_payment_method' ), 10, 3 );
		}

	}

	/**
	 * Don't transfer Peach Payments payment/token meta when creating a parent renewal order.
	 *
	 * @access public
	 * @param array $order_meta_query MySQL query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return void
	 */
	function remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		if ( 'parent' == $new_order_role )
				$order_meta_query .= " AND `meta_key` NOT LIKE '_peach_subscription_payment_method' "
					. " AND `meta_key` NOT LIKE '_peach_payment_token' ";

		return $order_meta_query;
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $order WC_Order The WC_Order object of the order which the subscription was purchased in.
	 * @param $product_id int The ID of the subscription product for which this payment relates.
	 * @access public
	 * @return void
	 */
	function scheduled_subscription_payment( $amount_to_charge, $order ) {

		$payment_id = get_post_meta( $this->get_order_id( $order ), '_peach_subscription_payment_method', true );
		$result     = $this->execute_post_subscription_payment_request( $order, $amount_to_charge, $payment_id );

		$product_id = 0;

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'Scheduled Subscription Payment Failed: Couldn\'t connect to gateway server - Peach Payments', 'wc-gateway-peach-payments' ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
		} elseif ( 'NOK' == $result['PROCESSING.RESULT'] ) {
			/* translators: %s: Scheduled Subscription Payment Failed */
			$order->add_order_note( sprintf( __( 'Scheduled Subscription Payment Failed: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), woocommerce_clean( $result['PROCESSING.RETURN'] ) ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
		} elseif ( 'ACK' == $result['PROCESSING.RESULT'] ) {
			/* translators: %s: Scheduled Subscription Payment Accepted */
			$order->add_order_note( sprintf( __( 'Scheduled Subscription Payment Accepted: Payment Response is "%s" - Peach Payments.', 'wc-gateway-peach-payments' ), woocommerce_clean( $result['PROCESSING.RETURN'] ) ) );
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
		} else {
			$order->add_order_note( __( 'Scheduled Subscription Payment Failed: An unknown error has occured - Peach Payments', 'wc-gateway-peach-payments' ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
		}

	}


	/**
	 * Execute subscriptions payment request through POST endpoint and returns response array
	 *
	 * @access public
	 * @param object $order
	 * @param int $amount
	 * @param string $payment_method_id
	 * @return array | object
	 */
	function execute_post_subscription_payment_request( $order, $amount, $payment_method_id ) {
		global $woocommerce;

		$data = array();

		$order_items = $order->get_items();
		$product     = $this->get_item_product( array_shift( $order_items ), $order );
		/* translators: %s: Subscription for */
		$subscription_name = sprintf( __( 'Subscription for "%s"', 'wc-gateway-peach-payments' ), $product->get_title() ) . ' ' . sprintf( __( '(Order %s)', 'wc-gateway-peach-payments' ), $order->get_order_number() );

		$payment_request = array(
			'PAYMENT.CODE'                 => 'CC.DB',
			'IDENTIFICATION.TRANSACTIONID' => $this->get_order_id( $order ),
			'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $order ),
			'PRESENTATION.USAGE'           => $subscription_name,
			'PRESENTATION.AMOUNT'          => $amount,
			'PRESENTATION.CURRENCY'        => 'ZAR',
			'ACCOUNT.REGISTRATION'         => $payment_method_id,
			'RECURRENCE.MODE'              => 'REPEATED',
			'FRONTEND.ENABLED'             => 'false',
		);
		if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
			$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
		}
		$request  = array_merge( $payment_request, $this->base_request );
		$response = wp_remote_post( $this->post_query_url, array(
			'method'     => 'POST',
			'body'       => $request,
			'timeout'    => 70,
			'sslverify'  => true,
			'user-agent' => 'WooCommerce ' . $woocommerce->version,
		));
		if ( is_wp_error( $response ) )
			return new WP_Error( 'peach_error', __( 'There was a problem connecting to the payment gateway.', 'wc-gateway-peach-payments' ) );
		if ( empty( $response['body'] ) )
			return new WP_Error( 'peach_error', __( 'Empty response.', 'wc-gateway-peach-payments' ) );
		// Convert response string to array
		$vars = explode( '&', $response['body'] );
		foreach ( $vars as $key => $val ) {
			$var             = explode( '=', $val );
			$data[ $var[0] ] = $var[1];
		}
		return $data;
	}

	/**
	 * Update the payment_id for a subscription after using Peach Payments to complete a payment to make up for
	 * an automatic renewal payment which previously failed.
	 *
	 * @access public
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @return array()
	 */
	function update_failing_payment_method( $original_order, $renewal_order, $subscription_key ) {
		global $woocommerce;
		try {
			// Check if paying with registered payment method
			if ( isset( $_POST['peach_payment_id'] ) && wp_verify_nonce( $_POST['peach_payment_id'] ) && ctype_digit( $_POST['peach_payment_id'] ) ) {
				$payment_ids = get_user_meta( $this->get_customer_id( $original_order ), '_peach_payment_id', false );
				$payment_id  = $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'];

				//throw exception if payment method does not exist
				if ( ! isset( $payment_ids[ $_POST['peach_payment_id'] ]['payment_id'] ) ) {
					throw new Exception( __( 'Invalid', 'wc-gateway-peach-payments' ) );
				} else {
					update_post_meta( $this->get_order_id( $original_order ), '_peach_subscription_payment_method', $payment_id );
				}
			} elseif ( isset( $_POST['peach_payment_id'] ) && wp_verify_nonce( $_POST['peach_payment_id'] ) && ( 'saveinfo' == $_POST['peach_payment_id'] ) ) {
				$subscription_request = array(
					'IDENTIFICATION.TRANSACTIONID' => $this->get_order_id( $original_order ),
					'IDENTIFICATION.SHOPPERID'     => $this->get_customer_id( $original_order ),

					'NAME.GIVEN'                   => $original_order->billing_first_name,
					'NAME.FAMILY'                  => $original_order->billing_last_name,
					'ADDRESS.STREET'               => $original_order->billing_address_1,
					'ADDRESS.ZIP'                  => $original_order->billing_postcode,
					'ADDRESS.CITY'                 => $original_order->billing_city,
					'ADDRESS.STATE'                => $original_order->billing_state,
					'ADDRESS.COUNTRY'              => $original_order->billing_country,

					'CONTACT.EMAIL'                => $original_order->billing_email,
					'CONTACT.IP'                   => $_SERVER['REMOTE_ADDR'],

					'PAYMENT.TYPE'                 => 'RG',
					'RECURRENCE.MODE'              => 'INITAL',
				);

				if ( 'CONNECTOR_TEST' || 'LIVE' == $this->transaction_mode ) {
					$payment_request['CRITERION.presentation.currency3D'] = 'ZAR';
					$payment_request['CRITERION.presentation.amount3D']   = '1.0';
				}

				if ( 'CONNECTOR_TEST' == $this->transaction_mode ) {
					$payment_request['CRITERION.USE_3D_SIMULATOR'] = 'false';
				}

				$request = array_merge( $subscription_request, $this->base_request );

				$json_token_response = $this->generate_token( $request );

				if ( is_wp_error( $json_token_response ) ) {
					throw new Exception( $json_token_response->get_error_message() );
				}

				//token received - offload payment processing to copyandpay form
				return array(
					'result'   => 'success',
					'redirect' => $original_order->get_checkout_payment_url( true ),
				);
			}
		} catch ( Exception $e ) {

			wc_add_notice( __( 'Error:', 'wc-gateway-peach-payments' ) . ' "' . $e->getMessage() . '"', 'error' );
			return;
		}
	}
}
