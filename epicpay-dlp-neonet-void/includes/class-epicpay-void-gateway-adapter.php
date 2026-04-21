<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpicPay_DLP_Neonet_Void_Gateway_Adapter {
	/**
	 * @var EpicPay_DLP_Neonet_Void_Settings
	 */
	private $settings;

	/**
	 * @param EpicPay_DLP_Neonet_Void_Settings $settings Settings service.
	 */
	public function __construct( EpicPay_DLP_Neonet_Void_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Attempts to void an order's transaction.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public function void_order( WC_Order $order ) {
		$gateway = $this->get_gateway_for_order( $order );

		if ( ! $gateway ) {
			return array(
				'success' => false,
				'code'    => 'gateway_not_found',
				'message' => __( 'Gateway instance not found for this order payment method.', 'epicpay-dlp-neonet-void' ),
			);
		}

		$transaction_reference = $this->get_transaction_reference( $order );

		if ( empty( $transaction_reference ) ) {
			return array(
				'success' => false,
				'code'    => 'missing_transaction_reference',
				'message' => __( 'No transaction reference found in order data/meta.', 'epicpay-dlp-neonet-void' ),
			);
		}

		$custom = apply_filters( 'epicpay_dlp_neonet_void_custom_attempt', null, $order, $gateway, $transaction_reference );

		if ( is_array( $custom ) && isset( $custom['success'] ) ) {
			return $custom;
		}

		if ( ! method_exists( $gateway, 'process_refund' ) ) {
			return array(
				'success' => false,
				'code'    => 'refund_method_missing',
				'message' => __( 'Gateway does not expose process_refund() to trigger void/refund handling.', 'epicpay-dlp-neonet-void' ),
			);
		}

		$reason = sprintf(
			/* translators: %s: Order number */
			__( 'Auto-void on cancellation for order %s', 'epicpay-dlp-neonet-void' ),
			$order->get_order_number()
		);

		$result = $gateway->process_refund( $order->get_id(), $order->get_total(), $reason );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'code'    => 'gateway_error',
				'message' => $result->get_error_message(),
				'errors'  => $result->get_error_messages(),
			);
		}

		if ( true !== $result ) {
			return array(
				'success' => false,
				'code'    => 'gateway_rejected',
				'message' => __( 'Gateway rejected the VOID/refund attempt.', 'epicpay-dlp-neonet-void' ),
			);
		}

		return array(
			'success'              => true,
			'code'                 => 'request_accepted',
			'message'              => __( 'Gateway accepted cancellation payment reversal request (void/refund per gateway eligibility).', 'epicpay-dlp-neonet-void' ),
			'transaction_reference' => $transaction_reference,
		);
	}

	/**
	 * Resolve gateway instance from order payment method.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway_for_order( WC_Order $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$payment_method = $order->get_payment_method();
		$gateways       = WC()->payment_gateways()->payment_gateways();

		if ( isset( $gateways[ $payment_method ] ) ) {
			return $gateways[ $payment_method ];
		}

		return null;
	}

	/**
	 * Build transaction reference string from order fields and meta fallback.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_transaction_reference( WC_Order $order ) {
		$transaction_id = $order->get_transaction_id();

		if ( ! empty( $transaction_id ) ) {
			return (string) $transaction_id;
		}

		foreach ( $this->settings->get_csv( 'transaction_meta_keys' ) as $meta_key ) {
			$value = $order->get_meta( $meta_key, true );
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		foreach ( $this->settings->get_csv( 'request_id_meta_keys' ) as $meta_key ) {
			$value = $order->get_meta( $meta_key, true );
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}
}
