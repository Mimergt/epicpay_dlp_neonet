<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpicPay_DLP_Neonet_Void_Listener {
	const META_VOID_STATUS      = '_epicpay_void_status';
	const META_VOID_ATTEMPTED_AT = '_epicpay_void_attempted_at';
	const META_VOID_REFERENCE   = '_epicpay_void_reference';
	const META_VOID_MESSAGE     = '_epicpay_void_message';

	/**
	 * @var EpicPay_DLP_Neonet_Void_Settings
	 */
	private $settings;

	/**
	 * @var EpicPay_DLP_Neonet_Void_Gateway_Adapter
	 */
	private $gateway_adapter;

	/**
	 * @param EpicPay_DLP_Neonet_Void_Settings        $settings Settings service.
	 * @param EpicPay_DLP_Neonet_Void_Gateway_Adapter $gateway_adapter Gateway adapter.
	 */
	public function __construct( EpicPay_DLP_Neonet_Void_Settings $settings, EpicPay_DLP_Neonet_Void_Gateway_Adapter $gateway_adapter ) {
		$this->settings        = $settings;
		$this->gateway_adapter = $gateway_adapter;
	}

	/**
	 * Register cancellation hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_cancelled_order' ), 20, 1 );
	}

	/**
	 * Handle order cancellation and attempt gateway void.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_cancelled_order( $order_id ) {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->is_allowed_gateway( $order ) ) {
			$this->mark_skipped( $order, __( 'Skipped: payment method not allowed by plugin settings.', 'epicpay-dlp-neonet-void' ) );
			return;
		}

		if ( 'success' === $order->get_meta( self::META_VOID_STATUS, true ) ) {
			return;
		}

		$result = $this->gateway_adapter->void_order( $order );

		$order->update_meta_data( self::META_VOID_ATTEMPTED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::META_VOID_STATUS, ! empty( $result['success'] ) ? 'success' : 'failed' );
		$order->update_meta_data( self::META_VOID_REFERENCE, isset( $result['transaction_reference'] ) ? wc_clean( (string) $result['transaction_reference'] ) : '' );
		$order->update_meta_data( self::META_VOID_MESSAGE, isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '' );
		$order->save();

		if ( ! empty( $result['success'] ) ) {
			$order->add_order_note(
				__( 'EpicPay VOID: Payment reversal request accepted by gateway.', 'epicpay-dlp-neonet-void' )
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: reason message */
				__( 'EpicPay VOID failed: %s', 'epicpay-dlp-neonet-void' ),
				isset( $result['message'] ) ? wc_clean( (string) $result['message'] ) : __( 'Unknown error', 'epicpay-dlp-neonet-void' )
			)
		);
	}

	/**
	 * Validate order payment method against configured gateway IDs.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function is_allowed_gateway( WC_Order $order ) {
		$allowed_ids = $this->settings->get_csv( 'gateway_ids' );

		if ( empty( $allowed_ids ) ) {
			return false;
		}

		return in_array( $order->get_payment_method(), $allowed_ids, true );
	}

	/**
	 * Mark order as skipped with audit metadata.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reason Reason text.
	 * @return void
	 */
	private function mark_skipped( WC_Order $order, $reason ) {
		$order->update_meta_data( self::META_VOID_ATTEMPTED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::META_VOID_STATUS, 'skipped' );
		$order->update_meta_data( self::META_VOID_MESSAGE, sanitize_text_field( $reason ) );
		$order->save();

		$order->add_order_note( sprintf( __( 'EpicPay VOID skipped: %s', 'epicpay-dlp-neonet-void' ), wc_clean( $reason ) ) );
	}
}
