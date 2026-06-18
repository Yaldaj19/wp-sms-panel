<?php
/**
 * FarazSMS / IPPanel adapter — api2.ippanel.com.
 *
 * Docs: https://docs.ippanel.com/
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Farazsms extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'farazsms';
	}

	public function get_label() {
		return __( 'فراز‌اس‌ام‌اس / IPPanel', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API از پنل فراز/IPPanel.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'شماره خط ارسال (originator).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'کد الگوی OTP (pattern_code)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'کد الگو برای ارسال کد. خالی = پیامک ساده.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern_var',
				'label'    => __( 'نام متغیر کد در الگو', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'نام متغیر کد در الگو (پیش‌فرض: code).', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key فراز/IPPanel تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post_json(
			'https://api2.ippanel.com/api/v1/sms/send/webservice/single',
			array(
				'sender'    => $this->cfg( $config, 'sender' ),
				'recipient' => array( $phone ),
				'message'   => $message,
			),
			array( 'apikey' => $api )
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$pattern = $this->cfg( $config, 'pattern' );
		if ( '' === $pattern ) {
			return $this->send( $phone, $message, $config );
		}

		$var  = $this->cfg( $config, 'pattern_var', 'code' );
		$resp = $this->http_post_json(
			'https://api2.ippanel.com/api/v1/sms/pattern/normal/send',
			array(
				'code'       => $pattern,
				'sender'     => $this->cfg( $config, 'sender' ),
				'recipient'  => $phone,
				'variable'   => array( $var => (string) $code ),
			),
			array( 'apikey' => $this->cfg( $config, 'api_key' ) )
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$data = $this->decode(
			$this->http_get(
				'https://api2.ippanel.com/api/v1/sms/accounting/credit/show',
				array( 'apikey' => $this->cfg( $config, 'api_key' ) )
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['data']['credit'] ) ) {
			return $data['data']['credit'];
		}
		return isset( $data['data'] ) && is_numeric( $data['data'] ) ? $data['data'] : 0;
	}

	/**
	 * IPPanel: status == "OK" or code == 0 on success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$status = isset( $data['status'] ) ? strtoupper( (string) $data['status'] ) : '';
		$code   = isset( $data['code'] ) ? (int) $data['code'] : -1;

		if ( 'OK' === $status || 0 === $code ) {
			return true;
		}

		$message = isset( $data['message'] ) ? ( is_array( $data['message'] ) ? wp_json_encode( $data['message'] ) : $data['message'] ) : __( 'خطای نامشخص فراز/IPPanel.', 'wp-sms-panel' );
		return new WP_Error( 'farazsms_error', $message );
	}
}
