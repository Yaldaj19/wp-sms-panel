<?php
/**
 * Ghasedak adapter — ghasedak.me v2.
 *
 * Docs: https://ghasedak.me/docs
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Ghasedak extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'ghasedak';
	}

	public function get_label() {
		return __( 'قاصدک (Ghasedak)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API از پنل قاصدک.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط (اختیاری)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'شماره خط ارسال (linenumber). خالی = خط پیش‌فرض.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'نام قالب OTP (template)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'نام قالب verification برای ارسال کد. خالی = پیامک ساده.', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key قاصدک تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post(
			'https://gateway.ghasedak.me/rest/api/v2/WebService/SendSimple',
			array(
				'receptor'   => $phone,
				'message'    => $message,
				'linenumber' => $this->cfg( $config, 'sender' ),
			),
			array( 'apikey' => $api )
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$template = $this->cfg( $config, 'pattern' );
		if ( '' === $template ) {
			return $this->send( $phone, $message, $config );
		}

		$resp = $this->http_post(
			'https://gateway.ghasedak.me/rest/api/v2/Verification/VerificationCode',
			array(
				'receptor' => $phone,
				'type'     => 1,
				'template' => $template,
				'param1'   => $code,
			),
			array( 'apikey' => $this->cfg( $config, 'api_key' ) )
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$data = $this->decode(
			$this->http_get(
				'https://gateway.ghasedak.me/rest/api/v2/WebService/GetCredit',
				array( 'apikey' => $this->cfg( $config, 'api_key' ) )
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['result']['data'] ) ? $data['result']['data'] : 0;
	}

	/**
	 * Ghasedak: result.code == 200 on success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$code = isset( $data['result']['code'] ) ? (int) $data['result']['code'] : 0;
		if ( 200 === $code ) {
			return true;
		}

		$message = isset( $data['result']['message'] ) ? $data['result']['message'] : __( 'خطای نامشخص قاصدک.', 'wp-sms-panel' );
		return new WP_Error( 'ghasedak_error', $message );
	}
}
