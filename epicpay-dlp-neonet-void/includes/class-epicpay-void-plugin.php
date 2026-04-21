<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpicPay_DLP_Neonet_Void_Plugin {
	/**
	 * @var EpicPay_DLP_Neonet_Void_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var EpicPay_DLP_Neonet_Void_Settings
	 */
	private $settings;

	/**
	 * @var EpicPay_DLP_Neonet_Void_Gateway_Adapter
	 */
	private $gateway_adapter;

	/**
	 * @var EpicPay_DLP_Neonet_Void_Listener
	 */
	private $listener;

	/**
	 * Singleton instance.
	 *
	 * @return EpicPay_DLP_Neonet_Void_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings        = new EpicPay_DLP_Neonet_Void_Settings();
		$this->gateway_adapter = new EpicPay_DLP_Neonet_Void_Gateway_Adapter( $this->settings );
		$this->listener        = new EpicPay_DLP_Neonet_Void_Listener( $this->settings, $this->gateway_adapter );

		$this->settings->register();
		$this->listener->register();
	}
}
