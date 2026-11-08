<?php
/**
 * Automatic ticket creation on order completion.
 *
 * Generates raffle tickets when WooCommerce orders containing
 * raffle products are marked as completed or processing.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates tickets when orders with raffle products are paid.
 *
 * @since 1.0.0
 */
class RFWC_Ticket_Generator {

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
		// Generate tickets when order is completed or processing.
		add_action( 'woocommerce_order_status_completed', array( $this, 'generate_tickets_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_generate_tickets_for_order' ), 10, 1 );

		// Cancel tickets when order is cancelled or refunded.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_tickets_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_tickets_for_order' ), 10, 1 );

		// Reactivate tickets when order is restored from cancelled.
		add_action( 'woocommerce_order_status_cancelled_to_processing', array( $this, 'reactivate_tickets_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled_to_completed', array( $this, 'reactivate_tickets_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled_to_on-hold', array( $this, 'reactivate_tickets_for_order' ), 10, 1 );

		// Display tickets in order details (admin and frontend).
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'display_tickets_in_order' ), 10, 3 );

		// Hide raw meta data from order item display.
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_order_item_meta' ) );

		// Display gift info on frontend order details.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_gift_info_frontend' ), 10, 1 );

		// Cancel tickets when an order is permanently deleted.
		add_action( 'before_delete_post', array( $this, 'cancel_tickets_on_order_delete' ), 10, 1 );
		// HPOS: woocommerce_before_delete_order fires for HPOS-managed orders.
		add_action( 'woocommerce_before_delete_order', array( $this, 'cancel_tickets_on_order_delete' ), 10, 1 );
	}

	/**
	 * Maybe generate tickets for order on processing status.
	 *
	 * Generates tickets unless the admin chose "Order completed" as the trigger,
	 * in which case tickets are only generated when the order reaches Completed.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function maybe_generate_tickets_for_order( $order_id ) {
		$trigger = get_option( 'rfwc_generate_tickets_on', 'payment' );

		if ( 'completed' !== $trigger ) {
			$this->generate_tickets_for_order( $order_id );
		}
	}

	/**
	 * Generate tickets for an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array Array of generated ticket IDs.
	 */
	public function generate_tickets_for_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array();
		}

		// Check if tickets already generated for this order.
		$tickets_generated = $order->get_meta( '_rfwc_tickets_generated', true );
		if ( 'yes' === $tickets_generated ) {
			return array();
		}

		// Prevent duplicate generation from concurrent status transitions.
		$lock_key = 'rfwc_generating_tickets_' . $order_id;
		if ( get_transient( $lock_key ) ) {
			return array();
		}
		set_transient( $lock_key, true, 60 );

		// Re-check after acquiring the lock (another request may have completed).
		$order             = wc_get_order( $order_id ); // Refresh order data.
		$tickets_generated = $order->get_meta( '_rfwc_tickets_generated', true );
		if ( 'yes' === $tickets_generated ) {
			delete_transient( $lock_key );
			return array();
		}

		$generated_tickets = array();

		// Get order-level gift information (backward compat for orders created before v1.6.0).
		$order_is_gift              = 'yes' === $order->get_meta( '_rfwc_is_gift', true );
		$order_recipient_first_name = $order->get_meta( '_rfwc_recipient_first_name', true );
		$order_recipient_last_name  = $order->get_meta( '_rfwc_recipient_last_name', true );
		$order_recipient_email      = $order->get_meta( '_rfwc_recipient_email', true );
		$order_gift_message         = $order->get_meta( '_rfwc_gift_message', true );
		$order_recipient_name       = trim( $order_recipient_first_name . ' ' . $order_recipient_last_name );

		// Get purchaser information.
		$purchaser_id    = $order->get_customer_id();
		$purchaser_name  = $order->get_formatted_billing_full_name();
		$purchaser_email = $order->get_billing_email();

		// Process each order item.
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$raffle_id = $product->get_id();
			$quantity  = $item->get_quantity();

			// Ensure we don't exceed available tickets.
			$total_tickets = $product->get_total_tickets();
			if ( 'unlimited' !== $total_tickets ) {
				$sold      = $product->get_tickets_sold();
				$available = max( 0, (int) $total_tickets - (int) $sold );
				if ( $quantity > $available ) {
					$quantity = $available;
				}
			}

			// Check for per-ticket recipients (v1.6.0+) on the order item.
			$item_is_gift    = 'yes' === $item->get_meta( '_rfwc_is_gift', true );
			$item_recipients = $item->get_meta( '_rfwc_gift_recipients', true );
			$has_per_ticket  = $item_is_gift && is_array( $item_recipients ) && ! empty( $item_recipients );

			// Fall back to order-level gift data if no per-ticket data exists.
			$is_gift = $has_per_ticket || $order_is_gift;

			// Safety net: if the recipient list has more entries than the line
			// item quantity (can happen when Block Checkout's async cart quantity
			// update didn't complete), trust the recipient count as the source
			// of truth for gift purchases and correct both quantity and totals.
			if ( $has_per_ticket && count( $item_recipients ) > $quantity ) {
				$old_qty       = max( 1, $quantity );
				$quantity      = count( $item_recipients );
				$unit_subtotal = (float) $item->get_subtotal() / $old_qty;
				$unit_total    = (float) $item->get_total() / $old_qty;

				$item->set_quantity( $quantity );
				$item->set_subtotal( round( $unit_subtotal * $quantity, 2 ) );
				$item->set_total( round( $unit_total * $quantity, 2 ) );
				$item->save();

				$order->calculate_totals( false );
				$order->save();
			}

			// Generate tickets for each quantity.
			for ( $i = 0; $i < $quantity; $i++ ) {
				$ticket_number = $this->generate_ticket_number( $raffle_id, $product );

				$ticket = new RFWC_Ticket();
				$ticket->set_ticket_number( $ticket_number );
				$ticket->set_raffle_id( $raffle_id );
				$ticket->set_order_id( $order_id );
				$ticket->set_order_item_id( $item_id );
				$ticket->set_purchaser_id( $purchaser_id );
				$ticket->set_purchaser_name( $purchaser_name );
				$ticket->set_purchaser_email( $purchaser_email );

				// Set recipient based on gift status.
				if ( $has_per_ticket && isset( $item_recipients[ $i ] ) && ! empty( $item_recipients[ $i ]['is_self'] ) ) {
					// Self-claim slot: buyer claimed this ticket for themselves.
					// Route to the non-gift path so the buyer keeps the ticket
					// and the gift email won't fire for this index.
					$ticket->set_recipient_name( $purchaser_name );
					$ticket->set_recipient_email( $purchaser_email );
					$ticket->set_is_gift( false );
				} elseif ( $has_per_ticket && isset( $item_recipients[ $i ] ) ) {
					// Per-ticket recipient from v1.6.0+ order item meta.
					$r_first = isset( $item_recipients[ $i ]['first_name'] ) ? $item_recipients[ $i ]['first_name'] : '';
					$r_last  = isset( $item_recipients[ $i ]['last_name'] ) ? $item_recipients[ $i ]['last_name'] : '';
					$r_email = isset( $item_recipients[ $i ]['email'] ) ? $item_recipients[ $i ]['email'] : '';
					$r_msg   = isset( $item_recipients[ $i ]['message'] ) ? $item_recipients[ $i ]['message'] : '';

					$ticket->set_recipient_name( trim( $r_first . ' ' . $r_last ) );
					$ticket->set_recipient_email( $r_email );
					$ticket->set_is_gift( true );
					$ticket->set_gift_message( $r_msg );
				} elseif ( $has_per_ticket && ! isset( $item_recipients[ $i ] ) && $order_is_gift && ! empty( $order_recipient_name ) ) {
					// Partial per-ticket data: recipient missing for this index.
					// Fall back to order-level gift data but still mark as gift.
					$ticket->set_recipient_name( $order_recipient_name );
					$ticket->set_recipient_email( $order_recipient_email );
					$ticket->set_is_gift( true );
					$ticket->set_gift_message( $order_gift_message );
				} elseif ( $order_is_gift && ! empty( $order_recipient_name ) ) {
					// Backward compat: order-level gift data (pre-v1.6.0 orders).
					$ticket->set_recipient_name( $order_recipient_name );
					$ticket->set_recipient_email( $order_recipient_email );
					$ticket->set_is_gift( true );
					$ticket->set_gift_message( $order_gift_message );
				} else {
					// Non-gift: purchaser is the recipient.
					$ticket->set_recipient_name( $purchaser_name );
					$ticket->set_recipient_email( $purchaser_email );
					$ticket->set_is_gift( false );
				}

				$ticket->set_status( 'active' );

				$ticket_id = $ticket->save();

				if ( $ticket_id ) {
					$generated_tickets[] = $ticket_id;

					/**
					 * Fires after a ticket is generated.
					 *
					 * @since 1.0.0
					 *
					 * @param RFWC_Ticket $ticket   The generated ticket.
					 * @param WC_Order    $order    The order object.
					 * @param bool        $is_gift  Whether this is a gift ticket.
					 */
					do_action( 'rfwc_ticket_generated', $ticket, $order, $is_gift );
				}
			}

			// Store ticket count on order item.
			wc_update_order_item_meta( $item_id, '_rfwc_ticket_count', $quantity );
		}

		// Mark order as having tickets generated.
		if ( ! empty( $generated_tickets ) ) {
			$order->update_meta_data( '_rfwc_tickets_generated', 'yes' );
			$order->update_meta_data( '_rfwc_ticket_ids', $generated_tickets );
			$order->save();

			/**
			 * Fires after tickets are generated for an order.
			 *
			 * @since 1.0.0
			 *
			 * @param int   $order_id          Order ID.
			 * @param array $generated_tickets Array of ticket IDs.
			 */
			do_action( 'rfwc_order_tickets_generated', $order_id, $generated_tickets );

			// Log the generation.
			$this->log_ticket_generation( $order_id, count( $generated_tickets ) );

			// Trigger email notifications.
			$this->trigger_ticket_emails( $order, $generated_tickets );

			// Check for early draw on sold-out raffles.
			$this->maybe_trigger_early_draw( $generated_tickets );
		}

		// Release the generation lock.
		delete_transient( $lock_key );

		return $generated_tickets;
	}

	/**
	 * Trigger email notifications for generated tickets.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $ticket_ids Array of generated ticket IDs.
	 */
	private function trigger_ticket_emails( $order, $ticket_ids ) {
		// Group tickets by raffle and type (gift vs non-gift).
		$tickets_by_raffle = array();

		foreach ( $ticket_ids as $ticket_id ) {
			$ticket = new RFWC_Ticket( $ticket_id );

			if ( ! $ticket->get_id() ) {
				continue;
			}

			$raffle_id = $ticket->get_raffle_id();
			$is_gift   = $ticket->is_gift();

			if ( ! isset( $tickets_by_raffle[ $raffle_id ] ) ) {
				$tickets_by_raffle[ $raffle_id ] = array(
					'gift'     => array(),
					'non_gift' => array(),
				);
			}

			if ( $is_gift ) {
				$tickets_by_raffle[ $raffle_id ]['gift'][] = $ticket;
			} else {
				$tickets_by_raffle[ $raffle_id ]['non_gift'][] = $ticket;
			}
		}

		// Trigger emails for each raffle.
		foreach ( $tickets_by_raffle as $raffle_id => $ticket_groups ) {
			// Non-gift tickets: send confirmation to purchaser.
			if ( ! empty( $ticket_groups['non_gift'] ) ) {
				/**
				 * Triggers ticket confirmation email to purchaser.
				 *
				 * @since 1.0.0
				 *
				 * @param int   $order_id  Order ID.
				 * @param array $tickets   Array of RFWC_Ticket objects.
				 * @param int   $raffle_id Raffle product ID.
				 */
				do_action( 'rfwc_tickets_generated', $order->get_id(), $ticket_groups['non_gift'], $raffle_id );
			}

			// Gift tickets: group by recipient email and send each their own email.
			if ( ! empty( $ticket_groups['gift'] ) ) {
				$tickets_by_recipient = array();

				foreach ( $ticket_groups['gift'] as $gift_ticket ) {
					$email_key = strtolower( trim( $gift_ticket->get_recipient_email() ) );
					if ( ! empty( $email_key ) ) {
						$tickets_by_recipient[ $email_key ][] = $gift_ticket;
					}
				}

				foreach ( $tickets_by_recipient as $recipient_email => $recipient_tickets ) {
					/**
					 * Triggers gift received email to recipient.
					 *
					 * @since 1.0.0
					 * @since 1.1.0 Now fires once per unique recipient email.
					 *
					 * @param int    $order_id        Order ID.
					 * @param array  $tickets         Array of RFWC_Ticket objects for this recipient.
					 * @param int    $raffle_id       Raffle product ID.
					 * @param string $recipient_email Recipient email address.
					 */
					do_action( 'rfwc_gift_tickets_generated', $order->get_id(), $recipient_tickets, $raffle_id, $recipient_email );
				}
			}
		}
	}

	/**
	 * Generate a ticket number.
	 *
	 * @since 1.0.0
	 *
	 * @param int                 $raffle_id Raffle ID.
	 * @param RFWC_Product_Raffle $product   Raffle product.
	 *
	 * @return string Ticket number.
	 */
	private function generate_ticket_number( $raffle_id, $product ) {
		$format = $product->get_ticket_format();

		/**
		 * Filter the generated ticket number.
		 *
		 * Return a non-null value to override the core generation logic.
		 * Used by Pro to implement custom formats (e.g. alphabet).
		 *
		 * @since 1.1.0
		 *
		 * @param string|null $number    Ticket number or null for core logic.
		 * @param int         $raffle_id Raffle product ID.
		 * @param WC_Product  $product   Raffle product.
		 * @param string      $format    Ticket format key.
		 */
		$number = apply_filters( 'rfwc_generate_ticket_number', null, $raffle_id, $product, $format );
		if ( null !== $number ) {
			return $number;
		}

		if ( 'random' === $format ) {
			return $this->generate_random_number( $raffle_id );
		}

		return $this->generate_sequential_number( $raffle_id );
	}

	/**
	 * Generate a sequential ticket number.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return string Ticket number.
	 */
	private function generate_sequential_number( $raffle_id ) {
		$next_number = RFWC_Ticket::get_next_number( $raffle_id );

		// Pad number with zeros based on total tickets.
		$product       = wc_get_product( $raffle_id );
		$total_tickets = $product ? $product->get_total_tickets() : 'unlimited';

		if ( 'unlimited' !== $total_tickets && $total_tickets > 0 ) {
			$padding = strlen( (string) $total_tickets );
			return str_pad( $next_number, max( $padding, 4 ), '0', STR_PAD_LEFT );
		}

		return str_pad( $next_number, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Generate a random ticket number.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return string Ticket number.
	 */
	private function generate_random_number( $raffle_id ) {
		$max_attempts = 100;
		$attempts     = 0;

		do {
			// Generate a random alphanumeric string.
			$number = strtoupper( wp_generate_password( 8, false, false ) );
			$exists = RFWC_Ticket::number_exists( $raffle_id, $number );
			++$attempts;
		} while ( $exists && $attempts < $max_attempts );

		// Fallback to sequential if too many collisions.
		if ( $exists ) {
			return $this->generate_sequential_number( $raffle_id );
		}

		return $number;
	}

	/**
	 * Check if any raffles became sold out and trigger early draw if enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param array $ticket_ids Array of generated ticket IDs.
	 */
	private function maybe_trigger_early_draw( $ticket_ids ) {
		$checked_raffles = array();

		foreach ( $ticket_ids as $ticket_id ) {
			$ticket = new RFWC_Ticket( $ticket_id );
			if ( ! $ticket->get_id() ) {
				continue;
			}

			$raffle_id = $ticket->get_raffle_id();
			if ( isset( $checked_raffles[ $raffle_id ] ) ) {
				continue;
			}
			$checked_raffles[ $raffle_id ] = true;

			$product = wc_get_product( $raffle_id );
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			if ( ! $product->is_sold_out() ) {
				continue;
			}

			// Must have auto-draw enabled.
			if ( 'yes' !== $product->get_meta( '_rfwc_auto_draw', true ) ) {
				continue;
			}

			// Must not already have a status set (completed/failed/cancelled).
			$status = $product->get_meta( '_rfwc_status', true );
			if ( ! empty( $status ) ) {
				continue;
			}

			// Must not already have draw winners (instant win records should not block early draw).
			if ( RFWC_Winner::raffle_has_draw_winners( $raffle_id ) ) {
				continue;
			}

			// Check early draw setting: per-product overrides global.
			$per_product = $product->get_meta( '_rfwc_early_draw_on_sellout', true );
			if ( 'yes' === $per_product ) {
				$early_draw_enabled = true;
			} elseif ( 'no' === $per_product ) {
				$early_draw_enabled = false;
			} else {
				$early_draw_enabled = 'yes' === get_option( 'rfwc_auto_close_sold_out', 'no' );
			}

			if ( ! $early_draw_enabled ) {
				continue;
			}

			// Schedule an immediate draw.
			if ( ! wp_next_scheduled( 'rfwc_auto_draw_raffle', array( $raffle_id ) ) ) {
				wp_schedule_single_event( time(), 'rfwc_auto_draw_raffle', array( $raffle_id ) );
			}

			$this->add_log_entry(
				$raffle_id,
				'early_draw_triggered',
				__( 'All tickets sold — early draw scheduled.', 'raffle-for-woocommerce' )
			);
		}
	}

	/**
	 * Cancel tickets for an order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function cancel_tickets_for_order( $order_id ) {
		$tickets = RFWC_Ticket::get_by_order( $order_id );

		foreach ( $tickets as $ticket ) {
			// Preserve winner and refunded status — cancelling an order shouldn't
			// destroy the audit trail or override an explicit admin refund.
			if ( in_array( $ticket->get_status(), array( 'winner', 'refunded' ), true ) ) {
				continue;
			}

			$ticket->set_status( 'cancelled' );
			$ticket->save();

			/**
			 * Fires after a ticket is cancelled.
			 *
			 * @since 1.0.0
			 *
			 * @param RFWC_Ticket $ticket   The cancelled ticket.
			 * @param int        $order_id Order ID.
			 */
			do_action( 'rfwc_ticket_cancelled', $ticket, $order_id );
		}

		// Update order meta.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_rfwc_tickets_cancelled', 'yes' );
			$order->save();
		}

		// Log the cancellation.
		$this->log_ticket_cancellation( $order_id, count( $tickets ) );
	}

	/**
	 * Reactivate cancelled tickets when an order is restored.
	 *
	 * Only reactivates tickets that are in 'cancelled' status and belong to a raffle
	 * that has not yet been drawn (no winners selected). Tickets for completed raffles
	 * are left as-is to avoid interfering with draw results.
	 *
	 * @since 1.1.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function reactivate_tickets_for_order( $order_id ) {
		$tickets     = RFWC_Ticket::get_by_order( $order_id );
		$reactivated = 0;

		foreach ( $tickets as $ticket ) {
			if ( 'cancelled' !== $ticket->get_status() ) {
				continue;
			}

			// Don't reactivate if the raffle already has draw winners (IW records shouldn't block).
			if ( RFWC_Winner::raffle_has_draw_winners( $ticket->get_raffle_id() ) ) {
				continue;
			}

			$ticket->set_status( 'active' );
			$ticket->save();
			++$reactivated;
		}

		if ( $reactivated > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete_meta_data( '_rfwc_tickets_cancelled' );
				$order->save();
			}
		}
	}

	/**
	 * Cancel tickets when an order is permanently deleted.
	 *
	 * Prevents orphaned 'active' tickets that reference a non-existent order
	 * from remaining eligible for the winner draw.
	 *
	 * @since 1.1.0
	 *
	 * @param int $order_id Post/order ID being deleted.
	 */
	public function cancel_tickets_on_order_delete( $order_id ) {
		// Only act on shop orders.
		if ( 'shop_order' !== get_post_type( $order_id ) && ! ( function_exists( 'wc_get_order' ) && wc_get_order( $order_id ) ) ) {
			return;
		}

		$tickets = RFWC_Ticket::get_by_order( $order_id );

		foreach ( $tickets as $ticket ) {
			if ( 'cancelled' !== $ticket->get_status() ) {
				$ticket->set_status( 'cancelled' );
				$ticket->save();
			}
		}
	}

	/**
	 * Display tickets in order item details.
	 *
	 * @since 1.0.0
	 *
	 * @param int           $item_id Item ID.
	 * @param WC_Order_Item $item    Order item.
	 * @param WC_Order      $order   Order object.
	 */
	public function display_tickets_in_order( $item_id, $item, $order ) {
		$product = $item->get_product();

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		$tickets = $this->get_tickets_for_order_item( $order->get_id(), $item_id );

		if ( empty( $tickets ) ) {
			return;
		}

		$status_labels = array(
			'active'    => __( 'Active', 'raffle-for-woocommerce' ),
			'winner'    => __( 'Winner', 'raffle-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'raffle-for-woocommerce' ),
			'expired'   => __( 'Expired', 'raffle-for-woocommerce' ),
			'refunded'  => __( 'Refunded', 'raffle-for-woocommerce' ),
		);

		echo '<div class="rfwc-order-tickets">';
		echo '<strong>' . esc_html__( 'Ticket Numbers:', 'raffle-for-woocommerce' ) . '</strong> ';

		$ticket_parts = array();
		foreach ( $tickets as $ticket ) {
			$t_status = $ticket->get_status();
			$number   = esc_html( $ticket->get_formatted_ticket_number() );
			if ( 'active' !== $t_status ) {
				$label          = isset( $status_labels[ $t_status ] ) ? $status_labels[ $t_status ] : ucfirst( $t_status );
				$ticket_parts[] = $number . ' <small>(' . esc_html( $label ) . ')</small>';
			} else {
				$ticket_parts[] = $number;
			}
		}

		echo wp_kses_post( implode( ', ', $ticket_parts ) );
		echo '</div>';
	}

	/**
	 * Get tickets for a specific order item.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id  Order item ID.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public function get_tickets_for_order_item( $order_id, $item_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE order_id = %d AND order_item_id = %d ORDER BY id ASC',
				absint( $order_id ),
				absint( $item_id )
			)
		);

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new RFWC_Ticket( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Log ticket generation.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id     Order ID.
	 * @param int $ticket_count Number of tickets generated.
	 */
	private function log_ticket_generation( $order_id, $ticket_count ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Find raffle IDs from tickets.
		$tickets    = RFWC_Ticket::get_by_order( $order_id );
		$raffle_ids = array_unique(
			wp_list_pluck(
				array_map(
					function ( $t ) {
						return (object) array( 'raffle_id' => $t->get_raffle_id() );
					},
					$tickets
				),
				'raffle_id'
			)
		);

		foreach ( $raffle_ids as $raffle_id ) {
			$this->add_log_entry(
				$raffle_id,
				'tickets_generated',
				sprintf(
					/* translators: 1: ticket count, 2: order ID */
					__( '%1$d tickets generated for order #%2$d', 'raffle-for-woocommerce' ),
					$ticket_count,
					$order_id
				)
			);
		}
	}

	/**
	 * Log ticket cancellation.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id     Order ID.
	 * @param int $ticket_count Number of tickets cancelled.
	 */
	private function log_ticket_cancellation( $order_id, $ticket_count ) {
		$tickets    = RFWC_Ticket::get_by_order( $order_id );
		$raffle_ids = array_unique(
			wp_list_pluck(
				array_map(
					function ( $t ) {
						return (object) array( 'raffle_id' => $t->get_raffle_id() );
					},
					$tickets
				),
				'raffle_id'
			)
		);

		foreach ( $raffle_ids as $raffle_id ) {
			$this->add_log_entry(
				$raffle_id,
				'tickets_cancelled',
				sprintf(
					/* translators: 1: ticket count, 2: order ID */
					__( '%1$d tickets cancelled for order #%2$d', 'raffle-for-woocommerce' ),
					$ticket_count,
					$order_id
				)
			);
		}
	}

	/**
	 * Add a log entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $action    Action performed.
	 * @param string $message   Log message.
	 */
	private function add_log_entry( $raffle_id, $action, $message ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'raffle_id' => $raffle_id,
				'action'    => $action,
				'details'   => $message,
				'user_id'   => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Get tickets for a user (by email).
	 *
	 * @since 1.0.0
	 *
	 * @param string $email User email.
	 * @param string $type  Type: 'purchased', 'received', or 'all'.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public static function get_user_tickets( $email, $type = 'all' ) {
		if ( 'purchased' === $type ) {
			return RFWC_Ticket::get_by_purchaser_email( $email );
		}

		if ( 'received' === $type ) {
			return RFWC_Ticket::get_by_recipient_email( $email );
		}

		// Get all tickets (purchased and received).
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE purchaser_email = %s OR recipient_email = %s ORDER BY created_at DESC',
				sanitize_email( $email ),
				sanitize_email( $email )
			)
		);

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new RFWC_Ticket( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Hide internal order item meta from display.
	 *
	 * This prevents raw meta keys like _rfwc_ticket_count from showing
	 * in admin and frontend order details.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hidden_meta Array of hidden meta keys.
	 *
	 * @return array Modified array of hidden meta keys.
	 */
	public function hide_order_item_meta( $hidden_meta ) {
		$hidden_meta[] = '_rfwc_ticket_count';
		$hidden_meta[] = '_rfwc_tickets_generated';
		$hidden_meta[] = '_rfwc_is_gift';
		$hidden_meta[] = '_rfwc_gift_recipients';
		$hidden_meta[] = '_rfwc_skill_question_audit';
		$hidden_meta[] = '_rfwc_bulk_original_price';
		$hidden_meta[] = '_rfwc_bulk_discount_pct';
		return $hidden_meta;
	}

	/**
	 * Display gift information on frontend order details.
	 *
	 * Shows per-ticket recipient details so the customer knows
	 * which ticket was assigned to which recipient.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Now shows all per-ticket recipients with ticket numbers.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_gift_info_frontend( $order ) {
		// Only show on frontend.
		if ( is_admin() ) {
			return;
		}

		$is_gift = $order->get_meta( '_rfwc_is_gift' );

		if ( 'yes' !== $is_gift ) {
			return;
		}

		// Build per-ticket recipient data from actual tickets.
		$tickets = class_exists( 'RFWC_Ticket' ) ? RFWC_Ticket::get_by_order( $order->get_id() ) : array();

		// Collect gift tickets with their recipient info.
		$gift_entries = array();
		foreach ( $tickets as $ticket ) {
			if ( $ticket->is_gift() ) {
				$gift_entries[] = array(
					'ticket_number' => $ticket->get_formatted_ticket_number(),
					'name'          => $ticket->get_holder_name(),
					'email'         => $ticket->get_recipient_email(),
					'message'       => $ticket->get_gift_message(),
				);
			}
		}

		// Fallback to order-level data if no tickets generated yet.
		if ( empty( $gift_entries ) ) {
			$gift_entries = $this->get_gift_entries_from_order_meta( $order );
		}

		if ( empty( $gift_entries ) ) {
			return;
		}

		// Check if all recipients are the same person.
		$unique_recipients = array();
		foreach ( $gift_entries as $entry ) {
			$key                       = strtolower( trim( $entry['email'] ) );
			$unique_recipients[ $key ] = $entry;
		}

		$has_multiple_recipients = count( $unique_recipients ) > 1;
		?>
		<section class="rfwc-gift-details">
			<h2><?php esc_html_e( 'Gift Details', 'raffle-for-woocommerce' ); ?></h2>

			<?php if ( $has_multiple_recipients ) : ?>
				<table class="woocommerce-table woocommerce-table--gift-details shop_table gift_details">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Recipient', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Email', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gift_entries as $entry ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $entry['ticket_number'] ) ) : ?>
										<code><?php echo esc_html( $entry['ticket_number'] ); ?></code>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $entry['name'] ); ?></td>
								<td><?php echo esc_html( $entry['email'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				// Show gift messages if any (group by unique message).
				$messages = array();
				foreach ( $gift_entries as $entry ) {
					if ( ! empty( $entry['message'] ) ) {
						$messages[ $entry['message'] ] = $entry['name'];
					}
				}

				if ( ! empty( $messages ) ) :
					foreach ( $messages as $msg => $sender_name ) :
						?>
						<p class="rfwc-gift-message-entry">
							<strong><?php echo esc_html( $sender_name ); ?>:</strong>
							<em>&ldquo;<?php echo esc_html( $msg ); ?>&rdquo;</em>
						</p>
						<?php
					endforeach;
				endif;
				?>

		<?php else : ?>
			<?php
			// Single recipient — simpler table.
			$single = reset( $unique_recipients );
			?>
			<table class="woocommerce-table woocommerce-table--gift-details shop_table gift_details">
				<tbody>
					<?php if ( ! empty( $single['name'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Recipient:', 'raffle-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $single['name'] ); ?></td>
					</tr>
					<?php endif; ?>
					<?php if ( ! empty( $single['email'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Recipient Email:', 'raffle-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $single['email'] ); ?></td>
					</tr>
					<?php endif; ?>
						<?php if ( count( $gift_entries ) > 1 ) : ?>
							<tr>
								<th><?php esc_html_e( 'Tickets:', 'raffle-for-woocommerce' ); ?></th>
								<td>
									<?php
									$nums = wp_list_pluck( $gift_entries, 'ticket_number' );
									echo esc_html( implode( ', ', array_filter( $nums ) ) );
									?>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $single['message'] ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Gift Message:', 'raffle-for-woocommerce' ); ?></th>
								<td><em><?php echo esc_html( $single['message'] ); ?></em></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="rfwc-gift-note">
				<em><?php esc_html_e( 'The raffle tickets have been assigned to the recipients. They can view them by logging in with their email address.', 'raffle-for-woocommerce' ); ?></em>
			</p>
		</section>
		<?php
	}

	/**
	 * Build gift entries from order-level meta (fallback when tickets are not generated yet).
	 *
	 * Also reads per-ticket recipient data from order line items when available.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Order $order Order object.
	 * @return array Array of gift entry arrays with name, email, message, ticket_number keys.
	 */
	private function get_gift_entries_from_order_meta( $order ) {
		$entries = array();

		// Try per-ticket recipients from order line items first.
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$item_recipients = $item->get_meta( '_rfwc_gift_recipients', true );

			if ( is_array( $item_recipients ) && ! empty( $item_recipients ) ) {
				foreach ( $item_recipients as $recipient ) {
					$first = isset( $recipient['first_name'] ) ? $recipient['first_name'] : '';
					$last  = isset( $recipient['last_name'] ) ? $recipient['last_name'] : '';
					$name  = trim( $first . ' ' . $last );
					$email = isset( $recipient['email'] ) ? $recipient['email'] : '';

					// Skip entries where both name and email are empty (incomplete data).
					if ( '' === $name && '' === $email ) {
						continue;
					}

					$entries[] = array(
						'ticket_number' => '',
						'name'          => $name,
						'email'         => $email,
						'message'       => isset( $recipient['message'] ) ? $recipient['message'] : '',
					);
				}
			}
		}

		if ( ! empty( $entries ) ) {
			return $entries;
		}

		// Fallback: order-level single recipient (pre-v1.6.0 orders).
		$name  = $order->get_meta( '_rfwc_recipient_name' );
		$email = $order->get_meta( '_rfwc_recipient_email' );
		$msg   = $order->get_meta( '_rfwc_gift_message' );

		if ( ! empty( $name ) || ! empty( $email ) ) {
			$entries[] = array(
				'ticket_number' => '',
				'name'          => $name,
				'email'         => $email,
				'message'       => $msg,
			);
		}

		return $entries;
	}
}

