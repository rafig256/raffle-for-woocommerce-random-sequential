<?php
/**
 * Raffle ticket data model.
 *
 * Stores and retrieves ticket data from the rfwc_tickets database table.
 * Each ticket represents one entry in a raffle draw.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raffle ticket data model.
 *
 * @since 1.0.0
 */
class RFWC_Ticket {

	/**
	 * Ticket ID.
	 *
	 * @var int
	 */
	protected $id = 0;

	/**
	 * Ticket data.
	 *
	 * @var array
	 */
	protected $data = array(
		'ticket_number'   => '',
		'raffle_id'       => 0,
		'order_id'        => 0,
		'order_item_id'   => 0,
		'purchaser_id'    => 0,
		'purchaser_name'  => '',
		'purchaser_email' => '',
		'recipient_name'  => '',
		'recipient_email' => '',
		'is_gift'         => false,
		'gift_message'    => '',
		'status'          => 'active',
		'created_at'      => '',
		'updated_at'      => '',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int|object $ticket Ticket ID or ticket data object.
	 */
	public function __construct( $ticket = 0 ) {
		if ( is_numeric( $ticket ) && $ticket > 0 ) {
			$this->id = absint( $ticket );
			$this->read();
		} elseif ( is_object( $ticket ) ) {
			$this->set_props_from_object( $ticket );
		}
	}

	/**
	 * Get table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name (escaped for SQL).
	 */
	public static function get_table_name() {
		global $wpdb;
		return esc_sql( $wpdb->prefix . 'rfwc_tickets' );
	}

	/**
	 * Get ticket by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return RFWC_Ticket|null Ticket object or null if not found.
	 */
	public static function get_by_id( $ticket_id ) {
		$ticket = new self( $ticket_id );
		return $ticket->get_id() ? $ticket : null;
	}

	/**
	 * Read ticket from database.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if ticket found.
	 */
	protected function read() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE id = %d', $this->id )
		);

		if ( $row ) {
			$this->set_props_from_object( $row );
			return true;
		}

		return false;
	}

	/**
	 * Set properties from database row object.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Database row.
	 */
	protected function set_props_from_object( $row ) {
		$this->id                      = absint( $row->id );
		$this->data['ticket_number']   = $row->ticket_number;
		$this->data['raffle_id']       = absint( $row->raffle_id );
		$this->data['order_id']        = absint( $row->order_id );
		$this->data['order_item_id']   = absint( $row->order_item_id );
		$this->data['purchaser_id']    = absint( $row->purchaser_id );
		$this->data['purchaser_name']  = $row->purchaser_name;
		$this->data['purchaser_email'] = $row->purchaser_email;
		$this->data['recipient_name']  = $row->recipient_name;
		$this->data['recipient_email'] = $row->recipient_email;
		$this->data['is_gift']         = (bool) $row->is_gift;
		$this->data['gift_message']    = $row->gift_message;
		$this->data['status']          = $row->status;
		$this->data['created_at']      = $row->created_at;
		$this->data['updated_at']      = $row->updated_at;
	}

	/**
	 * Save ticket to database.
	 *
	 * @since 1.0.0
	 *
	 * @return int|false Ticket ID on success, false on failure.
	 */
	public function save() {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		$data = array(
			'ticket_number'   => $this->data['ticket_number'],
			'raffle_id'       => $this->data['raffle_id'],
			'order_id'        => $this->data['order_id'],
			'order_item_id'   => $this->data['order_item_id'],
			'purchaser_id'    => $this->data['purchaser_id'],
			'purchaser_name'  => $this->data['purchaser_name'],
			'purchaser_email' => $this->data['purchaser_email'],
			'recipient_name'  => $this->data['recipient_name'],
			'recipient_email' => $this->data['recipient_email'],
			'is_gift'         => $this->data['is_gift'] ? 1 : 0,
			'gift_message'    => $this->data['gift_message'],
			'status'          => $this->data['status'],
		);

		$format = array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		if ( $this->id > 0 ) {
			// Update existing ticket.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table_name, $data, array( 'id' => $this->id ), $format, array( '%d' ) );
			return false !== $result ? $this->id : false;
		}

		// Insert new ticket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table_name, $data, $format );

		if ( $result ) {
			$this->id = $wpdb->insert_id;
			return $this->id;
		}

		return false;
	}

	/**
	 * Delete ticket from database.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public function delete() {
		global $wpdb;

		if ( ! $this->id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( esc_sql( $wpdb->prefix . 'rfwc_tickets' ), array( 'id' => $this->id ), array( '%d' ) );

		if ( $result ) {
			$this->id = 0;
			return true;
		}

		return false;
	}

	/**
	 * Get ticket ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int Ticket ID.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get ticket number.
	 *
	 * @since 1.0.0
	 *
	 * @return string Ticket number.
	 */
	public function get_ticket_number() {
		return $this->data['ticket_number'];
	}

	/**
	 * Set ticket number.
	 *
	 * @since 1.0.0
	 *
	 * @param string $number Ticket number.
	 */
	public function set_ticket_number( $number ) {
		$this->data['ticket_number'] = sanitize_text_field( $number );
	}

	/**
	 * Get raffle ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int Raffle ID.
	 */
	public function get_raffle_id() {
		return $this->data['raffle_id'];
	}

	/**
	 * Set raffle ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 */
	public function set_raffle_id( $raffle_id ) {
		$this->data['raffle_id'] = absint( $raffle_id );
	}

	/**
	 * Get order ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int Order ID.
	 */
	public function get_order_id() {
		return $this->data['order_id'];
	}

	/**
	 * Set order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function set_order_id( $order_id ) {
		$this->data['order_id'] = absint( $order_id );
	}

	/**
	 * Get order item ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int Order item ID.
	 */
	public function get_order_item_id() {
		return $this->data['order_item_id'];
	}

	/**
	 * Set order item ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_item_id Order item ID.
	 */
	public function set_order_item_id( $order_item_id ) {
		$this->data['order_item_id'] = absint( $order_item_id );
	}

	/**
	 * Get purchaser ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int Purchaser ID.
	 */
	public function get_purchaser_id() {
		return $this->data['purchaser_id'];
	}

	/**
	 * Set purchaser ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $purchaser_id Purchaser ID.
	 */
	public function set_purchaser_id( $purchaser_id ) {
		$this->data['purchaser_id'] = absint( $purchaser_id );
	}

	/**
	 * Get purchaser name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Purchaser name.
	 */
	public function get_purchaser_name() {
		return $this->data['purchaser_name'];
	}

	/**
	 * Set purchaser name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Purchaser name.
	 */
	public function set_purchaser_name( $name ) {
		$this->data['purchaser_name'] = sanitize_text_field( $name );
	}

	/**
	 * Get purchaser email.
	 *
	 * @since 1.0.0
	 *
	 * @return string Purchaser email.
	 */
	public function get_purchaser_email() {
		return $this->data['purchaser_email'];
	}

	/**
	 * Set purchaser email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Purchaser email.
	 */
	public function set_purchaser_email( $email ) {
		$this->data['purchaser_email'] = sanitize_email( $email );
	}

	/**
	 * Get recipient name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Recipient name.
	 */
	public function get_recipient_name() {
		return $this->data['recipient_name'];
	}

	/**
	 * Set recipient name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Recipient name.
	 */
	public function set_recipient_name( $name ) {
		$this->data['recipient_name'] = sanitize_text_field( $name );
	}

	/**
	 * Get recipient email.
	 *
	 * @since 1.0.0
	 *
	 * @return string Recipient email.
	 */
	public function get_recipient_email() {
		return $this->data['recipient_email'];
	}

	/**
	 * Set recipient email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Recipient email.
	 */
	public function set_recipient_email( $email ) {
		$this->data['recipient_email'] = sanitize_email( $email );
	}

	/**
	 * Check if this is a gift ticket.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if gift ticket.
	 */
	public function is_gift() {
		return $this->data['is_gift'];
	}

	/**
	 * Set gift status.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_gift Gift status.
	 */
	public function set_is_gift( $is_gift ) {
		$this->data['is_gift'] = (bool) $is_gift;
	}

	/**
	 * Get gift message.
	 *
	 * @since 1.0.0
	 *
	 * @return string Gift message.
	 */
	public function get_gift_message() {
		return $this->data['gift_message'];
	}

	/**
	 * Set gift message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Gift message.
	 */
	public function set_gift_message( $message ) {
		$this->data['gift_message'] = sanitize_textarea_field( $message );
	}

	/**
	 * Get ticket status.
	 *
	 * @since 1.0.0
	 *
	 * @return string Status.
	 */
	public function get_status() {
		return $this->data['status'];
	}

	/**
	 * Set ticket status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Status.
	 */
	public function set_status( $status ) {
		$valid_statuses = array( 'active', 'cancelled', 'winner', 'expired', 'refunded' );
		if ( in_array( $status, $valid_statuses, true ) ) {
			$this->data['status'] = $status;
		}
	}

	/**
	 * Get created date.
	 *
	 * @since 1.0.0
	 *
	 * @return string Created date.
	 */
	public function get_created_at() {
		return $this->data['created_at'];
	}

	/**
	 * Get updated date.
	 *
	 * @since 1.0.0
	 *
	 * @return string Updated date.
	 */
	public function get_updated_at() {
		return $this->data['updated_at'];
	}

	/**
	 * Get the raffle product.
	 *
	 * @since 1.0.0
	 *
	 * @return RFWC_Product_Raffle|false Product or false.
	 */
	public function get_raffle() {
		$product = wc_get_product( $this->data['raffle_id'] );
		if ( $product && 'raffle' === $product->get_type() ) {
			return $product;
		}
		return false;
	}

	/**
	 * Get the order.
	 *
	 * @since 1.0.0
	 *
	 * @return WC_Order|false Order or false.
	 */
	public function get_order() {
		return wc_get_order( $this->data['order_id'] );
	}

	/**
	 * Get the holder name (recipient if gift, purchaser otherwise).
	 *
	 * @since 1.0.0
	 *
	 * @return string Ticket holder name.
	 */
	public function get_holder_name() {
		return $this->data['recipient_name'];
	}

	/**
	 * Get the holder email (recipient if gift, purchaser otherwise).
	 *
	 * @since 1.0.0
	 *
	 * @return string Ticket holder email.
	 */
	public function get_holder_email() {
		if ( $this->is_gift() && ! empty( $this->data['recipient_email'] ) ) {
			return $this->data['recipient_email'];
		}
		return $this->data['purchaser_email'];
	}

	/**
	 * Get billing city for the ticket holder.
	 *
	 * Priority: order billing_city, then WooCommerce customer profile.
	 *
	 * @since 1.0.10
	 *
	 * @return string City name or empty string.
	 */
	public function get_holder_city() {
		$order = wc_get_order( $this->get_order_id() );
		if ( $order ) {
			$city = $order->get_billing_city();
			if ( $city ) {
				return $city;
			}
		}

		$purchaser_id = $this->get_purchaser_id();
		if ( $purchaser_id ) {
			$customer = new WC_Customer( $purchaser_id );
			$city     = $customer->get_billing_city();
			if ( $city ) {
				return $city;
			}
		}

		return '';
	}

	/**
	 * Get billing state/province for the ticket holder.
	 *
	 * @since 1.1.0
	 *
	 * @return string State name or empty string.
	 */
	public function get_holder_state() {
		$order = wc_get_order( $this->get_order_id() );
		if ( $order ) {
			$state   = $order->get_billing_state();
			$country = $order->get_billing_country();
			if ( $state ) {
				$states = WC()->countries->get_states( $country );
				return isset( $states[ $state ] ) ? $states[ $state ] : $state;
			}
		}

		$purchaser_id = $this->get_purchaser_id();
		if ( $purchaser_id ) {
			$customer = new WC_Customer( $purchaser_id );
			$state    = $customer->get_billing_state();
			$country  = $customer->get_billing_country();
			if ( $state ) {
				$states = WC()->countries->get_states( $country );
				return isset( $states[ $state ] ) ? $states[ $state ] : $state;
			}
		}

		return '';
	}

	/**
	 * Get billing country for the ticket holder.
	 *
	 * @since 1.1.0
	 *
	 * @return string Country name or empty string.
	 */
	public function get_holder_country() {
		$order = wc_get_order( $this->get_order_id() );
		if ( $order ) {
			$country_code = $order->get_billing_country();
			if ( $country_code ) {
				$countries = WC()->countries->get_countries();
				return isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;
			}
		}

		$purchaser_id = $this->get_purchaser_id();
		if ( $purchaser_id ) {
			$customer     = new WC_Customer( $purchaser_id );
			$country_code = $customer->get_billing_country();
			if ( $country_code ) {
				$countries = WC()->countries->get_countries();
				return isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;
			}
		}

		return '';
	}

	/**
	 * Get the holder's location string based on the global display setting.
	 *
	 * @since 1.1.0
	 *
	 * @return string Location string based on rfwc_winner_location_display setting.
	 */
	public function get_holder_location() {
		$display = get_option( 'rfwc_winner_location_display', 'city' );

		switch ( $display ) {
			case 'state':
				return $this->get_holder_state();

			case 'city_state':
				$city  = $this->get_holder_city();
				$state = $this->get_holder_state();
				if ( $city && $state ) {
					return $city . ', ' . $state;
				}
				return $city ? $city : $state;

			case 'country':
				return $this->get_holder_country();

			case 'none':
				return '';

			case 'city':
			default:
				return $this->get_holder_city();
		}
	}

	/**
	 * Get formatted ticket number with prefix and suffix.
	 *
	 * @since 1.0.0
	 *
	 * @return string Formatted ticket number.
	 */
	public function get_formatted_ticket_number() {
		$raffle = $this->get_raffle();
		$prefix = '';
		$suffix = '';

		if ( $raffle ) {
			$prefix = $raffle->get_ticket_prefix();
			$suffix = $raffle->get_ticket_suffix();
		}

		return $prefix . $this->data['ticket_number'] . $suffix;
	}

	/**
	 * Get tickets by raffle ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $raffle_id Raffle ID.
	 * @param array $args      Query arguments.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public static function get_by_raffle( $raffle_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'  => 'active',
			'limit'   => -1,
			'offset'  => 0,
			'orderby' => 'id',
			'order'   => 'ASC',
		);

		$args            = wp_parse_args( $args, $defaults );
		$raffle_id_clean = absint( $raffle_id );
		$status_clean    = sanitize_text_field( $args['status'] );
		$orderby_clean   = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$limit_clean     = absint( $args['limit'] );
		$offset_clean    = absint( $args['offset'] );
		$table           = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $status_clean && $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE raffle_id = %d AND status = %s ORDER BY {$orderby_clean} LIMIT %d OFFSET %d",
					$raffle_id_clean,
					$status_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} elseif ( $status_clean ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE raffle_id = %d AND status = %s ORDER BY {$orderby_clean}",
					$raffle_id_clean,
					$status_clean
				)
			);
		} elseif ( $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE raffle_id = %d ORDER BY {$orderby_clean} LIMIT %d OFFSET %d",
					$raffle_id_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE raffle_id = %d ORDER BY {$orderby_clean}",
					$raffle_id_clean
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new self( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Get tickets by order ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE order_id = %d ORDER BY id ASC', absint( $order_id ) )
		);

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new self( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Get tickets by purchaser email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Purchaser email.
	 * @param array  $args  Query arguments.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public static function get_by_purchaser_email( $email, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status' => '',
			'limit'  => -1,
			'offset' => 0,
		);

		$args         = wp_parse_args( $args, $defaults );
		$email_clean  = sanitize_email( $email );
		$status_clean = sanitize_text_field( $args['status'] );
		$limit_clean  = absint( $args['limit'] );
		$offset_clean = absint( $args['offset'] );
		$table        = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $status_clean && $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE purchaser_email = %s AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$email_clean,
					$status_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} elseif ( $status_clean ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE purchaser_email = %s AND status = %s ORDER BY created_at DESC",
					$email_clean,
					$status_clean
				)
			);
		} elseif ( $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE purchaser_email = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$email_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE purchaser_email = %s ORDER BY created_at DESC",
					$email_clean
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new self( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Get tickets by recipient email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Recipient email.
	 * @param array  $args  Query arguments.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	public static function get_by_recipient_email( $email, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status' => '',
			'limit'  => -1,
			'offset' => 0,
		);

		$args         = wp_parse_args( $args, $defaults );
		$email_clean  = sanitize_email( $email );
		$status_clean = sanitize_text_field( $args['status'] );
		$limit_clean  = absint( $args['limit'] );
		$offset_clean = absint( $args['offset'] );
		$table        = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $status_clean && $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE recipient_email = %s AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$email_clean,
					$status_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} elseif ( $status_clean ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE recipient_email = %s AND status = %s ORDER BY created_at DESC",
					$email_clean,
					$status_clean
				)
			);
		} elseif ( $limit_clean > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE recipient_email = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$email_clean,
					$limit_clean,
					$offset_clean
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE recipient_email = %s ORDER BY created_at DESC",
					$email_clean
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tickets = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$tickets[] = new self( $row );
			}
		}

		return $tickets;
	}

	/**
	 * Count tickets for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $status    Optional status filter.
	 *
	 * @return int Ticket count.
	 */
	public static function count_by_raffle( $raffle_id, $status = 'active' ) {
		global $wpdb;

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE raffle_id = %d AND status = %s',
					absint( $raffle_id ),
					sanitize_text_field( $status )
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE raffle_id = %d', absint( $raffle_id ) )
		);
	}

	/**
	 * Get next ticket number for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return int Next ticket number.
	 */
	public static function get_next_number( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(CAST(ticket_number AS UNSIGNED)) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE raffle_id = %d',
				absint( $raffle_id )
			)
		);

		$start_number = absint( get_option( 'rfwc_ticket_start_number', 1 ) );

		return max( $start_number, ( absint( $max ) + 1 ) );
	}

	/**
	 * Check if a ticket number exists for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id     Raffle ID.
	 * @param string $ticket_number Ticket number.
	 *
	 * @return bool True if exists.
	 */
	public static function number_exists( $raffle_id, $ticket_number ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_tickets' ) . ' WHERE raffle_id = %d AND ticket_number = %s',
				absint( $raffle_id ),
				sanitize_text_field( $ticket_number )
			)
		);

		return $exists > 0;
	}

	/**
	 * Get distinct order IDs that have active tickets for a raffle.
	 *
	 * Used by the mass refund system to discover which orders need refunding.
	 *
	 * @since 1.0.8
	 *
	 * @param int $raffle_id Raffle product ID.
	 * @return int[] Array of order IDs.
	 */
	public static function get_order_ids_for_raffle( $raffle_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$table} WHERE raffle_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $raffle_id )
			)
		);

		return array_map( 'absint', $results );
	}

	/**
	 * Update ticket status by raffle and order.
	 *
	 * Bulk-updates all tickets for a given raffle + order combination.
	 *
	 * @since 1.0.8
	 *
	 * @param int    $raffle_id  Raffle product ID.
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $new_status New status to set.
	 * @param string $old_status Only update tickets with this current status.
	 * @return int Number of rows updated.
	 */
	public static function update_status_by_raffle_order( $raffle_id, $order_id, $new_status, $old_status = 'active' ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE raffle_id = %d AND order_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_text_field( $new_status ),
				absint( $raffle_id ),
				absint( $order_id ),
				sanitize_text_field( $old_status )
			)
		);

		return absint( $updated );
	}

	/**
	 * Get ticket meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Meta key.
	 * @return mixed Meta value or empty string if not found.
	 */
	public function get_meta( $key ) {
		if ( ! $this->id ) {
			return '';
		}

		$meta = get_option( 'rfwc_ticket_meta_' . $this->id, array() );
		return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
	}

	/**
	 * Update ticket meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return bool True on success.
	 */
	public function update_meta( $key, $value ) {
		if ( ! $this->id ) {
			return false;
		}

		$meta         = get_option( 'rfwc_ticket_meta_' . $this->id, array() );
		$meta[ $key ] = $value;
		return update_option( 'rfwc_ticket_meta_' . $this->id, $meta, false );
	}

	/**
	 * Delete ticket meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Meta key.
	 * @return bool True on success.
	 */
	public function delete_meta( $key ) {
		if ( ! $this->id ) {
			return false;
		}

		$meta = get_option( 'rfwc_ticket_meta_' . $this->id, array() );
		if ( isset( $meta[ $key ] ) ) {
			unset( $meta[ $key ] );
			return update_option( 'rfwc_ticket_meta_' . $this->id, $meta, false );
		}
		return true;
	}
}
