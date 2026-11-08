<?php
/**
 * My Tickets template.
 *
 * Displays the customer's raffle tickets in the My Account area.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Ticket[] $tickets         Array of tickets.
 * @var array        $grouped_tickets Tickets grouped by raffle.
 * @var string       $current_filter  Current filter.
 * @var string       $user_email      User email.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables from wc_get_template().

// Flatten grouped tickets into a single list.
$all_tickets = array();
foreach ( $grouped_tickets as $group ) {
	foreach ( $group['tickets'] as $ticket ) {
		$all_tickets[] = $ticket;
	}
}

// Sort by creation date (newest first).
usort(
	$all_tickets,
	function ( $a, $b ) {
		return strtotime( $b->get_created_at() ) - strtotime( $a->get_created_at() );
	}
);

$has_tickets = ! empty( $all_tickets );
?>

<?php if ( $has_tickets ) : ?>

	<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></span></th>
				<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date"><span class="nobr"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></span></th>
				<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?></span></th>
				<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php esc_html_e( 'Holder', 'raffle-for-woocommerce' ); ?></span></th>
				<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions"><span class="nobr"><?php esc_html_e( 'Actions', 'raffle-for-woocommerce' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $all_tickets as $ticket ) : ?>
				<?php
				$raffle        = wc_get_product( $ticket->get_raffle_id() );
				$raffle_name   = $raffle ? $raffle->get_name() : __( 'Unknown Raffle', 'raffle-for-woocommerce' );
				$raffle_link   = $raffle ? get_permalink( $raffle->get_id() ) : '';
				$order_id      = $ticket->get_order_id();
				$is_purchaser  = strtolower( $ticket->get_purchaser_email() ) === strtolower( $user_email );
				$is_gift       = $ticket->is_gift();
				$ticket_status = $ticket->get_status();

				// Determine ticket type label.
				$type_label = '';
				if ( $is_gift ) {
					$type_label = $is_purchaser ? __( 'Gift you sent', 'raffle-for-woocommerce' ) : __( 'Gift received', 'raffle-for-woocommerce' );
				}
				?>
				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $ticket_status ); ?> order">
					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Ticket', 'raffle-for-woocommerce' ); ?>">
						<span class="rfwc-ticket-number"><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></span>
						<?php if ( $type_label ) : ?>
							<small class="rfwc-ticket-type"><?php echo esc_html( $type_label ); ?></small>
						<?php endif; ?>
					</td>

					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( 'Raffle', 'raffle-for-woocommerce' ); ?>">
						<?php if ( $raffle_link ) : ?>
							<a href="<?php echo esc_url( $raffle_link ); ?>"><?php echo esc_html( $raffle_name ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $raffle_name ); ?>
						<?php endif; ?>
					</td>

					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'raffle-for-woocommerce' ); ?>">
						<?php
						$frontend_status_labels = array(
							'active'    => __( 'Active', 'raffle-for-woocommerce' ),
							'winner'    => __( 'Winner!', 'raffle-for-woocommerce' ),
							'cancelled' => __( 'Cancelled', 'raffle-for-woocommerce' ),
							'expired'   => __( 'Expired', 'raffle-for-woocommerce' ),
							'refunded'  => __( 'Refunded', 'raffle-for-woocommerce' ),
						);
						echo esc_html( isset( $frontend_status_labels[ $ticket_status ] ) ? $frontend_status_labels[ $ticket_status ] : ucfirst( $ticket_status ) );

						// Show instant win result if applicable.
						if ( class_exists( 'RFWC_Instant_Win_Evaluator' ) ) {
							$iw_result = RFWC_Instant_Win_Evaluator::get_ticket_result( $ticket );
							if ( 'won' === $iw_result ) {
								echo ' <span class="rfwc-iw-tag rfwc-iw-tag-won">&#9889; ' . esc_html__( 'Instant Win!', 'raffle-for-woocommerce' ) . '</span>';
							} elseif ( 'lost' === $iw_result ) {
								echo ' <small class="rfwc-iw-tag rfwc-iw-tag-lost">' . esc_html__( 'No instant win', 'raffle-for-woocommerce' ) . '</small>';
							}
						}
						?>
					</td>

					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php esc_attr_e( 'Holder', 'raffle-for-woocommerce' ); ?>">
						<?php echo esc_html( $ticket->get_holder_name() ); ?>
					</td>

					<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e( 'Actions', 'raffle-for-woocommerce' ); ?>">
						<?php if ( 'active' === $ticket_status || 'winner' === $ticket_status ) : ?>
							<a href="<?php echo esc_url( RFWC_PDF_Ticket::get_download_url( $ticket ) ); ?>" class="woocommerce-button button" target="_blank">
								<?php esc_html_e( 'Print', 'raffle-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $is_purchaser && $order_id ) : ?>
							<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', $order_id, wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="woocommerce-button button view">
								<?php esc_html_e( 'Order', 'raffle-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
						<?php
						/**
						 * Fires after the default actions in each My Account ticket row.
						 *
						 * Addons can hook here to add instant win results, ticket grid
						 * references, or other per-ticket information.
						 *
						 * @since 1.1.0
						 *
						 * @param RFWC_Ticket $ticket The current ticket object.
						 */
						do_action( 'rfwc_after_ticket_display', $ticket );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php else : ?>

	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<?php esc_html_e( 'No raffle tickets yet.', 'raffle-for-woocommerce' ); ?>
		<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using WooCommerce core filter. ?>
		<a class="woocommerce-Button button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php esc_html_e( 'Browse raffles', 'raffle-for-woocommerce' ); ?>
		</a>
	</div>

<?php endif; ?>
