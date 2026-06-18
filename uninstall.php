<?php
/**
 * Uninstall cleanup.
 *
 * @package WP_SMS_Panel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_sms_panel_settings' );
delete_option( 'wp_sms_panel_migrated' );
