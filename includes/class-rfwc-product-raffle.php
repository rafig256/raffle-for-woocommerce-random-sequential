<?php
/**
 * WooCommerce raffle product type.
 *
 * Custom product type with raffle-specific fields like start date,
 * end date, ticket limits, and prize configuration.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Custom WooCommerce product type for raffles.
 *
 * @since 1.0.0
 */
class RFWC_Product_Raffle extends WC_Product {

	/**
	 * Product type.
	 *
	 * @var string
	 */
	protected $product_type = 'raffle';

	/**
	 * Cached sold-ticket count for the current request.
	 *
	 * Declared explicitly to avoid the PHP 8.2+ "dynamic property
	 * deprecated" notice. `null` means "not yet computed" so we can
	 * distinguish from a real zero.
	 *
	 * @since 1.1.14
	 *
	 * @var int|null
	 */
	protected $tickets_sold_cache = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int|WC_Product|object $product Product ID, post object, or product object.
	 */
	public function __construct( $product = 0 ) {
		parent::__construct( $product );
	}

	/**
	 * Get product type.
	 *
	 * @since 1.0.0
	 *
	 * @return string Product type.
	 */
	public function get_type() {
		return 'raffle';
	}

	/**
	 * Check if product is purchasable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if purchasable.
	 */
	public function is_purchasable() {
		return $this->exists() && $this->is_in_stock() && $this->has_enough_stock( 1 ) && $this->is_active();
	}

	/**
	 * Check if raffle is currently active.
	 *
	 * A raffle is active if it has started and has not ended.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if raffle is active and accepting entries.
	 */
	public function is_active() {
		// A raffle that has been drawn (completed) or failed is no longer active,
		// even if the scheduled end date hasn't passed yet.
		$status = $this->get_meta( '_rfwc_status', true );
		if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return false;
		}

		return $this->has_started() && ! $this->has_ended();
	}

	/**
	 * Check if raffle has started.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the raffle start time has passed.
	 */
	public function has_started() {
		$start_timestamp = $this->get_start_timestamp();

		if ( ! $start_timestamp ) {
			return true;
		}

		return $start_timestamp <= time();
	}

	/**
	 * Check if raffle has ended.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the raffle end time has passed.
	 */
	public function has_ended() {
		$end_timestamp = $this->get_end_timestamp();

		if ( ! $end_timestamp ) {
			return false;
		}

		return $end_timestamp < time();
	}

	/**
	 * Get raffle start date.
	 *
	 * @since 1.0.0
	 *
	 * @return string Start date in YYYY-MM-DD format or empty string.
	 */
	public function get_start_date() {
		return $this->get_meta( '_rfwc_start_date', true );
	}

	/**
	 * Set raffle start date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Start date in YYYY-MM-DD format.
	 */
	public function set_start_date( $date ) {
		$this->update_meta_data( '_rfwc_start_date', $date );
	}

	/**
	 * Get raffle start time.
	 *
	 * @since 1.0.0
	 *
	 * @return string Start time in HH:MM format or empty string.
	 */
	public function get_start_time() {
		return $this->get_meta( '_rfwc_start_time', true );
	}

	/**
	 * Set raffle start time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $time Start time in HH:MM format.
	 */
	public function set_start_time( $time ) {
		$this->update_meta_data( '_rfwc_start_time', $time );
	}

	/**
	 * Get raffle start datetime as timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null Unix timestamp or null if not set.
	 */
	public function get_start_timestamp() {
		$date = $this->get_start_date();

		if ( ! $date ) {
			return null;
		}

		$time     = $this->get_start_time();
		$time_str = $time ? $time : '00:00';
		$datetime = $date . ' ' . $time_str;

		// Parse in WordPress timezone to get a real UTC timestamp.
		try {
			$dt = new DateTime( $datetime, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return strtotime( $datetime );
		}
	}

	/**
	 * Get raffle end date.
	 *
	 * @since 1.0.0
	 *
	 * @return string End date in YYYY-MM-DD format or empty string.
	 */
	public function get_end_date() {
		return $this->get_meta( '_rfwc_end_date', true );
	}

	/**
	 * Set raffle end date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date End date in YYYY-MM-DD format.
	 */
	public function set_end_date( $date ) {
		$this->update_meta_data( '_rfwc_end_date', $date );
	}

	/**
	 * Get raffle end time.
	 *
	 * @since 1.0.0
	 *
	 * @return string End time in HH:MM format or empty string.
	 */
	public function get_end_time() {
		return $this->get_meta( '_rfwc_end_time', true );
	}

	/**
	 * Set raffle end time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $time End time in HH:MM format.
	 */
	public function set_end_time( $time ) {
		$this->update_meta_data( '_rfwc_end_time', $time );
	}

	/**
	 * Get raffle end datetime as timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null Unix timestamp or null if not set.
	 */
	public function get_end_timestamp() {
		$date = $this->get_end_date();

		if ( ! $date ) {
			return null;
		}

		$time = $this->get_end_time();
		// Default to end of day (23:59) if time not specified.
		$time_str = $time ? $time : '23:59';
		$datetime = $date . ' ' . $time_str;

		// Parse in WordPress timezone to get a real UTC timestamp.
		try {
			$dt = new DateTime( $datetime, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return strtotime( $datetime );
		}
	}

	/**
	 * Get total tickets available.
	 *
	 * @since 1.0.0
	 *
	 * @return int|string Total tickets or 'unlimited'.
	 */
	public function get_total_tickets() {
		$total = $this->get_meta( '_rfwc_total_tickets', true );
		return '' === $total ? 'unlimited' : absint( $total );
	}

	/**
	 * Set total tickets.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $count Total tickets or empty for unlimited.
	 */
	public function set_total_tickets( $count ) {
		$this->update_meta_data( '_rfwc_total_tickets', $count );
	}

	/**
	 * Get max tickets per customer.
	 *
	 * @since 1.0.0
	 *
	 * @return int Max tickets or 0 for unlimited.
	 */
	public function get_max_tickets_per_customer() {
		$max = $this->get_meta( '_rfwc_max_tickets_per_customer', true );
		return '' === $max ? 0 : absint( $max );
	}

	/**
	 * Set max tickets per customer.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count Max tickets.
	 */
	public function set_max_tickets_per_customer( $count ) {
		$this->update_meta_data( '_rfwc_max_tickets_per_customer', $count );
	}

	/**
	 * Get the maximum quantity purchasable at once.
	 *
	 * Overrides WC_Product to enforce raffle ticket limits on the product page,
	 * classic cart, Block Cart, and Block Checkout (via the Store API).
	 *
	 * Returns the tightest constraint among: available tickets and per-customer
	 * max. The per-customer limit is included here because the Block Cart and
	 * Block Checkout read this value to set the quantity input controls — the
	 * classic `woocommerce_update_cart_validation` filter does not fire in the
	 * blocks-based cart.
	 *
	 * For non-gift purchases, the limit is the admin-configured max per
	 * customer. When max per customer is empty/0 it is treated as unlimited
	 * (only the ticket availability cap applies). For gift purchases, only
	 * the ticket availability cap applies since each ticket goes to a
	 * different recipient.
	 *
	 * @since 1.0.5
	 *
	 * @return int Max quantity or -1 for unlimited.
	 */
	public function get_max_purchase_quantity() {
		$limits = array();

		// Ticket availability cap.
		$available = $this->get_available_tickets();
		if ( 'unlimited' !== $available ) {
			$limits[] = max( 1, (int) $available );
		}

		// Check if any cart item for this product is currently flagged as a
		// gift.  When the customer toggles gift mode at checkout (rather than
		// on the product page), the cart item's rfwc_is_gift flag is set by
		// handle_gift_data_update().  Gift items should not be capped by the
		// per-customer limit because each ticket goes to a different person.
		$is_gift_in_cart = false;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
				if ( isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] === $this->get_id() && ! empty( $cart_item['rfwc_is_gift'] ) ) {
					$is_gift_in_cart = true;
					break;
				}
			}

			// Fallback: check WC session for gift data (race-condition guard).
			if ( ! $is_gift_in_cart && WC()->session ) {
				$gift_data = WC()->session->get( 'rfwc_gift_data' );
				if ( ! empty( $gift_data['isGift'] ) && ! empty( $gift_data['recipients'] ) ) {
					foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
						if ( isset( $cart_item['product_id'] ) && (int) $cart_item['product_id'] === $this->get_id() && isset( $gift_data['recipients'][ $cart_key ] ) ) {
							$is_gift_in_cart = true;
							break;
						}
					}
				}
			}
		}

		// Non-gift: respect the admin-configured per-customer limit.
		// Empty/0 means unlimited per customer (capped only by available tickets),
		// matching the field tooltip "Leave empty for unlimited".
		if ( ! $is_gift_in_cart ) {
			$max_per = $this->get_max_tickets_per_customer();
			if ( $max_per > 0 ) {
				$limits[] = $max_per;
			}
		}

		if ( ! empty( $limits ) ) {
			return min( $limits );
		}

		return -1;
	}

	/**
	 * Get bulk discount tiers for this raffle.
	 *
	 * Returns an array of tiers sorted by min_qty ascending, e.g.:
	 * [ ['min_qty' => 5, 'discount_pct' => 10], ['min_qty' => 10, 'discount_pct' => 20] ]
	 *
	 * @since 1.0.8
	 *
	 * @return array Sorted discount tiers.
	 */
	public function get_bulk_discounts() {
		$tiers = $this->get_meta( '_rfwc_bulk_discounts', true );

		if ( ! is_array( $tiers ) || empty( $tiers ) ) {
			return array();
		}

		// Filter out invalid entries and ensure correct types.
		$valid = array();
		foreach ( $tiers as $tier ) {
			$min_qty      = isset( $tier['min_qty'] ) ? absint( $tier['min_qty'] ) : 0;
			$discount_pct = isset( $tier['discount_pct'] ) ? floatval( $tier['discount_pct'] ) : 0;

			if ( $min_qty >= 2 && $discount_pct > 0 && $discount_pct < 100 ) {
				$valid[] = array(
					'min_qty'      => $min_qty,
					'discount_pct' => $discount_pct,
				);
			}
		}

		// Sort by min_qty ascending so highest applicable tier can be found with a simple loop.
		usort(
			$valid,
			function ( $a, $b ) {
				return $a['min_qty'] - $b['min_qty'];
			}
		);

		return $valid;
	}

	/**
	 * Whether this raffle has any bulk discount tiers configured.
	 *
	 * @since 1.0.8
	 *
	 * @return bool
	 */
	public function has_bulk_discounts() {
		return ! empty( $this->get_bulk_discounts() );
	}

	/**
	 * Get the unit price after applying the highest applicable bulk tier.
	 *
	 * Uses the current active price (sale price if set, otherwise regular).
	 *
	 * @since 1.0.8
	 *
	 * @param int $quantity Total ticket quantity for this raffle in the cart.
	 *
	 * @return float Discounted unit price, or the original price if no tier matches.
	 */
	public function get_bulk_discount_price( $quantity ) {
		$base_price = (float) $this->get_price();
		$tiers      = $this->get_bulk_discounts();

		if ( empty( $tiers ) || $quantity < 1 ) {
			return $base_price;
		}

		$applicable_discount = 0;

		foreach ( $tiers as $tier ) {
			if ( $quantity >= $tier['min_qty'] ) {
				$applicable_discount = $tier['discount_pct'];
			}
		}

		if ( $applicable_discount > 0 ) {
			return round( $base_price * ( 1 - $applicable_discount / 100 ), wc_get_price_decimals() );
		}

		return $base_price;
	}

	/**
	 * Get tickets sold count.
	 *
	 * @since 1.0.0
	 *
	 * @return int Tickets sold.
	 */
	public function get_tickets_sold() {
		// Use request-level cache to avoid repeated COUNT queries on the same page load.
		if ( null !== $this->tickets_sold_cache ) {
			return $this->tickets_sold_cache;
		}

		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		// Count both 'active' and 'winner' tickets as sold. Winner tickets are still
		// sold tickets — their status changes when drawn but they should remain in the count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE raffle_id = %d AND status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_id(),
				'active',
				'winner'
			)
		);

		/**
		 * Filter the sold ticket count for a raffle.
		 *
		 * Addons can adjust this count — for example, to exclude bonus
		 * tickets that should not count against the purchasable pool.
		 *
		 * @since 1.1.0
		 *
		 * @param int $count     Number of sold tickets.
		 * @param int $raffle_id Raffle product ID.
		 */
		$this->tickets_sold_cache = absint( apply_filters( 'rfwc_tickets_sold_count', absint( $count ), $this->get_id() ) );

		return $this->tickets_sold_cache;
	}

	/**
	 * Get available tickets count.
	 *
	 * @since 1.0.0
	 *
	 * @return int|string Available tickets or 'unlimited'.
	 */
	public function get_available_tickets() {
		$total = $this->get_total_tickets();

		if ( 'unlimited' === $total ) {
			return 'unlimited';
		}

		$sold = $this->get_tickets_sold();
		return max( 0, $total - $sold );
	}

	/**
	 * Check if gift purchasing is enabled for this raffle.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if enabled.
	 */
	public function is_gift_enabled() {
		$product_setting = $this->get_meta( '_rfwc_enable_gift', true );

		// Default to global setting if not set on product.
		if ( '' === $product_setting ) {
			return 'yes' === get_option( 'rfwc_enable_gift_purchase', 'yes' );
		}

		return 'yes' === $product_setting;
	}

	/**
	 * Set gift purchasing status.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Enabled status.
	 */
	public function set_gift_enabled( $enabled ) {
		$this->update_meta_data( '_rfwc_enable_gift', $enabled ? 'yes' : 'no' );
	}

	/**
	 * Get number of winners for this raffle.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of winners.
	 */
	public function get_number_of_winners() {
		$winners = $this->get_meta( '_rfwc_number_of_winners', true );
		return '' === $winners ? 1 : absint( $winners );
	}

	/**
	 * Set number of winners.
	 *
	 * @since 1.0.0
	 *
	 * @param int $count Number of winners.
	 */
	public function set_number_of_winners( $count ) {
		$this->update_meta_data( '_rfwc_number_of_winners', absint( $count ) );
	}

	/**
	 * Get prize description.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tier Prize tier for multi-winner raffles.
	 *
	 * @return string Prize description.
	 */
	public function get_prize_description( $tier = 1 ) {
		$tier = max( 1, min( rfwc_max_prize_tiers(), absint( $tier ) ) );

		// Try to get tiered descriptions first.
		$prize_descriptions = $this->get_meta( '_rfwc_prize_descriptions', true );

		if ( is_array( $prize_descriptions ) && isset( $prize_descriptions[ $tier ] ) ) {
			return $prize_descriptions[ $tier ];
		}

		// Backward compatibility: fall back to single value.
		return $this->get_meta( '_rfwc_prize_description', true );
	}

	/**
	 * Set prize description.
	 *
	 * @since 1.0.0
	 *
	 * @param string $description Prize description.
	 * @param int    $tier        Prize tier for multi-winner raffles.
	 */
	public function set_prize_description( $description, $tier = 1 ) {
		$tier               = max( 1, min( rfwc_max_prize_tiers(), absint( $tier ) ) );
		$prize_descriptions = $this->get_meta( '_rfwc_prize_descriptions', true );

		if ( ! is_array( $prize_descriptions ) ) {
			$prize_descriptions = array();
		}

		$prize_descriptions[ $tier ] = $description;
		$this->update_meta_data( '_rfwc_prize_descriptions', $prize_descriptions );
	}

	/**
	 * Get prize value.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tier Prize tier for multi-winner raffles.
	 *
	 * @return float Prize value.
	 */
	public function get_prize_value( $tier = 1 ) {
		$tier = max( 1, min( rfwc_max_prize_tiers(), absint( $tier ) ) );

		// Try to get tiered values first.
		$prize_values = $this->get_meta( '_rfwc_prize_values', true );

		if ( is_array( $prize_values ) && isset( $prize_values[ $tier ] ) ) {
			return floatval( $prize_values[ $tier ] );
		}

		// Backward compatibility: fall back to single value.
		$value = $this->get_meta( '_rfwc_prize_value', true );
		return '' === $value ? 0 : floatval( $value );
	}

	/**
	 * Set prize value.
	 *
	 * @since 1.0.0
	 *
	 * @param float $value Prize value.
	 * @param int   $tier  Prize tier for multi-winner raffles.
	 */
	public function set_prize_value( $value, $tier = 1 ) {
		$tier         = max( 1, min( rfwc_max_prize_tiers(), absint( $tier ) ) );
		$prize_values = $this->get_meta( '_rfwc_prize_values', true );

		if ( ! is_array( $prize_values ) ) {
			$prize_values = array();
		}

		$prize_values[ $tier ] = floatval( $value );
		$this->update_meta_data( '_rfwc_prize_values', $prize_values );
	}

	/**
	 * Get prize type.
	 *
	 * @since 1.0.0
	 *
	 * @return string Prize type: 'custom', 'product', or 'store_credit'.
	 */
	public function get_prize_type() {
		$type = $this->get_meta( '_rfwc_prize_type', true );
		return $type ? $type : 'custom';
	}

	/**
	 * Set prize type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Prize type.
	 */
	public function set_prize_type( $type ) {
		$this->update_meta_data( '_rfwc_prize_type', $type );
	}

	/**
	 * Get prize products for each tier.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of product IDs keyed by tier number.
	 */
	public function get_prize_products() {
		$products = $this->get_meta( '_rfwc_prize_products', true );
		return is_array( $products ) ? $products : array();
	}

	/**
	 * Get prize product for a specific tier.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tier Prize tier (1, 2, 3...).
	 *
	 * @return WC_Product|null Product object or null.
	 */
	public function get_prize_product( $tier = 1 ) {
		$products   = $this->get_prize_products();
		$product_id = isset( $products[ $tier ] ) ? $products[ $tier ] : 0;

		if ( ! $product_id ) {
			return null;
		}

		return wc_get_product( $product_id );
	}

	/**
	 * Set prize products.
	 *
	 * @since 1.0.0
	 *
	 * @param array $products Array of product IDs keyed by tier.
	 */
	public function set_prize_products( $products ) {
		$this->update_meta_data( '_rfwc_prize_products', $products );
	}

	/**
	 * Get store credit prize amount.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tier Prize tier (1-5) for multi-winner raffles.
	 *
	 * @return float Credit amount.
	 */
	public function get_prize_credit_amount( $tier = 1 ) {
		$tier = max( 1, min( 5, absint( $tier ) ) );

		// Try to get tiered credits first.
		$prize_credits = $this->get_meta( '_rfwc_prize_credits', true );

		if ( is_array( $prize_credits ) && isset( $prize_credits[ $tier ] ) ) {
			return floatval( $prize_credits[ $tier ] );
		}

		// Backward compatibility: fall back to single value.
		$amount = $this->get_meta( '_rfwc_prize_credit_amount', true );
		return '' === $amount ? 0 : floatval( $amount );
	}

	/**
	 * Set store credit prize amount.
	 *
	 * @since 1.0.0
	 *
	 * @param float $amount Credit amount.
	 * @param int   $tier   Prize tier (1-5) for multi-winner raffles.
	 */
	public function set_prize_credit_amount( $amount, $tier = 1 ) {
		$tier          = max( 1, min( 5, absint( $tier ) ) );
		$prize_credits = $this->get_meta( '_rfwc_prize_credits', true );

		if ( ! is_array( $prize_credits ) ) {
			$prize_credits = array();
		}

		$prize_credits[ $tier ] = floatval( $amount );
		$this->update_meta_data( '_rfwc_prize_credits', $prize_credits );
	}

	/**
	 * Check if auto-fulfill is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if auto-fulfill is enabled.
	 */
	public function is_auto_fulfill_enabled() {
		return 'yes' === $this->get_meta( '_rfwc_auto_fulfill', true );
	}

	/**
	 * Set auto-fulfill status.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Enabled status.
	 */
	public function set_auto_fulfill( $enabled ) {
		$this->update_meta_data( '_rfwc_auto_fulfill', $enabled ? 'yes' : 'no' );
	}

	/**
	 * Get formatted prize display based on prize type.
	 *
	 * @since 1.0.0
	 *
	 * @param int $tier Prize tier (for multi-winner raffles).
	 *
	 * @return string Formatted prize display.
	 */
	public function get_formatted_prize( $tier = 1 ) {
		$prize_type = $this->get_prize_type();

		switch ( $prize_type ) {
			case 'product':
				$product = $this->get_prize_product( $tier );
				if ( $product ) {
					return sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_permalink( $product->get_id() ) ),
						esc_html( $product->get_name() )
					);
				}
				break;

			case 'store_credit':
				$amount = $this->get_prize_credit_amount( $tier );
				if ( $amount > 0 ) {
					return sprintf(
						/* translators: %s: credit amount */
						__( '%s Store Credit', 'raffle-for-woocommerce' ),
						wc_price( $amount )
					);
				}
				break;
		}

		// Default to custom description.
		return $this->get_prize_description( $tier );
	}

	/**
	 * Get ticket number format.
	 *
	 * @since 1.0.0
	 *
	 * @return string Format: 'sequential' or 'random'.
	 */
	public function get_ticket_format() {
		$format = $this->get_meta( '_rfwc_ticket_format', true );
		return '' === $format ? get_option( 'rfwc_ticket_number_format', 'sequential' ) : $format;
	}

	/**
	 * Set ticket number format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $format Format.
	 */
	public function set_ticket_format( $format ) {
		$this->update_meta_data( '_rfwc_ticket_format', $format );
	}

	/**
	 * Get ticket prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string Ticket prefix.
	 */
	public function get_ticket_prefix() {
		$prefix = $this->get_meta( '_rfwc_ticket_prefix', true );
		return '' === $prefix ? get_option( 'rfwc_ticket_prefix', '' ) : $prefix;
	}

	/**
	 * Set ticket prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Prefix.
	 */
	public function set_ticket_prefix( $prefix ) {
		$this->update_meta_data( '_rfwc_ticket_prefix', sanitize_text_field( $prefix ) );
	}

	/**
	 * Get ticket suffix.
	 *
	 * @since 1.1.0
	 *
	 * @return string Ticket suffix.
	 */
	public function get_ticket_suffix() {
		$suffix = $this->get_meta( '_rfwc_ticket_suffix', true );
		return '' === $suffix ? get_option( 'rfwc_ticket_suffix', '' ) : $suffix;
	}

	/**
	 * Get winner selection method.
	 *
	 * @since 1.0.0
	 *
	 * @return string Method: 'manual', 'auto', or 'random_org'.
	 */
	public function get_winner_selection_method() {
		$method = $this->get_meta( '_rfwc_winner_selection', true );
		return '' === $method ? 'manual' : $method;
	}

	/**
	 * Set winner selection method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method Selection method.
	 */
	public function set_winner_selection_method( $method ) {
		$this->update_meta_data( '_rfwc_winner_selection', $method );
	}

	/**
	 * Check if raffle is sold out.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if sold out.
	 */
	public function is_sold_out() {
		$available = $this->get_available_tickets();
		return 'unlimited' !== $available && $available <= 0;
	}

	/**
	 * Get progress percentage.
	 *
	 * @since 1.0.0
	 *
	 * @return int Progress percentage.
	 */
	public function get_progress_percentage() {
		$total = $this->get_total_tickets();

		if ( 'unlimited' === $total || 0 === $total ) {
			return 0;
		}

		$sold = $this->get_tickets_sold();
		return min( 100, round( ( $sold / $total ) * 100 ) );
	}

	/**
	 * Check if skill-testing question is enabled for this raffle.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if enabled.
	 */
	public function is_skill_question_enabled() {
		$product_setting = $this->get_meta( '_rfwc_enable_skill_question', true );

		// Default to global setting if not set on product.
		if ( '' === $product_setting ) {
			return 'yes' === get_option( 'rfwc_enable_skill_question', 'no' );
		}

		return 'yes' === $product_setting;
	}

	/**
	 * Get skill question data for this raffle.
	 *
	 * Returns an array with question, type, answer, and choices.
	 * If the product uses the global pool, a pinned question is returned —
	 * assigned once on first access and persisted for consistency (important
	 * for free/postal entry compliance where entrants must see the same
	 * question on every visit). If the pinned index is out of bounds (pool
	 * was modified), a new index is assigned automatically.
	 *
	 * If the product has a custom question, that is returned directly.
	 *
	 * @since 1.0.6
	 *
	 * @return array|null Question data or null if none available.
	 */
	public function get_skill_question_data() {
		$source = $this->get_meta( '_rfwc_skill_question_source', true );

		if ( 'custom' === $source ) {
			$question = $this->get_meta( '_rfwc_skill_question', true );
			$answer   = $this->get_meta( '_rfwc_skill_answer', true );

			if ( '' === $question || '' === $answer ) {
				return null;
			}

			return array(
				'question' => $question,
				'type'     => $this->get_meta( '_rfwc_skill_question_type', true ) ? $this->get_meta( '_rfwc_skill_question_type', true ) : 'text',
				'answer'   => $answer,
				'choices'  => $this->get_meta( '_rfwc_skill_choices', true ) ? $this->get_meta( '_rfwc_skill_choices', true ) : '',
			);
		}

		// Default: use global pool with a pinned question per product.
		$pool = get_option( 'rfwc_skill_question_pool', array() );

		if ( ! is_array( $pool ) || empty( $pool ) ) {
			return null;
		}

		$pool_count  = count( $pool );
		$saved_index = $this->get_meta( '_rfwc_skill_question_pool_index', true );

		if ( '' !== $saved_index && is_numeric( $saved_index ) ) {
			$saved_index = absint( $saved_index );

			// Validate the saved index is still within pool bounds.
			if ( $saved_index < $pool_count ) {
				return $pool[ $saved_index ];
			}
		}

		// No saved index or index out of bounds — assign a new one.
		$new_index = wp_rand( 0, $pool_count - 1 );
		$this->update_meta_data( '_rfwc_skill_question_pool_index', $new_index );
		$this->save_meta_data();

		return $pool[ $new_index ];
	}

	/**
	 * Validate a submitted skill question answer.
	 *
	 * The expected answer is passed as a wp_hash() of the correct answer, so the
	 * actual answer is never exposed in page source. We hash the submitted answer
	 * the same way and compare.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submitted       The customer's submitted answer.
	 * @param string $expected_hash   The hashed correct answer from the hidden field.
	 * @return bool True if the answer is correct.
	 */
	public function validate_skill_answer( $submitted, $expected_hash ) {
		if ( '' === $submitted || '' === $expected_hash ) {
			return false;
		}

		// Normalize: trim and lowercase for case-insensitive comparison.
		$normalized = strtolower( trim( $submitted ) );
		$hash       = wp_hash( $normalized );

		return hash_equals( $expected_hash, $hash );
	}

	/**
	 * Check if free entry route is enabled for this raffle.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if enabled.
	 */
	public function is_free_entry_enabled() {
		$product_setting = $this->get_meta( '_rfwc_enable_free_entry', true );

		// Default to global setting if not set on product.
		if ( '' === $product_setting ) {
			return 'yes' === get_option( 'rfwc_enable_free_entry', 'no' );
		}

		return 'yes' === $product_setting;
	}

	/**
	 * Get free entry instructions text for this raffle.
	 *
	 * @since 1.1.0
	 *
	 * @return string Instructions text (may contain basic HTML).
	 */
	public function get_free_entry_text() {
		$text = $this->get_meta( '_rfwc_free_entry_text', true );

		if ( '' === $text ) {
			// Fall back to global default.
			$text = get_option(
				'rfwc_free_entry_text',
				__( 'To enter for free, send a postcard with your name, email address, and the raffle name to: [Your Address]. One entry per person. Entries must be received before the raffle end date.', 'raffle-for-woocommerce' )
			);
		}

		// Replace [Your Address] placeholder with the WooCommerce store address.
		// Wrap in <strong> so the address stands out visually in the free entry panel.
		if ( false !== strpos( $text, '[Your Address]' ) ) {
			$address = $this->get_store_address_string();
			$text    = str_replace(
				'[Your Address]',
				'<strong class="rfwc-postal-address">' . esc_html( $address ) . '</strong>',
				$text
			);
		}

		return $text;
	}

	/**
	 * Build a formatted store address string from WooCommerce settings.
	 *
	 * @since 1.1.0
	 *
	 * @return string The formatted store address, or the placeholder if unavailable.
	 */
	private function get_store_address_string() {
		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address', '' ),
				get_option( 'woocommerce_store_address_2', '' ),
				get_option( 'woocommerce_store_city', '' ),
				get_option( 'woocommerce_store_postcode', '' ),
			)
		);

		// Add country/state.
		$country = get_option( 'woocommerce_default_country', '' );
		if ( $country ) {
			$country_parts = explode( ':', $country );
			$country_name  = WC()->countries ? WC()->countries->get_countries()[ $country_parts[0] ] ?? $country_parts[0] : $country_parts[0];
			$parts[]       = $country_name;
		}

		if ( empty( $parts ) ) {
			return '[Your Address]';
		}

		return implode( ', ', $parts );
	}

	/**
	 * Check if raffle has winners.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if raffle has at least one winner.
	 */
	public function has_winners() {
		return RFWC_Winner::get_winner_count( $this->get_id() ) > 0;
	}

	/**
	 * Get raffle winners.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of RFWC_Winner objects.
	 */
	public function get_winners() {
		return RFWC_Winner::get_by_raffle( $this->get_id() );
	}
}
