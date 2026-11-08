<?php
/**
 * My Raffle Tickets tab in WooCommerce My Account.
 *
 * Displays customer tickets with download links for PDF tickets
 * and shows gift ticket status.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds My Raffle Tickets endpoint to WooCommerce My Account.
 *
 * @since 1.0.0
 */
class RFWC_My_Account {

	/**
	 * Endpoint slug.
	 *
	 * @var string
	 */
	const ENDPOINT = 'my-raffle-tickets';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
		$this->maybe_flush_rewrite_rules();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Add endpoint - use high priority to ensure it runs early.
		add_action( 'init', array( $this, 'add_endpoint' ), 5 );

		// Add menu item.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );

		// Register endpoint content.
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'endpoint_content' ) );

		// Add query var.
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_vars' ) );

		// Change page title.
		add_filter( 'the_title', array( $this, 'endpoint_title' ) );
	}

	/**
	 * Check if rewrite rules need to be flushed and flush them.
	 *
	 * @since 1.0.0
	 */
	private function maybe_flush_rewrite_rules() {
		if ( get_option( 'rfwc_flush_rewrite_rules', false ) ) {
			add_action( 'init', array( $this, 'flush_rewrite_rules' ), 20 );
		}
	}

	/**
	 * Flush rewrite rules.
	 *
	 * @since 1.0.0
	 */
	public function flush_rewrite_rules() {
		$this->add_endpoint();
		flush_rewrite_rules();
		delete_option( 'rfwc_flush_rewrite_rules' );
	}

	/**
	 * Add rewrite endpoint.
	 *
	 * @since 1.0.0
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Static method to register endpoint (used during activation).
	 *
	 * @since 1.0.0
	 */
	public static function install() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	/**
	 * Add query vars.
	 *
	 * @since 1.0.0
	 *
	 * @param array $vars Query vars.
	 *
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Add menu item to My Account.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Menu items.
	 *
	 * @return array Modified menu items.
	 */
	public function add_menu_item( $items ) {
		// Insert after "orders" menu item.
		$new_items = array();

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;

			// Insert My Tickets right after Orders.
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'My Tickets', 'raffle-for-woocommerce' );
			}
		}

		// If orders wasn't found, add before logout as fallback.
		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$logout = false;
			if ( isset( $new_items['customer-logout'] ) ) {
				$logout = $new_items['customer-logout'];
				unset( $new_items['customer-logout'] );
			}

			$new_items[ self::ENDPOINT ] = __( 'My Tickets', 'raffle-for-woocommerce' );

			if ( $logout ) {
				$new_items['customer-logout'] = $logout;
			}
		}

		return $new_items;
	}

	/**
	 * Change endpoint title.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Page title.
	 *
	 * @return string Modified title (escaped for safe output).
	 */
	public function endpoint_title( $title ) {
		global $wp_query;

		$is_endpoint = isset( $wp_query->query_vars[ self::ENDPOINT ] );

		if ( $is_endpoint && ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
			// Return escaped title since WordPress outputs it via the_title filter.
			$title = esc_html__( 'My Raffle Tickets', 'raffle-for-woocommerce' );
			remove_filter( 'the_title', array( $this, 'endpoint_title' ) );
		}

		return $title;
	}

	/**
	 * Endpoint content.
	 *
	 * @since 1.0.0
	 */
	public function endpoint_content() {
		$current_user = wp_get_current_user();

		if ( ! $current_user->ID ) {
			echo '<p>' . esc_html__( 'Please log in to view your tickets.', 'raffle-for-woocommerce' ) . '</p>';
			return;
		}

		$email = $current_user->user_email;

		// Get filter from query string.
		$filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get tickets based on filter.
		$tickets = $this->get_filtered_tickets( $email, $filter );

		// Group tickets by raffle.
		$grouped_tickets = $this->group_tickets_by_raffle( $tickets );

		wc_get_template(
			'myaccount/my-tickets.php',
			array(
				'tickets'         => $tickets,
				'grouped_tickets' => $grouped_tickets,
				'current_filter'  => $filter,
				'user_email'      => $email,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Get filtered tickets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email  User email.
	 * @param string $filter Filter type.
	 *
	 * @return RFWC_Ticket[] Array of tickets.
	 */
	private function get_filtered_tickets( $email, $filter ) {
		switch ( $filter ) {
			case 'purchased':
				// Tickets I purchased (including gifts I bought).
				return RFWC_Ticket::get_by_purchaser_email( $email );

			case 'received':
				// Tickets I received as gifts.
				$tickets = RFWC_Ticket::get_by_recipient_email( $email, array( 'status' => '' ) );
				return array_filter(
					$tickets,
					function ( $ticket ) use ( $email ) {
						// Only show gift tickets where recipient is different from purchaser.
						return $ticket->is_gift() && strtolower( $ticket->get_purchaser_email() ) !== strtolower( $email );
					}
				);

			case 'gifted':
				// Tickets I purchased as gifts for others.
				$tickets = RFWC_Ticket::get_by_purchaser_email( $email );
				return array_filter(
					$tickets,
					function ( $ticket ) {
						return $ticket->is_gift();
					}
				);

			case 'active':
				// Active tickets only.
				return RFWC_Ticket::get_by_purchaser_email( $email, array( 'status' => 'active' ) );

			default:
				// All tickets (purchased and received).
				return RFWC_Ticket_Generator::get_user_tickets( $email, 'all' );
		}
	}

	/**
	 * Group tickets by raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket[] $tickets Array of tickets.
	 *
	 * @return array Grouped tickets.
	 */
	private function group_tickets_by_raffle( $tickets ) {
		$grouped = array();

		foreach ( $tickets as $ticket ) {
			$raffle_id = $ticket->get_raffle_id();

			if ( ! isset( $grouped[ $raffle_id ] ) ) {
				$raffle                = wc_get_product( $raffle_id );
				$grouped[ $raffle_id ] = array(
					'raffle'  => $raffle,
					'tickets' => array(),
				);
			}

			$grouped[ $raffle_id ]['tickets'][] = $ticket;
		}

		return $grouped;
	}

	/**
	 * Get ticket counts for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email User email.
	 *
	 * @return array Ticket counts.
	 */
	public static function get_ticket_counts( $email ) {
		$all_tickets = RFWC_Ticket_Generator::get_user_tickets( $email, 'all' );
		$purchased   = RFWC_Ticket::get_by_purchaser_email( $email );
		$received    = RFWC_Ticket::get_by_recipient_email( $email );

		$active = array_filter(
			$all_tickets,
			function ( $ticket ) {
				return 'active' === $ticket->get_status();
			}
		);

		$gifted = array_filter(
			$purchased,
			function ( $ticket ) {
				return $ticket->is_gift();
			}
		);

		$received_gifts = array_filter(
			$received,
			function ( $ticket ) use ( $email ) {
				return $ticket->is_gift() && strtolower( $ticket->get_purchaser_email() ) !== strtolower( $email );
			}
		);

		return array(
			'all'       => count( $all_tickets ),
			'active'    => count( $active ),
			'purchased' => count( $purchased ),
			'gifted'    => count( $gifted ),
			'received'  => count( $received_gifts ),
		);
	}
}
