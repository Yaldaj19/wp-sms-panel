<?php
/**
 * Dispatcher — resolves the active provider and routes send / send_otp.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_SMS {

	/**
	 * Read the full settings array (merged with defaults).
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( WP_SMS_PANEL_OPTION, array() );
		return is_array( $saved ) ? wp_parse_args( $saved, self::defaults() ) : self::defaults();
	}

	/**
	 * Default settings shape.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'active_provider' => 'dev',
			'otp_length'      => 5,
			'otp_ttl'         => 120,
			'otp_message'     => __( 'کد ورود شما: {code}', 'wp-sms-panel' ),
			'login_page'      => 1,
			'login_title'     => __( 'ورود / ثبت‌نام با موبایل', 'wp-sms-panel' ),
			'style'           => array(
				'accent'      => '#2563eb',
				'button_text' => '#ffffff',
				'card_bg'     => '#ffffff',
				'field_bg'    => '#f7f8fa',
				'border'      => '#e5e7eb',
				'radius'      => 10,
			),
			'providers'       => array(),
		);
	}

	/**
	 * Config sub-array for one provider.
	 *
	 * @param string $key Provider key.
	 * @return array
	 */
	public static function provider_config( $key ) {
		$settings = self::settings();
		return isset( $settings['providers'][ $key ] ) && is_array( $settings['providers'][ $key ] )
			? $settings['providers'][ $key ]
			: array();
	}

	/**
	 * Resolve the active provider adapter.
	 *
	 * @return WP_SMS_Panel_Provider|WP_Error
	 */
	protected static function active() {
		$settings = self::settings();
		$provider = WP_SMS_Panel_Provider_Registry::get( $settings['active_provider'] );

		if ( ! $provider ) {
			return new WP_Error( 'no_provider', __( 'درگاه پیامک انتخاب نشده یا نامعتبر است.', 'wp-sms-panel' ) );
		}

		return $provider;
	}

	/**
	 * Send a plain SMS through the active provider.
	 *
	 * @param string $phone   Mobile number.
	 * @param string $message Message body.
	 * @return true|WP_Error
	 */
	public static function send( $phone, $message ) {
		$phone = self::normalize_phone( $phone );
		if ( is_wp_error( $phone ) ) {
			return $phone;
		}

		$provider = self::active();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$result = $provider->send( $phone, $message, self::provider_config( $provider->get_key() ) );

		/**
		 * Fires after an SMS send attempt.
		 *
		 * @param string         $phone    Mobile number.
		 * @param string         $message  Message body.
		 * @param true|WP_Error  $result   Send result.
		 * @param string         $provider Provider key.
		 */
		do_action( 'wp_sms_panel_after_send', $phone, $message, $result, $provider->get_key() );

		return $result;
	}

	/**
	 * Send an OTP code through the active provider (pattern-aware).
	 *
	 * @param string $phone   Mobile number.
	 * @param string $code    OTP code.
	 * @param string $message Full message body (plain fallback).
	 * @return true|WP_Error
	 */
	public static function send_otp( $phone, $code, $message ) {
		$phone = self::normalize_phone( $phone );
		if ( is_wp_error( $phone ) ) {
			return $phone;
		}

		$provider = self::active();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		return $provider->send_otp( $phone, $code, $message, self::provider_config( $provider->get_key() ) );
	}

	/**
	 * Credit of the active provider.
	 *
	 * @return int|float|WP_Error
	 */
	public static function credit() {
		$provider = self::active();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		return $provider->get_credit( self::provider_config( $provider->get_key() ) );
	}

	/**
	 * Validate and normalise an Iranian mobile number to 09xxxxxxxxx.
	 *
	 * @param string $raw Raw input.
	 * @return string|WP_Error
	 */
	public static function normalize_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );

		// +98 / 0098 / 98 prefixes -> local 0.
		if ( preg_match( '/^(?:0098|98)(9\d{9})$/', $digits, $m ) ) {
			$digits = '0' . $m[1];
		} elseif ( preg_match( '/^(9\d{9})$/', $digits, $m ) ) {
			$digits = '0' . $m[1];
		}

		if ( ! preg_match( '/^09\d{9}$/', $digits ) ) {
			return new WP_Error( 'invalid_phone', __( 'شماره موبایل معتبر نیست.', 'wp-sms-panel' ) );
		}

		return $digits;
	}
}
