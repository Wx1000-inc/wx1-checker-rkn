<?php
/**
 * WordPress admin integration: menu page, assets, and page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WXRKN_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------

	public function add_menu() {
		add_menu_page(
			__( 'RKN Checker', 'wx1-checker-rkn' ),
			__( 'RKN Checker', 'wx1-checker-rkn' ),
			'manage_options',
			WXRKN_SLUG,
			array( $this, 'render_page' ),
			'dashicons-visibility',
			80
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, WXRKN_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wxrkn-admin',
			WXRKN_URL . 'assets/css/admin.css',
			array(),
			WXRKN_VERSION
		);

		wp_enqueue_script(
			'wxrkn-admin',
			WXRKN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WXRKN_VERSION,
			true
		);

		wp_localize_script(
			'wxrkn-admin',
			'wxrknData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wxrkn_nonce' ),
				'i18n'    => array(
					'yes'          => __( 'Да', 'wx1-checker-rkn' ),
					'no'           => __( 'Нет', 'wx1-checker-rkn' ),
					'na'           => __( 'н/д', 'wx1-checker-rkn' ),
					'error'        => __( 'Ошибка', 'wx1-checker-rkn' ),
					'pending'      => __( 'Ожидание', 'wx1-checker-rkn' ),
					'checking'     => __( 'Проверяется...', 'wx1-checker-rkn' ),
					'complete'     => __( 'Проверка завершена', 'wx1-checker-rkn' ),
					'nodomains'    => __( 'Введите хотя бы один домен.', 'wx1-checker-rkn' ),
					'confirmStop'  => __( 'Остановить проверку?', 'wx1-checker-rkn' ),
					'loadError'    => __( 'Не удалось загрузить результаты.', 'wx1-checker-rkn' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Доступ запрещён.', 'wx1-checker-rkn' ) );
		}

		$recent_sessions = WXRKN_Database::get_recent_sessions( 5 );
		?>
		<div class="wrap wxrkn-wrap">
			<h1><?php esc_html_e( 'WX1 RKN Checker', 'wx1-checker-rkn' ); ?></h1>

			<div class="wxrkn-layout">

				<!-- =================== LEFT: form =================== -->
				<div class="wxrkn-form-panel">
					<div class="wxrkn-card">
						<h2><?php esc_html_e( 'Список сайтов', 'wx1-checker-rkn' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Введите домены — по одному на строку (пример: example.com)', 'wx1-checker-rkn' ); ?>
						</p>
						<textarea
							id="wxrkn-domains"
							class="wxrkn-domains-textarea"
							placeholder="example.com&#10;site2.ru&#10;mysite.net"
							spellcheck="false"
						></textarea>

						<h3><?php esc_html_e( 'Настройки проверки', 'wx1-checker-rkn' ); ?></h3>
						<table class="form-table wxrkn-settings-table">
							<tr>
								<th>
									<label for="wxrkn-batch-size">
										<?php esc_html_e( 'Размер батча', 'wx1-checker-rkn' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="wxrkn-batch-size" value="1" min="1" max="10" class="small-text">
									<p class="description"><?php esc_html_e( 'Сколько сайтов проверять параллельно', 'wx1-checker-rkn' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="wxrkn-delay">
										<?php esc_html_e( 'Задержка между батчами (мс)', 'wx1-checker-rkn' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="wxrkn-delay" value="1000" min="0" max="30000" step="100" class="small-text">
									<p class="description"><?php esc_html_e( 'Пауза между группами запросов в миллисекундах', 'wx1-checker-rkn' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="wxrkn-max-pages">
										<?php esc_html_e( 'Страниц на сайт', 'wx1-checker-rkn' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="wxrkn-max-pages" value="3" min="1" max="10" class="small-text">
									<p class="description"><?php esc_html_e( 'Главная + до N-1 внутренних страниц', 'wx1-checker-rkn' ); ?></p>
								</td>
							</tr>
							<tr>
								<th>
									<label for="wxrkn-timeout">
										<?php esc_html_e( 'Таймаут запроса (сек)', 'wx1-checker-rkn' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="wxrkn-timeout" value="15" min="5" max="60" class="small-text">
								</td>
							</tr>
						</table>

						<div class="wxrkn-actions">
							<button id="wxrkn-start-btn" class="button button-primary button-large">
								&#9654; <?php esc_html_e( 'Запустить проверку', 'wx1-checker-rkn' ); ?>
							</button>
							<button id="wxrkn-stop-btn" class="button button-secondary" style="display:none;">
								&#9632; <?php esc_html_e( 'Остановить', 'wx1-checker-rkn' ); ?>
							</button>
							<button id="wxrkn-export-btn" class="button button-secondary" style="display:none;">
								&#8595; <?php esc_html_e( 'Экспорт CSV', 'wx1-checker-rkn' ); ?>
							</button>
						</div>
					</div><!-- .wxrkn-card -->
				</div><!-- .wxrkn-form-panel -->

				<!-- =================== RIGHT: progress + results =================== -->
				<div class="wxrkn-results-panel">

					<!-- Progress -->
					<div id="wxrkn-progress-wrap" class="wxrkn-card" style="display:none;">
						<h3><?php esc_html_e( 'Прогресс', 'wx1-checker-rkn' ); ?></h3>
						<div class="wxrkn-progress-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div id="wxrkn-progress-bar" class="wxrkn-progress-bar" style="width:0%"></div>
						</div>
						<p id="wxrkn-progress-text" class="wxrkn-progress-text">0 / 0</p>
						<p id="wxrkn-status-text" class="wxrkn-status-text"></p>
					</div>

					<!-- Results table -->
					<div id="wxrkn-results-wrap" class="wxrkn-card" style="display:none;">
						<h3><?php esc_html_e( 'Результаты', 'wx1-checker-rkn' ); ?></h3>
						<div class="wxrkn-table-scroll">
							<table id="wxrkn-results-table" class="wp-list-table widefat fixed striped wxrkn-results-table">
								<thead>
									<tr>
										<th class="col-domain"><?php esc_html_e( 'Домен', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'Google Analytics', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'Политика конфид.', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'Яндекс.Метрика', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'Sape', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'LiveInternet', 'wx1-checker-rkn' ); ?></th>
										<th class="col-check"><?php esc_html_e( 'Комментарии', 'wx1-checker-rkn' ); ?></th>
										<th class="col-status"><?php esc_html_e( 'Статус', 'wx1-checker-rkn' ); ?></th>
									</tr>
								</thead>
								<tbody id="wxrkn-results-tbody">
								</tbody>
							</table>
						</div>
					</div>

				</div><!-- .wxrkn-results-panel -->
			</div><!-- .wxrkn-layout -->

			<?php if ( ! empty( $recent_sessions ) ) : ?>
			<div class="wxrkn-card wxrkn-history">
				<h3><?php esc_html_e( 'Последние сессии', 'wx1-checker-rkn' ); ?></h3>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Сессия', 'wx1-checker-rkn' ); ?></th>
							<th><?php esc_html_e( 'Дата', 'wx1-checker-rkn' ); ?></th>
							<th><?php esc_html_e( 'Прогресс', 'wx1-checker-rkn' ); ?></th>
							<th><?php esc_html_e( 'Статус', 'wx1-checker-rkn' ); ?></th>
							<th><?php esc_html_e( 'Действия', 'wx1-checker-rkn' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_sessions as $session ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( $session['id'], 0, 12 ) ); ?>&hellip;</code></td>
							<td><?php echo esc_html( $session['created_at'] ); ?></td>
							<td><?php echo esc_html( $session['completed'] . ' / ' . $session['total'] ); ?></td>
							<td><?php echo esc_html( $session['status'] ); ?></td>
							<td>
								<button
									class="wxrkn-load-session button button-small"
									data-session="<?php echo esc_attr( $session['id'] ); ?>"
								>
									<?php esc_html_e( 'Загрузить', 'wx1-checker-rkn' ); ?>
								</button>
								<a
									href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wxrkn_export_csv&session_id=' . rawurlencode( $session['id'] ) . '&nonce=' . wp_create_nonce( 'wxrkn_nonce' ) ) ); ?>"
									class="button button-small"
								>
									<?php esc_html_e( 'CSV', 'wx1-checker-rkn' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

		</div><!-- .wrap -->
		<?php
	}
}
