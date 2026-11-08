<?php
/**
 * Prize Fulfillment Handler.
 *
 * Handles automatic prize fulfillment when winners are selected.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prize Fulfillment class.
 *
 * @since 1.0.0
 */
class RFWC_Prize_Fulfillment {

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
		// Handle prize fulfillment when winner is selected.
		add_action( 'rfwc_winner_selected', array( $this, 'fulfill_prize' ), 20, 2 );
	}

	/**
	 * Fulfill prize for a winner.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket    Winning ticket.
	 * @param int         $raffle_id Raffle product ID.
	 */
	public function fulfill_prize( $ticket, $raffle_id ) {
		$raffle = wc_get_product( $raffle_id );

		if ( ! $raffle || 'raffle' !== $raffle->get_type() ) {
			return;
		}

		/**
		 * Filter whether core should fulfill the prize for this winner.
		 *
		 * Return false to skip core fulfillment (e.g. when a Pro addon
		 * handles instant win prizes with a separate prize config).
		 *
		 * @since 1.1.0
		 *
		 * @param bool        $should_fulfill Whether to proceed with fulfillment.
		 * @param RFWC_Ticket $ticket         The winning ticket.
		 * @param WC_Product  $raffle         The raffle product.
		 */
		if ( ! apply_filters( 'rfwc_should_fulfill_prize', true, $ticket, $raffle ) ) {
			return;
		}

		// Check if auto-fulfill is enabled.
		if ( ! $raffle->is_auto_fulfill_enabled() ) {
			return;
		}

		$prize_type = $raffle->get_prize_type();

		switch ( $prize_type ) {
			case 'product':
				$this->create_prize_order( $ticket, $raffle );
				break;

			case 'store_credit':
				$this->award_store_credit( $ticket, $raffle );
				break;

			default:
				// Custom prizes require manual fulfillment.
				break;
		}
	}

	/**
	 * Create a prize order for the winner.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket         $ticket Winning ticket.
	 * @param RFWC_Product_Raffle $raffle Raffle product.
	 *
	 * @return int|false Order ID or false on failure.
	 */
	public function create_prize_order( $ticket, $raffle ) {
		// Get the winner from the ticket.
		$winner = RFWC_Winner::get_by_ticket( $ticket->get_id() );
		if ( ! $winner ) {
			return false;
		}

		// Idempotency guard: skip if a prize order was already created for this winner.
		$existing_order_id = $winner->get_prize_order_id();
		if ( $existing_order_id && wc_get_order( $existing_order_id ) ) {
			return $existing_order_id;
		}

		$prize_tier    = $winner->get_prize_tier();
		$prize_product = $raffle->get_prize_product( $prize_tier );

		if ( ! $prize_product ) {
			return false;
		}

		// Get winner details.
		$winner_name  = $ticket->get_holder_name();
		$winner_email = $ticket->get_holder_email();
		$name_parts   = explode( ' ', $winner_name, 2 );
		$first_name   = $name_parts[0];
		$last_name    = isset( $name_parts[1] ) ? $name_parts[1] : '';

		// Check if winner has an existing account.
		$user_id = email_exists( $winner_email );

		// Create order.
		$order = wc_create_order(
			array(
				'customer_id' => $user_id ? $user_id : 0,
				'status'      => 'processing',
			)
		);

		if ( is_wp_error( $order ) ) {
			return false;
		}

		// Add prize product with $0 price.
		$order->add_product(
			$prize_product,
			1,
			array(
				'subtotal' => 0,
				'total'    => 0,
			)
		);

		// Set billing address.
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_email( $winner_email );

		// Add order meta.
		$order->update_meta_data( '_rfwc_prize_order', 'yes' );
		$order->update_meta_data( '_rfwc_raffle_id', $raffle->get_id() );
		$order->update_meta_data( '_rfwc_ticket_id', $ticket->get_id() );
		$order->update_meta_data( '_rfwc_winner_id', $winner->get_id() );
		$order->update_meta_data( '_rfwc_prize_tier', $prize_tier );

		// Add order note.
		$order->add_order_note(
			sprintf(
				/* translators: %1$s: raffle name, %2$s: ticket number, %3$d: prize tier */
				__( 'Prize order created for raffle "%1$s". Winning ticket: %2$s (Prize tier: %3$d)', 'raffle-for-woocommerce' ),
				$raffle->get_name(),
				$ticket->get_formatted_ticket_number(),
				$prize_tier
			)
		);

		// Calculate totals and save.
		$order->calculate_totals();
		$order->save();

		// Update winner record with order ID.
		$winner->set_prize_order_id( $order->get_id() );
		$winner->save();

		/**
		 * Fires after a prize order is created.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order          $order  Prize order.
		 * @param RFWC_Ticket        $ticket Winning ticket.
		 * @param RFWC_Product_Raffle $raffle Raffle product.
		 */
		do_action( 'rfwc_prize_order_created', $order, $ticket, $raffle );

		return $order->get_id();
	}

	/**
	 * Award store credit to winner.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket         $ticket Winning ticket.
	 * @param RFWC_Product_Raffle $raffle Raffle product.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function award_store_credit( $ticket, $raffle ) {
		// Get the winner from the ticket to determine prize tier.
		$winner     = RFWC_Winner::get_by_ticket( $ticket->get_id() );
		$prize_tier = $winner ? $winner->get_prize_tier() : 1;

		// Idempotency guard: skip if credit/coupon was already awarded for this winner.
		if ( $winner && $winner->get_prize_coupon_code() ) {
			return true;
		}

		$credit_amount = $raffle->get_prize_credit_amount( $prize_tier );

		if ( $credit_amount <= 0 ) {
			return false;
		}

		$winner_email = $ticket->get_holder_email();
		$user_id      = email_exists( $winner_email );

		/**
		 * Filter to handle store credit awarding.
		 *
		 * Third-party store credit plugins can hook into this to award credit.
		 *
		 * @since 1.0.0
		 *
		 * @param bool              $awarded       Whether credit was awarded.
		 * @param float             $credit_amount Credit amount.
		 * @param string            $winner_email  Winner email.
		 * @param int|false         $user_id       User ID or false.
		 * @param RFWC_Ticket        $ticket        Winning ticket.
		 * @param RFWC_Product_Raffle $raffle        Raffle product.
		 */
		$awarded = apply_filters(
			'rfwc_award_store_credit',
			false,
			$credit_amount,
			$winner_email,
			$user_id,
			$ticket,
			$raffle
		);

		if ( ! $awarded ) {
			// Store credit plugin not available, create a coupon instead.
			$awarded = $this->create_prize_coupon( $ticket, $raffle, $credit_amount );
		}

		return $awarded;
	}

	/**
	 * Create a coupon as fallback for store credit.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket         $ticket        Winning ticket.
	 * @param RFWC_Product_Raffle $raffle        Raffle product.
	 * @param float               $credit_amount Credit amount.
	 *
	 * @return bool True on success.
	 */
	private function create_prize_coupon( $ticket, $raffle, $credit_amount ) {
		$winner_email = $ticket->get_holder_email();
		$winner_name  = $ticket->get_holder_name();

		// Generate unique coupon code.
		$coupon_code = 'PRIZE-' . strtoupper( wp_generate_password( 8, false ) );

		$coupon = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_description(
			sprintf(
				/* translators: %1$s: raffle name, %2$s: winner name */
				__( 'Prize coupon for raffle "%1$s" - Winner: %2$s', 'raffle-for-woocommerce' ),
				$raffle->get_name(),
				$winner_name
			)
		);
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $credit_amount );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_email_restrictions( array( $winner_email ) );

		// Add meta for tracking.
		$coupon->update_meta_data( '_rfwc_prize_coupon', 'yes' );
		$coupon->update_meta_data( '_rfwc_raffle_id', $raffle->get_id() );
		$coupon->update_meta_data( '_rfwc_ticket_id', $ticket->get_id() );

		$coupon->save();

		// Update winner record.
		$winner = RFWC_Winner::get_by_ticket( $ticket->get_id() );
		if ( $winner ) {
			$winner->set_prize_coupon_code( $coupon_code );
			$winner->save();
		}

		/**
		 * Fires after a prize coupon is created.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Coupon         $coupon Coupon object.
		 * @param RFWC_Ticket        $ticket Winning ticket.
		 * @param RFWC_Product_Raffle $raffle Raffle product.
		 */
		do_action( 'rfwc_prize_coupon_created', $coupon, $ticket, $raffle );

		return true;
	}

	/**
	 * Check if an order is a prize order.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order|int $order Order object or ID.
	 *
	 * @return bool True if prize order.
	 */
	public static function is_prize_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return false;
		}

		return 'yes' === $order->get_meta( '_rfwc_prize_order', true );
	}
}
