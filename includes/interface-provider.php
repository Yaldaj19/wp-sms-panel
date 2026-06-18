<?php
/**
 * Contract every SMS gateway adapter must fulfil.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

interface WP_SMS_Panel_Provider_Interface {

	/**
	 * Unique machine key, e.g. "kavenegar".
	 *
	 * @return string
	 */
	public function get_key();

	/**
	 * Human label shown in the settings dropdown.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Credential / config fields this provider needs.
	 *
	 * Each item: [ 'key' => string, 'label' => string, 'type' => 'text|password|number',
	 *              'required' => bool, 'help' => string ].
	 *
	 * @return array
	 */
	public function get_fields();

	/**
	 * Send a plain text message.
	 *
	 * @param string $phone   Normalised mobile number.
	 * @param string $message Message body.
	 * @param array  $config  Saved config for this provider.
	 * @return true|WP_Error
	 */
	public function send( $phone, $message, array $config );

	/**
	 * Send an OTP. Adapters that support pattern/verify endpoints override this;
	 * otherwise the abstract base falls back to send().
	 *
	 * @param string $phone  Normalised mobile number.
	 * @param string $code   The numeric OTP code.
	 * @param string $message Full message body (used for plain fallback).
	 * @param array  $config Saved config for this provider.
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $message, array $config );

	/**
	 * Remaining credit/balance, or WP_Error if unsupported/failed.
	 *
	 * @param array $config Saved config for this provider.
	 * @return int|float|WP_Error
	 */
	public function get_credit( array $config );
}
