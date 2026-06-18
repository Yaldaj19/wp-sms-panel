<?php
/**
 * Melipayamak adapter — payamak-panel.com REST.
 *
 * Docs: https://www.melipayamak.com/api/
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Melipayamak extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'melipayamak';
	}

	public function get_label() {
		return __( 'ملی‌پیامک (Melipayamak)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'username',
				'label'    => __( 'نام کاربری', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'نام کاربری پنل ملی‌پیامک.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'password',
				'label'    => __( 'رمز عبور', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'رمز عبور پنل (یا رمز وب‌سرویس).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'شماره ارسال‌کننده (from).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'شناسه قالب OTP (bodyId)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'شناسه عددی قالب خدماتی برای ارسال کد. خالی = پیامک ساده.', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$user = $this->cfg( $config, 'username' );
		$pass = $this->cfg( $config, 'password' );
		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'missing_credentials', __( 'نام کاربری/رمز ملی‌پیامک تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post(
			'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
			array(
				'username' => $user,
				'password' => $pass,
				'to'       => $phone,
				'from'     => $this->cfg( $config, 'sender' ),
				'text'     => $message,
				'isflash'  => 'false',
			)
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$body_id = $this->cfg( $config, 'pattern' );
		if ( '' === $body_id ) {
			return $this->send( $phone, $message, $config );
		}

		$resp = $this->http_post(
			'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
			array(
				'username' => $this->cfg( $config, 'username' ),
				'password' => $this->cfg( $config, 'password' ),
				'text'     => $code,
				'to'       => $phone,
				'bodyId'   => (int) $body_id,
			)
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$resp = $this->http_post(
			'https://rest.payamak-panel.com/api/SendSMS/GetCredit',
			array(
				'username' => $this->cfg( $config, 'username' ),
				'password' => $this->cfg( $config, 'password' ),
			)
		);
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['Value'] ) ? $data['Value'] : 0;
	}

	/**
	 * Melipayamak: a positive numeric RetStatus / a long numeric StrRetStatus means success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ret = isset( $data['RetStatus'] ) ? (int) $data['RetStatus'] : 0;
		// SendSMS returns the message recId (a long number) on success; RetStatus == 1 on the service endpoints.
		$rec = isset( $data['Value'] ) ? (string) $data['Value'] : '';

		if ( 1 === $ret || ( '' !== $rec && ctype_digit( $rec ) && strlen( $rec ) > 5 ) ) {
			return true;
		}

		$message = isset( $data['StrRetStatus'] ) ? $data['StrRetStatus'] : __( 'خطای نامشخص ملی‌پیامک.', 'wp-sms-panel' );
		return new WP_Error( 'melipayamak_error', $message );
	}
}
