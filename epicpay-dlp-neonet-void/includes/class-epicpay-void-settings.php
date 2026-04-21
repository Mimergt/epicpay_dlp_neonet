<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpicPay_DLP_Neonet_Void_Settings {
	const OPTION_KEY = 'epicpay_dlp_neonet_void_settings';

	/**
	 * Register admin settings hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings page in WordPress admin.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'EpicPay VOID', 'epicpay-dlp-neonet-void' ),
			__( 'EpicPay VOID', 'epicpay-dlp-neonet-void' ),
			'manage_woocommerce',
			'epicpay-dlp-neonet-void',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register options and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'epicpay_dlp_neonet_void_group',
			self::OPTION_KEY,
			array( $this, 'sanitize' )
		);

		add_settings_section(
			'epicpay_dlp_neonet_void_main',
			__( 'Automatic VOID on cancel', 'epicpay-dlp-neonet-void' ),
			'__return_false',
			'epicpay-dlp-neonet-void'
		);

		add_settings_field(
			'enabled',
			__( 'Enable auto VOID', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_checkbox_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'enabled',
				'label'       => __( 'Attempt VOID when an order is cancelled.', 'epicpay-dlp-neonet-void' ),
				'description' => __( 'Disable to stop all automatic VOID attempts.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'gateway_ids',
			__( 'Allowed payment method IDs', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_textarea_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'gateway_ids',
				'description' => __( 'Comma-separated gateway IDs that should trigger auto VOID. Example: cybersource,cybersource_credit_card', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'transaction_meta_keys',
			__( 'Transaction meta keys', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_textarea_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'transaction_meta_keys',
				'description' => __( 'Comma-separated order meta keys to search for transaction references.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'request_id_meta_keys',
			__( 'Request ID meta keys', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_textarea_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'request_id_meta_keys',
				'description' => __( 'Comma-separated order meta keys used to look up Cybersource request IDs.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'use_gateway_credentials',
			__( 'Use CyberSource gateway credentials', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_checkbox_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'use_gateway_credentials',
				'label'       => __( 'Read credentials from the active CyberSource WooCommerce gateway settings.', 'epicpay-dlp-neonet-void' ),
				'description' => __( 'Disable this only if you want to set manual credentials below.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'cybersource_environment',
			__( 'Cybersource environment', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_select_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'cybersource_environment',
				'options'     => array(
					'test'       => __( 'Test', 'epicpay-dlp-neonet-void' ),
					'production' => __( 'Production', 'epicpay-dlp-neonet-void' ),
				),
				'description' => __( 'Used for direct REST VOID requests when manual credentials are used or gateway environment is unavailable.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'cybersource_merchant_id',
			__( 'Cybersource merchant ID (manual)', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_text_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'cybersource_merchant_id',
				'description' => __( 'Manual override. Usually not needed when using gateway credentials.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'cybersource_api_key_id',
			__( 'Cybersource API key ID (manual)', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_text_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'cybersource_api_key_id',
				'description' => __( 'Manual override. Usually not needed when using gateway credentials.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'cybersource_api_shared_secret',
			__( 'Cybersource shared secret (manual)', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_password_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'cybersource_api_shared_secret',
				'description' => __( 'Manual override. Keep this secret private.', 'epicpay-dlp-neonet-void' ),
			)
		);

		add_settings_field(
			'allow_refund_fallback',
			__( 'Allow refund fallback', 'epicpay-dlp-neonet-void' ),
			array( $this, 'render_checkbox_field' ),
			'epicpay-dlp-neonet-void',
			'epicpay_dlp_neonet_void_main',
			array(
				'key'         => 'allow_refund_fallback',
				'label'       => __( 'If direct VOID fails, allow fallback to gateway refund flow.', 'epicpay-dlp-neonet-void' ),
				'description' => __( 'Recommended OFF when VOID is mandatory.', 'epicpay-dlp-neonet-void' ),
			)
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EpicPay DLP Neonet VOID settings', 'epicpay-dlp-neonet-void' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'epicpay_dlp_neonet_void_group' ); ?>
				<?php do_settings_sections( 'epicpay-dlp-neonet-void' ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize input settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'enabled'               => empty( $input['enabled'] ) ? 'no' : 'yes',
			'gateway_ids'           => $this->sanitize_csv( $input, 'gateway_ids' ),
			'transaction_meta_keys' => $this->sanitize_csv( $input, 'transaction_meta_keys' ),
			'request_id_meta_keys'  => $this->sanitize_csv( $input, 'request_id_meta_keys' ),
			'use_gateway_credentials' => empty( $input['use_gateway_credentials'] ) ? 'no' : 'yes',
			'cybersource_environment' => $this->sanitize_environment( $input ),
			'cybersource_merchant_id' => sanitize_text_field( isset( $input['cybersource_merchant_id'] ) ? (string) $input['cybersource_merchant_id'] : '' ),
			'cybersource_api_key_id'  => sanitize_text_field( isset( $input['cybersource_api_key_id'] ) ? (string) $input['cybersource_api_key_id'] : '' ),
			'cybersource_api_shared_secret' => sanitize_text_field( isset( $input['cybersource_api_shared_secret'] ) ? (string) $input['cybersource_api_shared_secret'] : '' ),
			'allow_refund_fallback' => empty( $input['allow_refund_fallback'] ) ? 'no' : 'yes',
		);
	}

	/**
	 * Sanitize configured Cybersource environment.
	 *
	 * @param array $input Settings input.
	 * @return string
	 */
	private function sanitize_environment( $input ) {
		$value = isset( $input['cybersource_environment'] ) ? sanitize_text_field( (string) $input['cybersource_environment'] ) : 'test';

		if ( 'production' === $value ) {
			return 'production';
		}

		return 'test';
	}

	/**
	 * Sanitize comma separated values.
	 *
	 * @param array  $input Settings input.
	 * @param string $key   Key name.
	 * @return string
	 */
	private function sanitize_csv( $input, $key ) {
		$raw = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

		$items = array_filter(
			array_map(
				'sanitize_text_field',
				array_map( 'trim', explode( ',', $raw ) )
			)
		);

		return implode( ',', array_unique( $items ) );
	}

	/**
	 * Get full settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( $stored, $this->get_defaults() );
	}

	/**
	 * Get a single setting key.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public function get( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Parse configured CSV setting into an array.
	 *
	 * @param string $key Setting key.
	 * @return array
	 */
	public function get_csv( $key ) {
		$value = (string) $this->get( $key );

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $value ) )
			)
		);
	}

	/**
	 * Check whether auto void is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->get( 'enabled' );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	private function get_defaults() {
		return array(
			'enabled'               => 'yes',
			'gateway_ids'           => 'cybersource,cybersource_credit_card,wc_cybersource_credit_card',
			'transaction_meta_keys' => '_transaction_id,_wc_cybersource_transaction_id,_wc_cybersource_trans_id,transaction_id',
			'request_id_meta_keys'  => '_wc_cybersource_request_id,request_id',
			'use_gateway_credentials' => 'yes',
			'cybersource_environment' => 'test',
			'cybersource_merchant_id' => '',
			'cybersource_api_key_id' => '',
			'cybersource_api_shared_secret' => '',
			'allow_refund_fallback' => 'no',
		);
	}

	/**
	 * Check whether refund fallback is enabled.
	 *
	 * @return bool
	 */
	public function allow_refund_fallback() {
		return 'yes' === $this->get( 'allow_refund_fallback' );
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_checkbox_field( $args ) {
		$key         = $args['key'];
		$label       = isset( $args['label'] ) ? $args['label'] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$value       = $this->get( $key );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="yes" <?php checked( 'yes', $value ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php if ( ! empty( $description ) ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render textarea field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_textarea_field( $args ) {
		$key         = $args['key'];
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$value       = (string) $this->get( $key );
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" rows="3" cols="70" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! empty( $description ) ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_text_field( $args ) {
		$key         = $args['key'];
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$value       = (string) $this->get( $key );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<?php if ( ! empty( $description ) ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render password field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_password_field( $args ) {
		$key         = $args['key'];
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$value       = (string) $this->get( $key );
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" />
		<?php if ( ! empty( $description ) ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render select field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_select_field( $args ) {
		$key         = $args['key'];
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$value       = (string) $this->get( $key );
		?>
		<select name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $description ) ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}
}
