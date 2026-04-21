<?php
/**
 * Plugin Name: EpicPay - DLP Neonet Void on Cancel
 * Plugin URI: https://github.com/Mimergt/epicpay_dlp_neonet
 * Description: Standalone WooCommerce plugin that attempts a payment void when an order is cancelled.
 * Version: 0.1.0
 * Author: EpicPay
 * Author URI: https://github.com/Mimergt
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: epicpay-dlp-neonet-void
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPICPAY_DLP_NEONET_VOID_VERSION', '0.1.0' );
define( 'EPICPAY_DLP_NEONET_VOID_FILE', __FILE__ );
define( 'EPICPAY_DLP_NEONET_VOID_PATH', plugin_dir_path( __FILE__ ) );
define( 'EPICPAY_DLP_NEONET_VOID_URL', plugin_dir_url( __FILE__ ) );

require_once EPICPAY_DLP_NEONET_VOID_PATH . 'includes/class-epicpay-void-settings.php';
require_once EPICPAY_DLP_NEONET_VOID_PATH . 'includes/class-epicpay-void-gateway-adapter.php';
require_once EPICPAY_DLP_NEONET_VOID_PATH . 'includes/class-epicpay-void-listener.php';
require_once EPICPAY_DLP_NEONET_VOID_PATH . 'includes/class-epicpay-void-plugin.php';

add_action(
	'plugins_loaded',
	static function() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		EpicPay_DLP_Neonet_Void_Plugin::instance()->init();
	}
);
