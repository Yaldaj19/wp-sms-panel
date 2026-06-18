<?php
/**
 * SMS.ir adapter — new api.sms.ir v1.
 *
 * Docs: https://app.sms.ir/developer/help
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Smsir extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'smsir';
	}

	public function get_label() {
		return __( 'پیامک‌سامانه (SMS.ir)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API Key', 'wp-sms-panel' ),
				'type'     => 'password',
				'required' => true,
				'help'     => __( 'کلید API از بخش توسعه‌دهندگان پنل SMS.ir.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'sender',
				'label'    => __( 'شماره خط', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'شماره خط ارسال انبوه (lineNumber).', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'template_id',
				'label'    => __( 'شناسه قالب OTP (templateId)', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'شناسه عددی قالب verify برای ارسال کد. خالی = ارسال پیامک ساده.', 'wp-sms-panel' ),
			),
			array(
				'key'      => 'template_param',
				'label'    => __( 'نام پارامتر کد در قالب', 'wp-sms-panel' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'نام متغیر کد در قالب (پیش‌فرض: CODE).', 'wp-sms-panel' ),
			),
		);
	}

	public function send( $phone, $message, array $config ) {
		$api = $this->cfg( $config, 'api_key' );
		if ( '' === $api ) {
			return new WP_Error( 'missing_api_key', __( 'API Key سرویس SMS.ir تنظیم نشده است.', 'wp-sms-panel' ) );
		}

		$resp = $this->http_post_json(
			'https://api.sms.ir/v1/send/bulk',
			array(
				'lineNumber'  => $this->cfg( $config, 'sender' ),
				'messageText' => $message,
				'mobiles'     => array( $phone ),
			),
			array( 'x-api-key' => $api )
		);

		return $this->parse( $resp );
	}

	public function send_otp( $phone, $code, $message, array $config ) {
		$template = $this->cfg( $config, 'template_id' );
		if ( '' === $template ) {
			return $this->send( $phone, $message, $config );
		}

		$param = $this->cfg( $config, 'template_param', 'CODE' );
		$resp  = $this->http_post_json(
			'https://api.sms.ir/v1/send/verify',
			array(
				'mobile'     => $phone,
				'templateId' => (int) $template,
				'parameters' => array(
					array(
						'name'  => $param,
						'value' => (string) $code,
					),
				),
			),
			array( 'x-api-key' => $this->cfg( $config, 'api_key' ) )
		);

		return $this->parse( $resp );
	}

	public function get_credit( array $config ) {
		$data = $this->decode( $this->http_get( 'https://api.sms.ir/v1/credit', array( 'x-api-key' => $this->cfg( $config, 'api_key' ) ) ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['data'] ) ? $data['data'] : 0;
	}

	/**
	 * SMS.ir uses `status == 1` for success.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return true|WP_Error
	 */
	protected function parse( $resp ) {
		$data = $this->decode( $resp );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['status'] ) && 1 === (int) $data['status'] ) {
			return true;
		}

		$message = isset( $data['message'] ) ? $data['message'] : __( 'خطای نامشخص SMS.ir.', 'wp-sms-panel' );
		return new WP_Error( 'smsir_error', $message );
	}
}
