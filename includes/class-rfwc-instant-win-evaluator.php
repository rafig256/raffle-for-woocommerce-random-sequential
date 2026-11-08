<?php
/**
 * Instant Win Evaluator.
 *
 * Evaluates tickets at generation time for instant win raffles.
 * Supports probability-based and guaranteed (interval) modes.
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates each ticket upon generation for instant win results.
 *
 * @since 1.1.0
 */
class RFWC_Instant_Win_Evaluator {

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		if ( ! rfwc_feature_enabled( 'rfwc_enable_instant_win' ) ) {
			return;
		}

		add_action( 'rfwc_ticket_generated', array( $this, 'evaluate_ticket' ), 10, 3 );
		add_action( 'woocommerce_thankyou', array( $this, 'display_thankyou_results' ), 5, 1 );
		add_action( 'rfwc_raffle_completed', array( $this, 'cleanup_window_options' ), 10, 1 );
	}

	/**
	 * Evaluate a newly generated ticket for instant win.
	 *
	 * Uses the caller's $order object to accumulate result meta in memory,
	 * avoiding per-ticket $order->save() calls. The parent generator saves
	 * the order once after all tickets are processed.
	 *
	 * @since 1.1.0
	 * @since 1.1.2 Reuse caller's $order to eliminate per-ticket DB saves.
	 *
	 * @param RFWC_Ticket $ticket  The generated ticket.
	 * @param WC_Order    $order   The order object.
	 * @param bool        $is_gift Whether this is a gift ticket.
	 */
	public function evaluate_ticket( $ticket, $order, $is_gift ) {
		$raffle_id = $ticket->get_raffle_id();
		$product   = wc_get_product( $raffle_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( 'yes' !== $product->get_meta( '_rfwc_instant_win_enabled', true ) ) {
			return;
		}

		$mode = $product->get_meta( '_rfwc_instant_win_mode', true );

		if ( ! in_array( $mode, array( 'probability', 'guaranteed' ), true ) ) {
			return;
		}

		/**
		 * Allow addons to override or extend the evaluation with additional modes.
		 *
		 * @since 1.1.0
		 *
		 * @param bool|null   $result  null to use core evaluation, true/false to override.
		 * @param RFWC_Ticket $ticket  The ticket being evaluated.
		 * @param WC_Product  $product The raffle product.
		 * @param string      $mode    The instant win mode.
		 */
		$override = apply_filters( 'rfwc_instant_win_evaluate', null, $ticket, $product, $mode );

		if ( null !== $override ) {
			$is_winner = (bool) $override;
		} elseif ( 'probability' === $mode ) {
			$is_winner = $this->evaluate_probability( $product, $ticket );
		} else {
			$is_winner = $this->evaluate_guaranteed( $product, $ticket );
		}

		$result_value = $is_winner ? 'won' : 'lost';
		$this->store_ticket_result( $ticket, $result_value, $order );

		if ( $is_winner ) {
			$this->create_instant_winner( $ticket, $product, $mode, $order );
		}

		$this->log_evaluation( $ticket, $product, $mode, $is_winner );

		/**
		 * Fires after an instant win evaluation is complete.
		 *
		 * @since 1.1.0
		 *
		 * @param RFWC_Ticket $ticket    The evaluated ticket.
		 * @param bool        $is_winner Whether the ticket won.
		 * @param string      $mode      The instant win mode used.
		 * @param WC_Product  $product   The raffle product.
		 */
		do_action( 'rfwc_instant_win_evaluated', $ticket, $is_winner, $mode, $product );
	}

	/**
	 * Evaluate using probability mode.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Product  $product The raffle product.
	 * @param RFWC_Ticket $ticket  The ticket being evaluated.
	 * @return bool Whether the ticket is a winner.
	 */
	private function evaluate_probability( $product, $ticket ) {
		// Check max winners cap before rolling the dice.
		$max_winners = absint( $product->get_meta( '_rfwc_instant_win_max_winners', true ) );
		if ( $max_winners > 0 ) {
			$current_winners = $this->count_instant_winners( $product->get_id() );
			if ( $current_winners >= $max_winners ) {
				return false;
			}
		}

		$denominator = absint( $product->get_meta( '_rfwc_instant_win_probability', true ) );

		if ( $denominator < 1 ) {
			$denominator = 50;
		}

		try {
			$roll = random_int( 1, $denominator );
		} catch ( \Exception $e ) {
			$roll = wp_rand( 1, $denominator );
		}

		return 1 === $roll;
	}

	/**
	 * Evaluate using guaranteed (interval) mode.
	 *
	 * Guarantees 1 winner per every N tickets. The winning position within
	 * each window is randomly determined at the time the first ticket in
	 * that window is evaluated (just-in-time, not predictable in advance).
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Product  $product The raffle product.
	 * @param RFWC_Ticket $ticket  The ticket being evaluated.
	 * @return bool Whether the ticket is a winner.
	 */
	private function evaluate_guaranteed( $product, $ticket ) {
		global $wpdb;

		$raffle_id = $product->get_id();
		$interval  = absint( $product->get_meta( '_rfwc_instant_win_interval', true ) );

		if ( $interval < 1 ) {
			$interval = 25;
		}

		// Serialize concurrent evaluations for this raffle via a row lock.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
					$raffle_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Check max winners cap inside the lock.
			$max_winners = absint( $product->get_meta( '_rfwc_instant_win_max_winners', true ) );
			if ( $max_winners > 0 ) {
				$current_winners = $this->count_instant_winners( $raffle_id );
				if ( $current_winners >= $max_winners ) {
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					return false;
				}
			}

			// Determine the ticket's sequence position (1-based).
			$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ticket_position = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a known safe prefix constant.
					"SELECT COUNT(*) FROM {$table} WHERE raffle_id = %d AND status != 'cancelled'",
					$raffle_id
				)
			);

			// Which window does this ticket fall in? (0-indexed window).
			$window_index = intval( ( $ticket_position - 1 ) / $interval );

			// Position within the window (1-based).
			$position_in_window = ( ( $ticket_position - 1 ) % $interval ) + 1;

			// Get or generate the winning position for this window.
			$winning_position = $this->get_window_winning_position( $raffle_id, $window_index, $interval );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			return $position_in_window === $winning_position;

		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return false;
		}
	}

	/**
	 * Get or generate the winning position for a ticket window.
	 *
	 * Positions are stored in the options table, keyed per raffle + window.
	 * Generated just-in-time when the first ticket in a window is evaluated.
	 *
	 * @since 1.1.0
	 *
	 * @param int $raffle_id    Raffle product ID.
	 * @param int $window_index Zero-based window index.
	 * @param int $interval     Window size (tickets per window).
	 * @return int Winning position within the window (1-based).
	 */
	private function get_window_winning_position( $raffle_id, $window_index, $interval ) {
		$option_key = '_rfwc_iw_window_' . $raffle_id . '_' . $window_index;
		$position   = get_option( $option_key, '' );

		if ( '' !== $position && is_numeric( $position ) ) {
			return (int) $position;
		}

		// Generate a random winning position for this window.
		try {
			$position = random_int( 1, $interval );
		} catch ( \Exception $e ) {
			$position = wp_rand( 1, $interval );
		}

		update_option( $option_key, $position, false );

		return $position;
	}

	/**
	 * Count existing instant winners for a raffle.
	 *
	 * @since 1.1.0
	 *
	 * @param int $raffle_id Raffle product ID.
	 * @return int Number of instant winners.
	 */
	private function count_instant_winners( $raffle_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rfwc_winners';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a known safe prefix constant.
				"SELECT COUNT(*) FROM {$table} WHERE raffle_id = %d AND selection_method = 'instant_win'",
				$raffle_id
			)
		);
	}

	/**
	 * Store the instant win result on the ticket.
	 *
	 * Accumulates results in the caller's $order object in memory.
	 * The order is NOT saved here — the ticket generator saves once
	 * after all tickets are processed, keeping the checkout response fast.
	 *
	 * @since 1.1.0
	 * @since 1.1.2 Accept $order param; stop per-ticket save for checkout performance.
	 *
	 * @param RFWC_Ticket   $ticket The ticket.
	 * @param string        $result 'won' or 'lost'.
	 * @param WC_Order|null $order  Order object (reuse caller's instance to avoid extra DB loads).
	 */
	private function store_ticket_result( $ticket, $result, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $ticket->get_order_id() );
		}

		if ( ! $order ) {
			return;
		}

		$results = $order->get_meta( '_rfwc_instant_win_results', true );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$results[ $ticket->get_id() ] = array(
			'ticket_id'     => $ticket->get_id(),
			'ticket_number' => $ticket->get_ticket_number(),
			'raffle_id'     => $ticket->get_raffle_id(),
			'result'        => $result,
			'timestamp'     => current_time( 'mysql' ),
		);

		$order->update_meta_data( '_rfwc_instant_win_results', $results );
		$order->update_meta_data( '_rfwc_has_instant_win', 'yes' );
	}

	/**
	 * Create a winner record for an instant win ticket.
	 *
	 * @since 1.1.0
	 *
	 * @param RFWC_Ticket $ticket  The winning ticket.
	 * @param WC_Product  $product The raffle product.
	 * @param string      $mode    The instant win mode ('probability' or 'guaranteed').
	 * @param WC_Order    $order   The order object.
	 */
	private function create_instant_winner( $ticket, $product, $mode, $order ) {
		/**
		 * Filter the ticket status after an instant win.
		 *
		 * Defaults to 'winner' which excludes the ticket from the main draw.
		 * Return 'active' to keep the ticket eligible for the end-date draw
		 * (hybrid "two chances to win" mode).
		 *
		 * @since 1.1.0
		 *
		 * @param string      $status  Ticket status ('winner' or 'active').
		 * @param RFWC_Ticket $ticket  The winning ticket.
		 * @param WC_Product  $product The raffle product.
		 */
		$ticket_status = apply_filters( 'rfwc_instant_win_ticket_status', 'winner', $ticket, $product );
		$ticket->set_status( $ticket_status );
		$ticket->save();

		// Determine prize tier (instant wins always get tier 1 in core).
		$prize_tier = 1;

		/**
		 * Filter the prize tier for an instant winner.
		 *
		 * @since 1.1.0
		 *
		 * @param int         $prize_tier The prize tier (default 1).
		 * @param RFWC_Ticket $ticket     The winning ticket.
		 * @param WC_Product  $product    The raffle product.
		 */
		$prize_tier = apply_filters( 'rfwc_instant_win_prize_tier', $prize_tier, $ticket, $product );

		$proof = wp_json_encode(
			array(
				'mode'        => $mode,
				'ticket_id'   => $ticket->get_id(),
				'raffle_id'   => $product->get_id(),
				'method'      => 'instant_win',
				'php_version' => PHP_VERSION,
				'timestamp'   => current_time( 'mysql' ),
			)
		);

		$winner = new RFWC_Winner();
		$winner->set_raffle_id( $product->get_id() );
		$winner->set_ticket_id( $ticket->get_id() );
		$winner->set_prize_tier( $prize_tier );
		$winner->set_selection_method( 'instant_win' );
		$winner->set_selection_proof( $proof );

		$winner_id = $winner->save();

		if ( $winner_id ) {
			/**
			 * Fires after an instant winner is created.
			 *
			 * @since 1.1.0
			 *
			 * @param RFWC_Winner $winner  The winner object.
			 * @param RFWC_Ticket $ticket  The winning ticket.
			 * @param WC_Product  $product The raffle product.
			 * @param WC_Order    $order   The order.
			 */
			do_action( 'rfwc_instant_winner_created', $winner, $ticket, $product, $order );

			// Trigger the existing winner notification email — must match the
			// ($ticket, $raffle_id) signature used by the winner selector.
			do_action( 'rfwc_winner_selected', $ticket, $product->get_id() );
		}
	}

	/**
	 * Log the instant win evaluation for audit trail.
	 *
	 * @since 1.1.0
	 *
	 * @param RFWC_Ticket $ticket    The ticket.
	 * @param WC_Product  $product   The raffle product.
	 * @param string      $mode      The instant win mode.
	 * @param bool        $is_winner Whether the ticket won.
	 */
	private function log_evaluation( $ticket, $product, $mode, $is_winner ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		$result_text = $is_winner ? 'WINNER' : 'not a winner';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$table,
			array(
				'raffle_id' => $product->get_id(),
				'action'    => 'instant_win_evaluation',
				'details'   => sprintf(
					'Ticket #%s (ID: %d) evaluated via %s mode — %s',
					$ticket->get_ticket_number(),
					$ticket->get_id(),
					$mode,
					$result_text
				),
				'user_id'   => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Display instant win results on the thank-you page.
	 *
	 * @since 1.1.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function display_thankyou_results( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( 'yes' !== $order->get_meta( '_rfwc_has_instant_win', true ) ) {
			return;
		}

		$results = $order->get_meta( '_rfwc_instant_win_results', true );

		if ( ! is_array( $results ) || empty( $results ) ) {
			return;
		}

		$has_winner = false;
		foreach ( $results as $r ) {
			if ( 'won' === $r['result'] ) {
				$has_winner = true;
				break;
			}
		}

		$winners = array_filter(
			$results,
			function ( $r ) {
				return 'won' === $r['result'];
			}
		);

		$non_winners = array_filter(
			$results,
			function ( $r ) {
				return 'lost' === $r['result'];
			}
		);

		$non_winner_count = count( $non_winners );

		// Check if any raffle in this order is instant-win-only (no main draw).
		$is_iw_only = false;
		if ( ! empty( $results ) ) {
			$first_result = reset( $results );
			$first_raffle = wc_get_product( $first_result['raffle_id'] );
			if ( $first_raffle && 'yes' === $first_raffle->get_meta( '_rfwc_instant_win_only', true ) ) {
				$is_iw_only = true;
			}
		}

		echo '<div class="rfwc-instant-win-thankyou">';

		if ( $has_winner ) {
			echo '<div class="rfwc-iw-result rfwc-iw-winner">';
			echo '<span class="rfwc-iw-icon">&#127942;</span>';
			echo '<h3>' . esc_html__( 'Congratulations! You won!', 'raffle-for-woocommerce' ) . '</h3>';

			foreach ( $winners as $r ) {
				$product = wc_get_product( $r['raffle_id'] );
				echo '<p class="rfwc-iw-ticket-result rfwc-iw-ticket-won">';
				printf(
					/* translators: 1: ticket number, 2: raffle name */
					esc_html__( 'Ticket %1$s (%2$s) — Winner!', 'raffle-for-woocommerce' ),
					'<strong>' . esc_html( $r['ticket_number'] ) . '</strong>',
					$product ? esc_html( $product->get_name() ) : '#' . absint( $r['raffle_id'] )
				);
				echo '</p>';
			}

			echo '<p class="rfwc-iw-prize-note">' . esc_html__( 'We\'ve sent prize details to your email.', 'raffle-for-woocommerce' ) . '</p>';

			if ( $non_winner_count > 0 && ! $is_iw_only ) {
				echo '<p class="rfwc-iw-remaining-note">';
				printf(
					esc_html(
						/* translators: %d: number of remaining tickets */
						_n(
							'Your remaining %d ticket is entered in the main raffle draw. Good luck!',
							'Your remaining %d tickets are entered in the main raffle draw. Good luck!',
							$non_winner_count,
							'raffle-for-woocommerce'
						)
					),
					(int) $non_winner_count
				);
				echo '</p>';
			}

			echo '</div>';
		} else {
			echo '<div class="rfwc-iw-result rfwc-iw-not-winner">';
			if ( $is_iw_only ) {
				echo '<p>' . esc_html__( 'Not an instant winner this time. Better luck on your next ticket!', 'raffle-for-woocommerce' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Not an instant winner this time, but all your tickets are entered in the main raffle draw. Good luck!', 'raffle-for-woocommerce' ) . '</p>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Get the instant win result for a specific ticket from the order meta.
	 *
	 * @since 1.1.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @return string|null 'won', 'lost', or null if not an instant win ticket.
	 */
	public static function get_ticket_result( $ticket ) {
		$order = wc_get_order( $ticket->get_order_id() );

		if ( ! $order ) {
			return null;
		}

		$results = $order->get_meta( '_rfwc_instant_win_results', true );

		if ( ! is_array( $results ) || ! isset( $results[ $ticket->get_id() ] ) ) {
			return null;
		}

		return $results[ $ticket->get_id() ]['result'];
	}

	/**
	 * Delete the per-window winning-position options for a completed raffle.
	 *
	 * @since 1.1.0
	 *
	 * @param int $raffle_id Raffle product ID.
	 */
	public function cleanup_window_options( $raffle_id ) {
		global $wpdb;

		$raffle_id = absint( $raffle_id );
		if ( ! $raffle_id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_rfwc_iw_window_' . $raffle_id . '_' ) . '%'
			)
		);
	}
}
