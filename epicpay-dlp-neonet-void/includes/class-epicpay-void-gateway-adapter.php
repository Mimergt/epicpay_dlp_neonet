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
	 * Attempts to void an order's transaction using Cybersource direct REST API.
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

	/**
	 * Attempt a direct Cybersource VOID request.
	 *
	 * Uses /voids endpoint (correct for post-capture/Charge transactions).
	 * Falls back to /reversals only if /voids returns 404 (auth-only orders).
	 *
	 * @param WC_Order           $order WooCommerce order.
	 * @param WC_Payment_Gateway $gateway Payment gateway instance.
	 * @param string             $transaction_reference Cybersource transaction ID/reference.
	 * @return array
	 */
	private function request_direct_void( WC_Order $order, $gateway, $transaction_reference ) {
		$credentials = $this->resolve_cybersource_credentials( $gateway );

		if ( ! empty( $credentials['error'] ) ) {
			return array(
				'success' => false,
				'code'    => 'missing_credentials',
				'message' => $credentials['error'],
			);
		}

		// POST /pts/v2/payments/{id}/voids — works for Charge (authorized + captured).
		$void_result = $this->call_cybersource_api(
			$order,
			$credentials,
			$transaction_reference,
			'voids',
			array(
				'clientReferenceInformation' => array(
					'code' => (string) $order->get_order_number(),
				),
			)
		);

		if ( ! empty( $void_result['success'] ) ) {
			return $void_result;
		}

		// If /voids returned 404, the transaction may be auth-only (not captured yet).
		// Retry with /reversals which is the correct endpoint for that case.
		if ( isset( $void_result['http_status'] ) && 404 === $void_result['http_status'] ) {
			$this->log( 'VOID 404 — retrying with /reversals for auth-only transaction', array( 'order_id' => $order->get_id() ) );

			return $this->call_cybersource_api(
				$order,
				$credentials,
				$transaction_reference,
				'reversals',
				array(
					'clientReferenceInformation' => array(
						'code' => (string) $order->get_order_number(),
					),
					'reversalInformation' => array(
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

	/**
	 * Sign and execute a Cybersource REST API POST request.
	 *
	 * @param WC_Order $order      WooCommerce order (for logging).
	 * @param array    $credentials Resolved credentials.
	 * @param string   $transaction_reference Cybersource payment ID.
	 * @param string   $action     API action segment: 'voids' or 'reversals'.
	 * @param array    $payload    Request payload (will be JSON-encoded).
	 * @return array
	 */
	private function call_cybersource_api( WC_Order $order, $credentials, $transaction_reference, $action, $payload ) {
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json ) {
			return array(
				'success' => false,
				'code'    => 'payload_encoding_failed',
				'message' => __( 'Failed to encode VOID payload.', 'epicpay-dlp-neonet-void' ),
			);
		}

		$host = 'production' === $credentials['environment'] ? 'api.cybersource.com' : 'apitest.cybersource.com';
		$path = '/pts/v2/payments/' . rawurlencode( $transaction_reference ) . '/' . $action;
		$url  = 'https://' . $host . $path;

		$date_header   = gmdate( 'D, d M Y H:i:s \G\M\T' );
		$digest_header = 'SHA-256=' . base64_encode( hash( 'sha256', $payload_json, true ) );

		$signature_headers = 'host date (request-target) digest v-c-merchant-id';
		$signature_payload =
			'host: ' . $host . "\n" .
			'date: ' . $date_header . "\n" .
			'(request-target): post ' . $path . "\n" .
			'digest: ' . $digest_header . "\n" .
			'v-c-merchant-id: ' . $credentials['merchant_id'];

		$decoded_secret = base64_decode( $credentials['shared_secret'], true );
		$hmac_key       = ( false !== $decoded_secret && strlen( $decoded_secret ) > 0 ) ? $decoded_secret : $credentials['shared_secret'];
		$signature      = base64_encode( hash_hmac( 'sha256', $signature_payload, $hmac_key, true ) );

		$signature_header = sprintf(
			'keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"',
			$credentials['key_id'],
			$signature_headers,
			$signature
		);

		$response = wp_remote_post(
			$url,
			array(
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
				'body'    => $payload_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Cybersource API transport error', array( 'action' => $action, 'error' => $response->get_error_message() ) );
			return array(
				'success' => false,
				'code'    => 'void_transport_error',
				'message' => $response->get_error_message(),
			);
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$response_body = (string) wp_remote_retrieve_body( $response );
		$body_json     = json_decode( $response_body, true );

		$this->log(
			'Cybersource API response',
			array(
				'action'                => $action,
				'status_code'           => $status_code,
				'order_id'              => $order->get_id(),
				'transaction_reference' => $transaction_reference,
				'body'                  => $body_json,
			)
		);

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
				/* translators: 1: HTTP status, 2: error message */
				__( 'Cybersource VOID/%1$s failed (%2$s): %3$s', 'epicpay-dlp-neonet-void' ),
				strtoupper( $action ),
				(string) $status_code,
				$error_message
			),
			'transaction_reference' => $transaction_reference,
		);
	}

	/**
	 * Optional fallback using process_refund.
	 *
	 * @param WC_Order           $order WooCommerce order.
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 * @param string             $transaction_reference Reference string.
	 * @return array
	 */
	private function attempt_refund_fallback( WC_Order $order, $gateway, $transaction_reference ) {
		if ( ! method_exists( $gateway, 'process_refund' ) ) {
			return array(
				'success' => false,
				'code'    => 'refund_method_missing',
				'message' => __( 'Gateway does not expose process_refund() for fallback.', 'epicpay-dlp-neonet-void' ),
			);
		}

		$reason = sprintf(
			/* translators: %s: Order number */
			__( 'Fallback reversal after VOID failure for order %s', 'epicpay-dlp-neonet-void' ),
			$order->get_order_number()
		);

		$result = $gateway->process_refund( $order->get_id(), $order->get_total(), $reason );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'code'    => 'fallback_gateway_error',
				'message' => $result->get_error_message(),
			);
		}

		if ( true !== $result ) {
			return array(
				'success' => false,
				'code'    => 'fallback_rejected',
				'message' => __( 'Fallback gateway reversal rejected.', 'epicpay-dlp-neonet-void' ),
			);
		}

		return array(
			'success'               => true,
			'code'                  => 'fallback_accepted',
			'message'               => __( 'Fallback gateway reversal accepted after direct VOID failure.', 'epicpay-dlp-neonet-void' ),
			'transaction_reference' => $transaction_reference,
		);
	}

	/**
	 * Resolve Cybersource credentials from gateway settings or manual plugin settings.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 * @return array
	 */
	private function resolve_cybersource_credentials( $gateway ) {
		if ( 'yes' === $this->settings->get( 'use_gateway_credentials' ) ) {
			$from_gateway = $this->extract_credentials_from_gateway( $gateway );
			if ( empty( $from_gateway['error'] ) ) {
				return $from_gateway;
			}
		}

		$manual = array(
			'merchant_id'  => trim( (string) $this->settings->get( 'cybersource_merchant_id' ) ),
			'key_id'       => trim( (string) $this->settings->get( 'cybersource_api_key_id' ) ),
			'shared_secret'=> trim( (string) $this->settings->get( 'cybersource_api_shared_secret' ) ),
			'environment'  => (string) $this->settings->get( 'cybersource_environment' ),
		);

		if ( empty( $manual['merchant_id'] ) || empty( $manual['key_id'] ) || empty( $manual['shared_secret'] ) ) {
			return array(
				'error' => __( 'Cybersource credentials are missing. Enable gateway credentials or set manual credentials in EpicPay VOID settings.', 'epicpay-dlp-neonet-void' ),
			);
		}

		return $manual;
	}

	/**
	 * Attempt to read credentials from active gateway settings.
	 *
	 * Reads both via the gateway object and directly from the wp_options table
	 * (woocommerce_{id}_settings) so credentials are found regardless of
	 * how the gateway was instantiated at runtime.
	 *
	 * @param WC_Payment_Gateway|null $gateway Gateway instance (may be null).
	 * @return array
	 */
	private function extract_credentials_from_gateway( $gateway ) {
		// Build a merged flat settings array from every available source.
		$flat = array();

		// Source 1: raw WP option — most reliable, works even if gateway object
		// is not fully initialised.
		$gateway_ids_to_try = array();
		if ( $gateway && ! empty( $gateway->id ) ) {
			$gateway_ids_to_try[] = $gateway->id;
		}
		// Also try the gateway IDs listed in our plugin settings as fallback.
		$configured_ids = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'gateway_ids' ) ) ) );
		foreach ( $configured_ids as $gid ) {
			if ( ! in_array( $gid, $gateway_ids_to_try, true ) ) {
				$gateway_ids_to_try[] = $gid;
			}
		}

		foreach ( $gateway_ids_to_try as $gid ) {
			$raw = get_option( 'woocommerce_' . $gid . '_settings', array() );
			if ( is_array( $raw ) && ! empty( $raw ) ) {
				$flat = array_merge( $raw, $flat ); // existing keys win
				break; // stop at first match
			}
		}

		// Source 2: gateway object settings array.
		if ( $gateway && isset( $gateway->settings ) && is_array( $gateway->settings ) ) {
			foreach ( $gateway->settings as $k => $v ) {
				if ( ! isset( $flat[ $k ] ) ) {
					$flat[ $k ] = $v;
				}
			}
		}

		// Source 3: gateway->get_option() for any keys not yet resolved.
		// We do this only for our specific candidates to avoid excessive calls.
		if ( $gateway && method_exists( $gateway, 'get_option' ) ) {
			$all_candidates = array(
				'merchant_id', 'merchantid', 'merchantId',
				'api_key', 'api_key_id', 'key_id', 'key',
				'api_shared_secret', 'api_shared_secret_key', 'shared_secret', 'sharedSecret',
				'secret_key',
				'environment', 'env', 'testmode', 'test_mode',
			);
			foreach ( $all_candidates as $c ) {
				if ( ! isset( $flat[ $c ] ) ) {
					$v = $gateway->get_option( $c );
					if ( '' !== $v && null !== $v ) {
						$flat[ $c ] = $v;
					}
				}
			}
		}

		// Log all discovered setting keys (values masked) for diagnostics.
		$masked = array();
		foreach ( $flat as $k => $v ) {
			$masked[ $k ] = ( strlen( (string) $v ) > 4 ) ? substr( $v, 0, 2 ) . '***' : ( empty( $v ) ? '(empty)' : '***' );
		}
		$this->log( 'Gateway settings keys discovered', array( 'keys' => $masked ) );

		// Resolve each credential from the merged flat array.
		$merchant_id = $this->pick_from_flat( $flat, array( 'merchant_id', 'merchantid', 'merchantId' ) );
		$key_id      = $this->pick_from_flat( $flat, array( 'api_key_id', 'key_id', 'api_key', 'key' ) );
		$secret      = $this->pick_from_flat( $flat, array( 'api_shared_secret_key', 'api_shared_secret', 'shared_secret', 'sharedSecret', 'secret_key' ) );
		$environment = $this->infer_environment_from_flat( $flat );
		if ( empty( $environment ) ) {
			$environment = (string) $this->settings->get( 'cybersource_environment' );
		}

		if ( empty( $merchant_id ) || empty( $key_id ) || empty( $secret ) ) {
			$this->log( 'Could not resolve credentials from gateway', array(
				'merchant_id_found' => ! empty( $merchant_id ),
				'key_id_found'      => ! empty( $key_id ),
				'secret_found'      => ! empty( $secret ),
				'all_keys'          => array_keys( $flat ),
			) );
			return array(
				'error' => __( 'Could not read Cybersource credentials from the gateway settings.', 'epicpay-dlp-neonet-void' ),
			);
		}

		return array(
			'merchant_id'   => $merchant_id,
			'key_id'        => $key_id,
			'shared_secret' => $secret,
			'environment'   => 'production' === $environment ? 'production' : 'test',
		);
	}

	/**
	 * Pick first non-empty value from a flat settings array using candidate keys.
	 *
	 * @param array $flat       Flat settings array.
	 * @param array $candidates Ordered list of keys to try.
	 * @return string
	 */
	private function pick_from_flat( $flat, $candidates ) {
		foreach ( $candidates as $key ) {
			if ( isset( $flat[ $key ] ) && '' !== trim( (string) $flat[ $key ] ) ) {
				return trim( (string) $flat[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Infer environment from a flat settings array.
	 *
	 * @param array $flat Flat settings array.
	 * @return string 'production'|'test'|''
	 */
	private function infer_environment_from_flat( $flat ) {
		$environment = strtolower( $this->pick_from_flat( $flat, array( 'environment', 'env' ) ) );
		$test_mode   = strtolower( $this->pick_from_flat( $flat, array( 'testmode', 'test_mode' ) ) );

		if ( in_array( $environment, array( 'production', 'prod', 'live' ), true ) ) {
			return 'production';
		}
		if ( in_array( $environment, array( 'test', 'sandbox' ), true ) ) {
			return 'test';
		}
		if ( in_array( $test_mode, array( 'yes', 'true', '1' ), true ) ) {
			return 'test';
		}
		if ( in_array( $test_mode, array( 'no', 'false', '0' ), true ) ) {
			return 'production';
		}
		return '';
	}

	/**
	 * Get first non-empty gateway option among candidate keys (legacy helper).
	 *
	 * @param WC_Payment_Gateway $gateway    Gateway instance.
	 * @param array              $candidates Option key candidates.
	 * @return string
	 */
	private function get_gateway_option( $gateway, $candidates ) {
		foreach ( $candidates as $candidate ) {
			$value = '';

			if ( method_exists( $gateway, 'get_option' ) ) {
				$value = $gateway->get_option( $candidate );
			}

			if ( empty( $value ) && isset( $gateway->settings ) && is_array( $gateway->settings ) && isset( $gateway->settings[ $candidate ] ) ) {
				$value = $gateway->settings[ $candidate ];
			}

			if ( ! empty( $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	/**
	 * Log message with plugin source.
	 *
	 * @param string $message Message text.
	 * @param array  $context Context payload.
	 * @return void
	 */
	private function log( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->info( $message, array( 'source' => 'epicpay-dlp-neonet-void', 'context' => $context ) );
	}
}
