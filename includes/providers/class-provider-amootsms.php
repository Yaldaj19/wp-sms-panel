<?php
/**
 * Amootsms adapter — portal.amootsms.com REST.
 *
 * NOTE: Verified at Medium-Low confidence. Endpoint names (SendSimple,
 * SendWithPattern, AccountInfo) are from public sample code; confirm the
 * response format against your Amootsms panel before relying on it.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Amootsms extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'amootsms';
	}

	public function get_label() {
		return __( 'آموت‌اس‌ام‌اس (AmootSMS)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'token',
				'label'    => __( 'توکن API', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'توکن API از پنل آموت‌اس‌ام‌اس.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'شماره خط ارسال (LineNumber).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'pattern',
				'label'    => __( 'کد الگوی OTP (PatternCode)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'کد الگو برای ارسال کد. خالی = پیامک ساده.', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$token = $this->cfg( $config, 'token' );
		if ( '' === $token ) {
			return new WP_Error( 'missing_token', __( 'توکن آموت‌اس‌ام‌اس تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post(
			'https://portal.amootsms.com/rest/SendSimple',
			array(
				'Token'      => $token,
				'SMSText'    => $message,
				'Mobiles'    => $phone,
				'LineNumber' => $this->cfg( $config, 'sender' ),
				'SendDateTime' => '',
			)
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$pattern = $this->cfg( $config, 'pattern' );
		if ( '' === $pattern ) {
			return $this->send( $phone, $message, $config );
		}

		$resp = $this->http_post(
			'https://portal.amootsms.com/rest/SendWithPattern',
			array(
				'Token'       => $this->cfg( $config, 'token' ),
				'PatternCodeID' => $pattern,
				'Mobile'      => $phone,
				'PatternValues' => (string) $code,
			)
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$url  = add_query_arg( 'Token', $this->cfg( $config, 'token' ), 'https://portal.amootsms.com/rest/AccountInfo' );
		$data = $this->decode( $this->http_get( $url ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['RemaindSms'] ) ) {
			return $data['RemaindSms'];
		}
		return isset( $data['Credit'] ) ? $data['Credit'] : 0;
	}

	/**
	 * Amootsms: Status == 1 (or "Success") means success. Response may be JSON or a status string.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// JSON shape.
		if ( isset( $data['Status'] ) ) {
			$status = is_numeric( $data['Status'] ) ? (int) $data['Status'] : strtolower( (string) $data['Status'] );
			if ( 1 === $status || 'success' === $status ) {
				return true;
			}
			$message = isset( $data['Message'] ) ? $data['Message'] : __( 'خطای نامشخص آموت‌اس‌ام‌اس.', 'wp-sms-panel' );
			return new WP_Error( 'amootsms_error', $message );
		}

		// Plain string shape (e.g. "Status=1").
		$raw = isset( $data['raw'] ) ? (string) $data['raw'] : '';
		if ( false !== stripos( $raw, 'status=1' ) || false !== stripos( $raw, 'success' ) ) {
			return true;
		}

		return new WP_Error( 'amootsms_error', '' !== $raw ? $raw : __( 'پاسخ نامشخص آموت‌اس‌ام‌اس.', 'wp-sms-panel' ) );
	}
}
