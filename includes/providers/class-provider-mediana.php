<?php
/**
 * Mediana adapter — mediana.ir.
 *
 * NOTE: Verified at LOW confidence. Mediana's public API docs are sparse.
 * The endpoint/auth below is a best-effort default — confirm the exact
 * endpoint, auth header and field names from your Mediana panel
 * (https://mediana.ir/) before relying on this in production.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Mediana extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'mediana';
	}

	public function get_label() {
		return __( 'مدیانا (Mediana)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key / Token', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API یا توکن از پنل مدیانا.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'شماره خط ارسال.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'endpoint',
				'label'    => __( 'آدرس Endpoint ارسال (در صورت تفاوت)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'اگر پنل شما آدرس متفاوتی دارد اینجا وارد کنید؛ خالی = آدرس پیش‌فرض.', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key مدیانا تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$endpoint = $this->cfg( $config, 'endpoint', 'https://api.mediana.ir/api/v1/sms/send/single' );

		$resp = $this->http_post_json(
			$endpoint,
			array(
				'sender'    => $this->cfg( $config, 'sender' ),
				'recipient' => $phone,
				'message'   => $message,
			),
			array( 'Authorization' => 'Bearer ' . $api )
		);

		return $this->parse( $resp );
	}

	/**
	 * Mediana: HTTP 2xx + a truthy status/code is treated as success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : '';
		$code   = isset( $data['code'] ) ? (int) $data['code'] : null;

		if ( in_array( $status, array( 'ok', 'success', '1' ), true ) || 200 === $code || 0 === $code || isset( $data['messageId'] ) || isset( $data['raw'] ) ) {
			return true;
		}

		$message = isset( $data['message'] ) ? $data['message'] : __( 'خطای نامشخص مدیانا.', 'wp-sms-panel' );
		return new WP_Error( 'mediana_error', $message );
	}
}
