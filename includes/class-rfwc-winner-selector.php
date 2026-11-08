<?php
/**
 * Winner selection with multiple randomization methods.
 *
 * Supports PHP random, cryptographically secure random, and Random.org API
 * for selecting raffle winners from eligible tickets.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects raffle winners using configurable randomization methods.
 *
 * @since 1.0.0
 */
class RFWC_Winner_Selector {

	/**
	 * Available selection methods.
	 *
	 * @since 1.0.0
	 *
	 * @return array Selection methods.
	 */
	public static function get_selection_methods() {
		return array(
			'php_random'    => __( 'Standard Random', 'raffle-for-woocommerce' ),
			'secure_random' => __( 'Cryptographically Secure', 'raffle-for-woocommerce' ),
			'random_org'    => __( 'Random.org (True Random)', 'raffle-for-woocommerce' ),
		);
	}

	/**
	 * Select winners for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id       Raffle product ID.
	 * @param int    $num_winners     Number of winners to select.
	 * @param string $method          Selection method.
	 * @param bool   $send_notification Whether to send email notifications.
	 *
	 * @return array|WP_Error Array of RFWC_Winner objects or error.
	 */
	public static function select_winners( $raffle_id, $num_winners = 1, $method = 'secure_random', $send_notification = true ) {
		// Get eligible tickets.
		$tickets = self::get_eligible_tickets( $raffle_id );

		if ( empty( $tickets ) ) {
			return new WP_Error( 'no_tickets', __( 'No eligible tickets found for this raffle.', 'raffle-for-woocommerce' ) );
		}

		// When unique winners is enabled, check that enough distinct users exist.
		$unique_winners_enabled = false;
		$raffle_product         = wc_get_product( $raffle_id );
		if ( $raffle_product && 'yes' === $raffle_product->get_meta( '_rfwc_unique_winners', true ) ) {
			$unique_winners_enabled = true;

			$distinct_users = array();
			foreach ( $tickets as $ticket ) {
				$user_key = strtolower( $ticket->get_purchaser_email() );
				if ( $ticket->is_gift() ) {
					$user_key = strtolower( $ticket->get_recipient_email() );
				}
				$distinct_users[ $user_key ] = true;
			}

			if ( count( $distinct_users ) < $num_winners ) {
				return new WP_Error(
					'insufficient_unique_users',
					sprintf(
						/* translators: %1$d: winners requested, %2$d: distinct users */
						__( 'Cannot select %1$d unique winners. Only %2$d distinct ticket holders available.', 'raffle-for-woocommerce' ),
						$num_winners,
						count( $distinct_users )
					)
				);
			}
		}

		if ( count( $tickets ) < $num_winners ) {
			return new WP_Error(
				'insufficient_tickets',
				sprintf(
					/* translators: %1$d: number of winners requested, %2$d: available tickets */
					__( 'Cannot select %1$d winners. Only %2$d eligible tickets available.', 'raffle-for-woocommerce' ),
					$num_winners,
					count( $tickets )
				)
			);
		}

		// Check if raffle already has draw winners (instant win records are excluded).
		if ( RFWC_Winner::raffle_has_draw_winners( $raffle_id ) ) {
			return new WP_Error( 'already_drawn', __( 'Winners have already been selected for this raffle. Clear existing winners first.', 'raffle-for-woocommerce' ) );
		}

		// Select winning ticket indices.
		if ( $unique_winners_enabled && $num_winners > 1 ) {
			// Iterative selection: pick one at a time, exclude same user's tickets after each.
			$winning_indices  = array();
			$excluded_users   = array();
			$available_pool   = $tickets;
			$combined_proof   = array(
				'method'         => $method,
				'pool_size'      => count( $tickets ),
				'selections'     => $num_winners,
				'unique_winners' => true,
				'timestamp'      => current_time( 'mysql' ),
				'rounds'         => array(),
			);

			for ( $round = 0; $round < $num_winners; $round++ ) {
				// Filter out tickets from users who already won.
				$round_pool    = array();
				$round_indices = array();
				foreach ( $available_pool as $orig_idx => $t ) {
					$user_key = strtolower( $t->get_purchaser_email() );
					if ( $t->is_gift() ) {
						$user_key = strtolower( $t->get_recipient_email() );
					}
					if ( ! isset( $excluded_users[ $user_key ] ) ) {
						$round_pool[]    = $t;
						$round_indices[] = $orig_idx;
					}
				}

				if ( empty( $round_pool ) ) {
					break;
				}

				$result = self::select_random_indices( count( $round_pool ), 1, $method );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$local_idx                      = $result['indices'][0];
				$original_idx                    = $round_indices[ $local_idx ];
				$winning_indices[]               = $original_idx;
				$combined_proof['rounds'][]      = $result['proof'];

				// Exclude this user from future rounds.
				$winning_ticket = $tickets[ $original_idx ];
				$winner_key     = strtolower( $winning_ticket->get_purchaser_email() );
				if ( $winning_ticket->is_gift() ) {
					$winner_key = strtolower( $winning_ticket->get_recipient_email() );
				}
				$excluded_users[ $winner_key ] = true;
			}

			$proof = $combined_proof;
		} else {
			$result = self::select_random_indices( count( $tickets ), $num_winners, $method );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$winning_indices = $result['indices'];
			$proof           = $result['proof'];
		}

		// Use a transaction with a locking read to prevent duplicate winners
		// from concurrent requests (e.g. admin clicks "Draw" while cron fires simultaneously).
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Acquire an exclusive row lock on the raffle post to serialize concurrent draws.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
				$raffle_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Re-check inside transaction -- another process may have inserted draw winners first.
		if ( RFWC_Winner::raffle_has_draw_winners( $raffle_id ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error( 'already_drawn', __( 'Winners have already been selected for this raffle. Clear existing winners first.', 'raffle-for-woocommerce' ) );
		}

		// Create winner records.
		$winners = array();
		$tier    = 1;

		foreach ( $winning_indices as $index ) {
			$ticket = $tickets[ $index ];

			$winner = new RFWC_Winner();
			$winner->set_raffle_id( $raffle_id );
			$winner->set_ticket_id( $ticket->get_id() );
			$winner->set_prize_tier( $tier );
			$winner->set_selection_method( $method );
			$winner->set_selection_proof( wp_json_encode( $proof ) );

			$winner_id = $winner->save();

			if ( $winner_id ) {
				// Update ticket status.
				$ticket->set_status( 'winner' );
				$ticket->save();

				$winners[] = $winner;

				// Log the selection.
				self::log_winner_selection( $raffle_id, $ticket, $winner, $method );

				++$tier;
			}
		}

		// If no winners were saved successfully, rollback.
		if ( empty( $winners ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error( 'save_failed', __( 'Failed to save winner records. Please try again.', 'raffle-for-woocommerce' ) );
		}

		// Update raffle status inside the transaction for atomicity.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'completed' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			array(
				'post_id'  => $raffle_id,
				'meta_key' => '_rfwc_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		// If no _rfwc_status meta exists yet, insert it.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_rfwc_status'",
				$raffle_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $status_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $raffle_id,
					'meta_key'   => '_rfwc_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => 'completed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				),
				array( '%d', '%s', '%s' )
			);
		}

		$commit_result = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $commit_result ) {
			return new WP_Error( 'commit_failed', __( 'Failed to commit winner records. Please try again.', 'raffle-for-woocommerce' ) );
		}

		// Post-transaction: set draw date via WC API (safe outside transaction).
		$raffle = wc_get_product( $raffle_id );
		if ( $raffle ) {
			$raffle->update_meta_data( '_rfwc_draw_date', current_time( 'mysql' ) );
			$raffle->save();
		}

		/**
		 * Fires after a raffle's main draw completes and status is set to completed.
		 *
		 * @since 1.1.0
		 *
		 * @param int   $raffle_id The raffle product ID.
		 * @param array $winners   Array of RFWC_Winner objects.
		 */
		do_action( 'rfwc_raffle_completed', $raffle_id, $winners );

		// Send notifications outside the transaction to avoid holding locks during email delivery.
		if ( $send_notification ) {
			foreach ( $winners as $winner ) {
				if ( function_exists( 'WC' ) && WC()->mailer() ) {
					$ticket = $winner->get_ticket();
					if ( $ticket ) {
						/**
						 * Triggers winner notification email.
						 *
						 * @since 1.0.0
						 *
						 * @param RFWC_Ticket $ticket    Winning ticket.
						 * @param int        $raffle_id Raffle ID.
						 */
						do_action( 'rfwc_winner_selected', $ticket, $raffle_id );
					}
				}
				$winner->set_notified( true );
				$winner->save();
			}
		}

		return $winners;
	}

	/**
	 * Select a single winner manually.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $raffle_id         Raffle product ID.
	 * @param int  $ticket_id         Ticket ID to select as winner.
	 * @param int  $prize_tier        Prize tier (1, 2, 3...).
	 * @param bool $send_notification Whether to send email notification.
	 *
	 * @return RFWC_Winner|WP_Error Winner object or error.
	 */
	public static function select_winner_manually( $raffle_id, $ticket_id, $prize_tier = 1, $send_notification = true ) {
		$ticket = new RFWC_Ticket( $ticket_id );

		if ( ! $ticket->get_id() ) {
			return new WP_Error( 'invalid_ticket', __( 'Invalid ticket ID.', 'raffle-for-woocommerce' ) );
		}

		if ( $ticket->get_raffle_id() !== (int) $raffle_id ) {
			return new WP_Error( 'ticket_mismatch', __( 'Ticket does not belong to this raffle.', 'raffle-for-woocommerce' ) );
		}

		if ( 'active' !== $ticket->get_status() ) {
			return new WP_Error( 'invalid_status', __( 'Ticket is not in active status.', 'raffle-for-woocommerce' ) );
		}

		// Check if this ticket is already a winner.
		$existing = RFWC_Winner::get_by_ticket( $ticket_id );
		if ( $existing ) {
			return new WP_Error( 'already_winner', __( 'This ticket has already been selected as a winner.', 'raffle-for-woocommerce' ) );
		}

		$winner = new RFWC_Winner();
		$winner->set_raffle_id( $raffle_id );
		$winner->set_ticket_id( $ticket_id );
		$winner->set_prize_tier( $prize_tier );
		$winner->set_selection_method( 'manual' );
		$winner->set_selection_proof(
			wp_json_encode(
				array(
					'selected_by' => get_current_user_id(),
					'timestamp'   => current_time( 'mysql' ),
				)
			)
		);

		$winner_id = $winner->save();

		if ( ! $winner_id ) {
			return new WP_Error( 'save_failed', __( 'Could not save winner to database.', 'raffle-for-woocommerce' ) );
		}

		// Update ticket status.
		$ticket->set_status( 'winner' );
		$ticket->save();

		// Send notification if enabled.
		if ( $send_notification ) {
			// Ensure WooCommerce emails are loaded for AJAX requests.
			if ( function_exists( 'WC' ) && WC()->mailer() ) {
				do_action( 'rfwc_winner_selected', $ticket, $raffle_id );
			}
			$winner->set_notified( true );
			$winner->save();
		}

		// Log the selection.
		self::log_winner_selection( $raffle_id, $ticket, $winner, 'manual' );

		// Update raffle status to completed (consistent with batch draw).
		$raffle = wc_get_product( $raffle_id );
		if ( $raffle ) {
			$raffle->update_meta_data( '_rfwc_status', 'completed' );
			$raffle->update_meta_data( '_rfwc_draw_date', current_time( 'mysql' ) );
			$raffle->save();
		}

		/** This action is documented in self::select_winners(). */
		do_action( 'rfwc_raffle_completed', $raffle_id, array( $winner ) );

		return $winner;
	}

	/**
	 * Get eligible tickets for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return RFWC_Ticket[] Array of eligible tickets.
	 */
	public static function get_eligible_tickets( $raffle_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . " WHERE raffle_id = %d AND status = 'active' ORDER BY id ASC",
				absint( $raffle_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new RFWC_Ticket( $row );
			}
		}

		/**
		 * Filter the pool of tickets eligible for a draw.
		 *
		 * Extensions can use this to combine pools across multiple raffles
		 * (master drawings), exclude tickets matching custom criteria, or
		 * inject additional tickets from external sources.
		 *
		 * @since 1.1.13
		 *
		 * @param RFWC_Ticket[] $tickets   Tickets currently eligible for the draw.
		 * @param int           $raffle_id Raffle product ID the draw was requested for.
		 */
		return apply_filters( 'rfwc_eligible_tickets', $tickets, $raffle_id );
	}

	/**
	 * Select random indices from a pool.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $pool_size   Total number of items.
	 * @param int    $num_winners Number of winners to select.
	 * @param string $method      Selection method.
	 *
	 * @return array|WP_Error Array with 'indices' and 'proof' or error.
	 */
	private static function select_random_indices( $pool_size, $num_winners, $method ) {
		$indices = array();
		$proof   = array(
			'method'     => $method,
			'pool_size'  => $pool_size,
			'selections' => $num_winners,
			'timestamp'  => current_time( 'mysql' ),
			'details'    => array(),
		);

		switch ( $method ) {
			case 'random_org':
				$result = self::select_via_random_org( $pool_size, $num_winners );
				if ( is_wp_error( $result ) ) {
					// Fallback to secure_random if Random.org fails.
					$proof['fallback']        = true;
					$proof['fallback_reason'] = $result->get_error_message();
					$proof['method']          = 'secure_random';

					$indices          = self::select_via_secure_random( $pool_size, $num_winners );
					$proof['details'] = array( 'function' => 'random_int' );
				} else {
					$indices          = $result['indices'];
					$proof['details'] = $result['proof'];
				}
				break;

			case 'secure_random':
				$indices          = self::select_via_secure_random( $pool_size, $num_winners );
				$proof['details'] = array( 'function' => 'random_int' );
				break;

			case 'php_random':
			default:
				$indices          = self::select_via_php_random( $pool_size, $num_winners );
				$proof['details'] = array( 'function' => 'mt_rand' );
				break;
		}

		return array(
			'indices' => $indices,
			'proof'   => $proof,
		);
	}

	/**
	 * Select using PHP's mt_rand (fast but not cryptographically secure).
	 *
	 * @since 1.0.0
	 *
	 * @param int $pool_size   Pool size.
	 * @param int $num_winners Number of winners.
	 *
	 * @return array Selected indices.
	 */
	private static function select_via_php_random( $pool_size, $num_winners ) {
		$indices       = array();
		$indices_count = 0;

		while ( $indices_count < $num_winners ) {
			$index = wp_rand( 0, $pool_size - 1 );
			if ( ! in_array( $index, $indices, true ) ) {
				$indices[] = $index;
				++$indices_count;
			}
		}

		return $indices;
	}

	/**
	 * Select using cryptographically secure random_int.
	 *
	 * @since 1.0.0
	 *
	 * @param int $pool_size   Pool size.
	 * @param int $num_winners Number of winners.
	 *
	 * @return array Selected indices.
	 */
	private static function select_via_secure_random( $pool_size, $num_winners ) {
		$indices       = array();
		$indices_count = 0;

		while ( $indices_count < $num_winners ) {
			try {
				$index = random_int( 0, $pool_size - 1 );
				if ( ! in_array( $index, $indices, true ) ) {
					$indices[] = $index;
					++$indices_count;
				}
			} catch ( Exception $e ) {
				// Fallback to wp_rand if random_int fails.
				$index = wp_rand( 0, $pool_size - 1 );
				if ( ! in_array( $index, $indices, true ) ) {
					$indices[] = $index;
					++$indices_count;
				}
			}
		}

		return $indices;
	}

	/**
	 * Select using Random.org API.
	 *
	 * @since 1.0.0
	 *
	 * @param int $pool_size   Pool size.
	 * @param int $num_winners Number of winners.
	 *
	 * @return array|WP_Error Result array or error.
	 */
	private static function select_via_random_org( $pool_size, $num_winners ) {
		$api_key = get_option( 'rfwc_random_org_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Random.org API key not configured.', 'raffle-for-woocommerce' ) );
		}

		$request_body = array(
			'jsonrpc' => '2.0',
			'method'  => 'generateIntegers',
			'params'  => array(
				'apiKey'      => $api_key,
				'n'           => $num_winners,
				'min'         => 0,
				'max'         => $pool_size - 1,
				'replacement' => false,
			),
			'id'      => wp_generate_uuid4(),
		);

		$response = wp_remote_post(
			'https://api.random.org/json-rpc/4/invoke',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'random_org_error', $body['error']['message'] );
		}

		if ( ! isset( $body['result']['random']['data'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from Random.org.', 'raffle-for-woocommerce' ) );
		}

		return array(
			'indices' => $body['result']['random']['data'],
			'proof'   => array(
				'serialNumber'   => $body['result']['random']['serialNumber'] ?? null,
				'completionTime' => $body['result']['random']['completionTime'] ?? null,
				'signature'      => $body['result']['signature'] ?? null,
			),
		);
	}

	/**
	 * Log winner selection to audit trail.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $raffle_id Raffle ID.
	 * @param RFWC_Ticket $ticket    Winning ticket.
	 * @param RFWC_Winner $winner    Winner object.
	 * @param string      $method    Selection method.
	 */
	private static function log_winner_selection( $raffle_id, $ticket, $winner, $method ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		$method_labels = array(
			'manual'        => __( 'Manual Selection', 'raffle-for-woocommerce' ),
			'php_random'    => __( 'PHP Random', 'raffle-for-woocommerce' ),
			'secure_random' => __( 'Cryptographically Secure Random', 'raffle-for-woocommerce' ),
			'random_org'    => __( 'Random.org API', 'raffle-for-woocommerce' ),
		);

		$method_label = $method_labels[ $method ] ?? $method;

		$message = sprintf(
			/* translators: %1$s: ticket number, %2$s: holder name, %3$d: prize tier, %4$s: selection method */
			__( 'Winner selected: Ticket %1$s (%2$s) - Prize Tier %3$d - Method: %4$s', 'raffle-for-woocommerce' ),
			$ticket->get_formatted_ticket_number(),
			$ticket->get_holder_name(),
			$winner->get_prize_tier(),
			$method_label
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'raffle_id' => $raffle_id,
				'action'    => 'winner_selected',
				'details'   => $message,
				'user_id'   => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Clear winners for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return bool True on success.
	 */
	public static function clear_winners( $raffle_id ) {
		$winners = RFWC_Winner::get_by_raffle( $raffle_id );

		foreach ( $winners as $winner ) {
			// Restore ticket status.
			$ticket = $winner->get_ticket();
			if ( $ticket ) {
				$ticket->set_status( 'active' );
				$ticket->save();
			}

			$winner->delete();
		}

		// Log the action.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			esc_sql( $wpdb->prefix . 'rfwc_log' ),
			array(
				'raffle_id' => $raffle_id,
				'action'    => 'winners_cleared',
				'details'   => __( 'All winners cleared for this raffle.', 'raffle-for-woocommerce' ),
				'user_id'   => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		// Update raffle status.
		$raffle = wc_get_product( $raffle_id );
		if ( $raffle ) {
			$raffle->delete_meta_data( '_rfwc_status' );
			$raffle->delete_meta_data( '_rfwc_draw_date' );
			$raffle->save();
		}

		return true;
	}
}
