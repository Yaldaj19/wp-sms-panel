<?php
/**
 * Discovers and stores the available gateway adapters.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Provider_Registry {

	/**
	 * key => WP_SMS_Panel_Provider instance.
	 *
	 * @var WP_SMS_Panel_Provider[]
	 */
	protected static $providers = array();

	/**
	 * Load every adapter from includes/providers/ and register it.
	 */
	public static function boot() {
		if ( ! empty( self::$providers ) ) {
			return;
		}

		$dir   = WP_SMS_PANEL_DIR . 'includes/providers/';
		$files = array(
			'class-provider-dev.php',
			'class-provider-kavenegar.php',
			'class-provider-smsir.php',
			'class-provider-melipayamak.php',
			'class-provider-ghasedak.php',
			'class-provider-farazsms.php',
			'class-provider-parsgreen.php',
			'class-provider-amootsms.php',
			'class-provider-mediana.php',
		);

		foreach ( $files as $file ) {
			require_once $dir . $file;
		}

		$classes = array(
			'WP_SMS_Panel_Provider_Dev',
			'WP_SMS_Panel_Provider_Kavenegar',
			'WP_SMS_Panel_Provider_Smsir',
			'WP_SMS_Panel_Provider_Melipayamak',
			'WP_SMS_Panel_Provider_Ghasedak',
			'WP_SMS_Panel_Provider_Farazsms',
			'WP_SMS_Panel_Provider_Parsgreen',
			'WP_SMS_Panel_Provider_Amootsms',
			'WP_SMS_Panel_Provider_Mediana',
		);

		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				/** @var WP_SMS_Panel_Provider $instance */
				$instance = new $class();
				self::$providers[ $instance->get_key() ] = $instance;
			}
		}

		/**
		 * Allow third parties to register custom adapters.
		 *
		 * @param WP_SMS_Panel_Provider_Registry $registry The registry class (static).
		 */
		do_action( 'wp_sms_panel_register_providers', __CLASS__ );
	}

	/**
	 * Register a provider instance programmatically.
	 *
	 * @param WP_SMS_Panel_Provider $provider Adapter instance.
	 */
	public static function add( WP_SMS_Panel_Provider $provider ) {
		self::$providers[ $provider->get_key() ] = $provider;
	}

	/**
	 * All registered adapters.
	 *
	 * @return WP_SMS_Panel_Provider[]
	 */
	public static function all() {
		self::boot();
		return self::$providers;
	}

	/**
	 * Get one adapter by key.
	 *
	 * @param string $key Provider key.
	 * @return WP_SMS_Panel_Provider|null
	 */
	public static function get( $key ) {
		self::boot();
		return isset( self::$providers[ $key ] ) ? self::$providers[ $key ] : null;
	}

	/**
	 * key => label map for the settings dropdown.
	 *
	 * @return array
	 */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $key => $provider ) {
			$out[ $key ] = $provider->get_label();
		}
		return $out;
	}
}
