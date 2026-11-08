<?php
/**
 * Admin winner selection interface.
 *
 * Displays winner draw controls on raffle product pages.
 * Supports manual, automatic, and Random.org selection methods.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product page metabox for drawing raffle winners.
 *
 * @since 1.0.0
 */
class RFWC_Admin_Winner {

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
		// Add metabox for winner selection.
		add_action( 'add_meta_boxes', array( $this, 'add_winner_metabox' ) );

		// Handle AJAX requests.
		add_action( 'wp_ajax_rfwc_draw_winners', array( $this, 'ajax_draw_winners' ) );
		add_action( 'wp_ajax_rfwc_select_manual_winner', array( $this, 'ajax_select_manual_winner' ) );
		add_action( 'wp_ajax_rfwc_clear_winners', array( $this, 'ajax_clear_winners' ) );
		add_action( 'wp_ajax_rfwc_resend_winner_notification', array( $this, 'ajax_resend_notification' ) );
		add_action( 'wp_ajax_rfwc_mark_prize_claimed', array( $this, 'ajax_mark_claimed' ) );
		add_action( 'wp_ajax_rfwc_mark_raffle_failed', array( $this, 'ajax_mark_raffle_failed' ) );
		add_action( 'wp_ajax_rfwc_cancel_raffle', array( $this, 'ajax_cancel_raffle' ) );
		add_action( 'wp_ajax_rfwc_extend_raffle', array( $this, 'ajax_extend_raffle' ) );
		add_action( 'wp_ajax_rfwc_relist_raffle', array( $this, 'ajax_relist_raffle' ) );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add winner selection metabox.
	 *
	 * @since 1.0.0
	 */
	public function add_winner_metabox() {
		global $post;

		if ( ! $post ) {
			return;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		add_meta_box(
			'rfwc-winner-selection',
			__( 'Winner Selection', 'raffle-for-woocommerce' ),
			array( $this, 'render_winner_metabox' ),
			'product',
			'normal',
			'low'
		);
	}

	/**
	 * Render winner selection metabox.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_winner_metabox( $post ) {
		$raffle_id      = $post->ID;
		$product        = wc_get_product( $raffle_id );
		$winners        = RFWC_Winner::get_by_raffle( $raffle_id );
		$eligible_count = count( RFWC_Winner_Selector::get_eligible_tickets( $raffle_id ) );
		$total_tickets  = RFWC_Ticket::count_by_raffle( $raffle_id, '' );
		$num_winners    = absint( $product->get_meta( '_rfwc_number_of_winners' ) );
		$num_winners    = $num_winners > 0 ? $num_winners : 1;
		$has_winners    = ! empty( $winners );
		$raffle_status  = $product->get_meta( '_rfwc_status', true );

		wp_nonce_field( 'rfwc_winner_nonce', 'rfwc_winner_nonce' );
		?>
		<div class="rfwc-winner-selection-wrap">
			<div class="rfwc-winner-stats">
				<div class="rfwc-stat-box">
					<span class="rfwc-stat-number"><?php echo esc_html( $total_tickets ); ?></span>
					<span class="rfwc-stat-label"><?php esc_html_e( 'Total Tickets', 'raffle-for-woocommerce' ); ?></span>
				</div>
				<div class="rfwc-stat-box">
					<span class="rfwc-stat-number"><?php echo esc_html( $eligible_count ); ?></span>
					<span class="rfwc-stat-label"><?php esc_html_e( 'Eligible', 'raffle-for-woocommerce' ); ?></span>
				</div>
				<div class="rfwc-stat-box">
					<span class="rfwc-stat-number"><?php echo esc_html( count( $winners ) ); ?></span>
					<span class="rfwc-stat-label"><?php esc_html_e( 'Winners', 'raffle-for-woocommerce' ); ?></span>
				</div>
			</div>

			<?php if ( 'failed' === $raffle_status ) : ?>
				<div class="rfwc-raffle-status-notice rfwc-status-failed-notice">
					<span class="dashicons dashicons-warning"></span>
					<div>
						<strong><?php esc_html_e( 'Raffle Failed', 'raffle-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'This raffle has been marked as failed. You can process refunds from the Raffle Tickets > Refunded tab.', 'raffle-for-woocommerce' ); ?></p>
					</div>
				</div>
				<?php $this->render_extend_relist_buttons( $raffle_id ); ?>
			<?php elseif ( 'cancelled' === $raffle_status ) : ?>
				<div class="rfwc-raffle-status-notice rfwc-status-cancelled-notice">
					<span class="dashicons dashicons-dismiss"></span>
					<div>
						<strong><?php esc_html_e( 'Raffle Cancelled', 'raffle-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'This raffle has been cancelled. You can process refunds from the Raffle Tickets > Refunded tab.', 'raffle-for-woocommerce' ); ?></p>
					</div>
				</div>
				<?php $this->render_extend_relist_buttons( $raffle_id ); ?>
			<?php elseif ( 'completed' === $raffle_status && $has_winners ) : ?>
				<?php $this->render_extend_relist_buttons( $raffle_id ); ?>
			<?php elseif ( ! $has_winners && 'completed' !== $raffle_status ) : ?>
				<?php $active_ticket_count = RFWC_Ticket::count_by_raffle( $raffle_id, 'active' ); ?>
				<?php if ( $product->has_ended() ) : ?>
					<?php $this->render_extend_relist_buttons( $raffle_id ); ?>
				<?php endif; ?>
				<div class="rfwc-raffle-status-actions">
					<div class="rfwc-status-action-group">
						<button type="button" class="button rfwc-mark-raffle-failed" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
							<?php esc_html_e( 'Mark as Failed', 'raffle-for-woocommerce' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'The raffle didn\'t reach its goal. Not enough entries, prize unavailable, or something went wrong. Tickets stay active until you process a mass refund.', 'raffle-for-woocommerce' ); ?>
						</p>
					</div>
					<div class="rfwc-status-action-group">
						<button type="button" class="button rfwc-cancel-raffle" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
							<?php esc_html_e( 'Cancel Raffle', 'raffle-for-woocommerce' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'You\'ve decided not to run this raffle. Changed plans, compliance issue, or other admin decision. Tickets stay active until you process a mass refund.', 'raffle-for-woocommerce' ); ?>
						</p>
					</div>
					<?php if ( $active_ticket_count > 0 ) : ?>
						<p class="rfwc-refund-note">
							<span class="dashicons dashicons-info-outline"></span>
							<?php
							printf(
								esc_html(
									/* translators: %d: number of active tickets that become refund-eligible. */
									_n(
										'%d active ticket will become eligible for mass refund after changing status.',
										'%d active tickets will become eligible for mass refund after changing status.',
										$active_ticket_count,
										'raffle-for-woocommerce'
									)
								),
								(int) $active_ticket_count
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $has_winners ) : ?>
				<div class="rfwc-winners-list">
					<h4><?php esc_html_e( 'Selected Winners', 'raffle-for-woocommerce' ); ?></h4>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="column-tier"><?php esc_html_e( 'Tier', 'raffle-for-woocommerce' ); ?></th>
								<th class="column-method"><?php esc_html_e( 'Method', 'raffle-for-woocommerce' ); ?></th>
								<th class="column-ticket"><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
								<th class="column-winner"><?php esc_html_e( 'Winner', 'raffle-for-woocommerce' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?></th>
								<th class="column-actions"><?php esc_html_e( 'Actions', 'raffle-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $winners as $winner ) :
								$ticket = $winner->get_ticket();
								if ( ! $ticket ) {
									continue;
								}
								?>
								<tr>
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
									<td class="column-ticket">
										<strong><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></strong>
									</td>
									<td class="column-winner">
										<?php echo esc_html( $ticket->get_holder_name() ); ?><br>
										<small><?php echo esc_html( $ticket->get_holder_email() ); ?></small>
									</td>
									<td class="column-status">
										<?php if ( $winner->is_claimed() ) : ?>
											<mark class="order-status status-completed"><span><?php esc_html_e( 'Claimed', 'raffle-for-woocommerce' ); ?></span></mark>
										<?php elseif ( $winner->is_notified() ) : ?>
											<mark class="order-status status-processing"><span><?php esc_html_e( 'Notified', 'raffle-for-woocommerce' ); ?></span></mark>
										<?php else : ?>
											<mark class="order-status status-pending"><span><?php esc_html_e( 'Pending', 'raffle-for-woocommerce' ); ?></span></mark>
										<?php endif; ?>
									</td>
									<td class="column-actions">
										<?php if ( ! $winner->is_notified() ) : ?>
											<button type="button" class="button button-small rfwc-resend-notification" data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
												<?php esc_html_e( 'Notify', 'raffle-for-woocommerce' ); ?>
											</button>
										<?php else : ?>
											<button type="button" class="button button-small rfwc-resend-notification" data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
												<?php esc_html_e( 'Resend', 'raffle-for-woocommerce' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( ! $winner->is_claimed() ) : ?>
											<button type="button" class="button button-small rfwc-mark-claimed" data-winner-id="<?php echo esc_attr( $winner->get_id() ); ?>">
												<?php esc_html_e( 'Claimed', 'raffle-for-woocommerce' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="rfwc-clear-winners-wrap">
						<button type="button" class="button rfwc-clear-winners" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
							<?php esc_html_e( 'Clear All Winners', 'raffle-for-woocommerce' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'Removes all winners and restores tickets.', 'raffle-for-woocommerce' ); ?></span>
					</p>
				</div>
			<?php else : ?>
				<?php if ( $eligible_count > 0 ) : ?>
					<div class="rfwc-draw-section">
						<h4><?php esc_html_e( 'Draw Winners', 'raffle-for-woocommerce' ); ?></h4>

						<p class="form-field">
							<label for="rfwc_num_winners"><?php esc_html_e( 'Number of Winners', 'raffle-for-woocommerce' ); ?></label>
							<input type="number" id="rfwc_num_winners" name="rfwc_num_winners" value="<?php echo esc_attr( $num_winners ); ?>" min="1" max="<?php echo esc_attr( $eligible_count ); ?>" class="rfwc-field">
							<span class="description">
								<?php
								printf(
									/* translators: %d: number of eligible tickets in the draw */
									esc_html( _n( '%d ticket in the draw', '%d tickets in the draw', (int) $eligible_count, 'raffle-for-woocommerce' ) ),
									(int) $eligible_count
								);
								?>
							</span>
						</p>

						<p class="form-field">
							<label for="rfwc_selection_method"><?php esc_html_e( 'Selection Method', 'raffle-for-woocommerce' ); ?></label>
							<select id="rfwc_selection_method" name="rfwc_selection_method" class="rfwc-field">
								<?php foreach ( RFWC_Winner_Selector::get_selection_methods() as $method => $label ) : ?>
									<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $method, 'secure_random' ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="form-field">
							<label for="rfwc_send_notifications">
								<input type="checkbox" id="rfwc_send_notifications" name="rfwc_send_notifications" value="1" checked>
								<?php esc_html_e( 'Send email notifications to winners', 'raffle-for-woocommerce' ); ?>
							</label>
						</p>

						<p class="form-field">
							<button type="button" class="button button-primary rfwc-draw-winners" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
								<?php esc_html_e( 'Draw Winners', 'raffle-for-woocommerce' ); ?>
							</button>
						</p>
					</div>

					<?php if ( 'yes' === get_option( 'rfwc_allow_manual_selection', 'yes' ) ) : ?>
					<hr>

					<div class="rfwc-manual-section">
						<h4><?php esc_html_e( 'Manual Selection', 'raffle-for-woocommerce' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Or select a specific ticket as winner.', 'raffle-for-woocommerce' ); ?></p>

						<p class="form-field">
							<label for="rfwc_manual_ticket"><?php esc_html_e( 'Select Ticket', 'raffle-for-woocommerce' ); ?></label>
							<select id="rfwc_manual_ticket" name="rfwc_manual_ticket" class="rfwc-field wc-enhanced-select">
								<option value=""><?php esc_html_e( 'Select a ticket...', 'raffle-for-woocommerce' ); ?></option>
								<?php
								$tickets = RFWC_Winner_Selector::get_eligible_tickets( $raffle_id );
								foreach ( $tickets as $ticket ) :
									?>
									<option value="<?php echo esc_attr( $ticket->get_id() ); ?>">
										<?php
										printf(
											'%s - %s',
											esc_html( $ticket->get_formatted_ticket_number() ),
											esc_html( $ticket->get_holder_name() )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="form-field">
							<label for="rfwc_manual_tier"><?php esc_html_e( 'Prize Tier', 'raffle-for-woocommerce' ); ?></label>
							<input type="number" id="rfwc_manual_tier" name="rfwc_manual_tier" value="1" min="1" class="rfwc-field">
						</p>

						<p class="form-field">
							<button type="button" class="button rfwc-select-manual-winner" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
								<?php esc_html_e( 'Select as Winner', 'raffle-for-woocommerce' ); ?>
							</button>
						</p>
					</div>
				<?php else : ?>
					<hr>
					<p class="rfwc-manual-disabled-notice">
						<span class="dashicons dashicons-lock"></span>
						<?php esc_html_e( 'Manual selection is disabled by the store administrator. Only random draws are allowed.', 'raffle-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
				<?php else : ?>
					<div class="rfwc-no-tickets">
						<p><?php esc_html_e( 'No eligible tickets available. Tickets are generated when orders are completed.', 'raffle-for-woocommerce' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="rfwc-ajax-message rfwc-hidden"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		global $post;

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		wp_enqueue_script(
			'rfwc-admin-winner',
			RFWC_PLUGIN_URL . 'assets/js/admin-winner.js',
			array( 'jquery' ),
			RFWC_VERSION,
			true
		);

		wp_localize_script(
			'rfwc-admin-winner',
			'rfwcWinner',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'rfwc_winner_nonce' ),
				'confirmDraw'   => __( 'Are you sure you want to draw winners? This action cannot be undone.', 'raffle-for-woocommerce' ),
				'confirmClear'  => __( 'Are you sure you want to clear all winners? Tickets will be restored to active status.', 'raffle-for-woocommerce' ),
				'confirmManual' => __( 'Are you sure you want to select this ticket as a winner?', 'raffle-for-woocommerce' ),
				'confirmFailed' => __( "Mark this raffle as failed?\n\nUse this when the raffle didn't reach its goal or something went wrong. You'll be able to process mass refunds afterward. This cannot be undone.", 'raffle-for-woocommerce' ),
				'confirmCancel' => __( "Cancel this raffle?\n\nUse this when you've decided not to run the raffle. You'll be able to process mass refunds afterward. This cannot be undone.", 'raffle-for-woocommerce' ),
				'confirmExtend' => __( 'Extend this raffle with the new end date? The raffle will become active again, keeping all existing data.', 'raffle-for-woocommerce' ),
				'confirmRelist' => __( "Relist this raffle?\n\nThis will clear all winners, reset the status, and set the new end date. Existing tickets will be kept. This cannot be undone.", 'raffle-for-woocommerce' ),
				'noEndDate'     => __( 'Please enter a new end date.', 'raffle-for-woocommerce' ),
				'drawing'       => __( 'Drawing winners...', 'raffle-for-woocommerce' ),
				'success'       => __( 'Success!', 'raffle-for-woocommerce' ),
				'error'         => __( 'Error:', 'raffle-for-woocommerce' ),
				'selectTicket'  => __( 'Please select a ticket.', 'raffle-for-woocommerce' ),
				'sending'       => __( 'Sending...', 'raffle-for-woocommerce' ),
				'sent'          => __( 'Sent!', 'raffle-for-woocommerce' ),
				'resend'        => __( 'Resend', 'raffle-for-woocommerce' ),
			)
		);

		wp_enqueue_style(
			'rfwc-admin-winner',
			RFWC_PLUGIN_URL . 'assets/css/admin-winner.css',
			array(),
			RFWC_VERSION . '.' . filemtime( RFWC_PLUGIN_PATH . 'assets/css/admin-winner.css' )
		);
	}

	/**
	 * AJAX handler for drawing winners.
	 *
	 * @since 1.0.0
	 */
	public function ajax_draw_winners() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id   = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$num_winners = isset( $_POST['num_winners'] ) ? absint( $_POST['num_winners'] ) : 1;
		$method      = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'secure_random';
		$send_notify = isset( $_POST['send_notifications'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['send_notifications'] ) );

		if ( ! $raffle_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid raffle ID.', 'raffle-for-woocommerce' ) ) );
		}

		$result = RFWC_Winner_Selector::select_winners( $raffle_id, $num_winners, $method, $send_notify );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %d: number of winners */
					_n( '%d winner selected successfully!', '%d winners selected successfully!', count( $result ), 'raffle-for-woocommerce' ),
					count( $result )
				),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for manual winner selection.
	 *
	 * @since 1.0.0
	 */
	public function ajax_select_manual_winner() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		if ( 'yes' !== get_option( 'rfwc_allow_manual_selection', 'yes' ) ) {
			wp_send_json_error( array( 'message' => __( 'Manual winner selection is disabled. Only random draws are allowed.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id  = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$ticket_id  = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
		$prize_tier = isset( $_POST['prize_tier'] ) ? absint( $_POST['prize_tier'] ) : 1;

		if ( ! $raffle_id || ! $ticket_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid raffle or ticket ID.', 'raffle-for-woocommerce' ) ) );
		}

		$result = RFWC_Winner_Selector::select_winner_manually( $raffle_id, $ticket_id, $prize_tier, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Winner selected successfully!', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for clearing winners.
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear_winners() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;

		if ( ! $raffle_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid raffle ID.', 'raffle-for-woocommerce' ) ) );
		}

		RFWC_Winner_Selector::clear_winners( $raffle_id );

		wp_send_json_success(
			array(
				'message' => __( 'All winners cleared.', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for resending notification.
	 *
	 * @since 1.0.0
	 */
	public function ajax_resend_notification() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

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

		// Send ONLY the winner notification email (not the admin alert).
		// Using a specific action avoids re-triggering admin emails on resend.
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

		wp_send_json_success(
			array(
				'message' => __( 'Notification sent!', 'raffle-for-woocommerce' ),
			)
		);
	}

	/**
	 * AJAX handler for marking prize as claimed.
	 *
	 * @since 1.0.0
	 */
	public function ajax_mark_claimed() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

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

		wp_send_json_success(
			array(
				'message' => __( 'Prize marked as claimed!', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for marking a raffle as failed.
	 *
	 * @since 1.0.8
	 */
	public function ajax_mark_raffle_failed() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

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

		$current_status = $product->get_meta( '_rfwc_status', true );
		if ( in_array( $current_status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This raffle already has a terminal status and cannot be changed.', 'raffle-for-woocommerce' ) ) );
		}

		if ( $product->has_winners() ) {
			wp_send_json_error( array( 'message' => __( 'Cannot mark as failed. Raffle has winners. Clear winners first.', 'raffle-for-woocommerce' ) ) );
		}

		$product->update_meta_data( '_rfwc_status', 'failed' );
		$product->save();

		$this->log_activity(
			$raffle_id,
			'raffle_failed',
			sprintf(
				/* translators: %s: raffle product name */
				__( 'Raffle "%s" manually marked as failed.', 'raffle-for-woocommerce' ),
				$product->get_name()
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Raffle marked as failed. You can now process refunds from the Raffle Tickets > Refunded tab.', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for cancelling a raffle.
	 *
	 * Cancels the raffle and marks all active tickets as cancelled.
	 *
	 * @since 1.0.8
	 */
	public function ajax_cancel_raffle() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

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

		$current_status = $product->get_meta( '_rfwc_status', true );
		if ( in_array( $current_status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This raffle already has a terminal status and cannot be changed.', 'raffle-for-woocommerce' ) ) );
		}

		if ( $product->has_winners() ) {
			wp_send_json_error( array( 'message' => __( 'Cannot cancel. Raffle has winners. Clear winners first.', 'raffle-for-woocommerce' ) ) );
		}

		$product->update_meta_data( '_rfwc_status', 'cancelled' );
		$product->save();

		$this->log_activity(
			$raffle_id,
			'raffle_cancelled',
			sprintf(
				/* translators: %s: raffle product name */
				__( 'Raffle "%s" cancelled by admin.', 'raffle-for-woocommerce' ),
				$product->get_name()
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Raffle cancelled. You can now process refunds from the Raffle Tickets > Refunded tab.', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Render Extend / Relist buttons for terminal-status raffles.
	 *
	 * @since 1.1.0
	 *
	 * @param int $raffle_id Raffle product ID.
	 */
	private function render_extend_relist_buttons( $raffle_id ) {
		?>
		<div class="rfwc-extend-relist-section">
			<h4><?php esc_html_e( 'Reopen Raffle', 'raffle-for-woocommerce' ); ?></h4>

			<div class="rfwc-extend-date-row">
				<div class="rfwc-extend-date-inputs">
					<input type="text" id="rfwc_new_end_date" name="rfwc_new_end_date" class="rfwc-field date-picker" placeholder="YYYY-MM-DD" maxlength="10">
					<input type="text" id="rfwc_new_end_time" name="rfwc_new_end_time" class="rfwc-field" placeholder="HH:MM" maxlength="5">
				</div>
				<span class="description">
					<?php
					printf(
						/* translators: %s: site timezone string e.g. UTC+5, America/New_York */
						esc_html__( '24-hour format. Site timezone: %s', 'raffle-for-woocommerce' ),
						esc_html( wp_timezone_string() )
					);
					?>
				</span>
			</div>

			<div class="rfwc-extend-relist-buttons">
				<div class="rfwc-extend-relist-action">
					<button type="button" class="button button-primary rfwc-extend-raffle" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
						<?php esc_html_e( 'Extend', 'raffle-for-woocommerce' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Keep all data, just push the end date and reactivate.', 'raffle-for-woocommerce' ); ?></span>
				</div>

				<div class="rfwc-extend-relist-action">
					<button type="button" class="button rfwc-relist-raffle" data-raffle-id="<?php echo esc_attr( $raffle_id ); ?>">
						<?php esc_html_e( 'Relist', 'raffle-for-woocommerce' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Reset to fresh state: clear winners and status, keep tickets, set new end date.', 'raffle-for-woocommerce' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for extending a raffle.
	 *
	 * @since 1.1.0
	 */
	public function ajax_extend_raffle() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id    = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$new_end_date = isset( $_POST['new_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_date'] ) ) : '';
		$new_end_time = isset( $_POST['new_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_time'] ) ) : '23:59';

		if ( ! $raffle_id || ! $new_end_date ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a raffle ID and new end date.', 'raffle-for-woocommerce' ) ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_end_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format. Use YYYY-MM-DD.', 'raffle-for-woocommerce' ) ) );
		}

		$end_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $new_end_date . ' ' . $new_end_time, wp_timezone() );
		if ( ! $end_dt || $end_dt->getTimestamp() <= time() ) {
			wp_send_json_error( array( 'message' => __( 'New end date must be in the future.', 'raffle-for-woocommerce' ) ) );
		}

		$product = wc_get_product( $raffle_id );
		if ( ! $product || 'raffle' !== $product->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Raffle product not found.', 'raffle-for-woocommerce' ) ) );
		}

		$product->update_meta_data( '_rfwc_end_date', $new_end_date );
		$product->update_meta_data( '_rfwc_end_time', $new_end_time );
		$product->delete_meta_data( '_rfwc_status' );
		$product->save();

		$this->log_activity(
			$raffle_id,
			'raffle_extended',
			sprintf(
				/* translators: 1: raffle name, 2: new end date, 3: new end time */
				__( 'Raffle "%1$s" extended to %2$s %3$s.', 'raffle-for-woocommerce' ),
				$product->get_name(),
				$new_end_date,
				$new_end_time
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Raffle extended successfully! The raffle is now active again.', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * AJAX handler for relisting a raffle.
	 *
	 * @since 1.1.0
	 */
	public function ajax_relist_raffle() {
		check_ajax_referer( 'rfwc_winner_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle_id    = isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0;
		$new_end_date = isset( $_POST['new_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_date'] ) ) : '';
		$new_end_time = isset( $_POST['new_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_end_time'] ) ) : '23:59';

		if ( ! $raffle_id || ! $new_end_date ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a raffle ID and new end date.', 'raffle-for-woocommerce' ) ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_end_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format. Use YYYY-MM-DD.', 'raffle-for-woocommerce' ) ) );
		}

		$end_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $new_end_date . ' ' . $new_end_time, wp_timezone() );
		if ( ! $end_dt || $end_dt->getTimestamp() <= time() ) {
			wp_send_json_error( array( 'message' => __( 'New end date must be in the future.', 'raffle-for-woocommerce' ) ) );
		}

		$product = wc_get_product( $raffle_id );
		if ( ! $product || 'raffle' !== $product->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Raffle product not found.', 'raffle-for-woocommerce' ) ) );
		}

		// Clear winners.
		RFWC_Winner_Selector::clear_winners( $raffle_id );

		// Reset status and set new end date.
		$product->update_meta_data( '_rfwc_end_date', $new_end_date );
		$product->update_meta_data( '_rfwc_end_time', $new_end_time );
		$product->delete_meta_data( '_rfwc_status' );
		$product->save();

		$this->log_activity(
			$raffle_id,
			'raffle_relisted',
			sprintf(
				/* translators: 1: raffle name, 2: new end date, 3: new end time */
				__( 'Raffle "%1$s" relisted with new end date %2$s %3$s. Winners cleared.', 'raffle-for-woocommerce' ),
				$product->get_name(),
				$new_end_date,
				$new_end_time
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Raffle relisted successfully! Winners cleared and raffle is active again.', 'raffle-for-woocommerce' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Write an entry to the activity log.
	 *
	 * @since 1.0.4
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $action    Action slug.
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
}

