<?php
/**
 * Parsgreen adapter — api.parsgreen.com Apiv2.
 *
 * NOTE: Verified at Medium confidence. Before relying on this in production,
 * confirm the exact endpoint paths and field names against your Parsgreen panel
 * docs at https://api.parsgreen.com/Apiv2/ — the success-flag key in particular.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Parsgreen extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'parsgreen';
	}

	public function get_label() {
		return __( 'پارس‌گرین (Parsgreen)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key (UserApiKey)', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API (Signature/UserApiKey) از پنل پارس‌گرین.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'شماره خط ارسال (FromNumber).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'کد الگوی OTP (PatternCode)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'کد الگو برای ارسال کد. خالی = پیامک ساده.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern_var',
				'label'    => __( 'نام متغیر کد در الگو', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'کلید متغیر کد در الگو (پیش‌فرض: code).', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key پارس‌گرین تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post_json(
			'https://api.parsgreen.com/Apiv2/Message/SendSms',
			array(
				'UserApiKey'   => $api,
				'FromNumber'   => $this->cfg( $config, 'sender' ),
				'ToNumbers'    => array( $phone ),
				'TextMessages' => array( $message ),
				'IsFlash'      => false,
			)
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
			'https://api.parsgreen.com/Apiv2/Message/SendPatternSms',
			array(
				'UserApiKey'  => $this->cfg( $config, 'api_key' ),
				'FromNumber'  => $this->cfg( $config, 'sender' ),
				'ToNumber'    => $phone,
				'PatternCode' => $pattern,
				'InputData'   => array(
					array(
						'Key'   => $var,
						'Value' => (string) $code,
					),
				),
			)
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$resp = $this->http_post_json(
			'https://api.parsgreen.com/Apiv2/Message/GetCredit',
			array( 'UserApiKey' => $this->cfg( $config, 'api_key' ) )
		);
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['Data'] ) ? $data['Data'] : 0;
	}

	/**
	 * Parsgreen: IsSuccessful == true (or numeric Status == 0) means success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ok = ( isset( $data['IsSuccessful'] ) && $data['IsSuccessful'] )
			|| ( isset( $data['Status'] ) && 0 === (int) $data['Status'] );

		if ( $ok ) {
			return true;
		}

		$message = isset( $data['Message'] ) ? $data['Message'] : __( 'خطای نامشخص پارس‌گرین.', 'wp-sms-panel' );
		return new WP_Error( 'parsgreen_error', $message );
	}
}
