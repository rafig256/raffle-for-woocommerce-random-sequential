<?php
/**
 * Raffle analytics dashboard.
 *
 * Displays ticket sales charts, revenue metrics, and top purchasers.
 * Uses Chart.js for data visualization.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce submenu page showing raffle performance data.
 *
 * @since 1.0.0
 */
class RFWC_Admin_Analytics {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_analytics_page' ), 52 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_rfwc_get_analytics_data', array( $this, 'ajax_get_analytics_data' ) );
		add_action( 'wp_ajax_rfwc_export_analytics', array( $this, 'ajax_export_analytics' ) );
	}

	/**
	 * Add analytics admin page.
	 *
	 * @since 1.0.0
	 */
	public function add_analytics_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Raffle Analytics', 'raffle-for-woocommerce' ),
			__( 'Raffle Analytics', 'raffle-for-woocommerce' ),
			'manage_woocommerce',
			'rfwc-analytics',
			array( $this, 'render_analytics_page' ),
			5
		);
	}

	/**
	 * Enqueue scripts for analytics page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_rfwc-analytics' !== $hook ) {
			return;
		}

		// Chart.js for visualizations (bundled locally).
		wp_enqueue_script(
			'chartjs',
			RFWC_PLUGIN_URL . 'assets/js/lib/chart.min.js',
			array(),
			'4.5.1',
			true
		);

		wp_enqueue_style(
			'rfwc-analytics',
			RFWC_PLUGIN_URL . 'assets/css/admin-analytics.css',
			array(),
			RFWC_VERSION
		);

		// Chart.js is a soft dependency — data loads even if charts fail.
		// This prevents security plugins or caching from breaking the entire page.
		wp_enqueue_script(
			'rfwc-analytics',
			RFWC_PLUGIN_URL . 'assets/js/admin-analytics.js',
			array( 'jquery' ),
			RFWC_VERSION,
			true
		);

		wp_localize_script(
			'rfwc-analytics',
			'rfwc_analytics',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'rfwc_analytics_nonce' ),
				'i18n'     => array(
					'tickets_sold'     => __( 'Tickets Sold', 'raffle-for-woocommerce' ),
					'gift_purchases'   => __( 'Gift Purchases', 'raffle-for-woocommerce' ),
					'loading'          => __( 'Loading...', 'raffle-for-woocommerce' ),
					'no_data'          => __( 'No data available for this period.', 'raffle-for-woocommerce' ),
					'status_active'    => __( 'Active', 'raffle-for-woocommerce' ),
					'status_winner'    => __( 'Winners', 'raffle-for-woocommerce' ),
					'status_cancelled' => __( 'Cancelled', 'raffle-for-woocommerce' ),
					'status_refunded'  => __( 'Refunded', 'raffle-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Render the analytics page.
	 *
	 * @since 1.0.0
	 */
	public function render_analytics_page() {
		$raffles = $this->get_all_raffles();
		?>
		<div class="wrap rfwc-analytics-page">
			<h1><?php esc_html_e( 'Raffle Analytics', 'raffle-for-woocommerce' ); ?></h1>

			<div class="rfwc-analytics-filters">
				<label for="rfwc-raffle-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by raffle', 'raffle-for-woocommerce' ); ?></label>
				<select id="rfwc-raffle-filter">
					<option value=""><?php esc_html_e( 'All Raffles', 'raffle-for-woocommerce' ); ?></option>
					<?php foreach ( $raffles as $raffle ) : ?>
						<option value="<?php echo esc_attr( $raffle->get_id() ); ?>">
							<?php echo esc_html( $raffle->get_name() ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="rfwc-date-range-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by date range', 'raffle-for-woocommerce' ); ?></label>
				<select id="rfwc-date-range-filter">
					<option value="7"><?php esc_html_e( 'Last 7 Days', 'raffle-for-woocommerce' ); ?></option>
					<option value="30" selected><?php esc_html_e( 'Last 30 Days', 'raffle-for-woocommerce' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 Days', 'raffle-for-woocommerce' ); ?></option>
					<option value="365"><?php esc_html_e( 'Last Year', 'raffle-for-woocommerce' ); ?></option>
					<option value="all"><?php esc_html_e( 'All Time', 'raffle-for-woocommerce' ); ?></option>
				</select>

				<button type="button" id="rfwc-refresh-analytics" class="button">
					<?php esc_html_e( 'Refresh', 'raffle-for-woocommerce' ); ?>
				</button>

				<button type="button" id="rfwc-export-csv" class="button">
					<?php esc_html_e( 'Export CSV', 'raffle-for-woocommerce' ); ?>
				</button>
			</div>

			<div class="rfwc-analytics-overview">
				<div class="rfwc-stat-card">
					<span class="rfwc-stat-icon dashicons dashicons-tickets-alt"></span>
					<div class="rfwc-stat-content">
						<span class="rfwc-stat-value" id="stat-total-tickets">-</span>
						<span class="rfwc-stat-label"><?php esc_html_e( 'Total Tickets', 'raffle-for-woocommerce' ); ?></span>
					</div>
				</div>

				<div class="rfwc-stat-card">
					<span class="rfwc-stat-icon dashicons dashicons-money-alt"></span>
					<div class="rfwc-stat-content">
						<span class="rfwc-stat-value" id="stat-total-revenue">-</span>
						<span class="rfwc-stat-label"><?php esc_html_e( 'Total Revenue', 'raffle-for-woocommerce' ); ?></span>
					</div>
				</div>

				<div class="rfwc-stat-card">
					<span class="rfwc-stat-icon dashicons dashicons-heart"></span>
					<div class="rfwc-stat-content">
						<span class="rfwc-stat-value" id="stat-gift-tickets">-</span>
						<span class="rfwc-stat-label"><?php esc_html_e( 'Gift Purchases', 'raffle-for-woocommerce' ); ?></span>
					</div>
				</div>

				<div class="rfwc-stat-card">
					<span class="rfwc-stat-icon dashicons dashicons-groups"></span>
					<div class="rfwc-stat-content">
						<span class="rfwc-stat-value" id="stat-unique-customers">-</span>
						<span class="rfwc-stat-label"><?php esc_html_e( 'Unique Customers', 'raffle-for-woocommerce' ); ?></span>
					</div>
				</div>
			</div>

			<div class="rfwc-analytics-charts">
				<div class="rfwc-chart-container">
					<h3><?php esc_html_e( 'Tickets Sold Over Time', 'raffle-for-woocommerce' ); ?></h3>
					<canvas id="tickets-chart"></canvas>
				</div>

				<div class="rfwc-chart-container rfwc-chart-small">
					<h3><?php esc_html_e( 'Ticket Status Distribution', 'raffle-for-woocommerce' ); ?></h3>
					<canvas id="status-chart"></canvas>
				</div>
			</div>

			<div class="rfwc-analytics-tables">
				<div class="rfwc-table-container">
					<h3><?php esc_html_e( 'Top Raffles', 'raffle-for-woocommerce' ); ?></h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Tickets', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Revenue', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody id="top-raffles-table">
							<tr><td colspan="4"><?php esc_html_e( 'Loading...', 'raffle-for-woocommerce' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="rfwc-table-container">
					<h3><?php esc_html_e( 'Top Purchasers', 'raffle-for-woocommerce' ); ?></h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Tickets', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Spent', 'raffle-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Gifts', 'raffle-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody id="top-purchasers-table">
							<tr><td colspan="4"><?php esc_html_e( 'Loading...', 'raffle-for-woocommerce' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="rfwc-analytics-activity-log">
				<div class="rfwc-table-container" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Activity Log', 'raffle-for-woocommerce' ); ?></h3>
					<?php $this->render_activity_log(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the activity log table.
	 *
	 * Displays the most recent entries from the rfwc_log table, showing
	 * draw results, ticket generation, and other audit events.
	 *
	 * @since 1.1.0
	 */
	private function render_activity_log() {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$logs = $wpdb->get_results(
			"SELECT l.*, p.post_title AS raffle_name FROM {$table} l LEFT JOIN {$wpdb->posts} p ON l.raffle_id = p.ID ORDER BY l.created_at DESC LIMIT 50"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $logs ) ) {
			?>
			<p class="rfwc-activity-log-empty"><?php esc_html_e( 'No activity recorded yet. Events like ticket generation, winner draws, and errors will appear here.', 'raffle-for-woocommerce' ); ?></p>
			<?php
			return;
		}

		$action_labels = array(
			'tickets_generated'         => __( 'Tickets Generated', 'raffle-for-woocommerce' ),
			'tickets_cancelled'         => __( 'Tickets Cancelled', 'raffle-for-woocommerce' ),
			'winner_selected'           => __( 'Winner Selected', 'raffle-for-woocommerce' ),
			'winners_cleared'           => __( 'Winners Cleared', 'raffle-for-woocommerce' ),
			'prize_claimed'             => __( 'Prize Claimed', 'raffle-for-woocommerce' ),
			'notification_resent'       => __( 'Notification Resent', 'raffle-for-woocommerce' ),
			'auto_draw_success'         => __( 'Auto Draw Success', 'raffle-for-woocommerce' ),
			'auto_draw_error'           => __( 'Auto Draw Failed', 'raffle-for-woocommerce' ),
			'raffle_failed'             => __( 'Raffle Failed', 'raffle-for-woocommerce' ),
			'raffle_cancelled'          => __( 'Raffle Cancelled', 'raffle-for-woocommerce' ),
			'mass_refund'               => __( 'Mass Refund', 'raffle-for-woocommerce' ),
			'selective_refund'          => __( 'Selective Refund', 'raffle-for-woocommerce' ),
			'ticket_validated'          => __( 'Ticket Validated', 'raffle-for-woocommerce' ),
			'ticket_checkin'            => __( 'Ticket Checked In', 'raffle-for-woocommerce' ),
			'ticket_validation_failed'  => __( 'Validation Failed', 'raffle-for-woocommerce' ),
			'free_entry_recorded'       => __( 'Free Entry Recorded', 'raffle-for-woocommerce' ),
			// Pro events.
			'manual_sale_recorded'      => __( 'Manual Sale Recorded', 'raffle-for-woocommerce' ),
			'volunteer_added'           => __( 'Volunteer Added', 'raffle-for-woocommerce' ),
			'volunteer_updated'         => __( 'Volunteer Updated', 'raffle-for-woocommerce' ),
			'volunteer_deleted'         => __( 'Volunteer Deleted', 'raffle-for-woocommerce' ),
			'volunteer_sale_attributed' => __( 'Volunteer Sale Attributed', 'raffle-for-woocommerce' ),
			'master_draw_created'       => __( 'Master Draw Created', 'raffle-for-woocommerce' ),
			'master_draw_updated'       => __( 'Master Draw Updated', 'raffle-for-woocommerce' ),
			'master_draw_completed'     => __( 'Master Draw Completed', 'raffle-for-woocommerce' ),
			'master_draw_deleted'       => __( 'Master Draw Deleted', 'raffle-for-woocommerce' ),
			'master_draw_failed'        => __( 'Master Draw Failed', 'raffle-for-woocommerce' ),
		);

		$action_icons = array(
			'tickets_generated'         => 'dashicons-tickets-alt',
			'tickets_cancelled'         => 'dashicons-no',
			'winner_selected'           => 'dashicons-awards',
			'winners_cleared'           => 'dashicons-dismiss',
			'prize_claimed'             => 'dashicons-yes',
			'notification_resent'       => 'dashicons-email-alt',
			'auto_draw_success'         => 'dashicons-yes-alt',
			'auto_draw_error'           => 'dashicons-warning',
			'raffle_failed'             => 'dashicons-warning',
			'raffle_cancelled'          => 'dashicons-dismiss',
			'mass_refund'               => 'dashicons-money-alt',
			'selective_refund'          => 'dashicons-money-alt',
			'ticket_validated'          => 'dashicons-yes',
			'ticket_checkin'            => 'dashicons-location',
			'ticket_validation_failed'  => 'dashicons-shield',
			'free_entry_recorded'       => 'dashicons-email-alt',
			// Pro events.
			'manual_sale_recorded'      => 'dashicons-cart',
			'volunteer_added'           => 'dashicons-groups',
			'volunteer_updated'         => 'dashicons-edit',
			'volunteer_deleted'         => 'dashicons-no-alt',
			'volunteer_sale_attributed' => 'dashicons-megaphone',
			'master_draw_created'       => 'dashicons-calendar-alt',
			'master_draw_updated'       => 'dashicons-edit',
			'master_draw_completed'     => 'dashicons-awards',
			'master_draw_deleted'       => 'dashicons-trash',
			'master_draw_failed'        => 'dashicons-warning',
		);
		?>
		<table class="wp-list-table widefat fixed striped rfwc-activity-log-table">
			<thead>
				<tr>
					<th class="column-action" style="width: 180px;"><?php esc_html_e( 'Event', 'raffle-for-woocommerce' ); ?></th>
					<th class="column-raffle" style="width: 160px;"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
					<th class="column-details"><?php esc_html_e( 'Details', 'raffle-for-woocommerce' ); ?></th>
					<th class="column-user" style="width: 120px;"><?php esc_html_e( 'User', 'raffle-for-woocommerce' ); ?></th>
					<th class="column-date" style="width: 160px;"><?php esc_html_e( 'Date', 'raffle-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$action_label = isset( $action_labels[ $log->action ] ) ? $action_labels[ $log->action ] : ucwords( str_replace( '_', ' ', $log->action ) );
					$action_icon  = isset( $action_icons[ $log->action ] ) ? $action_icons[ $log->action ] : 'dashicons-info';
					$is_error     = in_array( $log->action, array( 'auto_draw_error', 'ticket_validation_failed', 'raffle_failed', 'raffle_cancelled', 'master_draw_failed' ), true );

					// System-level events (no specific raffle) get a friendly
					// "System" label instead of an empty cell or #0.
					if ( 0 === (int) $log->raffle_id ) {
						$raffle_name = __( 'System', 'raffle-for-woocommerce' );
					} else {
						$raffle_name = $log->raffle_name ? $log->raffle_name : sprintf( '#%d', $log->raffle_id );
					}
					$details_text = $this->format_log_details( $log->action, $log->details );
					$user_display = '';

					// Resolve user: check table column first, fall back to JSON details for legacy records.
					$resolved_user_id = (int) $log->user_id;
					if ( 0 === $resolved_user_id && ! empty( $log->details ) ) {
						$details_data = json_decode( $log->details, true );
						if ( is_array( $details_data ) && ! empty( $details_data['user_id'] ) ) {
							$resolved_user_id = (int) $details_data['user_id'];
						}
					}

					if ( $resolved_user_id > 0 ) {
						$user         = get_userdata( $resolved_user_id );
						$user_display = $user ? $user->display_name : sprintf( '#%d', $resolved_user_id );
					} else {
						$user_display = __( 'System', 'raffle-for-woocommerce' );
					}
					?>
					<tr<?php echo $is_error ? ' class="rfwc-log-error"' : ''; ?>>
						<td class="column-action">
							<span class="dashicons <?php echo esc_attr( $action_icon ); ?>" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px; vertical-align: text-top;<?php echo $is_error ? ' color: #d63638;' : ''; ?>"></span>
							<strong<?php echo $is_error ? ' style="color: #d63638;"' : ''; ?>><?php echo esc_html( $action_label ); ?></strong>
						</td>
						<td class="column-raffle">
							<?php if ( $log->raffle_name && (int) $log->raffle_id > 0 ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $log->raffle_id ) ); ?>"><?php echo esc_html( $raffle_name ); ?></a>
							<?php else : ?>
								<em><?php echo esc_html( $raffle_name ); ?></em>
							<?php endif; ?>
						</td>
						<td class="column-details">
							<?php echo esc_html( $details_text ); ?>
						</td>
						<td class="column-user">
							<?php echo esc_html( $user_display ); ?>
						</td>
						<td class="column-date">
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $log->created_at )
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format log details into human-readable text.
	 *
	 * Details may be plain text or JSON-encoded structured data.
	 * This method detects JSON and formats it into a readable sentence.
	 *
	 * @since 1.1.0
	 *
	 * @param string $action  The log action type.
	 * @param string $details The raw details string.
	 * @return string Formatted, human-readable details.
	 */
	private function format_log_details( $action, $details ) {
		if ( empty( $details ) ) {
			return '-';
		}

		// Try to decode as JSON.
		$data = json_decode( $details, true );

		// If not valid JSON, strip any leftover HTML (e.g. wc_price() tags from
		// older log entries) and return plain text.
		if ( ! is_array( $data ) ) {
			return wp_strip_all_tags( $details );
		}

		// Format based on action type.
		switch ( $action ) {
			case 'ticket_validated':
				$ticket       = isset( $data['ticket_number'] ) ? $data['ticket_number'] : '';
				$source       = isset( $data['source'] ) ? $data['source'] : '';
				$source_label = 'admin_scan' === $source
					? __( 'admin scanner', 'raffle-for-woocommerce' )
					: __( 'public QR scan', 'raffle-for-woocommerce' );

				return $ticket
					/* translators: 1: ticket number, 2: validation source */
					? sprintf( __( 'Ticket %1$s validated via %2$s', 'raffle-for-woocommerce' ), $ticket, $source_label )
					: $details;

			case 'ticket_checkin':
				$ticket = isset( $data['ticket_number'] ) ? $data['ticket_number'] : '';

				return $ticket
					/* translators: %s: ticket number */
					? sprintf( __( 'Ticket %s checked in', 'raffle-for-woocommerce' ), $ticket )
					: $details;

			case 'ticket_validation_failed':
				$ticket       = isset( $data['ticket_number'] ) ? $data['ticket_number'] : '';
				$source       = isset( $data['source'] ) ? $data['source'] : '';
				$ip           = isset( $data['ip_address'] ) ? $data['ip_address'] : '';
				$source_label = 'admin_scan' === $source
					? __( 'admin scanner', 'raffle-for-woocommerce' )
					: __( 'public QR scan', 'raffle-for-woocommerce' );

				$text = $ticket
					/* translators: 1: ticket number, 2: validation source */
					? sprintf( __( 'Ticket %1$s not found via %2$s', 'raffle-for-woocommerce' ), $ticket, $source_label )
					: $details;

				if ( $ip ) {
					/* translators: 1: formatted message, 2: IP address */
					$text = sprintf( __( '%1$s (IP: %2$s)', 'raffle-for-woocommerce' ), $text, $ip );
				}

				return $text;

			default:
				// For any other JSON data, produce a simple key: value summary.
				$parts = array();
				foreach ( $data as $key => $value ) {
					if ( is_array( $value ) || is_object( $value ) ) {
						continue;
					}
					$label   = ucwords( str_replace( '_', ' ', $key ) );
					$parts[] = sprintf( '%s: %s', $label, $value );
				}

				return ! empty( $parts ) ? implode( ', ', $parts ) : $details;
		}
	}

	/**
	 * Get all raffle products.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of RFWC_Product_Raffle objects.
	 */
	private function get_all_raffles() {
		$args = array(
			'type'   => 'raffle',
			'status' => 'publish',
			'limit'  => -1,
		);

		return wc_get_products( $args );
	}

	/**
	 * AJAX handler for getting analytics data.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_analytics_data() {
		check_ajax_referer( 'rfwc_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id  = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$date_range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : '30';

		// Calculate date range.
		$end_date   = current_time( 'Y-m-d' );
		$start_date = 'all' === $date_range ? '2000-01-01' : gmdate( 'Y-m-d', strtotime( "-{$date_range} days" ) );

		// Get statistics.
		$stats = $this->get_statistics( $raffle_id, $start_date, $end_date );

		// Get chart data.
		$chart_data = $this->get_chart_data( $raffle_id, $start_date, $end_date );

		// Get top raffles.
		$top_raffles = $this->get_top_raffles( $start_date, $end_date );

		// Get top purchasers.
		$top_purchasers = $this->get_top_purchasers( $raffle_id, $start_date, $end_date );

		// Get ticket status distribution.
		$status_distribution = $this->get_status_distribution( $raffle_id, $start_date, $end_date );

		wp_send_json_success(
			array(
				'stats'               => $stats,
				'chart_data'          => $chart_data,
				'top_raffles'         => $top_raffles,
				'top_purchasers'      => $top_purchasers,
				'status_distribution' => $status_distribution,
			)
		);
	}

	/**
	 * Get general statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id  Optional raffle ID filter.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function get_statistics( $raffle_id, $start_date, $end_date ) {
		global $wpdb;
		$table_name       = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$start_date_clean = sanitize_text_field( $start_date );
		$end_date_clean   = sanitize_text_field( $end_date );
		$raffle_id_clean  = absint( $raffle_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		// Total tickets.
		if ( $raffle_id_clean ) {
			$total_tickets = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND raffle_id = %d",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$total_tickets = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'",
					$start_date_clean,
					$end_date_clean
				)
			);
		}

		// Gift tickets.
		if ( $raffle_id_clean ) {
			$gift_tickets = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND is_gift = 1 AND raffle_id = %d",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$gift_tickets = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND is_gift = 1",
					$start_date_clean,
					$end_date_clean
				)
			);
		}

		// Unique customers.
		if ( $raffle_id_clean ) {
			$unique_customers = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT purchaser_email) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND raffle_id = %d",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$unique_customers = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT purchaser_email) FROM ' . $table_name . " WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'",
					$start_date_clean,
					$end_date_clean
				)
			);
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		// Revenue from the order line items the tickets were purchased on,
		// so coupons/discounts and later price changes are reflected.
		$revenue = $this->get_actual_revenue( $raffle_id_clean, $start_date_clean, $end_date_clean );

		return array(
			'total_tickets'    => (int) $total_tickets,
			'gift_tickets'     => (int) $gift_tickets,
			'self_tickets'     => (int) $total_tickets - (int) $gift_tickets,
			'unique_customers' => (int) $unique_customers,
			'total_revenue'    => wc_price( $revenue ),
			'revenue_raw'      => $revenue,
			'gift_percentage'  => $total_tickets > 0 ? round( ( $gift_tickets / $total_tickets ) * 100, 1 ) : 0,
		);
	}

	/**
	 * Get chart data for tickets over time.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id  Optional raffle ID filter.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function get_chart_data( $raffle_id, $start_date, $end_date ) {
		global $wpdb;
		$table_name       = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$start_date_clean = sanitize_text_field( $start_date );
		$end_date_clean   = sanitize_text_field( $end_date );
		$raffle_id_clean  = absint( $raffle_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		// Get daily ticket counts.
		if ( $raffle_id_clean ) {
			$daily_data = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(created_at) as date, COUNT(*) as total, SUM(is_gift) as gifts 
					FROM ' . $table_name . " 
					WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND raffle_id = %d
					GROUP BY DATE(created_at) 
					ORDER BY date ASC",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$daily_data = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(created_at) as date, COUNT(*) as total, SUM(is_gift) as gifts 
					FROM ' . $table_name . " 
					WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'
					GROUP BY DATE(created_at) 
					ORDER BY date ASC",
					$start_date_clean,
					$end_date_clean
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		// Index data by date for quick lookup.
		$data_by_date = array();
		foreach ( $daily_data as $row ) {
			$data_by_date[ $row->date ] = array(
				'total' => (int) $row->total,
				'gifts' => (int) $row->gifts,
			);
		}

		// Generate complete date range to fill gaps with zeros.
		$labels = array();
		$totals = array();
		$gifts  = array();

		$current = new DateTime( $start_date_clean );
		$end     = new DateTime( $end_date_clean );
		$end->modify( '+1 day' ); // Include end date.

		// Limit to reasonable number of data points (max 60 for readability).
		$interval    = $current->diff( $end );
		$total_days  = $interval->days;
		$step        = max( 1, ceil( $total_days / 60 ) );
		$day_counter = 0;

		while ( $current < $end ) {
			$date_str = $current->format( 'Y-m-d' );

			// Only add every nth day if range is large.
			if ( 0 === $day_counter % $step ) {
				$labels[] = wp_date( 'M j', strtotime( $date_str ) );

				if ( isset( $data_by_date[ $date_str ] ) ) {
					$totals[] = $data_by_date[ $date_str ]['total'];
					$gifts[]  = $data_by_date[ $date_str ]['gifts'];
				} else {
					$totals[] = 0;
					$gifts[]  = 0;
				}
			}

			$current->modify( '+1 day' );
			++$day_counter;
		}

		return array(
			'labels' => $labels,
			'totals' => $totals,
			'gifts'  => $gifts,
		);
	}

	/**
	 * Get ticket status distribution counts.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $raffle_id  Optional raffle ID filter.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Associative array of status => count.
	 */
	private function get_status_distribution( $raffle_id, $start_date, $end_date ) {
		global $wpdb;
		$table_name       = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$start_date_clean = sanitize_text_field( $start_date );
		$end_date_clean   = sanitize_text_field( $end_date );
		$raffle_id_clean  = absint( $raffle_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $raffle_id_clean ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) as count FROM {$table_name} WHERE DATE(created_at) BETWEEN %s AND %s AND raffle_id = %d GROUP BY status",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) as count FROM {$table_name} WHERE DATE(created_at) BETWEEN %s AND %s GROUP BY status",
					$start_date_clean,
					$end_date_clean
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$distribution = array(
			'active'    => 0,
			'winner'    => 0,
			'cancelled' => 0,
			'refunded'  => 0,
		);

		foreach ( $results as $row ) {
			if ( isset( $distribution[ $row->status ] ) ) {
				$distribution[ $row->status ] = (int) $row->count;
			}
		}

		return $distribution;
	}

	/**
	 * Get top raffles by ticket sales.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function get_top_raffles( $start_date, $end_date ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT raffle_id, COUNT(*) as ticket_count 
				FROM {$table} 
				WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'
				GROUP BY raffle_id 
				ORDER BY ticket_count DESC 
				LIMIT 10",
				$start_date,
				$end_date
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$top_raffles = array();
		foreach ( $results as $row ) {
			$raffle = wc_get_product( $row->raffle_id );

			// Skip if product was deleted or its type changed away from 'raffle'.
			if ( ! $raffle || ! ( $raffle instanceof RFWC_Product_Raffle ) ) {
				continue;
			}

			$revenue = $this->get_actual_revenue( (int) $row->raffle_id, $start_date, $end_date );
			$status  = $this->get_raffle_status( $raffle );

			$top_raffles[] = array(
				'id'           => $row->raffle_id,
				'name'         => $raffle->get_name(),
				'ticket_count' => (int) $row->ticket_count,
				'revenue'      => wc_price( $revenue ),
				'status'       => $status,
				'edit_url'     => get_edit_post_link( $row->raffle_id, 'raw' ),
			);
		}

		return $top_raffles;
	}

	/**
	 * Order object cache for the current request.
	 *
	 * The stats, Top Raffles, and Top Purchasers calculations all run in one
	 * AJAX request and mostly touch the same orders, so cache lookups to
	 * avoid refetching each order several times.
	 *
	 * @since 1.1.14
	 *
	 * @var array
	 */
	private $order_cache = array();

	/**
	 * Get an order, cached for the current request.
	 *
	 * @since 1.1.14
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|false
	 */
	private function get_cached_order( $order_id ) {
		$order_id = (int) $order_id;

		if ( ! array_key_exists( $order_id, $this->order_cache ) ) {
			$this->order_cache[ $order_id ] = wc_get_order( $order_id );
		}

		return $this->order_cache[ $order_id ];
	}

	/**
	 * Calculate revenue from the order line items the tickets were purchased on.
	 *
	 * Uses the amount actually charged at checkout (after coupons/discounts)
	 * instead of ticket count multiplied by the raffle's current list price,
	 * so discounts and later price changes don't skew the figures. Counted
	 * tickets per order item are capped at the purchased quantity so free
	 * bonus tickets (which share the same order item) don't inflate revenue.
	 *
	 * Includes tax by default to match the gross figures the dashboard
	 * reported previously. Use the `rfwc_analytics_revenue_include_tax`
	 * filter to report net revenue instead.
	 *
	 * @since 1.1.14
	 *
	 * @param int    $raffle_id       Optional raffle ID filter. 0 for all raffles.
	 * @param string $start_date      Start date (Y-m-d).
	 * @param string $end_date        End date (Y-m-d).
	 * @param string $purchaser_email Optional purchaser email filter.
	 * @return float
	 */
	private function get_actual_revenue( $raffle_id, $start_date, $end_date, $purchaser_email = '' ) {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		$sql    = "SELECT order_id, order_item_id, COUNT(*) as ticket_count
			FROM {$table_name}
			WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'";
		$params = array( $start_date, $end_date );

		if ( $raffle_id ) {
			$sql     .= ' AND raffle_id = %d';
			$params[] = $raffle_id;
		}

		if ( '' !== $purchaser_email ) {
			$sql     .= ' AND purchaser_email = %s';
			$params[] = $purchaser_email;
		}

		$sql .= ' GROUP BY order_id, order_item_id';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * Filter whether analytics revenue includes tax.
		 *
		 * Defaults to true (gross revenue, matching the pre-1.1.14 figures
		 * for tax-inclusive stores). Return false to report net revenue.
		 *
		 * @since 1.1.14
		 *
		 * @param bool $include_tax Whether to include tax in revenue figures.
		 */
		$include_tax = apply_filters( 'rfwc_analytics_revenue_include_tax', true );

		$revenue = 0.0;

		foreach ( (array) $rows as $row ) {
			$order = $this->get_cached_order( $row->order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$item = $order->get_item( $row->order_item_id );
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );

			$line_total = (float) $item->get_total();
			if ( $include_tax ) {
				$line_total += (float) $item->get_total_tax();
			}

			// Cap counted tickets at the purchased quantity so free bonus
			// tickets sharing this order item don't add phantom revenue.
			$counted = min( (int) $row->ticket_count, $quantity );

			$revenue += ( $line_total / $quantity ) * $counted;
		}

		return $revenue;
	}

	/**
	 * Get raffle status.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Product_Raffle $raffle The raffle product.
	 * @return array Status info.
	 */
	private function get_raffle_status( $raffle ) {
		$now       = time();
		$start     = $raffle->get_start_timestamp();
		$end       = $raffle->get_end_timestamp();
		$has_ended = $end && $end < $now;
		$status    = $raffle->get_meta( '_rfwc_status', true );

		if ( 'cancelled' === $status ) {
			return array(
				'label' => __( 'Cancelled', 'raffle-for-woocommerce' ),
				'class' => 'status-cancelled',
			);
		} elseif ( 'failed' === $status ) {
			return array(
				'label' => __( 'Failed', 'raffle-for-woocommerce' ),
				'class' => 'status-failed',
			);
		} elseif ( $raffle->has_winners() || 'completed' === $status ) {
			return array(
				'label' => __( 'Completed', 'raffle-for-woocommerce' ),
				'class' => 'status-completed',
			);
		} elseif ( $has_ended ) {
			return array(
				'label' => __( 'Ended', 'raffle-for-woocommerce' ),
				'class' => 'status-ended',
			);
		} elseif ( $start && $start > $now ) {
			return array(
				'label' => __( 'Scheduled', 'raffle-for-woocommerce' ),
				'class' => 'status-scheduled',
			);
		} else {
			return array(
				'label' => __( 'Active', 'raffle-for-woocommerce' ),
				'class' => 'status-active',
			);
		}
	}

	/**
	 * Get top purchasers.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id  Optional raffle ID filter.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function get_top_purchasers( $raffle_id, $start_date, $end_date ) {
		global $wpdb;
		$table_name       = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$start_date_clean = sanitize_text_field( $start_date );
		$end_date_clean   = sanitize_text_field( $end_date );
		$raffle_id_clean  = absint( $raffle_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( $raffle_id_clean ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT purchaser_email, purchaser_name, 
					COUNT(*) as ticket_count, 
					SUM(is_gift) as gift_count,
					GROUP_CONCAT(DISTINCT raffle_id) as raffle_ids
					FROM ' . $table_name . " 
					WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled' AND raffle_id = %d
					GROUP BY purchaser_email 
					ORDER BY ticket_count DESC 
					LIMIT 10",
					$start_date_clean,
					$end_date_clean,
					$raffle_id_clean
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT purchaser_email, purchaser_name, 
					COUNT(*) as ticket_count, 
					SUM(is_gift) as gift_count,
					GROUP_CONCAT(DISTINCT raffle_id) as raffle_ids
					FROM ' . $table_name . " 
					WHERE DATE(created_at) BETWEEN %s AND %s AND status != 'cancelled'
					GROUP BY purchaser_email 
					ORDER BY ticket_count DESC 
					LIMIT 10",
					$start_date_clean,
					$end_date_clean
				)
			);
		}

		$top_purchasers = array();
		foreach ( $results as $row ) {
			// Spend from actual order line-item totals, scoped to the same
			// date range and raffle filter as the rest of the report.
			$spent = $this->get_actual_revenue(
				$raffle_id_clean,
				$start_date_clean,
				$end_date_clean,
				$row->purchaser_email
			);

			$top_purchasers[] = array(
				'name'         => $row->purchaser_name ? $row->purchaser_name : $row->purchaser_email,
				'email'        => $row->purchaser_email,
				'ticket_count' => (int) $row->ticket_count,
				'gift_count'   => (int) $row->gift_count,
				'spent'        => wc_price( $spent ),
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return $top_purchasers;
	}

	/**
	 * AJAX handler for exporting analytics.
	 *
	 * @since 1.0.0
	 */
	public function ajax_export_analytics() {
		check_ajax_referer( 'rfwc_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id  = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$date_range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : '30';

		$end_date   = current_time( 'Y-m-d' );
		$start_date = 'all' === $date_range ? '2000-01-01' : gmdate( 'Y-m-d', strtotime( "-{$date_range} days" ) );

		// Get all data.
		$stats          = $this->get_statistics( $raffle_id, $start_date, $end_date );
		$top_raffles    = $this->get_top_raffles( $start_date, $end_date );
		$top_purchasers = $this->get_top_purchasers( $raffle_id, $start_date, $end_date );

		// Build CSV content.
		$csv = array();

		// Summary section.
		$csv[] = array( 'RAFFLE ANALYTICS REPORT' );
		$csv[] = array( 'Generated:', current_time( 'Y-m-d H:i:s' ) );
		$csv[] = array( 'Period:', $start_date . ' to ' . $end_date );
		$csv[] = array( '' );

		// Stats.
		$csv[] = array( 'SUMMARY' );
		$csv[] = array( 'Total Tickets:', $stats['total_tickets'] );
		$csv[] = array( 'Total Revenue:', wp_strip_all_tags( $stats['total_revenue'] ) );
		$csv[] = array( 'Gift Purchases:', $stats['gift_tickets'] );
		$csv[] = array( 'Self Purchases:', $stats['self_tickets'] );
		$csv[] = array( 'Unique Customers:', $stats['unique_customers'] );
		$csv[] = array( 'Gift Percentage:', $stats['gift_percentage'] . '%' );
		$csv[] = array( '' );

		// Top raffles.
		$csv[] = array( 'TOP RAFFLES' );
		$csv[] = array( 'Raffle Name', 'Tickets Sold', 'Revenue', 'Status' );
		foreach ( $top_raffles as $raffle ) {
			$csv[] = array(
				$raffle['name'],
				$raffle['ticket_count'],
				wp_strip_all_tags( $raffle['revenue'] ),
				$raffle['status']['label'],
			);
		}
		$csv[] = array( '' );

		// Top purchasers.
		$csv[] = array( 'TOP PURCHASERS' );
		$csv[] = array( 'Customer', 'Email', 'Tickets', 'Spent', 'Gifts' );
		foreach ( $top_purchasers as $purchaser ) {
			$csv[] = array(
				$purchaser['name'],
				$purchaser['email'],
				$purchaser['ticket_count'],
				wp_strip_all_tags( $purchaser['spent'] ),
				$purchaser['gift_count'],
			);
		}

		// Generate CSV content.
		$csv_content = '';
		foreach ( $csv as $row ) {
			$csv_content .= implode(
				',',
				array_map(
					function ( $value ) {
						return '"' . str_replace( '"', '""', $value ) . '"';
					},
					$row
				)
			) . "\n";
		}

		// Return CSV content directly instead of writing to disk,
		// avoiding PII exposure via publicly-accessible uploaded files.
		$filename = sprintf( 'raffle-analytics-%s.csv', gmdate( 'Y-m-d-His' ) );

		wp_send_json_success(
			array(
				'csv_content' => $csv_content,
				'filename'    => $filename,
			)
		);
	}
}

