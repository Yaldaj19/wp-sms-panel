<?php
/**
 * Plugin Name:       WP SMS Panel
 * Plugin URI:        https://wordpress.org/plugins/wp-sms-panel
 * Description:       پنل پیامک عمومی برای وردپرس — اتصال به درگاه‌های پیامک ایرانی (کاوه‌نگار، SMS.ir، ملی‌پیامک، قاصدک، فراز‌اس‌ام‌اس/IPPanel، پارس‌گرین، آموت‌اس‌ام‌اس، مدیانا)، ورود/ثبت‌نام با کد یک‌بارمصرف (OTP)، شورت‌کد فرم و API ساده برای ارسال پیامک.
 * Version:           2.1.0
 * Author:            Yalda Jahanshahi
 * Text Domain:       wp-sms-panel
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_SMS_PANEL_VERSION', '2.1.0' );
define( 'WP_SMS_PANEL_FILE', __FILE__ );
define( 'WP_SMS_PANEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_SMS_PANEL_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_SMS_PANEL_OPTION', 'wp_sms_panel_settings' );

/**
 * Load all plugin classes.
 */
require_once WP_SMS_PANEL_DIR . 'includes/interface-provider.php';
require_once WP_SMS_PANEL_DIR . 'includes/abstract-provider.php';
require_once WP_SMS_PANEL_DIR . 'includes/class-provider-registry.php';
require_once WP_SMS_PANEL_DIR . 'includes/class-logger.php';
require_once WP_SMS_PANEL_DIR . 'includes/class-sms.php';
require_once WP_SMS_PANEL_DIR . 'includes/class-settings.php';
require_once WP_SMS_PANEL_DIR . 'includes/class-otp.php';

/**
 * Boot the plugin.
 */
function wp_sms_panel_init() {
	load_plugin_textdomain( 'wp-sms-panel', false, dirname( plugin_basename( WP_SMS_PANEL_FILE ) ) . '/languages' );

	WP_SMS_Panel_Provider_Registry::boot();
	WP_SMS_Panel_Logger::maybe_install();
	WP_SMS_Panel_Settings::instance();
	WP_SMS_Panel_OTP::instance();
}
add_action( 'plugins_loaded', 'wp_sms_panel_init' );

/**
 * Activation: seed default settings and migrate from the legacy rangnet-sms plugin.
 */
function wp_sms_panel_activate() {
	require_once WP_SMS_PANEL_DIR . 'includes/class-settings.php';
	require_once WP_SMS_PANEL_DIR . 'includes/class-logger.php';
	WP_SMS_Panel_Settings::install_defaults();
	WP_SMS_Panel_Logger::install();
}
register_activation_hook( __FILE__, 'wp_sms_panel_activate' );

/* -------------------------------------------------------------------------
 * Public API
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'wp_sms_panel_send' ) ) {
	/**
	 * Send an SMS through the active gateway.
	 *
	 * @param string $phone   Iranian mobile number (09xxxxxxxxx or +98).
	 * @param string $message Message body.
	 * @return true|WP_Error
	 */
	function wp_sms_panel_send( $phone, $message ) {
		return WP_SMS_Panel_SMS::send( $phone, $message );
	}
}

if ( ! function_exists( 'yj19_send_sms' ) ) {
	/**
	 * Backward-compatible alias for projects that used the legacy rangnet-sms plugin.
	 *
	 * @param string $phone   Mobile number.
	 * @param string $message Message body.
	 * @return true|WP_Error
	 */
	function yj19_send_sms( $phone, $message ) {
		return WP_SMS_Panel_SMS::send( $phone, $message );
	}
}
