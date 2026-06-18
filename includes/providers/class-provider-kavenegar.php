<?php
/**
 * Kavenegar adapter — kavenegar.com.
 *
 * Docs: https://kavenegar.com/rest.html
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Kavenegar extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'kavenegar';
	}

	public function get_label() {
		return __( 'کاوه‌نگار (Kavenegar)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API از بخش «انتخاب وب‌سرویس» پنل کاوه‌نگار.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط (اختیاری)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'خالی بگذارید تا از خط پیش‌فرض حساب استفاده شود.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'نام قالب OTP (template)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'نام قالب verify lookup برای ارسال کد. در صورت خالی بودن، کد به‌صورت پیامک ساده ارسال می‌شود.', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key کاوه‌نگار تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$url  = sprintf( 'https://api.kavenegar.com/v1/%s/sms/send.json', rawurlencode( $api ) );
		$resp = $this->http_post(
			$url,
			array(
				'receptor' => $phone,
				'message'  => $message,
				'sender'   => $this->cfg( $config, 'sender' ),
			)
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$template = $this->cfg( $config, 'pattern' );
		if ( '' === $template ) {
			return $this->send( $phone, $message, $config );
		}

		$api  = $this->cfg( $config, 'api_key' );
		$url  = sprintf( 'https://api.kavenegar.com/v1/%s/verify/lookup.json', rawurlencode( $api ) );
		$resp = $this->http_post(
			$url,
			array(
				'receptor' => $phone,
				'token'    => $code,
				'template' => $template,
			)
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$api  = $this->cfg( $config, 'api_key' );
		$url  = sprintf( 'https://api.kavenegar.com/v1/%s/account/info.json', rawurlencode( $api ) );
		$data = $this->decode( $this->http_get( $url ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['entries']['remaincredit'] ) ? $data['entries']['remaincredit'] : 0;
	}

	/**
	 * Kavenegar wraps status in `return.status` (200 == ok).
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$status  = isset( $data['return']['status'] ) ? (int) $data['return']['status'] : 0;
		if ( 200 === $status ) {
			return true;
		}

		$message = isset( $data['return']['message'] ) ? $data['return']['message'] : __( 'خطای نامشخص کاوه‌نگار.', 'wp-sms-panel' );
		return new WP_Error( 'kavenegar_error', $message );
	}
}
