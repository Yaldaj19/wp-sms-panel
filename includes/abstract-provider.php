<?php
/**
 * Shared base for all gateway adapters: HTTP helpers + sane defaults.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

abstract class WP_SMS_Panel_Provider implements WP_SMS_Panel_Provider_Interface {

	/**
	 * Default OTP behaviour: send the full pre-built message as a plain SMS.
	 * Adapters with a dedicated verify/pattern endpoint override this.
	 *
	 * @param string $phone   Mobile number.
	 * @param string $code    OTP code (unused in the plain fallback).
	 * @param string $message Full message body.
	 * @param array  $config  Provider config.
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $message, array $config ) {
		return $this->send( $phone, $message, $config );
	}

	/**
	 * Credit lookup is optional; default to "unsupported".
	 *
	 * @param array $config Provider config.
	 * @return int|float|WP_Error
	 */
	public function get_credit( array $config ) {
		return new WP_Error( 'unsupported', __( 'استعلام اعتبار برای این درگاه پشتیبانی نمی‌شود.', 'wp-sms-panel' ) );
	}

	/**
	 * Read a single config value with a default.
	 *
	 * @param array  $config  Provider config.
	 * @param string $key     Field key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function cfg( array $config, $key, $default = '' ) {
		return isset( $config[ $key ] ) && '' !== $config[ $key ] ? $config[ $key ] : $default;
	}

	/**
	 * POST a form-urlencoded body.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $body    Body fields.
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error WP HTTP response array or WP_Error.
	 */
	protected function http_post( $url, array $body, array $headers = array() ) {
		return wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $data    Data to JSON-encode.
	 * @param array  $headers Extra headers (Content-Type json is added).
	 * @return array|WP_Error
	 */
	protected function http_post_json( $url, array $data, array $headers = array() ) {
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			$headers
		);

		return wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( $data ),
			)
		);
	}

	/**
	 * GET request.
	 *
	 * @param string $url     Endpoint (query already built in).
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error
	 */
	protected function http_get( $url, array $headers = array() ) {
		return wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Turn a WP HTTP response into a decoded body array or WP_Error on transport/HTTP failure.
	 *
	 * @param array|WP_Error $resp Raw response.
	 * @return array|WP_Error Decoded JSON (assoc) or raw string under 'raw'; WP_Error on failure.
	 */
	protected function decode( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'http_error',
				/* translators: 1: HTTP status code 2: response body */
				sprintf( __( 'خطای درگاه (HTTP %1$d): %2$s', 'wp-sms-panel' ), $code, wp_strip_all_tags( (string) $raw ) )
			);
		}

		$json = json_decode( $raw, true );

		return null === $json ? array( 'raw' => $raw ) : $json;
	}
}
