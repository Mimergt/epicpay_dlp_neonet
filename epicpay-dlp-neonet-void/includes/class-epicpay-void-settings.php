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
		);
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
		);
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
}
