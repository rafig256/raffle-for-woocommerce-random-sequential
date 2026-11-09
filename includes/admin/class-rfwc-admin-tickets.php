<?php
/**
 * Admin ticket list and management page.
 *
 * Displays all raffle tickets with filtering by raffle, status, and date.
 * Supports CSV export for reporting and compliance.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce submenu page for viewing and exporting tickets.
 *
 * @since 1.0.0
 */
class RFWC_Admin_Tickets {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 50 );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX handlers for winner management from Tickets page.
		add_action( 'wp_ajax_rfwc_tickets_mark_claimed', array( $this, 'ajax_mark_claimed' ) );
		add_action( 'wp_ajax_rfwc_tickets_bulk_claim', array( $this, 'ajax_bulk_claim' ) );
		add_action( 'wp_ajax_rfwc_tickets_resend_notification', array( $this, 'ajax_resend_notification' ) );

		// AJAX handlers for mass refund.
		add_action( 'wp_ajax_rfwc_mass_refund_info', array( $this, 'ajax_mass_refund_info' ) );
		add_action( 'wp_ajax_rfwc_mass_refund_process', array( $this, 'ajax_mass_refund_process' ) );
		add_action( 'wp_ajax_rfwc_mass_refund_complete', array( $this, 'ajax_mass_refund_complete' ) );

		// AJAX handlers for selective refund.
		add_action( 'wp_ajax_rfwc_selective_refund_info', array( $this, 'ajax_selective_refund_info' ) );
		add_action( 'wp_ajax_rfwc_selective_refund_process', array( $this, 'ajax_selective_refund_process' ) );

		// AJAX handler for marking manual refunds as done.
		add_action( 'wp_ajax_rfwc_mark_refund_done', array( $this, 'ajax_mark_refund_done' ) );
	}

	/**
	 * Add admin menu item under WooCommerce.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Raffle Tickets', 'raffle-for-woocommerce' ),
			__( 'Raffle Tickets', 'raffle-for-woocommerce' ),
			'manage_woocommerce',
			'rfwc-tickets',
			array( $this, 'render_page' ),
			3
		);
	}

	/**
	 * Handle CSV export request.
	 *
	 * @since 1.0.0
	 */
	public function handle_export() {
		if ( ! isset( $_GET['rfwc_export'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rfwc_export_tickets' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'raffle-for-woocommerce' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export tickets.', 'raffle-for-woocommerce' ) );
		}

		$export_type = isset( $_GET['export_type'] ) ? sanitize_text_field( wp_unslash( $_GET['export_type'] ) ) : 'tickets';
		$raffle_id   = isset( $_GET['raffle_id'] ) ? sanitize_text_field( wp_unslash( $_GET['raffle_id'] ) ) : 'all';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( 'winners' === $export_type ) {
			$claimed = isset( $_GET['claimed'] ) ? sanitize_text_field( wp_unslash( $_GET['claimed'] ) ) : '';
			$this->export_winners( $raffle_id, $claimed, $search );
		} else {
			$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
			$this->export_tickets( $raffle_id, $status, $search );
		}
	}

	/**
	 * Export tickets to CSV file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raffle_id Raffle ID or 'all'.
	 * @param string $status    Status filter.
	 * @param string $search    Search term.
	 */
	private function export_tickets( $raffle_id, $status, $search = '' ) {
		$tickets = $this->get_filtered_tickets(
			'all' === $raffle_id ? 0 : absint( $raffle_id ),
			$status,
			$search,
			0
		);

		$filename = sprintf( 'raffle-tickets-%s.csv', wp_date( 'Y-m-d-His' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://output for streaming.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$output,
			array(
				__( 'Ticket Number', 'raffle-for-woocommerce' ),
				__( 'Raffle', 'raffle-for-woocommerce' ),
				__( 'Holder Name', 'raffle-for-woocommerce' ),
				__( 'Holder Email', 'raffle-for-woocommerce' ),
				__( 'Is Gift', 'raffle-for-woocommerce' ),
				__( 'Purchaser Name', 'raffle-for-woocommerce' ),
				__( 'Purchaser Email', 'raffle-for-woocommerce' ),
				__( 'Status', 'raffle-for-woocommerce' ),
				__( 'Order ID', 'raffle-for-woocommerce' ),
				__( 'Date', 'raffle-for-woocommerce' ),
			)
		);

		foreach ( $tickets as $ticket ) {
			$raffle      = wc_get_product( $ticket->get_raffle_id() );
			$raffle_name = $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' );

			fputcsv(
				$output,
				array(
					$ticket->get_formatted_ticket_number(),
					$raffle_name,
					$ticket->get_holder_name(),
					$ticket->get_holder_email(),
					$ticket->is_gift() ? __( 'Yes', 'raffle-for-woocommerce' ) : __( 'No', 'raffle-for-woocommerce' ),
					$ticket->get_purchaser_name(),
					$ticket->get_purchaser_email(),
					ucfirst( $ticket->get_status() ),
					$ticket->get_order_id(),
					wp_date( 'Y-m-d H:i:s', strtotime( $ticket->get_created_at() ) ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output for streaming.
		exit;
	}

	/**
	 * Export winners to CSV file.
	 *
	 * @since 1.0.10
	 *
	 * @param string $raffle_id Raffle ID or 'all'.
	 * @param string $claimed   Claimed filter: 'yes', 'no', or ''.
	 * @param string $search    Search term.
	 */
	private function export_winners( $raffle_id, $claimed, $search = '' ) {
		$query_args = array(
			'limit' => 0,
		);
		if ( 'all' !== $raffle_id && absint( $raffle_id ) ) {
			$query_args['raffle_id'] = absint( $raffle_id );
		}
		if ( $claimed ) {
			$query_args['claimed'] = $claimed;
		}
		if ( $search ) {
			$query_args['search'] = $search;
		}

		$winners = RFWC_Winner::get_all( $query_args );

		$filename = sprintf( 'raffle-winners-%s.csv', wp_date( 'Y-m-d-His' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://output for streaming.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$output,
			array(
				__( 'Raffle', 'raffle-for-woocommerce' ),
				__( 'Ticket Number', 'raffle-for-woocommerce' ),
				__( 'Winner Name', 'raffle-for-woocommerce' ),
				__( 'Winner Email', 'raffle-for-woocommerce' ),
				__( 'Prize Tier', 'raffle-for-woocommerce' ),
				__( 'Selection Method', 'raffle-for-woocommerce' ),
				__( 'Claimed', 'raffle-for-woocommerce' ),
				__( 'Claimed At', 'raffle-for-woocommerce' ),
				__( 'Notified', 'raffle-for-woocommerce' ),
				__( 'Draw Date', 'raffle-for-woocommerce' ),
			)
		);

		foreach ( $winners as $winner ) {
			$ticket      = $winner->get_ticket();
			$raffle      = wc_get_product( $winner->get_raffle_id() );
			$raffle_name = $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' );

			fputcsv(
				$output,
				array(
					$raffle_name,
					$ticket ? $ticket->get_formatted_ticket_number() : '',
					$ticket ? $ticket->get_holder_name() : '',
					$ticket ? $ticket->get_holder_email() : '',
					$winner->get_prize_tier(),
					ucfirst( str_replace( '_', ' ', $winner->get_selection_method() ) ),
					$winner->is_claimed() ? __( 'Yes', 'raffle-for-woocommerce' ) : __( 'No', 'raffle-for-woocommerce' ),
					$winner->is_claimed() ? wp_date( 'Y-m-d H:i:s', strtotime( $winner->get_claimed_at() ) ) : '',
					$winner->is_notified() ? __( 'Yes', 'raffle-for-woocommerce' ) : __( 'No', 'raffle-for-woocommerce' ),
					wp_date( 'Y-m-d H:i:s', strtotime( $winner->get_created_at() ) ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output for streaming.
		exit;
	}

	/**
	 * Render the tickets admin page.
	 *
	 * Routes to the correct view based on the `view` query parameter.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page routing.
		$current_view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'tickets';

		$base_url       = admin_url( 'admin.php?page=rfwc-tickets' );
		$winner_counts  = RFWC_Winner::get_claim_counts();
		$refunded_count = $this->get_refunded_ticket_count();

		/**
		 * Filter extra tabs displayed on the Raffle Tickets admin page.
		 *
		 * Each tab is keyed by its `view` query var and accepts:
		 *  - label (string)       Tab text. Required.
		 *  - count (int|null)     Optional badge count.
		 *  - url   (string|null)  Optional override URL (defaults to the standard view link).
		 *
		 * @since 1.1.13
		 *
		 * @param array  $tabs         Extra tabs to append after the core tabs.
		 * @param string $current_view Currently active view slug.
		 */
		$extra_tabs = (array) apply_filters( 'rfwc_admin_tickets_extra_tabs', array(), $current_view );

		?>
		<div class="wrap woocommerce">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Raffle Tickets', 'raffle-for-woocommerce' ); ?></h1>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper rfwc-tickets-tabs">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'tickets' === $current_view ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'All Tickets', 'raffle-for-woocommerce' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'winners', $base_url ) ); ?>" class="nav-tab <?php echo 'winners' === $current_view ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Winners', 'raffle-for-woocommerce' ); ?>
					<?php if ( $winner_counts['all'] > 0 ) : ?>
						<span class="count">(<?php echo esc_html( $winner_counts['all'] ); ?>)</span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'claimed', $base_url ) ); ?>" class="nav-tab <?php echo 'claimed' === $current_view ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Claimed', 'raffle-for-woocommerce' ); ?>
					<?php if ( $winner_counts['claimed'] > 0 ) : ?>
						<span class="count">(<?php echo esc_html( $winner_counts['claimed'] ); ?>)</span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'refunded', $base_url ) ); ?>" class="nav-tab <?php echo 'refunded' === $current_view ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Mass Refund', 'raffle-for-woocommerce' ); ?>
					<?php if ( $refunded_count > 0 ) : ?>
						<span class="count">(<?php echo esc_html( $refunded_count ); ?>)</span>
					<?php endif; ?>
				</a>
				<?php foreach ( $extra_tabs as $view_slug => $tab ) :
					$slug       = sanitize_key( $view_slug );
					$label      = isset( $tab['label'] ) ? (string) $tab['label'] : '';
					$count      = isset( $tab['count'] ) ? absint( $tab['count'] ) : 0;
					$tab_url    = isset( $tab['url'] ) && $tab['url']
						? (string) $tab['url']
						: add_query_arg( 'view', $slug, $base_url );
					$is_active  = $slug === $current_view;
					if ( '' === $label ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( $count > 0 ) : ?>
							<span class="count">(<?php echo esc_html( $count ); ?>)</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<?php
		$core_views = array( 'tickets', 'winners', 'claimed', 'refunded' );

		if ( ! in_array( $current_view, $core_views, true ) ) {
			/**
			 * Render a custom Raffle Tickets admin view registered via `rfwc_admin_tickets_extra_tabs`.
			 *
			 * Listeners should echo their view markup. Return true to mark the view as handled
			 * and prevent the default fallback to the All Tickets view.
			 *
			 * @since 1.1.13
			 *
			 * @param bool   $handled      Whether the view has been rendered.
			 * @param string $current_view Currently active view slug.
			 */
			$handled = (bool) apply_filters( 'rfwc_admin_tickets_render_view', false, $current_view );

			if ( $handled ) {
				return;
			}
		}

		switch ( $current_view ) {
			case 'winners':
				$this->render_winners_view( '' );
				break;

			case 'claimed':
				$this->render_winners_view( 'yes' );
				break;

			case 'refunded':
				$this->render_refunded_view();
				break;

			default:
				$this->render_tickets_view();
				break;
		}
	}

	/**
	 * Render the tickets list view (original All Tickets tab).
	 *
	 * @since 1.0.4
	 */
	private function render_tickets_view() {
		$raffles = $this->get_raffles_with_tickets();

		// Get filter values - these are display filters, nonce not required for admin page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page filter values.
		$current_raffle = isset( $_GET['raffle_id'] ) ? absint( $_GET['raffle_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page filter values.
		$current_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page search value.
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// Search respects the current status tab -- users can search within
		// Winners, Cancelled, etc. On the "All" tab, search covers every status.
		$query_status = $current_status;

		$tickets  = $this->get_filtered_tickets( $current_raffle, $query_status, $search_query );
		$counts   = $this->get_status_counts( $current_raffle );
		$base_url = admin_url( 'admin.php?page=rfwc-tickets' );

		if ( $current_raffle ) {
			$base_url = add_query_arg( 'raffle_id', $current_raffle, $base_url );
		}

		// Clicking a tab gives a clean view -- strip any active search.
		$tab_url = remove_query_arg( 's', $base_url );
		?>
		<?php
		$export_csv_url = ! empty( $tickets ) ? wp_nonce_url(
			add_query_arg(
				array(
					'rfwc_export' => 1,
					'raffle_id'   => $current_raffle ? $current_raffle : 'all',
					'status'      => $query_status,
					's'           => $search_query,
				),
				$base_url
			),
			'rfwc_export_tickets'
		) : '';
		?>
		<div class="wrap woocommerce rfwc-tickets-wrap">

			<?php if ( $search_query && ! empty( $tickets ) ) : ?>
				<span class="subtitle">
					<?php
					$status_labels = array(
						'active'    => __( 'Active', 'raffle-for-woocommerce' ),
						'cancelled' => __( 'Cancelled', 'raffle-for-woocommerce' ),
						'winner'    => __( 'Winners', 'raffle-for-woocommerce' ),
						'refunded'  => __( 'Refunded', 'raffle-for-woocommerce' ),
					);

					if ( $current_status && isset( $status_labels[ $current_status ] ) ) {
						printf(
							/* translators: 1: search term, 2: status label */
							esc_html__( 'Search results for %1$s in %2$s', 'raffle-for-woocommerce' ),
							'<strong>' . esc_html( $search_query ) . '</strong>',
							'<strong>' . esc_html( $status_labels[ $current_status ] ) . '</strong>'
						);
					} else {
						printf(
							/* translators: %s: search term */
							esc_html__( 'Search results for: %s', 'raffle-for-woocommerce' ),
							'<strong>' . esc_html( $search_query ) . '</strong>'
						);
					}
					?>
				</span>
			<?php endif; ?>

			<?php // Selective refund modal (shared across All Tickets tab). ?>
			<div id="rfwc-selective-refund-modal" class="rfwc-modal" style="display:none;">
				<div class="rfwc-modal-backdrop"></div>
				<div class="rfwc-modal-content">
					<div class="rfwc-modal-header">
						<h2><?php esc_html_e( 'Confirm Refund', 'raffle-for-woocommerce' ); ?></h2>
						<button type="button" class="rfwc-modal-close rfwc-selective-modal-close" aria-label="<?php esc_attr_e( 'Close', 'raffle-for-woocommerce' ); ?>">&times;</button>
					</div>
					<div class="rfwc-modal-body">
						<div id="rfwc-selective-refund-summary">
							<p><?php esc_html_e( 'Loading refund details...', 'raffle-for-woocommerce' ); ?></p>
						</div>
						<div class="rfwc-refund-mode" id="rfwc-selective-refund-mode" style="display:none; margin: 12px 0; padding: 10px 12px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
							<fieldset style="margin:0; padding:0; border:0;">
								<legend style="font-weight:600; margin-bottom:8px;"><?php esc_html_e( 'Refund method', 'raffle-for-woocommerce' ); ?></legend>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; margin-bottom:10px;">
									<input type="radio" name="rfwc-selective-refund-method" value="standard" checked style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Standard refund', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'Creates WooCommerce refund records only. You will need to return funds manually for offline payment methods.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; margin-bottom:10px;">
									<input type="radio" name="rfwc-selective-refund-method" value="gateway" style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Refund via payment gateway', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'For orders paid via Stripe, PayPal, etc., money returns to the customer automatically. Offline payment methods (bank transfer, COD, check) will fall back to standard refund records.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<?php if ( function_exists( 'woo_wallet' ) ) : ?>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
									<input type="radio" name="rfwc-selective-refund-method" value="wallet" style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Refund to wallet', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'Credits the refund amount to each customer\'s wallet (TeraWallet). Works for all payment methods. Guest orders will fall back to standard refund.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<?php endif; ?>
							</fieldset>
						</div>
						<div id="rfwc-selective-refund-progress" style="display:none;">
							<p id="rfwc-selective-progress-text"></p>
							<div class="rfwc-refund-progress-bar-wrap">
								<div class="rfwc-refund-progress-bar" id="rfwc-selective-progress-bar" style="width:0%;"></div>
							</div>
						</div>
						<div id="rfwc-selective-refund-result" style="display:none;"></div>
					</div>
					<div class="rfwc-modal-footer" id="rfwc-selective-modal-footer">
						<button type="button" class="button rfwc-selective-modal-close"><?php esc_html_e( 'Cancel', 'raffle-for-woocommerce' ); ?></button>
						<button type="button" class="button button-primary" id="rfwc-selective-refund-confirm" disabled>
							<?php esc_html_e( 'Confirm Refund', 'raffle-for-woocommerce' ); ?>
						</button>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $raffles ) ) : ?>
				<ul class="subsubsub">
					<li class="all">
						<a href="<?php echo esc_url( remove_query_arg( 'status', $tab_url ) ); ?>" <?php echo empty( $current_status ) ? 'class="current"' : ''; ?>>
							<?php esc_html_e( 'All', 'raffle-for-woocommerce' ); ?>
							<span class="count">(<?php echo esc_html( $counts['all'] ); ?>)</span>
						</a> |
					</li>
					<li class="active">
						<a href="<?php echo esc_url( add_query_arg( 'status', 'active', $tab_url ) ); ?>" <?php echo 'active' === $current_status ? 'class="current"' : ''; ?>>
							<?php esc_html_e( 'Active', 'raffle-for-woocommerce' ); ?>
							<span class="count">(<?php echo esc_html( $counts['active'] ); ?>)</span>
						</a> |
					</li>
					<li class="cancelled">
						<a href="<?php echo esc_url( add_query_arg( 'status', 'cancelled', $tab_url ) ); ?>" <?php echo 'cancelled' === $current_status ? 'class="current"' : ''; ?>>
							<?php esc_html_e( 'Cancelled', 'raffle-for-woocommerce' ); ?>
							<span class="count">(<?php echo esc_html( $counts['cancelled'] ); ?>)</span>
						</a> |
					</li>
					<li class="winner">
						<a href="<?php echo esc_url( add_query_arg( 'status', 'winner', $tab_url ) ); ?>" <?php echo 'winner' === $current_status ? 'class="current"' : ''; ?>>
							<?php esc_html_e( 'Winners', 'raffle-for-woocommerce' ); ?>
							<span class="count">(<?php echo esc_html( $counts['winner'] ); ?>)</span>
						</a> |
					</li>
					<li class="refunded">
						<a href="<?php echo esc_url( add_query_arg( 'status', 'refunded', $tab_url ) ); ?>" <?php echo 'refunded' === $current_status ? 'class="current"' : ''; ?>>
							<?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?>
							<span class="count">(<?php echo esc_html( $counts['refunded'] ); ?>)</span>
						</a>
					</li>
				</ul>

				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select id="rfwc-ticket-bulk-action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'raffle-for-woocommerce' ); ?></option>
							<option value="refund"><?php esc_html_e( 'Refund Selected', 'raffle-for-woocommerce' ); ?></option>
						</select>
						<button type="button" class="button" id="rfwc-ticket-bulk-apply">
							<?php esc_html_e( 'Apply', 'raffle-for-woocommerce' ); ?>
						</button>
					</div>
					<div class="alignleft actions">
						<form method="get" action="">
							<input type="hidden" name="page" value="rfwc-tickets">
							<?php if ( $current_status ) : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>">
							<?php endif; ?>
							<?php if ( $search_query ) : ?>
								<input type="hidden" name="s" value="<?php echo esc_attr( $search_query ); ?>">
							<?php endif; ?>
						<select name="raffle_id">
							<option value=""><?php esc_html_e( 'All raffles', 'raffle-for-woocommerce' ); ?></option>
							<?php $this->render_grouped_raffle_options( $raffles, $current_raffle ); ?>
						</select>
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'raffle-for-woocommerce' ); ?>">
					</form>
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of items */
								esc_html( _n( '%s item', '%s items', count( $tickets ), 'raffle-for-woocommerce' ) ),
								esc_html( number_format_i18n( count( $tickets ) ) )
							);
							?>
						</span>
					</div>
					<div class="alignright actions rfwc-tablenav-right">
						<form method="get" action="" class="rfwc-search-form">
							<input type="hidden" name="page" value="rfwc-tickets">
							<?php if ( $current_raffle ) : ?>
								<input type="hidden" name="raffle_id" value="<?php echo esc_attr( $current_raffle ); ?>">
							<?php endif; ?>
							<?php if ( $current_status ) : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>">
							<?php endif; ?>
							<label class="screen-reader-text" for="rfwc-ticket-search-input"><?php esc_html_e( 'Search tickets', 'raffle-for-woocommerce' ); ?></label>
							<input type="search" id="rfwc-ticket-search-input" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search by ticket, name, or email...', 'raffle-for-woocommerce' ); ?>">
							<input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search', 'raffle-for-woocommerce' ); ?>">
						</form>
						<?php if ( $export_csv_url ) : ?>
							<a href="<?php echo esc_url( $export_csv_url ); ?>" class="button rfwc-export-csv-btn"><?php esc_html_e( 'Export CSV', 'raffle-for-woocommerce' ); ?></a>
						<?php endif; ?>
					</div>
					<br class="clear">
				</div>
			<?php endif; ?>

			<?php if ( empty( $tickets ) ) : ?>
				<div class="woocommerce-BlankState">
					<?php
					// Build a "clear search" URL that keeps the current tab.
					$clear_search_url = remove_query_arg( 's', $base_url );
					if ( $current_status ) {
						$clear_search_url = add_query_arg( 'status', $current_status, $clear_search_url );
					}
					?>
					<?php if ( $search_query && $current_status ) : ?>
						<h2 class="woocommerce-BlankState-message">
							<?php
							$status_labels_empty = array(
								'active'    => __( 'active tickets', 'raffle-for-woocommerce' ),
								'cancelled' => __( 'cancelled tickets', 'raffle-for-woocommerce' ),
								'winner'    => __( 'winning tickets', 'raffle-for-woocommerce' ),
								'refunded'  => __( 'refunded tickets', 'raffle-for-woocommerce' ),
							);
							$status_label_text   = isset( $status_labels_empty[ $current_status ] ) ? $status_labels_empty[ $current_status ] : __( 'tickets', 'raffle-for-woocommerce' );
							printf(
								/* translators: 1: search term, 2: status context (e.g. "winning tickets") */
								esc_html__( 'No %2$s found matching "%1$s".', 'raffle-for-woocommerce' ),
								esc_html( $search_query ),
								esc_html( $status_label_text )
							);
							?>
						</h2>
						<p>
							<?php esc_html_e( 'Try a different search term, or switch to the "All" tab to search across all ticket statuses.', 'raffle-for-woocommerce' ); ?>
							<br><a href="<?php echo esc_url( $clear_search_url ); ?>"><?php esc_html_e( 'Clear search', 'raffle-for-woocommerce' ); ?></a>
						</p>
					<?php elseif ( $search_query ) : ?>
						<h2 class="woocommerce-BlankState-message">
							<?php
							printf(
								/* translators: %s: search term */
								esc_html__( 'No tickets found matching "%s".', 'raffle-for-woocommerce' ),
								esc_html( $search_query )
							);
							?>
						</h2>
						<p>
							<?php esc_html_e( 'Try a different search term, or search by ticket number, holder name, or email address.', 'raffle-for-woocommerce' ); ?>
							<br><a href="<?php echo esc_url( $clear_search_url ); ?>"><?php esc_html_e( 'Clear search and show all tickets', 'raffle-for-woocommerce' ); ?></a>
						</p>
					<?php elseif ( 'winner' === $current_status ) : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No winners have been drawn yet.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Winners will appear here after a raffle draw is completed. You can draw winners from the raffle product edit screen.', 'raffle-for-woocommerce' ); ?></p>
					<?php elseif ( 'cancelled' === $current_status ) : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No cancelled tickets.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Tickets are cancelled automatically when their order is cancelled or refunded.', 'raffle-for-woocommerce' ); ?></p>
					<?php elseif ( 'refunded' === $current_status ) : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No refunded tickets.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Tickets appear here when a failed raffle is mass-refunded.', 'raffle-for-woocommerce' ); ?></p>
					<?php elseif ( empty( $raffles ) ) : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No raffle tickets yet.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Tickets will appear here once customers purchase them from your raffle products.', 'raffle-for-woocommerce' ); ?></p>
					<?php else : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No tickets found.', 'raffle-for-woocommerce' ); ?></h2>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped posts rfwc-tickets-table">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<input type="checkbox" id="rfwc-select-all-tickets">
							</td>
							<th scope="col" class="manage-column column-ticket"><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-raffle"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-holder"><?php esc_html_e( 'Ticket Holder', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-purchaser"><?php esc_html_e( 'Purchased By', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-phone"><?php esc_html_e( 'Phone', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-order"><?php esc_html_e( 'Order', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
				<tbody id="the-list">
					<?php
					$raffle_completed_cache = array();
					$order_phone_cache = array();
					?>
					<?php foreach ( $tickets as $ticket ) : ?>
						<?php
						$raffle_id_loop = $ticket->get_raffle_id();
						$raffle         = wc_get_product( $raffle_id_loop );
						$raffle_name    = $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' );
						$order_id = $ticket->get_order_id();
                        $order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
						if ( ! isset( $order_phone_cache[ $order_id ] ) ) {
							$order = wc_get_order( $order_id );
							$order_phone_cache[ $order_id ] = $order ? $order->get_billing_phone() : '';
						}
						$status         = $ticket->get_status();
						$is_gift        = $ticket->is_gift();

						if ( ! isset( $raffle_completed_cache[ $raffle_id_loop ] ) ) {
							$r_status                                  = $raffle ? $raffle->get_meta( '_rfwc_status', true ) : '';
							$raffle_completed_cache[ $raffle_id_loop ] = $raffle && ( $raffle->has_winners() || 'completed' === $r_status );
						}
						$can_refund = 'active' === $status && ! $raffle_completed_cache[ $raffle_id_loop ];
						?>
							<tr>
								<th scope="row" class="check-column">
									<?php if ( $can_refund ) : ?>
										<input type="checkbox" class="rfwc-ticket-cb" value="<?php echo esc_attr( $ticket->get_id() ); ?>" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-raffle-id="<?php echo esc_attr( $ticket->get_raffle_id() ); ?>">
									<?php endif; ?>
								</th>
								<td class="column-ticket">
									<strong class="rfwc-ticket-number"><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></strong>
							<?php if ( 'active' === $status ) : ?>
								<span class="rfwc-status-badge rfwc-status-active"><?php esc_html_e( 'Active', 'raffle-for-woocommerce' ); ?></span>
							<?php elseif ( 'winner' === $status ) : ?>
								<?php
								$winner_record     = RFWC_Winner::get_by_ticket( $ticket->get_id() );
								$is_instant_winner = $winner_record && 'instant_win' === $winner_record->get_selection_method();
								?>
								<?php if ( $is_instant_winner ) : ?>
									<span class="rfwc-status-badge rfwc-status-instant-winner"><?php esc_html_e( 'Instant Winner', 'raffle-for-woocommerce' ); ?></span>
								<?php else : ?>
									<span class="rfwc-status-badge rfwc-status-winner"><?php esc_html_e( 'Winner', 'raffle-for-woocommerce' ); ?></span>
								<?php endif; ?>
								<?php elseif ( 'cancelled' === $status ) : ?>
									<span class="rfwc-status-badge rfwc-status-cancelled"><?php esc_html_e( 'Cancelled', 'raffle-for-woocommerce' ); ?></span>
								<?php elseif ( 'expired' === $status ) : ?>
									<span class="rfwc-status-badge rfwc-status-cancelled"><?php esc_html_e( 'Expired', 'raffle-for-woocommerce' ); ?></span>
								<?php elseif ( 'refunded' === $status ) : ?>
									<span class="rfwc-status-badge rfwc-status-refunded"><?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?></span>
								<?php endif; ?>
								<div class="row-actions">
									<span class="view-order"><a href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'View Order', 'raffle-for-woocommerce' ); ?></a></span>
									<?php if ( $can_refund ) : ?>
										| <span class="refund"><a href="#" class="rfwc-single-refund" data-ticket-id="<?php echo esc_attr( $ticket->get_id() ); ?>" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-raffle-id="<?php echo esc_attr( $ticket->get_raffle_id() ); ?>"><?php esc_html_e( 'Refund', 'raffle-for-woocommerce' ); ?></a></span>
									<?php endif; ?>
								</div>
								</td>
								<td class="column-raffle">
									<?php if ( $raffle ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $raffle->get_id() ) ); ?>"><strong><?php echo esc_html( $raffle_name ); ?></strong></a>
									<?php else : ?>
										<?php echo esc_html( $raffle_name ); ?>
									<?php endif; ?>
								</td>
								<td class="column-holder">
									<strong><?php echo esc_html( $ticket->get_holder_name() ); ?></strong>
									<?php if ( $is_gift ) : ?>
										<span class="rfwc-gift-badge"><?php esc_html_e( 'Gift', 'raffle-for-woocommerce' ); ?></span>
									<?php endif; ?>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( $ticket->get_holder_email() ); ?></span>
								</td>
								<td class="column-purchaser">
									<?php echo esc_html( $ticket->get_purchaser_name() ); ?>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( $ticket->get_purchaser_email() ); ?></span>
								</td>
								<td class="column-phone">
									<?php echo ! empty( $order_phone_cache[ $order_id ] ) ? esc_html( $order_phone_cache[ $order_id ] ) : '—'; ?>
								</td>
								<td class="column-order">
									<a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $order_id ); ?></a>
								</td>
								<td class="column-date">
									<?php echo esc_html( wp_date( 'M j, Y', strtotime( $ticket->get_created_at() ) ) ); ?>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( wp_date( 'g:i a', strtotime( $ticket->get_created_at() ) ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of items */
								esc_html( _n( '%s item', '%s items', count( $tickets ), 'raffle-for-woocommerce' ) ),
								esc_html( number_format_i18n( count( $tickets ) ) )
							);
							?>
						</span>
					</div>
					<br class="clear">
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Winners or Claimed tab view.
	 *
	 * @since 1.0.4
	 *
	 * @param string $claimed_filter 'yes' for Claimed tab, 'no' for Winners tab.
	 */
	private function render_winners_view( $claimed_filter ) {
		$is_claimed_tab = 'yes' === $claimed_filter;
		$view_slug      = $is_claimed_tab ? 'claimed' : 'winners';
		$base_url       = admin_url( 'admin.php?page=rfwc-tickets&view=' . $view_slug );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page filter.
		$filter_raffle = isset( $_GET['raffle_id'] ) ? absint( $_GET['raffle_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page search value.
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$query_args = array( 'claimed' => $claimed_filter );
		if ( $filter_raffle ) {
			$query_args['raffle_id'] = $filter_raffle;
		}
		if ( $search_query ) {
			$query_args['search'] = $search_query;
		}

		$winners = RFWC_Winner::get_all( $query_args );
		$raffles = $this->get_raffles_with_winners();

		$export_csv_url = ! empty( $winners ) ? wp_nonce_url(
			add_query_arg(
				array(
					'rfwc_export'   => 1,
					'export_type'   => 'winners',
					'raffle_id'     => $filter_raffle ? $filter_raffle : 'all',
					'claimed'       => $claimed_filter,
					's'             => $search_query,
				),
				$base_url
			),
			'rfwc_export_tickets'
		) : '';
		?>
		<div class="wrap woocommerce rfwc-tickets-wrap">

			<?php if ( $search_query && ! empty( $winners ) ) : ?>
				<span class="subtitle">
					<?php
					printf(
						/* translators: %s: search term */
						esc_html__( 'Search results for: %s', 'raffle-for-woocommerce' ),
						'<strong>' . esc_html( $search_query ) . '</strong>'
					);
					?>
				</span>
			<?php endif; ?>

			<?php if ( ! empty( $raffles ) || $search_query ) : ?>
				<div class="tablenav top">
					<div class="alignleft actions">
						<form method="get" action="">
							<input type="hidden" name="page" value="rfwc-tickets">
							<input type="hidden" name="view" value="<?php echo esc_attr( $view_slug ); ?>">
							<?php if ( $search_query ) : ?>
								<input type="hidden" name="s" value="<?php echo esc_attr( $search_query ); ?>">
							<?php endif; ?>
							<select name="raffle_id">
								<option value=""><?php esc_html_e( 'All raffles', 'raffle-for-woocommerce' ); ?></option>
								<?php $this->render_grouped_raffle_options( $raffles, $filter_raffle, false ); ?>
							</select>
							<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'raffle-for-woocommerce' ); ?>">
						</form>
					</div>
					<?php if ( ! $is_claimed_tab && ! empty( $winners ) ) : ?>
						<div class="alignleft actions rfwc-bulk-actions">
							<button type="button" class="button rfwc-bulk-claim-btn" disabled>
								<?php esc_html_e( 'Mark Selected as Claimed', 'raffle-for-woocommerce' ); ?>
							</button>
						</div>
					<?php endif; ?>
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of items */
								esc_html( _n( '%s item', '%s items', count( $winners ), 'raffle-for-woocommerce' ) ),
								esc_html( number_format_i18n( count( $winners ) ) )
							);
							?>
						</span>
					</div>
					<div class="alignright actions rfwc-tablenav-right">
						<form method="get" action="" class="rfwc-search-form">
							<input type="hidden" name="page" value="rfwc-tickets">
							<input type="hidden" name="view" value="<?php echo esc_attr( $view_slug ); ?>">
							<?php if ( $filter_raffle ) : ?>
								<input type="hidden" name="raffle_id" value="<?php echo esc_attr( $filter_raffle ); ?>">
							<?php endif; ?>
							<label class="screen-reader-text" for="rfwc-winner-search-input"><?php esc_html_e( 'Search winners', 'raffle-for-woocommerce' ); ?></label>
							<input type="search" id="rfwc-winner-search-input" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, or ticket...', 'raffle-for-woocommerce' ); ?>">
							<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'raffle-for-woocommerce' ); ?>">
						</form>
						<?php if ( $export_csv_url ) : ?>
							<a href="<?php echo esc_url( $export_csv_url ); ?>" class="button rfwc-export-csv-btn"><?php esc_html_e( 'Export CSV', 'raffle-for-woocommerce' ); ?></a>
						<?php endif; ?>
					</div>
					<br class="clear">
				</div>
			<?php endif; ?>

			<?php if ( empty( $winners ) ) : ?>
				<div class="woocommerce-BlankState">
					<?php if ( $is_claimed_tab ) : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No claimed prizes yet.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'When you mark a winner\'s prize as claimed, they will appear here.', 'raffle-for-woocommerce' ); ?></p>
					<?php else : ?>
						<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No winners yet.', 'raffle-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Winners will appear here after a raffle draw is completed.', 'raffle-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="rfwc-table-responsive">
				<table class="wp-list-table widefat striped posts rfwc-winners-table">
					<thead>
						<tr>
							<?php if ( ! $is_claimed_tab ) : ?>
								<td class="manage-column column-cb check-column">
									<input type="checkbox" id="rfwc-select-all-winners">
								</td>
							<?php endif; ?>
							<th scope="col" class="manage-column column-ticket"><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-raffle"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-winner"><?php esc_html_e( 'Winner', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-tier"><?php esc_html_e( 'Prize Tier', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-method"><?php esc_html_e( 'Method', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date Won', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody id="the-list">
						<?php
						foreach ( $winners as $winner ) :
							$ticket = $winner->get_ticket();
							if ( ! $ticket ) {
								continue;
							}
							$raffle      = wc_get_product( $winner->get_raffle_id() );
							$raffle_name = $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' );
							$order_id    = $ticket->get_order_id();
							$order_url   = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
							?>
							<tr data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
								<?php if ( ! $is_claimed_tab ) : ?>
									<th scope="row" class="check-column">
										<input type="checkbox" class="rfwc-winner-cb" value="<?php echo esc_attr( $winner->get_id() ); ?>">
									</th>
								<?php endif; ?>
								<td class="column-ticket">
									<strong class="rfwc-ticket-number"><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></strong>
									<div class="row-actions">
										<span class="view-order"><a href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'View Order', 'raffle-for-woocommerce' ); ?></a></span>
									</div>
								</td>
								<td class="column-raffle">
									<?php if ( $raffle ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $raffle->get_id() ) ); ?>"><strong><?php echo esc_html( $raffle_name ); ?></strong></a>
									<?php else : ?>
										<?php echo esc_html( $raffle_name ); ?>
									<?php endif; ?>
								</td>
								<td class="column-winner">
									<strong><?php echo esc_html( $ticket->get_holder_name() ); ?></strong>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( $ticket->get_holder_email() ); ?></span>
								</td>
								<td class="column-tier">
									<span class="rfwc-prize-tier"><?php echo esc_html( $winner->get_prize_tier() ); ?></span>
								</td>
								<td class="column-method">
									<?php if ( 'instant_win' === $winner->get_selection_method() ) : ?>
										<span class="rfwc-method-badge rfwc-method-instant-win">⚡ <?php echo esc_html( $winner->get_selection_method_label() ); ?></span>
									<?php else : ?>
										<span class="rfwc-method-badge rfwc-method-draw"><?php echo esc_html( $winner->get_selection_method_label() ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-status">
									<?php if ( $winner->is_claimed() ) : ?>
										<mark class="order-status status-completed"><span><?php esc_html_e( 'Claimed', 'raffle-for-woocommerce' ); ?></span></mark>
										<?php if ( $winner->get_claimed_at() ) : ?>
											<br><span class="rfwc-text-secondary"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $winner->get_claimed_at() ) ) ); ?></span>
										<?php endif; ?>
									<?php elseif ( $winner->is_notified() ) : ?>
										<mark class="order-status status-processing"><span><?php esc_html_e( 'Notified', 'raffle-for-woocommerce' ); ?></span></mark>
									<?php else : ?>
										<mark class="order-status status-pending"><span><?php esc_html_e( 'Pending', 'raffle-for-woocommerce' ); ?></span></mark>
									<?php endif; ?>
								</td>
								<td class="column-date">
									<?php echo esc_html( wp_date( 'M j, Y', strtotime( $winner->get_created_at() ) ) ); ?>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( wp_date( 'g:i a', strtotime( $winner->get_created_at() ) ) ); ?></span>
								</td>
								<td class="column-actions">
									<?php if ( ! $winner->is_claimed() ) : ?>
										<button type="button" class="button button-small rfwc-claim-btn" data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
											<?php esc_html_e( 'Mark Claimed', 'raffle-for-woocommerce' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small rfwc-resend-btn" data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
										<?php echo $winner->is_notified() ? esc_html__( 'Resend', 'raffle-for-woocommerce' ) : esc_html__( 'Notify', 'raffle-for-woocommerce' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<div class="rfwc-ajax-message rfwc-hidden" style="margin-top:10px;"></div>
		</div>
		<?php
	}

	/**
	 * Get all raffles that have winners.
	 *
	 * @since 1.0.4
	 *
	 * @return array Array of raffle data with id and name.
	 */
	private function get_raffles_with_winners() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			'SELECT DISTINCT raffle_id FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' ORDER BY raffle_id DESC'
		);

		$raffles = array();
		foreach ( $results as $row ) {
			$product = wc_get_product( $row->raffle_id );
			if ( $product ) {
				$raffles[] = array(
					'id'           => $row->raffle_id,
					'name'         => $product->get_name(),
					'status_group' => $this->get_raffle_status_group( $product ),
				);
			}
		}

		return $raffles;
	}

	/**
	 * Determine the status group label for a raffle product.
	 *
	 * @since 1.0.10
	 *
	 * @param WC_Product $product Raffle product.
	 *
	 * @return string Translated status group label.
	 */
	private function get_raffle_status_group( $product ) {
		$status = $product->get_meta( '_rfwc_status', true );

		if ( 'completed' === $status ) {
			return __( 'Completed', 'raffle-for-woocommerce' );
		}
		if ( 'failed' === $status ) {
			return __( 'Failed', 'raffle-for-woocommerce' );
		}
		if ( 'cancelled' === $status ) {
			return __( 'Cancelled', 'raffle-for-woocommerce' );
		}
		if ( method_exists( $product, 'has_ended' ) && $product->has_ended() ) {
			return __( 'Ended', 'raffle-for-woocommerce' );
		}
		if ( method_exists( $product, 'has_started' ) && $product->has_started() ) {
			return __( 'Active', 'raffle-for-woocommerce' );
		}

		return __( 'Upcoming', 'raffle-for-woocommerce' );
	}

	/**
	 * Render raffle dropdown `<option>` elements grouped by status using `<optgroup>`.
	 *
	 * @since 1.0.10
	 *
	 * @param array $raffles    Array of raffle data (id, name, status_group, optional count).
	 * @param int   $selected   Currently selected raffle ID.
	 * @param bool  $show_count Whether to append ticket/winner count to the label.
	 */
	private function render_grouped_raffle_options( $raffles, $selected = 0, $show_count = true ) {
		$group_order = array(
			__( 'Active', 'raffle-for-woocommerce' ),
			__( 'Upcoming', 'raffle-for-woocommerce' ),
			__( 'Ended', 'raffle-for-woocommerce' ),
			__( 'Completed', 'raffle-for-woocommerce' ),
			__( 'Failed', 'raffle-for-woocommerce' ),
			__( 'Cancelled', 'raffle-for-woocommerce' ),
		);

		$groups = array();
		foreach ( $raffles as $raffle ) {
			$group = isset( $raffle['status_group'] ) ? $raffle['status_group'] : __( 'Active', 'raffle-for-woocommerce' );
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array();
			}
			$groups[ $group ][] = $raffle;
		}

		foreach ( $group_order as $label ) {
			if ( empty( $groups[ $label ] ) ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $label ) . '">';
			foreach ( $groups[ $label ] as $raffle ) {
				$display = $raffle['name'];
				if ( $show_count && isset( $raffle['count'] ) ) {
					$display .= ' (' . $raffle['count'] . ')';
				}
				echo '<option value="' . esc_attr( $raffle['id'] ) . '" ' . selected( $selected, $raffle['id'], false ) . '>';
				echo esc_html( $display );
				echo '</option>';
			}
			echo '</optgroup>';
		}
	}

	/**
	 * Get all raffles that have tickets.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of raffle data with id, name, count, and status_group.
	 */
	private function get_raffles_with_tickets() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			'SELECT raffle_id, COUNT(*) as count FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' GROUP BY raffle_id ORDER BY count DESC'
		);

		$raffles = array();
		foreach ( $results as $row ) {
			$product = wc_get_product( $row->raffle_id );
			if ( $product ) {
				$raffles[] = array(
					'id'           => $row->raffle_id,
					'name'         => $product->get_name(),
					'count'        => $row->count,
					'status_group' => $this->get_raffle_status_group( $product ),
				);
			}
		}

		return $raffles;
	}

	/**
	 * Get ticket counts by status.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Optional raffle ID to filter by.
	 *
	 * @return array Associative array of status counts.
	 */
	private function get_status_counts( $raffle_id = 0 ) {
		global $wpdb;

		$raffle_id_clean = absint( $raffle_id );

		if ( $raffle_id_clean ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT status, COUNT(*) as count FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE raffle_id = %d GROUP BY status',
					$raffle_id_clean
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results(
				'SELECT status, COUNT(*) as count FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' GROUP BY status'
			);
		}

		$counts = array(
			'all'       => 0,
			'active'    => 0,
			'cancelled' => 0,
			'winner'    => 0,
			'expired'   => 0,
			'refunded'  => 0,
		);

		foreach ( $results as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->count;
			}
			$counts['all'] += (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Get tickets with optional filters.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID to filter by (0 for all).
	 * @param string $status    Status to filter by (empty for all).
	 * @param string $search    Search term to filter by (empty for all).
	 * @param int    $limit     Maximum rows to return. 0 for unlimited.
	 *
	 * @return RFWC_Ticket[] Array of ticket objects.
	 */
	private function get_filtered_tickets( $raffle_id, $status, $search = '', $limit = 200 ) {
		global $wpdb;

		$table_name      = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$raffle_id_clean = absint( $raffle_id );
		$status_clean    = sanitize_text_field( $status );
		$search_clean    = sanitize_text_field( $search );

		$where  = array();
		$values = array();

		if ( $raffle_id_clean ) {
			$where[]  = 'raffle_id = %d';
			$values[] = $raffle_id_clean;
		}

		if ( $status_clean ) {
			$where[]  = 'status = %s';
			$values[] = $status_clean;
		}

		if ( $search_clean ) {
			$search_like = '%' . $wpdb->esc_like( $search_clean ) . '%';

			// The ticket_number column stores the raw number (e.g. "0001") without
			// the prefix. Users search using the formatted number (e.g. "TLD0001").
			// Build additional search terms by stripping known prefixes.
			$stripped_terms = $this->get_stripped_search_terms( $search_clean );

			$search_conditions = array(
				'ticket_number LIKE %s',
				'recipient_name LIKE %s',
				'recipient_email LIKE %s',
				'purchaser_name LIKE %s',
				'purchaser_email LIKE %s',
			);
			$search_values     = array(
				$search_like,
				$search_like,
				$search_like,
				$search_like,
				$search_like,
			);

			// Add stripped prefix variants for ticket_number matching.
			foreach ( $stripped_terms as $stripped ) {
				$search_conditions[] = 'ticket_number LIKE %s';
				$search_values[]     = '%' . $wpdb->esc_like( $stripped ) . '%';
			}

			$where[] = '(' . implode( ' OR ', $search_conditions ) . ')';
			$values  = array_merge( $values, $search_values );
		}

		$sql = 'SELECT * FROM ' . $table_name;

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY created_at DESC';

		if ( $limit > 0 ) {
			$sql    .= ' LIMIT %d';
			$values[] = absint( $limit );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic SQL is built from sanitized/escaped parts above; table name via esc_sql(), values via $wpdb->prepare().
		if ( ! empty( $values ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		} else {
			$rows = $wpdb->get_results( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$tickets = array();
		foreach ( $rows as $row ) {
			$tickets[] = new RFWC_Ticket( $row );
		}

		return $tickets;
	}

	/**
	 * Tabs (view slugs) that render WooCommerce-style enhanced selects.
	 *
	 * On these tabs we explicitly load WooCommerce's wc-enhanced-select
	 * assets so the dropdowns get SelectWoo styling. Other tabs (All
	 * Tickets, Winners, Claimed, Mass Refund) don't need the extra
	 * payload, so we leave them alone to preserve their original look.
	 *
	 * @since 1.1.14
	 *
	 * @var string[]
	 */
	private $views_needing_enhanced_select = array(
		'volunteers',
		'manual-sale',
		'free-entries',
		'master-draws',
	);

	/**
	 * Enqueue scripts for the Raffle Tickets page.
	 *
	 * @since 1.0.4
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_rfwc-tickets' !== $hook ) {
			return;
		}

		// Load CSS on every tab.
		wp_enqueue_style(
			'rfwc-admin-tickets',
			RFWC_PLUGIN_URL . 'assets/css/admin-tickets.css',
			array(),
			RFWC_VERSION
		);

		wp_enqueue_script(
			'rfwc-admin-tickets',
			RFWC_PLUGIN_URL . 'assets/js/admin-tickets.js',
			array( 'jquery' ),
			RFWC_VERSION,
			true
		);

		// Only load SelectWoo on the tabs that actually use it so the
		// rest of the page (All Tickets, Winners, etc.) keeps its
		// original styling.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag.
		$current_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		if ( in_array( $current_view, $this->views_needing_enhanced_select, true ) ) {
			// WC registers wc-enhanced-select on admin_init, which runs
			// before admin_enqueue_scripts, so we can enqueue it here
			// without checking is_registered.
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_localize_script(
			'rfwc-admin-tickets',
			'rfwcTickets',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'rfwc_tickets_nonce' ),
				'confirmClaim'           => __( 'Mark this winner\'s prize as claimed?', 'raffle-for-woocommerce' ),
				'confirmBulkClaim'       => __( 'Mark all selected winners as claimed?', 'raffle-for-woocommerce' ),
				'sending'                => __( 'Sending...', 'raffle-for-woocommerce' ),
				'sent'                   => __( 'Sent!', 'raffle-for-woocommerce' ),
				'resend'                 => __( 'Resend', 'raffle-for-woocommerce' ),
				'selectAtLeastOne'       => __( 'Please select at least one winner.', 'raffle-for-woocommerce' ),
				'selectAtLeastOneTicket' => __( 'Please select at least one ticket.', 'raffle-for-woocommerce' ),
				'refundLoading'          => __( 'Loading refund details...', 'raffle-for-woocommerce' ),
				'refundProcessing'       => __( 'Processing refunds...', 'raffle-for-woocommerce' ),
				'refundComplete'         => __( 'Refund complete!', 'raffle-for-woocommerce' ),
				'refundOf'               => __( 'of', 'raffle-for-woocommerce' ),
				'refundOrders'           => __( 'orders processed', 'raffle-for-woocommerce' ),
				'orderEditUrl'           => admin_url( 'admin.php?page=wc-orders&action=edit&id=' ),
				'manualRefundNotice'     => __( 'The following orders require manual refund. The payment was collected offline or the gateway does not support automated refunds:', 'raffle-for-woocommerce' ),
				'copiedToClipboard'      => __( 'Copied to clipboard!', 'raffle-for-woocommerce' ),
				'copyManualRefunds'      => __( 'Copy List', 'raffle-for-woocommerce' ),
				'confirmMarkDone'        => __( 'Confirm: You have manually sent the refund for this order?', 'raffle-for-woocommerce' ),
				'processing'             => __( 'Processing...', 'raffle-for-woocommerce' ),
				'markDoneLabel'          => __( 'Mark Done', 'raffle-for-woocommerce' ),
				'manualDoneLabel'        => __( 'Manual - Done', 'raffle-for-woocommerce' ),
				'walletActive'           => function_exists( 'woo_wallet' ),
				'walletRefundNotice'     => __( 'The following orders were refunded to the customer\'s wallet:', 'raffle-for-woocommerce' ),
				'walletGuestFallback'    => __( 'Guest orders without a user account will receive standard refund records instead of wallet credit.', 'raffle-for-woocommerce' ),
			)
		);
	}

	/**
	 * AJAX: Mark a single winner as claimed.
	 *
	 * @since 1.0.4
	 */
	public function ajax_mark_claimed() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$winner_id = isset( $_POST['winner_id'] ) ? absint( $_POST['winner_id'] ) : 0;
		if ( ! $winner_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid winner ID.', 'raffle-for-woocommerce' ) ) );
		}

		$winner = new RFWC_Winner( $winner_id );
		if ( ! $winner->get_id() ) {
			wp_send_json_error( array( 'message' => __( 'Winner not found.', 'raffle-for-woocommerce' ) ) );
		}

		$winner->set_claimed( true );
		$winner->save();

		$ticket = $winner->get_ticket();

		// Fire email notification action.
		if ( $ticket && function_exists( 'WC' ) && WC()->mailer() ) {
			do_action( 'rfwc_prize_claimed', $ticket, $winner->get_raffle_id() );
		}

		$this->log_activity(
			$winner->get_raffle_id(),
			'prize_claimed',
			sprintf(
				/* translators: %1$s: ticket number, %2$s: holder name */
				__( 'Prize marked as claimed for ticket %1$s (%2$s).', 'raffle-for-woocommerce' ),
				$ticket ? $ticket->get_formatted_ticket_number() : $winner_id,
				$ticket ? $ticket->get_holder_name() : ''
			)
		);

		wp_send_json_success( array( 'message' => __( 'Prize marked as claimed.', 'raffle-for-woocommerce' ) ) );
	}

	/**
	 * AJAX: Bulk mark winners as claimed.
	 *
	 * @since 1.0.4
	 */
	public function ajax_bulk_claim() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$winner_ids = isset( $_POST['winner_ids'] ) ? array_map( 'absint', (array) $_POST['winner_ids'] ) : array();
		if ( empty( $winner_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No winners selected.', 'raffle-for-woocommerce' ) ) );
		}

		$count = 0;
		foreach ( $winner_ids as $wid ) {
			$winner = new RFWC_Winner( $wid );
			if ( $winner->get_id() && ! $winner->is_claimed() ) {
				$winner->set_claimed( true );
				$winner->save();

				$ticket = $winner->get_ticket();

				// Fire email notification action.
				if ( $ticket && function_exists( 'WC' ) && WC()->mailer() ) {
					do_action( 'rfwc_prize_claimed', $ticket, $winner->get_raffle_id() );
				}

				$this->log_activity(
					$winner->get_raffle_id(),
					'prize_claimed',
					sprintf(
						/* translators: %1$s: ticket number, %2$s: holder name */
						__( 'Prize marked as claimed for ticket %1$s (%2$s).', 'raffle-for-woocommerce' ),
						$ticket ? $ticket->get_formatted_ticket_number() : $wid,
						$ticket ? $ticket->get_holder_name() : ''
					)
				);

				++$count;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of winners marked as claimed */
					_n( '%d winner marked as claimed.', '%d winners marked as claimed.', $count, 'raffle-for-woocommerce' ),
					$count
				),
			)
		);
	}

	/**
	 * AJAX: Resend winner notification from the Tickets page.
	 *
	 * @since 1.0.4
	 */
	public function ajax_resend_notification() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$winner_id = isset( $_POST['winner_id'] ) ? absint( $_POST['winner_id'] ) : 0;
		if ( ! $winner_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid winner ID.', 'raffle-for-woocommerce' ) ) );
		}

		$winner = new RFWC_Winner( $winner_id );
		if ( ! $winner->get_id() ) {
			wp_send_json_error( array( 'message' => __( 'Winner not found.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket = $winner->get_ticket();
		if ( ! $ticket ) {
			wp_send_json_error( array( 'message' => __( 'Ticket not found.', 'raffle-for-woocommerce' ) ) );
		}

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			do_action( 'rfwc_resend_winner_notification', $ticket, $winner->get_raffle_id() );
		}

		$winner->set_notified( true );
		$winner->save();

		$this->log_activity(
			$winner->get_raffle_id(),
			'notification_resent',
			sprintf(
				/* translators: %1$s: ticket number, %2$s: holder email */
				__( 'Winner notification resent for ticket %1$s to %2$s.', 'raffle-for-woocommerce' ),
				$ticket->get_formatted_ticket_number(),
				$ticket->get_holder_email()
			)
		);

		wp_send_json_success( array( 'message' => __( 'Notification sent!', 'raffle-for-woocommerce' ) ) );
	}

	/**
	 * Get the total count of refunded tickets across all raffles.
	 *
	 * @since 1.0.8
	 *
	 * @return int Refunded ticket count.
	 */
	private function get_refunded_ticket_count() {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'refunded'" );
	}

	/**
	 * Render the Refunded tab view.
	 *
	 * Shows refunded tickets and provides the mass refund action for failed raffles.
	 *
	 * @since 1.0.8
	 */
	private function render_refunded_view() {
		$failed_raffles = $this->get_failed_raffles_with_active_tickets();
		$base_url       = admin_url( 'admin.php?page=rfwc-tickets&view=refunded' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page filter.
		$filter_raffle = isset( $_GET['raffle_id'] ) ? absint( $_GET['raffle_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page filter.
		$filter_refund_method = isset( $_GET['refund_method'] ) ? sanitize_text_field( wp_unslash( $_GET['refund_method'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page search value.
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$refunded_tickets = $this->get_filtered_tickets( $filter_raffle, 'refunded', $search_query );

		if ( in_array( $filter_refund_method, array( 'gateway', 'wallet', 'manual', 'manual_done' ), true ) ) {
			$refunded_tickets = array_filter(
				$refunded_tickets,
				function ( $ticket ) use ( $filter_refund_method ) {
					$order  = wc_get_order( $ticket->get_order_id() );
					$method = $order ? $order->get_meta( '_rfwc_refund_method', true ) : '';
					return $method === $filter_refund_method;
				}
			);
		}

		$raffles_with_refunds = $this->get_raffles_with_refunded_tickets();

		$export_csv_url = ! empty( $refunded_tickets ) ? wp_nonce_url(
			add_query_arg(
				array(
					'rfwc_export'   => 1,
					'raffle_id'     => $filter_raffle ? $filter_raffle : 'all',
					'status'        => 'refunded',
					's'             => $search_query,
				),
				$base_url
			),
			'rfwc_export_tickets'
		) : '';
		?>
		<div class="wrap woocommerce rfwc-tickets-wrap">

			<?php if ( ! empty( $failed_raffles ) ) : ?>
				<div class="rfwc-mass-refund-box">
				<h3><?php esc_html_e( 'Mass Refund for Failed / Cancelled Raffles', 'raffle-for-woocommerce' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Select a failed or cancelled raffle to refund all ticket purchases. This will issue WooCommerce refunds for each order and mark tickets as refunded.', 'raffle-for-woocommerce' ); ?></p>
					<div class="rfwc-mass-refund-controls">
						<select id="rfwc-mass-refund-raffle">
							<option value=""><?php esc_html_e( 'Select a raffle...', 'raffle-for-woocommerce' ); ?></option>
							<?php foreach ( $failed_raffles as $raffle ) : ?>
								<option value="<?php echo esc_attr( $raffle['id'] ); ?>">
									<?php echo esc_html( $raffle['name'] ); ?> (<?php echo esc_html( $raffle['ticket_count'] ); ?> <?php esc_html_e( 'tickets', 'raffle-for-woocommerce' ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="rfwc-mass-refund-btn" disabled>
							<?php esc_html_e( 'Refund All Tickets', 'raffle-for-woocommerce' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<?php // Confirmation modal (hidden, shown via JS). ?>
			<div id="rfwc-refund-modal" class="rfwc-modal" style="display:none;">
				<div class="rfwc-modal-backdrop"></div>
				<div class="rfwc-modal-content">
					<div class="rfwc-modal-header">
						<h2><?php esc_html_e( 'Confirm Mass Refund', 'raffle-for-woocommerce' ); ?></h2>
						<button type="button" class="rfwc-modal-close" aria-label="<?php esc_attr_e( 'Close', 'raffle-for-woocommerce' ); ?>">&times;</button>
					</div>
					<div class="rfwc-modal-body">
						<div class="rfwc-refund-summary" id="rfwc-refund-summary">
							<p class="rfwc-refund-loading"><?php esc_html_e( 'Loading refund details...', 'raffle-for-woocommerce' ); ?></p>
						</div>
						<div class="rfwc-refund-mode" id="rfwc-refund-mode" style="display:none; margin: 12px 0; padding: 10px 12px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
							<fieldset style="margin:0; padding:0; border:0;">
								<legend style="font-weight:600; margin-bottom:8px;"><?php esc_html_e( 'Refund method', 'raffle-for-woocommerce' ); ?></legend>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; margin-bottom:10px;">
									<input type="radio" name="rfwc-refund-method" value="standard" checked style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Standard refund', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'Creates WooCommerce refund records only. You will need to return funds manually for offline payment methods.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; margin-bottom:10px;">
									<input type="radio" name="rfwc-refund-method" value="gateway" style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Refund via payment gateway', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'For orders paid via Stripe, PayPal, etc., money returns to the customer automatically. Offline payment methods (bank transfer, COD, check) will fall back to standard refund records.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<?php if ( function_exists( 'woo_wallet' ) ) : ?>
								<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
									<input type="radio" name="rfwc-refund-method" value="wallet" style="margin-top:3px;">
									<span>
										<strong><?php esc_html_e( 'Refund to wallet', 'raffle-for-woocommerce' ); ?></strong><br>
										<span class="description" style="font-size:12px; color:#666;">
											<?php esc_html_e( 'Credits the refund amount to each customer\'s wallet (TeraWallet). Works for all payment methods. Guest orders will fall back to standard refund.', 'raffle-for-woocommerce' ); ?>
										</span>
									</span>
								</label>
								<?php endif; ?>
							</fieldset>
						</div>
						<div class="rfwc-refund-progress" id="rfwc-refund-progress" style="display:none;">
							<p class="rfwc-refund-progress-text" id="rfwc-refund-progress-text"></p>
							<div class="rfwc-refund-progress-bar-wrap">
								<div class="rfwc-refund-progress-bar" id="rfwc-refund-progress-bar" style="width:0%;"></div>
							</div>
						</div>
						<div class="rfwc-refund-result" id="rfwc-refund-result" style="display:none;"></div>
					</div>
					<div class="rfwc-modal-footer" id="rfwc-modal-footer">
						<button type="button" class="button" id="rfwc-refund-cancel"><?php esc_html_e( 'Cancel', 'raffle-for-woocommerce' ); ?></button>
						<button type="button" class="button button-primary" id="rfwc-refund-confirm" disabled>
							<?php esc_html_e( 'Confirm Refund', 'raffle-for-woocommerce' ); ?>
						</button>
					</div>
				</div>
			</div>

			<?php if ( $search_query && ! empty( $refunded_tickets ) ) : ?>
				<span class="subtitle">
					<?php
					printf(
						/* translators: %s: search term */
						esc_html__( 'Search results for: %s', 'raffle-for-woocommerce' ),
						'<strong>' . esc_html( $search_query ) . '</strong>'
					);
					?>
				</span>
			<?php endif; ?>

			<?php if ( ! empty( $raffles_with_refunds ) || $search_query ) : ?>
				<div class="tablenav top">
					<div class="alignleft actions">
						<form method="get" action="">
							<input type="hidden" name="page" value="rfwc-tickets">
							<input type="hidden" name="view" value="refunded">
							<?php if ( $search_query ) : ?>
								<input type="hidden" name="s" value="<?php echo esc_attr( $search_query ); ?>">
							<?php endif; ?>
							<select name="raffle_id">
								<option value=""><?php esc_html_e( 'All raffles', 'raffle-for-woocommerce' ); ?></option>
								<?php $this->render_grouped_raffle_options( $raffles_with_refunds, $filter_raffle ); ?>
							</select>
							<select name="refund_method">
								<option value=""><?php esc_html_e( 'All refund types', 'raffle-for-woocommerce' ); ?></option>
								<option value="gateway" <?php selected( $filter_refund_method, 'gateway' ); ?>><?php esc_html_e( 'Gateway Refunded', 'raffle-for-woocommerce' ); ?></option>
								<?php if ( function_exists( 'woo_wallet' ) ) : ?>
								<option value="wallet" <?php selected( $filter_refund_method, 'wallet' ); ?>><?php esc_html_e( 'Wallet Refunded', 'raffle-for-woocommerce' ); ?></option>
								<?php endif; ?>
								<option value="manual" <?php selected( $filter_refund_method, 'manual' ); ?>><?php esc_html_e( 'Manual - Action Required', 'raffle-for-woocommerce' ); ?></option>
								<option value="manual_done" <?php selected( $filter_refund_method, 'manual_done' ); ?>><?php esc_html_e( 'Manual - Done', 'raffle-for-woocommerce' ); ?></option>
							</select>
							<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'raffle-for-woocommerce' ); ?>">
						</form>
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of items */
								esc_html( _n( '%s item', '%s items', count( $refunded_tickets ), 'raffle-for-woocommerce' ) ),
								esc_html( number_format_i18n( count( $refunded_tickets ) ) )
							);
							?>
						</span>
					</div>
					<div class="alignright actions rfwc-tablenav-right">
						<form method="get" action="" class="rfwc-search-form">
							<input type="hidden" name="page" value="rfwc-tickets">
							<input type="hidden" name="view" value="refunded">
							<?php if ( $filter_raffle ) : ?>
								<input type="hidden" name="raffle_id" value="<?php echo esc_attr( $filter_raffle ); ?>">
							<?php endif; ?>
							<?php if ( $filter_refund_method ) : ?>
								<input type="hidden" name="refund_method" value="<?php echo esc_attr( $filter_refund_method ); ?>">
							<?php endif; ?>
							<label class="screen-reader-text" for="rfwc-refund-search-input"><?php esc_html_e( 'Search refunded tickets', 'raffle-for-woocommerce' ); ?></label>
							<input type="search" id="rfwc-refund-search-input" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search by ticket, name, or email...', 'raffle-for-woocommerce' ); ?>">
							<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'raffle-for-woocommerce' ); ?>">
						</form>
						<?php if ( $export_csv_url ) : ?>
							<a href="<?php echo esc_url( $export_csv_url ); ?>" class="button rfwc-export-csv-btn"><?php esc_html_e( 'Export CSV', 'raffle-for-woocommerce' ); ?></a>
						<?php endif; ?>
					</div>
					<br class="clear">
				</div>
			<?php endif; ?>

			<?php if ( empty( $refunded_tickets ) && empty( $failed_raffles ) ) : ?>
				<div class="woocommerce-BlankState">
					<h2 class="woocommerce-BlankState-message"><?php esc_html_e( 'No refunded tickets.', 'raffle-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'When a raffle fails and you process a mass refund, refunded tickets will appear here.', 'raffle-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( ! empty( $refunded_tickets ) ) : ?>
				<table class="wp-list-table widefat fixed striped posts rfwc-tickets-table">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-ticket"><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-raffle"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-holder"><?php esc_html_e( 'Ticket Holder', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-order"><?php esc_html_e( 'Order', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-payment"><?php esc_html_e( 'Payment', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-refund-status"><?php esc_html_e( 'Refund Status', 'raffle-for-woocommerce' ); ?></th>
							<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody id="the-list">
						<?php
						$order_cache = array();
						foreach ( $refunded_tickets as $ticket ) :
							$raffle      = wc_get_product( $ticket->get_raffle_id() );
							$raffle_name = $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' );
							$order_id    = $ticket->get_order_id();
							$order_url   = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );

							if ( ! isset( $order_cache[ $order_id ] ) ) {
								$order_obj                = wc_get_order( $order_id );
								$order_cache[ $order_id ] = array(
									'refund_method'        => $order_obj ? $order_obj->get_meta( '_rfwc_refund_method', true ) : '',
									'payment_method_title' => $order_obj ? $order_obj->get_payment_method_title() : '-',
								);
							}

							$refund_method        = $order_cache[ $order_id ]['refund_method'];
							$payment_method_title = $order_cache[ $order_id ]['payment_method_title'];
							?>
							<tr>
								<td class="column-ticket">
									<strong class="rfwc-ticket-number"><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></strong>
									<div class="row-actions">
										<span class="view-order"><a href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'View Order', 'raffle-for-woocommerce' ); ?></a></span>
									</div>
								</td>
								<td class="column-raffle">
									<?php if ( $raffle ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $raffle->get_id() ) ); ?>"><strong><?php echo esc_html( $raffle_name ); ?></strong></a>
									<?php else : ?>
										<?php echo esc_html( $raffle_name ); ?>
									<?php endif; ?>
								</td>
								<td class="column-holder">
									<strong><?php echo esc_html( $ticket->get_holder_name() ); ?></strong>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( $ticket->get_holder_email() ); ?></span>
								</td>
								<td class="column-order">
									<a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $order_id ); ?></a>
								</td>
								<td class="column-payment">
									<?php echo esc_html( $payment_method_title ); ?>
								</td>
								<td class="column-refund-status">
								<?php if ( 'gateway' === $refund_method ) : ?>
									<span class="rfwc-refund-badge rfwc-refund-gateway"><?php esc_html_e( 'Gateway Refunded', 'raffle-for-woocommerce' ); ?></span>
								<?php elseif ( 'wallet' === $refund_method ) : ?>
									<span class="rfwc-refund-badge rfwc-refund-wallet"><?php esc_html_e( 'Wallet Refunded', 'raffle-for-woocommerce' ); ?></span>
								<?php elseif ( 'manual' === $refund_method ) : ?>
									<span class="rfwc-refund-badge rfwc-refund-manual"><?php esc_html_e( 'Manual - Action Required', 'raffle-for-woocommerce' ); ?></span>
									<button type="button" class="button button-small rfwc-mark-refund-done" data-order-id="<?php echo esc_attr( $order_id ); ?>" style="margin-left:6px; vertical-align:middle;">
										<?php esc_html_e( 'Mark Done', 'raffle-for-woocommerce' ); ?>
									</button>
								<?php elseif ( 'manual_done' === $refund_method ) : ?>
									<span class="rfwc-refund-badge rfwc-refund-manual-done"><?php esc_html_e( 'Manual - Done', 'raffle-for-woocommerce' ); ?></span>
									<?php else : ?>
										<span class="rfwc-refund-badge rfwc-refund-unknown"><?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="column-date">
									<?php echo esc_html( wp_date( 'M j, Y', strtotime( $ticket->get_created_at() ) ) ); ?>
									<br><span class="rfwc-text-secondary"><?php echo esc_html( wp_date( 'g:i a', strtotime( $ticket->get_created_at() ) ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get failed raffles that still have active tickets (eligible for mass refund).
	 *
	 * @since 1.0.8
	 *
	 * @return array Array of raffle data with id, name, ticket_count.
	 */
	private function get_failed_raffles_with_active_tickets() {
		$raffle_products = wc_get_products(
			array(
				'type'   => 'raffle',
				'limit'  => -1,
				'return' => 'objects',
				'status' => 'publish',
			)
		);

		$failed = array();

		foreach ( $raffle_products as $product ) {
			$status = $product->get_meta( '_rfwc_status', true );

			if ( ! in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
				continue;
			}

			$active_count = RFWC_Ticket::count_by_raffle( $product->get_id(), 'active' );

			if ( $active_count > 0 ) {
				$failed[] = array(
					'id'           => $product->get_id(),
					'name'         => $product->get_name(),
					'ticket_count' => $active_count,
				);
			}
		}

		return $failed;
	}

	/**
	 * Get raffles that have refunded tickets (for filter dropdown).
	 *
	 * @since 1.0.8
	 *
	 * @return array Array of raffle data with id, name, count.
	 */
	private function get_raffles_with_refunded_tickets() {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT raffle_id, COUNT(*) as count FROM {$table} WHERE status = 'refunded' GROUP BY raffle_id ORDER BY count DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$raffles = array();
		foreach ( $results as $row ) {
			$product = wc_get_product( $row->raffle_id );
			if ( $product ) {
				$raffles[] = array(
					'id'           => $row->raffle_id,
					'name'         => $product->get_name(),
					'count'        => $row->count,
					'status_group' => $this->get_raffle_status_group( $product ),
				);
			}
		}

		return $raffles;
	}

	/**
	 * AJAX: Get refund summary info for a failed raffle.
	 *
	 * Returns order count, ticket count, and total refund amount.
	 *
	 * @since 1.0.8
	 */
	public function ajax_mass_refund_info() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		if ( ! $raffle_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid raffle ID.', 'raffle-for-woocommerce' ) ) );
		}

		$product = wc_get_product( $raffle_id );
		if ( ! $product || 'raffle' !== $product->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Raffle product not found.', 'raffle-for-woocommerce' ) ) );
		}

		$status = $product->get_meta( '_rfwc_status', true );
		if ( ! in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only failed or cancelled raffles can be mass-refunded.', 'raffle-for-woocommerce' ) ) );
		}

		$order_ids    = RFWC_Ticket::get_order_ids_for_raffle( $raffle_id );
		$ticket_count = RFWC_Ticket::count_by_raffle( $raffle_id, 'active' );

		$total_amount = 0;
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $raffle_id ) {
					$total_amount += (float) $item->get_total() + (float) $item->get_total_tax();
				}
			}
		}

		wp_send_json_success(
			array(
				'raffle_name'  => $product->get_name(),
				'order_count'  => count( $order_ids ),
				'ticket_count' => $ticket_count,
				'total_amount' => wc_price( $total_amount ),
				'order_ids'    => $order_ids,
			)
		);
	}

	/**
	 * AJAX: Process a batch of refunds for a failed raffle.
	 *
	 * Processes one order at a time to avoid timeouts.
	 *
	 * @since 1.0.8
	 */
	public function ajax_mass_refund_process() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$try_gateway      = ! empty( $_POST['try_gateway'] );
		$refund_to_wallet = ! empty( $_POST['refund_to_wallet'] );

		if ( ! $raffle_id || ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing raffle or order ID.', 'raffle-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			/* translators: %d: order ID */
			wp_send_json_error( array( 'message' => sprintf( __( 'Order #%d not found.', 'raffle-for-woocommerce' ), $order_id ) ) );
		}

		// Find the raffle line item(s) in this order.
		$refund_items  = array();
		$refund_amount = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== $raffle_id ) {
				continue;
			}

			$line_total = (float) $item->get_total();
			$line_tax   = (float) $item->get_total_tax();

			$refund_items[ $item_id ] = array(
				'qty'          => $item->get_quantity(),
				'refund_total' => $line_total,
				'refund_tax'   => array(),
			);

			// Build tax refund data.
			$taxes = $item->get_taxes();
			if ( ! empty( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $tax_id => $tax_total ) {
					$refund_items[ $item_id ]['refund_tax'][ $tax_id ] = (float) $tax_total;
				}
			}

			$refund_amount += $line_total + $line_tax;
		}

		if ( empty( $refund_items ) ) {
			// No raffle items in this order — just mark tickets as refunded.
			RFWC_Ticket::update_status_by_raffle_order( $raffle_id, $order_id, 'refunded', 'active' );
			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'status'   => 'skipped',
					/* translators: %d: order ID */
					'message'  => sprintf( __( 'Order #%d: no raffle line items found. Tickets marked as refunded.', 'raffle-for-woocommerce' ), $order_id ),
				)
			);
			return;
		}

		// Mark tickets as refunded BEFORE calling wc_create_refund() so that a
		// full-order refund's synchronous status transition to "refunded" does not
		// set them to "cancelled" via cancel_tickets_for_order().
		$updated = RFWC_Ticket::update_status_by_raffle_order( $raffle_id, $order_id, 'refunded', 'active' );

		// Build the refund reason with the raffle status.
		$raffle_status = get_post_meta( $raffle_id, '_rfwc_status', true );
		$refund_reason = sprintf(
			/* translators: %1$s: raffle product name, %2$s: raffle status */
			__( 'Mass refund: raffle "%1$s" %2$s.', 'raffle-for-woocommerce' ),
			get_the_title( $raffle_id ),
			$raffle_status ? $raffle_status : __( 'ended', 'raffle-for-woocommerce' )
		);

		$result = $this->create_smart_refund(
			array(
				'amount'            => $refund_amount,
				'reason'            => $refund_reason,
				'order_id'          => $order_id,
				'line_items'        => $refund_items,
				'try_gateway'       => $try_gateway,
				'refund_to_wallet'  => $refund_to_wallet,
			)
		);

		$refund         = $result['refund'];
		$refund_method  = $result['method'];
		$refund_gateway = $result['gateway'];

		if ( is_wp_error( $refund ) ) {
			// Refund failed — revert tickets back to active.
			RFWC_Ticket::update_status_by_raffle_order( $raffle_id, $order_id, 'active', 'refunded' );

			wp_send_json_error(
				array(
					'order_id' => $order_id,
					'message'  => sprintf(
						/* translators: 1: order ID, 2: error message */
						__( 'Order #%1$d refund failed: %2$s', 'raffle-for-woocommerce' ),
						$order_id,
						$refund->get_error_message()
					),
				)
			);
		}

		$method_label = $this->get_refund_method_label( $refund_method, $refund_gateway );

		$order->update_meta_data( '_rfwc_refund_method', $refund_method );
		$order->save();

		$this->fire_refund_emails( $raffle_id, $order_id, $refund_amount, $raffle_status );

		$this->log_activity(
			$raffle_id,
			'mass_refund',
			sprintf(
				/* translators: 1: order ID, 2: refund amount, 3: ticket count, 4: refund method */
				__( 'Mass refund: Order #%1$d refunded %2$s (%3$d tickets, %4$s).', 'raffle-for-woocommerce' ),
				$order_id,
				wp_strip_all_tags( wc_price( $refund_amount ) ),
				$updated,
				$method_label
			)
		);

		wp_send_json_success(
			array(
				'order_id'             => $order_id,
				'status'               => 'refunded',
				'amount'               => wc_price( $refund_amount ),
				'amount_raw'           => $refund_amount,
				'tickets'              => $updated,
				'refund_method'        => $refund_method,
				'refund_gateway'       => $refund_gateway,
				'payment_method_title' => $order->get_payment_method_title(),
				'message'              => sprintf(
					/* translators: 1: order ID, 2: refund amount, 3: refund method */
					__( 'Order #%1$d: refunded %2$s (%3$s).', 'raffle-for-woocommerce' ),
					$order_id,
					wc_price( $refund_amount ),
					$method_label
				),
			)
		);
	}

	/**
	 * AJAX: Finalize mass refund — fires the summary email action.
	 *
	 * Called once by JS after all per-order refund calls complete.
	 * Aggregates the client-side tallies and fires `rfwc_mass_refund_completed`
	 * so the admin summary email (and any other listeners) can trigger.
	 *
	 * @since 1.1.0
	 */
	public function ajax_mass_refund_complete() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;

		if ( ! $raffle_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing raffle ID.', 'raffle-for-woocommerce' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$summary = array(
			'orders_refunded' => isset( $_POST['orders_refunded'] ) ? absint( $_POST['orders_refunded'] ) : 0,
			'orders_failed'   => isset( $_POST['orders_failed'] ) ? absint( $_POST['orders_failed'] ) : 0,
			'total_amount'    => isset( $_POST['total_amount'] ) ? (float) $_POST['total_amount'] : 0,
			'failures'        => isset( $_POST['failures'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['failures'] ) ) : array(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/**
		 * Fires after all mass refund orders have been processed.
		 *
		 * @since 1.1.0
		 *
		 * @param int   $raffle_id Raffle product ID.
		 * @param array $summary   { orders_refunded, orders_failed, total_amount, failures }.
		 */
		do_action( 'rfwc_mass_refund_completed', $raffle_id, $summary );

		$this->log_activity(
			$raffle_id,
			'mass_refund_complete',
			sprintf(
				/* translators: 1: orders refunded count, 2: orders failed count, 3: total refund amount */
				__( 'Mass refund completed: %1$d orders refunded, %2$d failed. Total: %3$s.', 'raffle-for-woocommerce' ),
				$summary['orders_refunded'],
				$summary['orders_failed'],
				wp_strip_all_tags( wc_price( $summary['total_amount'] ) )
			)
		);

		wp_send_json_success( array( 'message' => __( 'Mass refund finalized.', 'raffle-for-woocommerce' ) ) );
	}

	/**
	 * AJAX: Get refund summary for selected tickets.
	 *
	 * Groups selected tickets by order and calculates per-ticket refund amounts.
	 *
	 * @since 1.0.8
	 */
	public function ajax_selective_refund_info() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket_ids = isset( $_POST['ticket_ids'] ) ? array_map( 'absint', (array) $_POST['ticket_ids'] ) : array();
		if ( empty( $ticket_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No tickets selected.', 'raffle-for-woocommerce' ) ) );
		}

		global $wpdb;
		$table        = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$tickets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, raffle_id, order_id FROM {$table} WHERE id IN ({$placeholders}) AND status = 'active'",
				...$ticket_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $tickets ) ) {
			wp_send_json_error( array( 'message' => __( 'No active tickets found among the selected.', 'raffle-for-woocommerce' ) ) );
		}

		// Group tickets by order to calculate refund amounts.
		$orders_map   = array();
		$total_amount = 0;

		foreach ( $tickets as $ticket ) {
			$order_id  = (int) $ticket->order_id;
			$raffle_id = (int) $ticket->raffle_id;

			if ( ! isset( $orders_map[ $order_id ] ) ) {
				$orders_map[ $order_id ] = array(
					'raffle_ids' => array(),
					'ticket_ids' => array(),
				);
			}

			$orders_map[ $order_id ]['ticket_ids'][] = (int) $ticket->id;

			if ( ! in_array( $raffle_id, $orders_map[ $order_id ]['raffle_ids'], true ) ) {
				$orders_map[ $order_id ]['raffle_ids'][] = $raffle_id;
			}
		}

		// Calculate refund amounts per order from actual line item data.
		foreach ( $orders_map as $order_id => $data ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$product_id = (int) $item->get_product_id();
				if ( ! in_array( $product_id, $data['raffle_ids'], true ) ) {
					continue;
				}

				$tickets_for_this = count(
					array_filter(
						$tickets,
						function ( $t ) use ( $order_id, $product_id ) {
							return (int) $t->order_id === $order_id && (int) $t->raffle_id === $product_id;
						}
					)
				);

				$item_qty       = $item->get_quantity();
				$price_per_unit = $item_qty > 0 ? ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $item_qty : 0;
				$total_amount  += round( $price_per_unit * $tickets_for_this, 2 );
			}
		}

		wp_send_json_success(
			array(
				'ticket_count' => count( $tickets ),
				'order_count'  => count( $orders_map ),
				'total_amount' => wc_price( $total_amount ),
				'orders'       => array_keys( $orders_map ),
				'ticket_ids'   => wp_list_pluck( $tickets, 'id' ),
			)
		);
	}

	/**
	 * AJAX: Process selective refund for one order's tickets.
	 *
	 * Refunds specific tickets from an order (partial refund if the order
	 * contains more items than selected).
	 *
	 * @since 1.0.8
	 */
	public function ajax_selective_refund_process() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$ticket_ids = isset( $_POST['ticket_ids'] ) ? array_map( 'absint', (array) $_POST['ticket_ids'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$try_gateway      = ! empty( $_POST['try_gateway'] );
		$refund_to_wallet = ! empty( $_POST['refund_to_wallet'] );

		if ( ! $order_id || empty( $ticket_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing order or ticket IDs.', 'raffle-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			/* translators: %d: order ID */
			wp_send_json_error( array( 'message' => sprintf( __( 'Order #%d not found.', 'raffle-for-woocommerce' ), $order_id ) ) );
		}

		// Get the raffle IDs and ticket counts for this order from the selected tickets.
		global $wpdb;
		$table        = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$tickets_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, raffle_id FROM {$table} WHERE id IN ({$placeholders}) AND order_id = %d AND status = 'active'",
				...array_merge( $ticket_ids, array( $order_id ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $tickets_data ) ) {
			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'status'   => 'skipped',
					/* translators: %d: order ID */
					'message'  => sprintf( __( 'Order #%d: no active tickets to refund.', 'raffle-for-woocommerce' ), $order_id ),
				)
			);
			return;
		}

		// Count tickets per raffle for this order.
		$raffle_counts = array();
		foreach ( $tickets_data as $td ) {
			$rid = (int) $td->raffle_id;
			if ( ! isset( $raffle_counts[ $rid ] ) ) {
				$raffle_counts[ $rid ] = 0;
			}
			++$raffle_counts[ $rid ];
		}

		// Build refund items — find matching line items and calculate partial amounts.
		$refund_items  = array();
		$refund_amount = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();

			if ( ! isset( $raffle_counts[ $product_id ] ) ) {
				continue;
			}

			$qty_to_refund    = $raffle_counts[ $product_id ];
			$item_qty         = $item->get_quantity();
			$price_per_ticket = $item_qty > 0 ? (float) $item->get_total() / $item_qty : 0;
			$tax_per_ticket   = $item_qty > 0 ? (float) $item->get_total_tax() / $item_qty : 0;

			$line_refund = round( $price_per_ticket * $qty_to_refund, 2 );
			$tax_refund  = round( $tax_per_ticket * $qty_to_refund, 2 );

			$refund_items[ $item_id ] = array(
				'qty'          => $qty_to_refund,
				'refund_total' => $line_refund,
				'refund_tax'   => array(),
			);

			$taxes = $item->get_taxes();
			if ( ! empty( $taxes['total'] ) ) {
				foreach ( $taxes['total'] as $tax_id => $tax_total ) {
					$per_ticket_tax                                    = $item_qty > 0 ? (float) $tax_total / $item_qty : 0;
					$refund_items[ $item_id ]['refund_tax'][ $tax_id ] = round( $per_ticket_tax * $qty_to_refund, 2 );
				}
			}

			$refund_amount += $line_refund + $tax_refund;
		}

		if ( empty( $refund_items ) ) {
			// Mark tickets as refunded anyway.
			foreach ( $tickets_data as $td ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update(
					$table,
					array( 'status' => 'refunded' ),
					array( 'id' => (int) $td->id ),
					array( '%s' ),
					array( '%d' )
				);
			}

			wp_send_json_success(
				array(
					'order_id' => $order_id,
					'status'   => 'skipped',
					/* translators: %d: order ID */
					'message'  => sprintf( __( 'Order #%d: no matching line items. Tickets marked as refunded.', 'raffle-for-woocommerce' ), $order_id ),
				)
			);
			return;
		}

		// Mark the specific tickets as refunded BEFORE calling wc_create_refund().
		// On a full refund, WooCommerce synchronously transitions the order to
		// "refunded" status, which triggers cancel_tickets_for_order(). By setting
		// our tickets to "refunded" first, that handler will skip them (it only
		// touches "active" tickets).
		$updated = 0;
		foreach ( $tickets_data as $td ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->update(
				$table,
				array( 'status' => 'refunded' ),
				array(
					'id'     => (int) $td->id,
					'status' => 'active',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( $result ) {
				++$updated;
			}
		}

		$result = $this->create_smart_refund(
			array(
				'amount'            => $refund_amount,
				'reason'            => __( 'Selective ticket refund from Raffle Tickets admin.', 'raffle-for-woocommerce' ),
				'order_id'          => $order_id,
				'line_items'        => $refund_items,
				'try_gateway'       => $try_gateway,
				'refund_to_wallet'  => $refund_to_wallet,
			)
		);

		$refund         = $result['refund'];
		$refund_method  = $result['method'];
		$refund_gateway = $result['gateway'];

		if ( is_wp_error( $refund ) ) {
			// Refund failed — revert tickets back to active.
			foreach ( $tickets_data as $td ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update(
					$table,
					array( 'status' => 'active' ),
					array(
						'id'     => (int) $td->id,
						'status' => 'refunded',
					),
					array( '%s' ),
					array( '%d', '%s' )
				);
			}

			wp_send_json_error(
				array(
					'order_id' => $order_id,
					'message'  => sprintf(
						/* translators: 1: order ID, 2: error message */
						__( 'Order #%1$d refund failed: %2$s', 'raffle-for-woocommerce' ),
						$order_id,
						$refund->get_error_message()
					),
				)
			);
		}

		$method_label = $this->get_refund_method_label( $refund_method, $refund_gateway );

		$order->update_meta_data( '_rfwc_refund_method', $refund_method );
		$order->save();

		// Fire refund email notifications for each raffle.
		foreach ( $raffle_counts as $rid => $count ) {
			$this->fire_refund_emails( $rid, $order_id, $refund_amount, __( 'Selective refund', 'raffle-for-woocommerce' ) );
		}

		// Log to activity log — use the first raffle_id for the log entry.
		$first_raffle_id = ! empty( $tickets_data ) ? (int) $tickets_data[0]->raffle_id : 0;
		$this->log_activity(
			$first_raffle_id,
			'selective_refund',
			sprintf(
				/* translators: 1: ticket count, 2: order ID, 3: refund amount, 4: refund method */
				__( 'Selective refund: %1$d tickets from Order #%2$d refunded %3$s (%4$s).', 'raffle-for-woocommerce' ),
				$updated,
				$order_id,
				wp_strip_all_tags( wc_price( $refund_amount ) ),
				$method_label
			)
		);

		wp_send_json_success(
			array(
				'order_id'             => $order_id,
				'status'               => 'refunded',
				'amount'               => wc_price( $refund_amount ),
				'amount_raw'           => $refund_amount,
				'tickets'              => $updated,
				'refund_method'        => $refund_method,
				'refund_gateway'       => $refund_gateway,
				'payment_method_title' => $order->get_payment_method_title(),
				'message'              => sprintf(
					/* translators: 1: order ID, 2: refund amount, 3: ticket count, 4: refund method */
					__( 'Order #%1$d: refunded %2$s (%3$d tickets, %4$s).', 'raffle-for-woocommerce' ),
					$order_id,
					wc_price( $refund_amount ),
					$updated,
					$method_label
				),
			)
		);
	}

	/**
	 * Create a WooCommerce refund with gateway-aware payment processing.
	 *
	 * When $try_gateway is true, checks whether the order's payment gateway
	 * supports automated refunds. If it does, attempts an API refund first.
	 * If the API call fails or the gateway doesn't support refunds, falls
	 * back to a manual/accounting refund (refund_payment => false).
	 *
	 * When refund_to_wallet is true and TeraWallet is active, credits the
	 * refund amount to the customer's wallet after creating the WC refund
	 * record. Guest orders (no user account) fall back to manual.
	 *
	 * @since 1.0.9
	 *
	 * @param array $args Standard wc_create_refund() arguments plus 'try_gateway'
	 *                    and 'refund_to_wallet' booleans.
	 * @return array {
	 *     @type WC_Order_Refund|WP_Error $refund  The refund object or error.
	 *     @type string                   $method  'gateway', 'wallet', or 'manual'.
	 *     @type string                   $gateway The payment method slug (e.g. 'stripe', 'bacs').
	 * }
	 */
	private function create_smart_refund( $args ) {
		$try_gateway      = ! empty( $args['try_gateway'] );
		$refund_to_wallet = ! empty( $args['refund_to_wallet'] );
		unset( $args['try_gateway'], $args['refund_to_wallet'] );

		$order   = wc_get_order( $args['order_id'] );
		$gateway = $order ? $order->get_payment_method() : '';
		$method  = 'manual';

		// Wallet refund: create WC refund record + credit TeraWallet.
		if ( $refund_to_wallet && $order && function_exists( 'woo_wallet' ) ) {
			$customer_id = $order->get_customer_id();

			if ( $customer_id ) {
				$args['refund_payment'] = false;
				$refund                 = wc_create_refund( $args );

				if ( ! is_wp_error( $refund ) ) {
					$credit_result = woo_wallet()->wallet->credit(
						$customer_id,
						(float) $args['amount'],
						sprintf(
							/* translators: 1: order ID, 2: refund ID */
							__( 'Raffle refund for Order #%1$d (Refund #%2$d)', 'raffle-for-woocommerce' ),
							$args['order_id'],
							$refund->get_id()
						),
						array( 'for' => 'rfwc_refund' )
					);

					if ( $credit_result ) {
						return array(
							'refund'  => $refund,
							'method'  => 'wallet',
							'gateway' => $gateway,
						);
					}

					// Wallet credit failed — WC refund record exists but no wallet credit.
					// Fall back to manual so the admin knows action is needed.
					return array(
						'refund'  => $refund,
						'method'  => 'manual',
						'gateway' => $gateway,
					);
				}

				return array(
					'refund'  => $refund,
					'method'  => 'manual',
					'gateway' => $gateway,
				);
			}

			// Guest order — no wallet account. Fall through to standard logic.
		}

		// Gateway refund.
		if ( $try_gateway && $order ) {
			$all_gateways = WC_Payment_Gateways::instance()->payment_gateways();
			$gateway_obj  = isset( $all_gateways[ $gateway ] ) ? $all_gateways[ $gateway ] : null;
			$supports     = $gateway_obj && $gateway_obj->supports( 'refunds' );

			if ( $supports ) {
				$args['refund_payment'] = true;
				$refund                 = wc_create_refund( $args );

				if ( ! is_wp_error( $refund ) ) {
					return array(
						'refund'  => $refund,
						'method'  => 'gateway',
						'gateway' => $gateway,
					);
				}

				// Gateway refund failed — fall back to manual.
				$args['refund_payment'] = false;
			}
		}

		$args['refund_payment'] = false;
		$refund                 = wc_create_refund( $args );

		return array(
			'refund'  => $refund,
			'method'  => $method,
			'gateway' => $gateway,
		);
	}

	/**
	 * Get a human-readable label for a refund method.
	 *
	 * @since 1.0.9
	 *
	 * @param string $method  The refund method slug (gateway, wallet, manual).
	 * @param string $gateway The payment gateway slug.
	 * @return string
	 */
	private function get_refund_method_label( $method, $gateway = '' ) {
		if ( 'gateway' === $method ) {
			/* translators: %s: payment gateway name */
			return sprintf( __( 'via %s', 'raffle-for-woocommerce' ), $gateway );
		}

		if ( 'wallet' === $method ) {
			return __( 'wallet', 'raffle-for-woocommerce' );
		}

		return __( 'manual', 'raffle-for-woocommerce' );
	}

	/**
	 * Fire refund email notifications for refunded tickets.
	 *
	 * Groups refunded tickets by holder email and sends a ticket refunded
	 * notification to each unique holder, including gift recipients.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $raffle_id     Raffle product ID.
	 * @param int    $order_id      Order ID.
	 * @param float  $refund_amount Total refund amount for this order.
	 * @param string $reason        Refund reason or raffle status.
	 */
	private function fire_refund_emails( $raffle_id, $order_id, $refund_amount, $reason = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ticket_number, raffle_id, purchaser_email, recipient_email, is_gift FROM {$table} WHERE raffle_id = %d AND order_id = %d AND status = 'refunded'",
				$raffle_id,
				$order_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			return;
		}

		$product = wc_get_product( $raffle_id );
		$prefix  = $product ? $product->get_meta( '_rfwc_ticket_prefix', true ) : '';
		$suffix  = $product ? $product->get_meta( '_rfwc_ticket_suffix', true ) : '';

		$by_email = array();
		foreach ( $rows as $row ) {
			$holder_email = ! empty( $row->is_gift ) && ! empty( $row->recipient_email )
				? $row->recipient_email
				: $row->purchaser_email;

			if ( empty( $holder_email ) ) {
				continue;
			}

			$key = strtolower( trim( $holder_email ) );
			if ( ! isset( $by_email[ $key ] ) ) {
				$by_email[ $key ] = array();
			}

			$number = $row->ticket_number;
			if ( $prefix ) {
				$number = $prefix . $number;
			}
			if ( $suffix ) {
				$number .= $suffix;
			}

			$by_email[ $key ][] = array( 'ticket_number' => $number );
		}

		foreach ( $by_email as $email => $tickets ) {
			/**
			 * Triggers ticket refunded email to holder.
			 *
			 * Registered with WooCommerce via `woocommerce_email_actions`. WC
			 * loads `WC_Emails::instance()` on this hook and then dispatches
			 * `rfwc_ticket_refunded_notification`, which the email handler
			 * listens to. Listening to the source action directly used to
			 * cause a hook timing race when the email classes had not been
			 * loaded yet for the request.
			 *
			 * @since 1.1.0
			 * @since 1.1.12 Renamed from `rfwc_ticket_refunded_notification`
			 *               so the auto-appended `_notification` variant
			 *               carries the email handler.
			 *
			 * @param string $email     Recipient email.
			 * @param array  $tickets   Array of ticket data arrays.
			 * @param int    $raffle_id Raffle product ID.
			 * @param array  $args      Additional args.
			 */
			do_action(
				'rfwc_ticket_refunded',
				$email,
				$tickets,
				$raffle_id,
				array(
					'refund_amount' => $refund_amount,
					'refund_reason' => $reason,
					'order_id'      => $order_id,
				)
			);
		}
	}

	/**
	 * AJAX: Mark a manual refund as done (admin has sent the money offline).
	 *
	 * Updates the order meta from 'manual' to 'manual_done' and adds an
	 * order note so there is a permanent audit trail on the order itself.
	 *
	 * @since 1.0.9
	 */
	public function ajax_mark_refund_done() {
		check_ajax_referer( 'rfwc_tickets_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order ID.', 'raffle-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			/* translators: %d: order ID */
			wp_send_json_error( array( 'message' => sprintf( __( 'Order #%d not found.', 'raffle-for-woocommerce' ), $order_id ) ) );
		}

		$current = $order->get_meta( '_rfwc_refund_method', true );
		if ( 'manual' !== $current ) {
			wp_send_json_error( array( 'message' => __( 'This order is not pending manual refund.', 'raffle-for-woocommerce' ) ) );
		}

		$order->update_meta_data( '_rfwc_refund_method', 'manual_done' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: admin user display name */
				__( 'Manual refund marked as completed by %s (Raffle for WooCommerce).', 'raffle-for-woocommerce' ),
				wp_get_current_user()->display_name
			)
		);

		wp_send_json_success(
			array(
				/* translators: %d: order ID */
				'message' => sprintf( __( 'Order #%d manual refund marked as done.', 'raffle-for-woocommerce' ), $order_id ),
			)
		);
	}

	/**
	 * Write an entry to the activity log.
	 *
	 * @since 1.0.4
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $action    Action slug (e.g. 'prize_claimed').
	 * @param string $details   Human-readable details.
	 */
	private function log_activity( $raffle_id, $action, $details ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			esc_sql( $wpdb->prefix . 'rfwc_log' ),
			array(
				'raffle_id' => absint( $raffle_id ),
				'action'    => sanitize_text_field( $action ),
				'details'   => $details,
				'user_id'   => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Strip known raffle ticket prefixes from a search term.
	 *
	 * The ticket_number column stores raw numbers without the prefix (e.g. "0001"),
	 * but users search with the formatted number (e.g. "TLD0001"). This method
	 * collects all known prefixes and returns additional search terms with
	 * those prefixes removed, so the SQL LIKE can match the raw number.
	 *
	 * @since 1.1.0
	 *
	 * @param string $search The original search term.
	 *
	 * @return string[] Additional search terms with prefixes stripped.
	 */
	private function get_stripped_search_terms( $search ) {
		$stripped = array();

		// Collect prefixes: global default + per-raffle overrides.
		$prefixes   = array();
		$global_pfx = get_option( 'rfwc_ticket_prefix', '' );

		if ( '' !== $global_pfx ) {
			$prefixes[] = $global_pfx;
		}

		// Get per-product prefixes from raffle products.
		$raffle_products = wc_get_products(
			array(
				'type'   => 'raffle',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		foreach ( $raffle_products as $product_id ) {
			$meta = get_post_meta( $product_id, '_rfwc_ticket_prefix', true );
			if ( '' !== $meta && ! in_array( $meta, $prefixes, true ) ) {
				$prefixes[] = $meta;
			}
		}

		// Try stripping each known prefix from the beginning of the search term.
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && stripos( $search, $prefix ) === 0 ) {
				$remainder = substr( $search, strlen( $prefix ) );
				if ( '' !== $remainder && ! in_array( $remainder, $stripped, true ) ) {
					$stripped[] = $remainder;
				}
			}
		}

		return $stripped;
	}
}
