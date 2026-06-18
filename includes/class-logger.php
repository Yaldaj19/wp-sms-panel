<?php
/**
 * SMS send log — records each attempt (status + error) in a custom table.
 *
 * Never stores OTP codes or full message bodies, only status/error notes.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Logger {

	const DB_VERSION    = '1';
	const DB_VERSION_OPT = 'wp_sms_panel_db_version';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpsp_sms_logs';
	}

	/**
	 * Create/upgrade the log table. Safe to call repeatedly (dbDelta).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			phone varchar(20) NOT NULL DEFAULT '',
			provider varchar(40) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT '',
			note text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	/**
	 * Create the table on demand if it's missing or out of date (for already-active installs).
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPT ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Record a send attempt.
	 *
	 * @param string        $phone    Mobile number.
	 * @param string        $provider Provider key.
	 * @param string        $type     'send' | 'otp' | 'test'.
	 * @param true|WP_Error $result   Send result.
	 */
	public static function record( $phone, $provider, $type, $result ) {
		global $wpdb;

		$is_error = is_wp_error( $result );
		$note     = $is_error ? $result->get_error_message() : __( 'ارسال موفق', 'wp-sms-panel' );

		// Truncate overly long notes.
		if ( function_exists( 'mb_substr' ) ) {
			$note = mb_substr( $note, 0, 500 );
		} else {
			$note = substr( $note, 0, 500 );
		}

		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql' ),
				'phone'      => $phone,
				'provider'   => $provider,
				'type'       => $type,
				'status'     => $is_error ? 'error' : 'success',
				'note'       => $note,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		self::prune();
	}

	/**
	 * Most recent log rows.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );

		// Table name can't be parameterised; it's built from $wpdb->prefix (safe).
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Total rows.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete all log rows.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Keep the table bounded (drop rows beyond the newest $keep).
	 *
	 * @param int $keep Rows to retain.
	 */
	public static function prune( $keep = 500 ) {
		global $wpdb;
		$table = self::table();
		$keep  = max( 50, (int) $keep );

		$threshold = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep )
		);
		if ( $threshold ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );
		}
	}
}
