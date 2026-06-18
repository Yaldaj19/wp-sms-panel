<?php
/**
 * Dev/test adapter — logs instead of sending. Useful on localhost.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Dev extends WP_SMS_Panel_Provider {

	public function get_key() {
		return 'dev';
	}

	public function get_label() {
		return __( 'حالت توسعه (لاگ — بدون ارسال واقعی)', 'wp-sms-panel' );
	}

	public function get_fields() {
		return array();
	}

	public function send( $phone, $message, array $config ) {
		error_log( sprintf( '[WP SMS Panel][DEV] to=%s msg=%s', $phone, $message ) );
		set_transient( 'wp_sms_panel_dev_last', array( 'phone' => $phone, 'message' => $message ), 5 * MINUTE_IN_SECONDS );
		return true;
	}
}
