<?php
/**
 * Uninstall cleanup.
 *
 * @package WP_SMS_Panel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wp_sms_panel_settings' );
delete_option( 'wp_sms_panel_migrated' );
delete_option( 'wp_sms_panel_db_version' );

$table = $wpdb->prefix . 'wpsp_sms_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
