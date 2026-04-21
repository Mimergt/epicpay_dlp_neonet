<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpicPay_DLP_Neonet_Void_Gateway_Adapter {
	private $settings;

	public function __construct( EpicPay_DLP_Neonet_Void_Settings $settings ) {
		$this->settings = $settings;
	}

	public function void_order( WC_Order $order ) {
		$gateway = $this->get_gateway_for_order( $order );

		if ( ! $gateway ) {
			return array( 'success' => false, 'code' => 'gateway_not_found', 'message' => __( 'Gateway instance not found for this order payment method.', 'epicpay-dlp-neonet-void' ) );
		}

		$transaction_reference = $this->get_transaction_reference( $order );

		if ( empty( $transaction_reference ) ) {
			return array( 'success' => false, 'code' => 'missing_transaction_reference', 'message' => __( 'No transaction reference found in order data/meta.', 'epicpay-dlp-neonet-void' ) );
		}

		$custom = apply_filters( 'epicpay_dlp_neonet_void_custom_attempt', null, $order, $gateway, $transaction_reference );
		if ( is_array( $custom ) && isset( $custom['success'] ) ) {
			return $custom;
		}

		$void_result = $this->request_direct_void( $order, $gateway, $transaction_reference );

		if ( ! empty( $void_result['success'] ) ) {
			return $void_result;
		}

		if ( ! $this->settings->allow_refund_fallback() ) {
			return $void_result;
		}

		$fallback_result = $this->attempt_refund_fallback( $order, $gateway, $transaction_reference );
		if ( ! empty( $fallback_result['success'] ) ) {
			return $fallback_result;
		}

		return $void_result;
	}

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

	private function request_direct_void( WC_Order $order, $gateway, $transaction_reference ) {
		$credentials = $this->resolve_cybersource_credentials( $gateway );

		if ( ! empty( $credentials['error'] ) ) {
			return array( 'success' => false, 'code' => 'missing_credentials', 'message' => $credentials['error'] );
		}

		$void_result = $this->call_cybersource_api(
			$order, $credentials, $transaction_reference, 'voids',
			array( 'clientReferenceInformation' => array( 'code' => (string) $order->get_order_number() ) )
		);

		if ( ! empty( $void_result['success'] ) ) {
			return $void_result;
		}

		if ( isset( $void_result['http_status'] ) && 404 === $void_result['http_status'] ) {
			$this->log( 'VOID 404 — retrying with /reversals for auth-only transaction', array( 'order_id' => $order->get_id() ) );
			return $this->call_cybersource_api(
				$order, $credentials, $transaction_reference, 'reversals',
				array(
					'clientReferenceInformation' => array( 'code' => (string) $order->get_order_number() ),
					'reversalInformation'        => array(
						'amountDetails' => array(
							'totalAmount' => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
							'currency'    => (string) $order->get_currency(),
						),
					),
				)
			);
		}

		return $void_result;
	}

	private function call_cybersource_api( WC_Order $order, $credentials, $transaction_reference, $action, $payload ) {
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json ) {
			return array( 'success' => false, 'code' => 'payload_encoding_failed', 'message' => __( 'Failed to encode VOID payload.', 'epicpay-dlp-neonet-void' ) );
		}

		$host = 'production' === $credentials['environment'] ? 'api.cybersource.com' : 'apitest.cybersource.com';
		$path = '/pts/v2/payments/' . rawurlencode( $transaction_reference ) . '/' . $action;
		$url  = 'https://' . $host . $path;

		$date_header   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$digest_header = 'SHA-256=' . base64_encode( hash( 'sha256', $payload_json, true ) );

		$sig_headers = 'host date (request-target) digest v-c-merchant-id';
		$sig_payload =
			'host: ' . $host . "\n" .
			'date: ' . $date_header . "\n" .
			'(request-target): post ' . $path . "\n" .
			'digest: ' . $digest_header . "\n" .
			'v-c-merchant-id: ' . $credentials['merchant_id'];

		$decoded_secret = base64_decode( $credentials['shared_secret'], true );
		$hmac_key       = ( false !== $decoded_secret && strlen( $decoded_secret ) > 0 ) ? $decoded_secret : $credentials['shared_secret'];
		$signature      = base64_encode( hash_hmac( 'sha256', $sig_payload, $hmac_key, true ) );

		$signature_header = sprintf(
			'keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"',
			$credentials['key_id'],
			$sig_headers,
			$signature
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 45,
			'headers' => array(
				'Accept'          => 'application/hal+json;charset=utf-8',
				'Content-Type'    => 'application/json;charset=utf-8',
				'v-c-merchant-id' => $credentials['merchant_id'],
				'Date'            => $date_header,
				'Host'            => $host,
				'Digest'          => $digest_header,
				'Signature'       => $signature_header,
			),
			'body' => $payload_json,
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Cybersource API transport error', array( 'action' => $action, 'error' => $response->get_error_message() ) );
			return array( 'success' => false, 'code' => 'void_transport_error', 'message' => $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$this->log( 'Cybersource API response', array(
			'action'                => $action,
			'status_code'           => $status_code,
			'order_id'              => $order->get_id(),
			'transaction_reference' => $transaction_reference,
			'body'                  => $body_json,
		) );

		if ( $status_code >= 200 && $status_code < 300 ) {
			$new_reference = isset( $body_json['id'] ) ? (string) $body_json['id'] : $transaction_reference;
			return array(
				'success'               => true,
				'code'                  => 'void_approved',
				'message'               => __( 'Cybersource VOID approved.', 'epicpay-dlp-neonet-void' ),
				'transaction_reference' => $new_reference,
				'void_reference'        => $new_reference,
			);
		}

		$error_message = __( 'Cybersource VOID rejected.', 'epicpay-dlp-neonet-void' );
		if ( is_array( $body_json ) ) {
			if ( ! empty( $body_json['message'] ) ) {
				$error_message = sanitize_text_field( (string) $body_json['message'] );
			} elseif ( ! empty( $body_json['details'] ) ) {
				$error_message = sanitize_text_field( wp_json_encode( $body_json['details'] ) );
			}
		}

		return array(
			'success'               => false,
			'code'                  => 'void_rejected',
			'http_status'           => $status_code,
			'message'               => sprintf(
				__( 'Cybersource VOID/%1$s failed (%2$s): %3$s', 'epicpay-dlp-neonet-void' ),
				strtoupper( $action ),
				(string) $status_code,
				$error_message
			),
			'transaction_reference' => $transaction_reference,
		);
	}

	private function attempt_refund_fallback( WC_Order $order, $gateway, $transaction_reference ) {
		if ( ! method_exists( $gateway, 'process_refund' ) ) {
			return array( 'success' => false, 'code' => 'refund_method_missing', 'message' => __( 'Gateway does not expose process_refund() for fallback.', 'epicpay-dlp-neonet-void' ) );
		}
		$reason = sprintf( __( 'Fallback reversal after VOID failure for order %s', 'epicpay-dlp-neonet-void' ), $order->get_order_number() );
		$result = $gateway->process_refund( $order->get_id(), $order->get_total(), $reason );
		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'code' => 'fallback_gateway_error', 'message' => $result->get_error_message() );
		}
		if ( true !== $result ) {
			return array( 'success' => false, 'code' => 'fallback_rejected', 'message' => __( 'Fallback gateway reversal rejected.', 'epicpay-dlp-neonet-void' ) );
		}
		return array( 'success' => true, 'code' => 'fallback_accepted', 'message' => __( 'Fallback gateway reversal accepted after direct VOID failure.', 'epicpay-dlp-neonet-void' ), 'transaction_reference' => $transaction_reference );
	}

	private function resolve_cybersource_credentials( $gateway ) {
		if ( 'yes' === $this->settings->get( 'use_gateway_credentials' ) ) {
			$from_gateway = $this->extract_credentials_from_gateway( $gateway );
			if ( empty( $from_gateway['error'] ) ) {
				return $from_gateway;
			}
		}
		$manual = array(
			'merchant_id'   => trim( (string) $this->settings->get( 'cybersource_merchant_id' ) ),
			'key_id'        => trim( (string) $this->settings->get( 'cybersource_api_key_id' ) ),
			'shared_secret' => trim( (string) $this->settings->get( 'cybersource_api_shared_secret' ) ),
			'environment'   => (string) $this->settings->get( 'cybersource_environment' ),
		);
		if ( empty( $manual['merchant_id'] ) || empty( $manual['key_id'] ) || empty( $manual['shared_secret'] ) ) {
			return array( 'error' => __( 'Cybersource credentials are missing. Enable gateway credentials or set manual credentials in EpicPay VOID settings.', 'epicpay-dlp-neonet-void' ) );
		}
		return $manual;
	}

	/**
	 * Read credentials from the active CyberSource gateway.
	 *
	 * Strategy 1: SkyVerge gateway methods — get_merchant_id(), get_api_key(),
	 *   get_api_shared_secret(), get_environment() — already return the correct
	 *   value for the active environment.
	 *
	 * Strategy 2: Read raw WP option (woocommerce_{id}_settings).
	 *   SkyVerge stores env-specific keys:
	 *     production  → merchant_id, api_key, api_shared_secret
	 *     test        → test_merchant_id, test_api_key, test_api_shared_secret
	 */
	private function extract_credentials_from_gateway( $gateway ) {
		// Strategy 1: call SkyVerge methods directly.
		if ( $gateway ) {
			$merchant_id = method_exists( $gateway, 'get_merchant_id' )       ? (string) $gateway->get_merchant_id()       : '';
			$key_id      = method_exists( $gateway, 'get_api_key' )           ? (string) $gateway->get_api_key()           : '';
			$secret      = method_exists( $gateway, 'get_api_shared_secret' ) ? (string) $gateway->get_api_shared_secret() : '';
			$environment = method_exists( $gateway, 'get_environment' )       ? (string) $gateway->get_environment()       : '';

			if ( ! empty( $merchant_id ) && ! empty( $key_id ) && ! empty( $secret ) ) {
				$this->log( 'Credentials resolved via gateway methods', array( 'merchant_id' => $merchant_id, 'environment' => $environment ) );
				return array(
					'merchant_id'   => $merchant_id,
					'key_id'        => $key_id,
					'shared_secret' => $secret,
					'environment'   => ( 'production' === $environment ) ? 'production' : 'test',
				);
			}
		}

		// Strategy 2: read from wp_options.
		$flat               = array();
		$gateway_ids_to_try = array();
		if ( $gateway && ! empty( $gateway->id ) ) {
			$gateway_ids_to_try[] = $gateway->id;
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'gateway_ids' ) ) ) ) as $gid ) {
			if ( ! in_array( $gid, $gateway_ids_to_try, true ) ) {
				$gateway_ids_to_try[] = $gid;
			}
		}
		foreach ( $gateway_ids_to_try as $gid ) {
			$raw = get_option( 'woocommerce_' . $gid . '_settings', array() );
			if ( is_array( $raw ) && ! empty( $raw ) ) {
				$flat = $raw;
				break;
			}
		}

		$env_val = ( isset( $flat['environment'] ) && is_scalar( $flat['environment'] ) )
			? strtolower( (string) $flat['environment'] )
			: 'test';
		$is_prod = ( 'production' === $env_val );

		$merchant_id = $is_prod
			? $this->pick_scalar( $flat, array( 'merchant_id' ) )
			: $this->pick_scalar( $flat, array( 'test_merchant_id', 'merchant_id' ) );
		$key_id = $is_prod
			? $this->pick_scalar( $flat, array( 'api_key' ) )
			: $this->pick_scalar( $flat, array( 'test_api_key', 'api_key' ) );
		$secret = $is_prod
			? $this->pick_scalar( $flat, array( 'api_shared_secret' ) )
			: $this->pick_scalar( $flat, array( 'test_api_shared_secret', 'api_shared_secret' ) );

		if ( ! empty( $merchant_id ) && ! empty( $key_id ) && ! empty( $secret ) ) {
			$this->log( 'Credentials resolved via wp_options', array( 'merchant_id' => $merchant_id, 'environment' => $env_val ) );
			return array(
				'merchant_id'   => $merchant_id,
				'key_id'        => $key_id,
				'shared_secret' => $secret,
				'environment'   => $is_prod ? 'production' : 'test',
			);
		}

		$this->log( 'Could not resolve credentials from gateway', array(
			'merchant_id_found' => ! empty( $merchant_id ),
			'key_id_found'      => ! empty( $key_id ),
			'secret_found'      => ! empty( $secret ),
			'flat_keys'         => array_keys( $flat ),
		) );

		return array( 'error' => __( 'Could not read Cybersource credentials from the gateway settings.', 'epicpay-dlp-neonet-void' ) );
	}

	/**
	 * Pick first non-empty scalar value from a flat settings array.
	 * Safely skips values that are arrays (some WP settings store arrays).
	 */
	private function pick_scalar( $flat, $candidates ) {
		foreach ( $candidates as $key ) {
			if ( isset( $flat[ $key ] ) && is_scalar( $flat[ $key ] ) && '' !== trim( (string) $flat[ $key ] ) ) {
				return trim( (string) $flat[ $key ] );
			}
		}
		return '';
	}

	private function log( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->info( $message, array( 'source' => 'epicpay-dlp-neonet-void', 'context' => $context ) );
	}
}
