<?php
/**
 * Database layer: table creation, reads and writes for sessions and results.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WXRKN_Database {

	const TABLE_SESSIONS = 'wxrkn_sessions';
	const TABLE_RESULTS  = 'wxrkn_results';

	// -------------------------------------------------------------------------
	// Schema management
	// -------------------------------------------------------------------------

	/**
	 * Create (or upgrade) the plugin's custom tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sessions = $wpdb->prefix . self::TABLE_SESSIONS;
		dbDelta(
			"CREATE TABLE {$sessions} (
  id VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  total INT NOT NULL DEFAULT 0,
  completed INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  PRIMARY KEY  (id)
) {$charset};"
		);

		$results = $wpdb->prefix . self::TABLE_RESULTS;
		dbDelta(
			"CREATE TABLE {$results} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  domain VARCHAR(255) NOT NULL,
  google_analytics TINYINT(1) NOT NULL DEFAULT 0,
  privacy_policy TINYINT(1) NOT NULL DEFAULT 0,
  yandex_metrika TINYINT(1) NOT NULL DEFAULT 0,
  sape TINYINT(1) NOT NULL DEFAULT 0,
  liveinternet TINYINT(1) NOT NULL DEFAULT 0,
  comments TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  error_msg TEXT,
  checked_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_session (session_id)
) {$charset};"
		);
	}

	/**
	 * Drop all plugin tables (used on uninstall).
	 */
	public static function drop_tables() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_RESULTS );  // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_SESSIONS ); // phpcs:ignore
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Sessions
	// -------------------------------------------------------------------------

	public static function create_session( $session_id, $total ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_SESSIONS,
			array(
				'id'         => $session_id,
				'created_at' => current_time( 'mysql' ),
				'total'      => (int) $total,
				'completed'  => 0,
				'status'     => 'running',
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);
	}

	public static function get_session( $session_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . self::TABLE_SESSIONS . ' WHERE id = %s',
				$session_id
			),
			ARRAY_A
		);
	}

	public static function increment_completed( $session_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . self::TABLE_SESSIONS . ' SET completed = completed + 1 WHERE id = %s',
				$session_id
			)
		);
	}

	public static function complete_session( $session_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . self::TABLE_SESSIONS,
			array( 'status' => 'completed' ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	public static function get_recent_sessions( $limit = 10 ) {
		global $wpdb;
		$limit = (int) $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . self::TABLE_SESSIONS . ' ORDER BY created_at DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);
	}

	// -------------------------------------------------------------------------
	// Results
	// -------------------------------------------------------------------------

	public static function save_result( $session_id, $domain, $data ) {
		global $wpdb;

		$has_error = ! empty( $data['error'] );

		$wpdb->insert(
			$wpdb->prefix . self::TABLE_RESULTS,
			array(
				'session_id'       => $session_id,
				'domain'           => $domain,
				'google_analytics' => $has_error ? 0 : (int) ( $data['google_analytics'] ?? 0 ),
				'privacy_policy'   => $has_error ? 0 : (int) ( $data['privacy_policy'] ?? 0 ),
				'yandex_metrika'   => $has_error ? 0 : (int) ( $data['yandex_metrika'] ?? 0 ),
				'sape'             => $has_error ? 0 : (int) ( $data['sape'] ?? 0 ),
				'liveinternet'     => $has_error ? 0 : (int) ( $data['liveinternet'] ?? 0 ),
				'comments'         => $has_error ? 0 : (int) ( $data['comments'] ?? 0 ),
				'status'           => $has_error ? 'error' : 'done',
				'error_msg'        => $has_error ? mb_substr( $data['error'], 0, 500 ) : null,
				'checked_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! empty( $session_id ) ) {
			self::increment_completed( $session_id );
		}
	}

	public static function get_results( $session_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . self::TABLE_RESULTS . ' WHERE session_id = %s ORDER BY id ASC',
				$session_id
			),
			ARRAY_A
		);
	}
}
