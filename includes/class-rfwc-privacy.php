<?php
/**
 * GDPR privacy compliance.
 *
 * Integrates with WordPress privacy tools for personal data export and erasure.
 * Adds privacy policy suggestions and processes data requests for raffle tickets.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress privacy tools integration for GDPR compliance.
 *
 * @since 1.0.0
 */
class RFWC_Privacy {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Privacy policy content.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// Personal data exporters.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );

		// Personal data erasers.
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Add privacy policy content.
	 *
	 * @since 1.0.0
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = $this->get_privacy_policy_content();

		wp_add_privacy_policy_content(
			__( 'Raffle for WooCommerce', 'raffle-for-woocommerce' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Get privacy policy content.
	 *
	 * @since 1.0.0
	 * @return string Privacy policy content.
	 */
	private function get_privacy_policy_content() {
		$content = '<h2>' . esc_html__( 'Raffle Ticket Data', 'raffle-for-woocommerce' ) . '</h2>';

		$content .= '<p>' . esc_html__( 'When you purchase raffle tickets, we collect and store the following information:', 'raffle-for-woocommerce' ) . '</p>';

		$content .= '<ul>';
		$content .= '<li>' . esc_html__( 'Your name and email address (associated with your ticket)', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Ticket numbers and purchase dates', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Order information linked to ticket purchases', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h3>' . esc_html__( 'Gift Purchases', 'raffle-for-woocommerce' ) . '</h3>';

		$content .= '<p>' . esc_html__( 'When purchasing tickets as a gift for someone else, we collect:', 'raffle-for-woocommerce' ) . '</p>';

		$content .= '<ul>';
		$content .= '<li>' . esc_html__( 'Recipient name and email address', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Gift message (if provided)', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '</ul>';

		$content .= '<p>' . esc_html__( 'Gift recipients are notified via email that they have received raffle tickets. Their data is processed to fulfill the gift and for winner selection purposes.', 'raffle-for-woocommerce' ) . '</p>';

		$content .= '<h3>' . esc_html__( 'How We Use This Data', 'raffle-for-woocommerce' ) . '</h3>';

		$content .= '<ul>';
		$content .= '<li>' . esc_html__( 'To generate and manage raffle tickets', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'To conduct fair winner selection', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'To notify winners and fulfill prizes', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '<li>' . esc_html__( 'To maintain audit trails for transparency', 'raffle-for-woocommerce' ) . '</li>';
		$content .= '</ul>';

		$content .= '<h3>' . esc_html__( 'Data Retention', 'raffle-for-woocommerce' ) . '</h3>';

		$content .= '<p>' . esc_html__( 'Raffle ticket data is retained for as long as the associated WooCommerce order exists, or until you request deletion. Winner records may be retained longer for audit and legal compliance purposes.', 'raffle-for-woocommerce' ) . '</p>';

		$content .= '<h3>' . esc_html__( 'Your Rights', 'raffle-for-woocommerce' ) . '</h3>';

		$content .= '<p>' . esc_html__( 'You can request export or deletion of your raffle ticket data through the standard WordPress personal data request system, or by contacting us directly.', 'raffle-for-woocommerce' ) . '</p>';

		return $content;
	}

	/**
	 * Register personal data exporters.
	 *
	 * @since 1.0.0
	 * @param array $exporters Registered exporters.
	 * @return array Modified exporters.
	 */
	public function register_exporters( $exporters ) {
		$exporters['raffle-for-woocommerce-tickets'] = array(
			'exporter_friendly_name' => __( 'Raffle Tickets', 'raffle-for-woocommerce' ),
			'callback'               => array( $this, 'export_tickets' ),
		);

		$exporters['raffle-for-woocommerce-winners'] = array(
			'exporter_friendly_name' => __( 'Raffle Winner Records', 'raffle-for-woocommerce' ),
			'callback'               => array( $this, 'export_winners' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data erasers.
	 *
	 * @since 1.0.0
	 * @param array $erasers Registered erasers.
	 * @return array Modified erasers.
	 */
	public function register_erasers( $erasers ) {
		$erasers['raffle-for-woocommerce-tickets'] = array(
			'eraser_friendly_name' => __( 'Raffle Tickets', 'raffle-for-woocommerce' ),
			'callback'             => array( $this, 'erase_tickets' ),
		);

		return $erasers;
	}

	/**
	 * Export raffle tickets for a user.
	 *
	 * @since 1.0.0
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array Export data.
	 */
	public function export_tickets( $email_address, $page = 1 ) {
		global $wpdb;

		$items_per_page = 10;
		$offset         = ( $page - 1 ) * $items_per_page;

		// Get tickets where user is purchaser or recipient.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tickets = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE purchaser_email = %s OR recipient_email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$email_address,
				$email_address,
				$items_per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$export_items = array();

		foreach ( $tickets as $ticket ) {
			$raffle = wc_get_product( $ticket->raffle_id );

			$data = array(
				array(
					'name'  => __( 'Ticket Number', 'raffle-for-woocommerce' ),
					'value' => $ticket->ticket_number,
				),
				array(
					'name'  => __( 'Raffle', 'raffle-for-woocommerce' ),
					'value' => $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ),
				),
				array(
					'name'  => __( 'Holder Name', 'raffle-for-woocommerce' ),
					'value' => $ticket->holder_name,
				),
				array(
					'name'  => __( 'Status', 'raffle-for-woocommerce' ),
					'value' => ucfirst( $ticket->status ),
				),
				array(
					'name'  => __( 'Purchase Date', 'raffle-for-woocommerce' ),
					'value' => $ticket->created_at,
				),
				array(
					'name'  => __( 'Is Gift', 'raffle-for-woocommerce' ),
					'value' => $ticket->is_gift ? __( 'Yes', 'raffle-for-woocommerce' ) : __( 'No', 'raffle-for-woocommerce' ),
				),
			);

			if ( $ticket->is_gift && $ticket->purchaser_email === $email_address ) {
				$data[] = array(
					'name'  => __( 'Gift Recipient', 'raffle-for-woocommerce' ),
					'value' => $ticket->recipient_email,
				);
			}

			if ( $ticket->is_gift && $ticket->recipient_email === $email_address ) {
				$data[] = array(
					'name'  => __( 'Gift From', 'raffle-for-woocommerce' ),
					'value' => $ticket->purchaser_email,
				);
			}

			$export_items[] = array(
				'group_id'          => 'raffle-tickets',
				'group_label'       => __( 'Raffle Tickets', 'raffle-for-woocommerce' ),
				'group_description' => __( 'Raffle ticket purchase and participation data.', 'raffle-for-woocommerce' ),
				'item_id'           => 'ticket-' . $ticket->id,
				'data'              => $data,
			);
		}

		$done = count( $tickets ) < $items_per_page;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Export winner records for a user.
	 *
	 * @since 1.0.0
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array Export data.
	 */
	public function export_winners( $email_address, $page = 1 ) {
		global $wpdb;

		$items_per_page = 10;
		$offset         = ( $page - 1 ) * $items_per_page;

		// Get winner records for tickets belonging to this user.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$winners = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT w.*, t.ticket_number, t.holder_name FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' w
				INNER JOIN ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' t ON w.ticket_id = t.id
				WHERE t.purchaser_email = %s OR t.recipient_email = %s
				ORDER BY w.id ASC LIMIT %d OFFSET %d',
				$email_address,
				$email_address,
				$items_per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$export_items = array();

		foreach ( $winners as $winner ) {
			$raffle = wc_get_product( $winner->raffle_id );

			$data = array(
				array(
					'name'  => __( 'Raffle', 'raffle-for-woocommerce' ),
					'value' => $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ),
				),
				array(
					'name'  => __( 'Winning Ticket', 'raffle-for-woocommerce' ),
					'value' => $winner->ticket_number,
				),
				array(
					'name'  => __( 'Winner Name', 'raffle-for-woocommerce' ),
					'value' => $winner->holder_name,
				),
				array(
					'name'  => __( 'Position', 'raffle-for-woocommerce' ),
					'value' => $this->get_position_label( $winner->position ),
				),
				array(
					'name'  => __( 'Prize Type', 'raffle-for-woocommerce' ),
					'value' => ucfirst( $winner->prize_type ),
				),
				array(
					'name'  => __( 'Selection Date', 'raffle-for-woocommerce' ),
					'value' => $winner->created_at,
				),
			);

			$export_items[] = array(
				'group_id'          => 'raffle-winners',
				'group_label'       => __( 'Raffle Winner Records', 'raffle-for-woocommerce' ),
				'group_description' => __( 'Records of winning raffle entries.', 'raffle-for-woocommerce' ),
				'item_id'           => 'winner-' . $winner->id,
				'data'              => $data,
			);
		}

		$done = count( $winners ) < $items_per_page;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Erase raffle ticket data for a user.
	 *
	 * @since 1.0.0
	 * @param string $email_address User email address.
	 * @param int    $page          Page number (unused but required by WordPress API).
	 * @return array Erasure result.
	 */
	public function erase_tickets( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		global $wpdb;

		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		// Get tickets where user is purchaser or recipient.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tickets = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE purchaser_email = %s OR recipient_email = %s',
				$email_address,
				$email_address
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $tickets as $ticket ) {
			// Check if this ticket is a winner - retain for audit.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$is_winner = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE ticket_id = %d',
					$ticket->id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $is_winner ) {
				// Anonymize winner data instead of deleting.
				$this->anonymize_ticket( $ticket, $email_address );
				++$items_retained;
				$messages[] = __( 'Winner records retained for audit purposes but personal data anonymized.', 'raffle-for-woocommerce' );
			} else {
				// Anonymize non-winner tickets.
				$this->anonymize_ticket( $ticket, $email_address );
				++$items_removed;
			}
		}

		// Anonymize log entries.
		$this->anonymize_logs( $email_address );

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => array_unique( $messages ),
			'done'           => true,
		);
	}

	/**
	 * Anonymize a ticket's personal data.
	 *
	 * @since 1.0.0
	 * @param object $ticket        Ticket object.
	 * @param string $email_address Email to anonymize.
	 */
	private function anonymize_ticket( $ticket, $email_address ) {
		global $wpdb;

		$update_data = array();

		// Anonymize purchaser data if it matches.
		if ( $ticket->purchaser_email === $email_address ) {
			$update_data['purchaser_email'] = wp_privacy_anonymize_data( 'email', $ticket->purchaser_email );
			$update_data['purchaser_id']    = 0;
		}

		// Anonymize recipient data if it matches.
		if ( $ticket->recipient_email === $email_address ) {
			$update_data['recipient_email'] = wp_privacy_anonymize_data( 'email', $ticket->recipient_email );
			$update_data['holder_name']     = __( 'Anonymized', 'raffle-for-woocommerce' );
		}

		// Anonymize gift message if purchaser is being erased.
		if ( $ticket->purchaser_email === $email_address && $ticket->gift_message ) {
			$update_data['gift_message'] = '';
		}

		if ( ! empty( $update_data ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'rfwc_tickets',
				$update_data,
				array( 'id' => $ticket->id ),
				array_fill( 0, count( $update_data ), '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Anonymize log entries for a user.
	 *
	 * @since 1.0.0
	 * @param string $email_address Email to anonymize.
	 */
	private function anonymize_logs( $email_address ) {
		global $wpdb;

		// Anonymize email in log details.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, details FROM ' . esc_sql( $wpdb->prefix . 'rfwc_log' ) . ' WHERE details LIKE %s',
				'%' . $wpdb->esc_like( $email_address ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$anonymized_email = wp_privacy_anonymize_data( 'email', $email_address );

		foreach ( $logs as $log ) {
			$new_details = str_replace( $email_address, $anonymized_email, $log->details );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'rfwc_log',
				array( 'details' => $new_details ),
				array( 'id' => $log->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Get position label.
	 *
	 * @since 1.0.0
	 * @param int $position Winner position.
	 * @return string Position label.
	 */
	private function get_position_label( $position ) {
		$labels = array(
			1 => __( '1st Place', 'raffle-for-woocommerce' ),
			2 => __( '2nd Place', 'raffle-for-woocommerce' ),
			3 => __( '3rd Place', 'raffle-for-woocommerce' ),
		);

		if ( isset( $labels[ $position ] ) ) {
			return $labels[ $position ];
		}

		/* translators: %d: winner position number */
		return sprintf( __( '%dth Place', 'raffle-for-woocommerce' ), $position );
	}
}
