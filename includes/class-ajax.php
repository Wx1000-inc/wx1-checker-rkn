<?php
/**
 * AJAX endpoints:
 *   wxrkn_init_session   – create a new check session
 *   wxrkn_check_domain   – check a single domain and persist the result
 *   wxrkn_get_results    – retrieve all results for a session
 *   wxrkn_export_csv     – stream a CSV file download
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WXRKN_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_wxrkn_init_session', array( $this, 'init_session' ) );
		add_action( 'wp_ajax_wxrkn_check_domain', array( $this, 'check_domain' ) );
		add_action( 'wp_ajax_wxrkn_get_results',  array( $this, 'get_results' ) );
		add_action( 'wp_ajax_wxrkn_export_csv',   array( $this, 'export_csv' ) );
	}

	// -------------------------------------------------------------------------
	// Guards
	// -------------------------------------------------------------------------

	private function verify_post_request() {
		if ( ! check_ajax_referer( 'wxrkn_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}
	}

	// -------------------------------------------------------------------------
	// Endpoints
	// -------------------------------------------------------------------------

	/**
	 * Create a new session record so the client can track overall progress.
	 */
	public function init_session() {
		$this->verify_post_request();

		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$total      = absint( $_POST['total'] ?? 0 );

		if ( empty( $session_id ) || $total < 1 ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
		}

		WXRKN_Database::create_session( $session_id, $total );

		wp_send_json_success( array( 'session_id' => $session_id ) );
	}

	/**
	 * Fetch and inspect a single domain, persist the result, return it as JSON.
	 */
	public function check_domain() {
		// Bump PHP time limit to accommodate slow sites.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$this->verify_post_request();

		$domain     = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$max_pages  = absint( $_POST['max_pages'] ?? 3 );
		$timeout    = absint( $_POST['timeout']   ?? 15 );

		if ( empty( $domain ) ) {
			wp_send_json_error( array( 'message' => 'Empty domain.' ) );
		}

		$checker = new WXRKN_Checker(
			array(
				'max_pages' => $max_pages,
				'timeout'   => $timeout,
			)
		);

		$result = $checker->check_domain( $domain );

		if ( ! empty( $session_id ) ) {
			WXRKN_Database::save_result( $session_id, $domain, $result );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Return all saved results for a given session (for "load previous session").
	 */
	public function get_results() {
		$this->verify_post_request();

		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid session.' ) );
		}

		$results = WXRKN_Database::get_results( $session_id );
		$session = WXRKN_Database::get_session( $session_id );

		wp_send_json_success(
			array(
				'results' => $results,
				'session' => $session,
			)
		);
	}

	/**
	 * Stream a CSV download of all results for a session.
	 * Uses a GET request with a nonce in the query string.
	 */
	public function export_csv() {
		// Nonce comes via GET for direct-download links.
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wxrkn_nonce' ) ) {
			wp_die( esc_html__( 'Проверка безопасности не пройдена.', 'wx1-checker-rkn' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Доступ запрещён.', 'wx1-checker-rkn' ), 403 );
		}

		$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );
		if ( empty( $session_id ) ) {
			wp_die( esc_html__( 'Неверный идентификатор сессии.', 'wx1-checker-rkn' ) );
		}

		$results  = WXRKN_Database::get_results( $session_id );
		$filename = 'wxrkn-results-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens the file correctly.
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore

		fputcsv(
			$fh,
			array(
				'Домен',
				'Google Analytics',
				'Политика конфиденциальности',
				'Яндекс.Метрика',
				'Sape',
				'LiveInternet',
				'Комментарии',
				'Статус',
				'Дата проверки',
			)
		);

		$bool = static function ( $val ) {
			return $val ? 'Да' : 'Нет';
		};

		foreach ( $results as $row ) {
			fputcsv(
				$fh,
				array(
					$row['domain'],
					$bool( $row['google_analytics'] ),
					$bool( $row['privacy_policy'] ),
					$bool( $row['yandex_metrika'] ),
					$bool( $row['sape'] ),
					$bool( $row['liveinternet'] ),
					$bool( $row['comments'] ),
					$row['status'],
					$row['checked_at'],
				)
			);
		}

		fclose( $fh ); // phpcs:ignore
		exit;
	}
}
