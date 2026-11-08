<?php
/**
 * Plugin loader.
 *
 * Initializes core plugin functionality and loads required components.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Loader class.
 *
 * @since 1.0.0
 */
class RFWC_Loader {

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
		// Register custom product type.
		add_filter( 'product_type_selector', array( $this, 'add_raffle_product_type' ) );
		add_filter( 'woocommerce_product_class', array( $this, 'raffle_product_class' ), 10, 2 );

		// Server-side cart validation for raffle products.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_mixed_checkout' ), 5, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_update' ), 10, 4 );

		// Enforce raffle quantity limits in the cart (classic + blocks).
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'enforce_cart_item_quantity_display' ), 10, 3 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'enforce_cart_item_quantity_limit' ), 10, 4 );

		// Store gift flag and skill question audit trail in cart item data.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_gift_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_skill_question_cart_item_data' ), 10, 2 );

		// Display gift badge in cart / mini-cart.
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_gift_cart_item_data' ), 10, 2 );

		// Bulk ticket pricing: adjust line item prices based on quantity tiers.
		if ( rfwc_feature_enabled( 'rfwc_enable_bulk_discounts' ) ) {
			add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bulk_ticket_pricing' ), 20 );
			add_filter( 'woocommerce_cart_item_price', array( $this, 'display_bulk_cart_item_price' ), 10, 3 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_bulk_discount_order_item_meta' ), 10, 4 );
		}

		// Save skill question audit trail to order item meta.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_skill_question_order_item_meta' ), 10, 4 );

		// Filter available payment gateways when raffle products are in the cart.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_payment_gateways' ) );

		// Redirect to checkout after adding raffle product (when per-product setting enabled).
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'maybe_redirect_to_checkout' ), 10, 2 );

		// Raffle items are virtual — skip "Processing" and auto-complete orders.
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'raffle_item_needs_processing' ), 10, 3 );

		// Check for database updates.
		add_action( 'admin_init', array( 'RFWC_Install', 'maybe_update_db' ) );
	}

	/**
	 * Add raffle product type to selector.
	 *
	 * @since 1.0.0
	 *
	 * @param array $types Product types.
	 * @return array Modified product types.
	 */
	public function add_raffle_product_type( $types ) {
		$types['raffle'] = __( 'Raffle', 'raffle-for-woocommerce' );
		return $types;
	}

	/**
	 * Return custom product class for raffle products.
	 *
	 * @since 1.0.0
	 *
	 * @param string $classname Product class name.
	 * @param string $product_type Product type.
	 * @return string Modified class name.
	 */
	public function raffle_product_class( $classname, $product_type ) {
		if ( 'raffle' === $product_type ) {
			$classname = 'RFWC_Product_Raffle';
		}
		return $classname;
	}

	/**
	 * Store gift flag in cart item data when added from product page.
	 *
	 * @since 1.1.0
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id    Product ID.
	 *
	 * @return array Modified cart item data.
	 */
	public function add_gift_cart_item_data( $cart_item_data, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return $cart_item_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Add-to-cart form does not use a nonce.
		if ( isset( $_POST['rfwc_is_gift'] ) && '1' === $_POST['rfwc_is_gift'] ) {
			$cart_item_data['rfwc_is_gift'] = true;
		}

		return $cart_item_data;
	}

	/**
	 * Display gift badge in cart for gift-flagged items.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item_data Cart item display data.
	 * @param array $cart_item Cart item.
	 *
	 * @return array Modified display data.
	 */
	public function display_gift_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['rfwc_is_gift'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Purchase type', 'raffle-for-woocommerce' ),
				'value' => __( 'Gift purchase', 'raffle-for-woocommerce' ),
			);
		}

		return $item_data;
	}

	/**
	 * Store skill question audit data in cart item data.
	 *
	 * When a skill question was rendered and the customer answered it correctly
	 * (validated in validate_add_to_cart), capture a lightweight audit record:
	 * the question text, answer type, and timestamp. The actual answer is NOT
	 * stored for privacy — only the fact that the gate was passed.
	 *
	 * @since 1.0.9
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id    Product ID.
	 * @return array Modified cart item data.
	 */
	public function add_skill_question_cart_item_data( $cart_item_data, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return $cart_item_data;
		}

		// Only record when a skill question was actually shown and answered.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Add-to-cart form does not use a nonce.
		if ( ! $product->is_skill_question_enabled() || empty( $_POST['rfwc_skill_expected'] ) ) {
			return $cart_item_data;
		}

		$question_data = $product->get_skill_question_data();

		if ( ! $question_data ) {
			return $cart_item_data;
		}

		$cart_item_data['rfwc_skill_audit'] = array(
			'question'  => $question_data['question'],
			'type'      => isset( $question_data['type'] ) ? $question_data['type'] : 'text',
			'passed'    => true,
			'timestamp' => current_time( 'mysql' ),
		);

		return $cart_item_data;
	}

	/**
	 * Save skill question audit trail to WooCommerce order line item meta.
	 *
	 * Transfers the audit data captured during add-to-cart into permanent order
	 * item meta so it persists as part of the order record.
	 *
	 * @since 1.0.9
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 */
	public function save_skill_question_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['rfwc_skill_audit'] ) || ! is_array( $values['rfwc_skill_audit'] ) ) {
			return;
		}

		$item->add_meta_data( '_rfwc_skill_question_audit', $values['rfwc_skill_audit'], true );
	}

	/**
	 * Save bulk discount details as order line item meta.
	 *
	 * Stores admin-only hidden meta (original price, percentage) and a
	 * customer-visible "Bulk savings" line that appears in order emails,
	 * My Account order details, and the thank-you page.
	 *
	 * @since 1.0.8
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 */
	public function save_bulk_discount_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['rfwc_bulk_discount'] ) || ! is_array( $values['rfwc_bulk_discount'] ) ) {
			return;
		}

		$data = $values['rfwc_bulk_discount'];

		$item->add_meta_data( '_rfwc_bulk_original_price', $data['original_price'], true );
		$item->add_meta_data( '_rfwc_bulk_discount_pct', $data['discount_pct'], true );

		$total_savings = $data['savings_per'] * $item->get_quantity();

		$item->add_meta_data(
			__( 'Bulk savings', 'raffle-for-woocommerce' ),
			sprintf(
				/* translators: 1: discount percentage, 2: formatted total savings amount */
				__( '%1$s%% off - you saved %2$s', 'raffle-for-woocommerce' ),
				$data['discount_pct'],
				wc_price( $total_savings )
			),
			true
		);
	}

	/**
	 * Block mixed checkout for any product type (runs for ALL products at priority 5).
	 *
	 * Prevents adding a non-raffle product when the cart already contains a raffle,
	 * and vice versa.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product ID being added.
	 * @param int  $quantity   Quantity being added.
	 * @return bool
	 */
	public function validate_mixed_checkout( $passed, $product_id, $quantity ) {
		if ( 'yes' !== get_option( 'rfwc_block_mixed_checkout', 'no' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return $passed;
		}

		$product       = wc_get_product( $product_id );
		$adding_raffle = $product && 'raffle' === $product->get_type();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$cart_product   = wc_get_product( $cart_item['product_id'] );
			$cart_is_raffle = $cart_product && 'raffle' === $cart_product->get_type();

			if ( $adding_raffle !== $cart_is_raffle ) {
				wc_add_notice( __( 'Raffle tickets cannot be purchased together with other products. Please complete your current order first or empty your cart.', 'raffle-for-woocommerce' ), 'error' );
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Validate raffle product before adding to cart.
	 *
	 * Enforces server-side checks that the frontend HTML attributes alone cannot guarantee:
	 * raffle active status, ticket availability, and per-customer max limits.
	 * Gift purchases bypass the per-customer limit (they don't count against the buyer).
	 *
	 * @since 1.1.0
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product ID being added.
	 * @param int  $quantity   Quantity being added.
	 *
	 * @return bool False to prevent add-to-cart.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return $passed;
		}

		// Guest access control.
		if ( ! is_user_logged_in() ) {
			$guest_access = get_option( 'rfwc_guest_access', 'allow' );

			if ( 'block' === $guest_access ) {
				wc_add_notice( __( 'You must be logged in to purchase raffle tickets.', 'raffle-for-woocommerce' ), 'error' );
				return false;
			}

			if ( 'force_login' === $guest_access ) {
				$login_url = wp_login_url( get_permalink( $product_id ) );
				if ( function_exists( 'wc_get_page_permalink' ) ) {
					$myaccount_url = wc_get_page_permalink( 'myaccount' );
					if ( $myaccount_url ) {
						$login_url = add_query_arg( 'redirect_to', rawurlencode( get_permalink( $product_id ) ), $myaccount_url );
					}
				}
				wp_safe_redirect( $login_url );
				exit;
			}
		}

		// Skill-testing question validation.
		// Only validate when the hidden expected-hash field was posted, which
		// means a question was actually rendered on the page. This avoids
		// blocking add-to-cart when skill questions are enabled but the pool
		// is empty (no question was shown).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Add-to-cart form does not use a nonce.
		if ( $product->is_skill_question_enabled() && ! empty( $_POST['rfwc_skill_expected'] ) ) {
			// Check attempt limit before validating.
			$max_attempts = absint( get_option( 'rfwc_skill_question_max_attempts', 0 ) );
			if ( $max_attempts > 0 ) {
				$attempt_key = 'rfwc_qa_attempts_' . md5( $this->get_current_customer_email() . '_' . $product_id );
				$attempts    = absint( get_transient( $attempt_key ) );

				if ( $attempts >= $max_attempts ) {
					wc_add_notice( __( 'You have exceeded the maximum number of attempts for this skill question. Please try again later.', 'raffle-for-woocommerce' ), 'error' );
					return false;
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$submitted = isset( $_POST['rfwc_skill_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['rfwc_skill_answer'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$expected = sanitize_text_field( wp_unslash( $_POST['rfwc_skill_expected'] ) );

			if ( ! $product->validate_skill_answer( $submitted, $expected ) ) {
				// Track failed attempt.
				if ( $max_attempts > 0 ) {
					$attempt_key = 'rfwc_qa_attempts_' . md5( $this->get_current_customer_email() . '_' . $product_id );
					$attempts    = absint( get_transient( $attempt_key ) );
					set_transient( $attempt_key, $attempts + 1, DAY_IN_SECONDS );
				}

				wc_add_notice( __( 'Incorrect answer to the skill-testing question. Please try again.', 'raffle-for-woocommerce' ), 'error' );
				return false;
			}
		}

		// Check raffle is currently active (started and not ended).
		if ( ! $product->is_active() ) {
			if ( $product->has_ended() ) {
				wc_add_notice( __( 'This raffle has ended and is no longer accepting entries.', 'raffle-for-woocommerce' ), 'error' );
			} elseif ( ! $product->has_started() ) {
				wc_add_notice( __( 'This raffle has not started yet.', 'raffle-for-woocommerce' ), 'error' );
			} else {
				wc_add_notice( __( 'This raffle is not available for purchase.', 'raffle-for-woocommerce' ), 'error' );
			}
			return false;
		}

		// Check ticket availability (applies to all purchases including gifts).
		$available = $product->get_available_tickets();
		if ( 'unlimited' !== $available ) {
			$cart_qty = $this->get_cart_quantity_for_product( $product_id );

			if ( ( $cart_qty + $quantity ) > $available ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: number of tickets remaining */
						__( 'Only %d tickets remaining for this raffle.', 'raffle-for-woocommerce' ),
						$available
					),
					'error'
				);
				return false;
			}
		}

		// Gift purchases bypass the per-customer limit.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Add-to-cart form does not use a nonce.
		$is_gift = isset( $_POST['rfwc_is_gift'] ) && '1' === $_POST['rfwc_is_gift'];

		if ( $is_gift ) {
			return $passed;
		}

		// Check per-customer max (only non-gift purchases count against the limit).
		$max_per_customer = $product->get_max_tickets_per_customer();

		if ( $max_per_customer > 0 ) {
			$existing_count = $this->get_customer_ticket_count( $product_id );
			$cart_qty       = $this->get_cart_quantity_for_product( $product_id, true );
			$total          = $existing_count + $cart_qty + $quantity;

			if ( $total > $max_per_customer ) {
				$remaining    = max( 0, $max_per_customer - $existing_count - $cart_qty );
				$gift_enabled = $product->is_gift_enabled();

				if ( 0 === $remaining ) {
					if ( $gift_enabled ) {
						wc_add_notice(
							sprintf(
								/* translators: %d: max tickets per customer */
								__( 'You have already reached the maximum of %d ticket(s) for this raffle. You can still purchase gift tickets for others.', 'raffle-for-woocommerce' ),
								$max_per_customer
							),
							'error'
						);
					} else {
						wc_add_notice(
							sprintf(
								/* translators: %d: max tickets per customer */
								__( 'You have already reached the maximum of %d ticket(s) per person for this raffle.', 'raffle-for-woocommerce' ),
								$max_per_customer
							),
							'error'
						);
					}
				} elseif ( $gift_enabled ) {
						wc_add_notice(
							sprintf(
								/* translators: %d: number of tickets the customer can still buy */
								__( 'You can only purchase %d more ticket(s) for this raffle. You can still purchase gift tickets for others.', 'raffle-for-woocommerce' ),
								$remaining
							),
							'error'
						);
				} else {
					wc_add_notice(
						sprintf(
							/* translators: %d: number of tickets the customer can still buy */
							__( 'You can only purchase %d more ticket(s) for this raffle.', 'raffle-for-woocommerce' ),
							$remaining
						),
						'error'
					);
				}
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Get quantity of a product already in the cart.
	 *
	 * @since 1.1.0
	 *
	 * @param int  $product_id    Product ID.
	 * @param bool $exclude_gifts Whether to exclude gift-flagged cart items from the count.
	 *
	 * @return int Quantity in cart.
	 */
	private function get_cart_quantity_for_product( $product_id, $exclude_gifts = false ) {
		$cart_qty = 0;

		if ( ! WC()->cart ) {
			return $cart_qty;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( (int) $cart_item['product_id'] === (int) $product_id ) {
				// Skip gift items when counting toward per-customer limit.
				if ( $exclude_gifts && ! empty( $cart_item['rfwc_is_gift'] ) ) {
					continue;
				}
				$cart_qty += $cart_item['quantity'];
			}
		}

		return $cart_qty;
	}

	/**
	 * Validate cart quantity updates for raffle products.
	 *
	 * Prevents customers from increasing raffle ticket quantity beyond limits
	 * via the cart page quantity input. Gift-flagged items bypass the per-customer limit.
	 *
	 * @since 1.1.0
	 *
	 * @param bool   $passed        Whether the update is valid.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values        Cart item values.
	 * @param int    $quantity      New quantity.
	 *
	 * @return bool False to prevent the update.
	 */
	public function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		$product = wc_get_product( $values['product_id'] );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return $passed;
		}

		$product_id = $product->get_id();

		// Check ticket availability.
		$available = $product->get_available_tickets();
		if ( 'unlimited' !== $available ) {
			$other_cart_qty = $this->get_cart_quantity_for_product( $product_id ) - $values['quantity'];

			if ( ( $other_cart_qty + $quantity ) > $available ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: number of available tickets */
						__( 'Only %d tickets are available for this raffle.', 'raffle-for-woocommerce' ),
						$available
					),
					'error'
				);
				return false;
			}
		}

		// Gift-flagged cart items bypass the per-customer limit.
		if ( ! empty( $values['rfwc_is_gift'] ) ) {
			return $passed;
		}

		// Check per-customer max (non-gift items only).
		$max_per_customer = $product->get_max_tickets_per_customer();

		if ( $max_per_customer > 0 ) {
			$existing_count = $this->get_customer_ticket_count( $product_id );

			// Count only non-gift cart items for this product (excluding the one being updated).
			$other_non_gift_qty = $this->get_cart_quantity_for_product( $product_id, true ) - $values['quantity'];

			if ( ( $existing_count + $other_non_gift_qty + $quantity ) > $max_per_customer ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: max tickets per customer */
						__( 'You can only have a maximum of %d ticket(s) per person for this raffle.', 'raffle-for-woocommerce' ),
						$max_per_customer
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Enforce quantity display for raffle items in the classic cart.
	 *
	 * For non-gift raffle items with a per-customer limit, replaces the editable
	 * quantity input with a read-only display. This prevents manual quantity
	 * changes directly in the HTML.
	 *
	 * @since 1.1.0
	 *
	 * @param string $product_quantity HTML for the quantity input.
	 * @param string $cart_item_key    Cart item key.
	 * @param array  $cart_item        Cart item data.
	 *
	 * @return string Modified quantity HTML.
	 */
	public function enforce_cart_item_quantity_display( $product_quantity, $cart_item_key, $cart_item ) {
		$product = wc_get_product( $cart_item['product_id'] );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return $product_quantity;
		}

		// Gift items remain editable.
		if ( ! empty( $cart_item['rfwc_is_gift'] ) ) {
			return $product_quantity;
		}

		// Fallback: check WC session for gift data (checkout toggled gift).
		if ( WC()->session ) {
			$gift_data = WC()->session->get( 'rfwc_gift_data' );
			if ( ! empty( $gift_data['isGift'] ) && isset( $gift_data['recipients'][ $cart_item_key ] ) ) {
				return $product_quantity;
			}
		}

		// Show editable input unless the admin explicitly capped at 1 with
		// no bulk discounts. Empty/0 means unlimited per customer, so the
		// quantity input must remain editable.
		$max_per = $product->get_max_tickets_per_customer();

		if ( 1 !== $max_per || $product->has_bulk_discounts() ) {
			return $product_quantity;
		}

		return sprintf(
			'<span class="rfwc-cart-quantity-fixed">%d</span>',
			absint( $cart_item['quantity'] )
		);
	}

	/**
	 * Enforce raffle quantity limits after any cart quantity update.
	 *
	 * Fires for both classic cart form submissions and Store API (blocks cart)
	 * quantity changes. If a non-gift raffle item exceeds the per-customer max,
	 * the quantity is forcibly corrected.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $cart_item_key Cart item key.
	 * @param int     $quantity      New quantity.
	 * @param int     $old_quantity  Previous quantity.
	 * @param WC_Cart $cart          Cart object.
	 */
	public function enforce_cart_item_quantity_limit( $cart_item_key, $quantity, $old_quantity, $cart ) {
		if ( ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$cart_item = $cart->cart_contents[ $cart_item_key ];
		$product   = wc_get_product( $cart_item['product_id'] );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		// Gift items bypass the per-customer limit.
		if ( ! empty( $cart_item['rfwc_is_gift'] ) ) {
			return;
		}

		// Fallback: check WC session for gift data.  The customer may have
		// toggled gift mode at checkout without the cart item data being
		// updated yet (race condition between extensionCartUpdate and
		// changeCartItemQuantity requests).
		if ( WC()->session ) {
			$gift_data = WC()->session->get( 'rfwc_gift_data' );
			if ( ! empty( $gift_data['isGift'] ) && isset( $gift_data['recipients'][ $cart_item_key ] ) ) {
				return;
			}
		}

		// Non-gift raffle items are limited to the per-customer max.
		// When max is not set and bulk discounts exist, no per-customer cap
		// applies — ticket availability is the only limit (handled elsewhere).
		$max_per = $product->get_max_tickets_per_customer();

		if ( $max_per > 0 ) {
			if ( $quantity > $max_per ) {
				$cart->cart_contents[ $cart_item_key ]['quantity'] = $max_per;

				wc_add_notice(
					sprintf(
						/* translators: %d: max tickets per customer */
						__( 'You can purchase a maximum of %d tickets per person for this raffle.', 'raffle-for-woocommerce' ),
						$max_per
					),
					'error'
				);
			}
		} elseif ( ! $product->has_bulk_discounts() ) {
			// No per-customer limit and no bulk discounts: default to 1.
			if ( $quantity > 1 ) {
				$cart->cart_contents[ $cart_item_key ]['quantity'] = 1;

				wc_add_notice(
					__( 'Raffle tickets are limited to 1 per person. To buy tickets for others, select the gift option.', 'raffle-for-woocommerce' ),
					'error'
				);
			}
		}
	}

	/**
	 * Apply bulk ticket pricing to raffle cart items.
	 *
	 * Sums the total quantity of tickets for each raffle in the cart, finds
	 * the highest applicable discount tier, and sets the discounted unit price
	 * on every matching line item.
	 *
	 * @since 1.0.8
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function apply_bulk_ticket_pricing( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		// First pass: sum quantities per raffle product.
		$raffle_qtys = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['product_id'];
			$product    = wc_get_product( $product_id );

			if ( ! $product || 'raffle' !== $product->get_type() || ! $product->has_bulk_discounts() ) {
				continue;
			}

			if ( ! isset( $raffle_qtys[ $product_id ] ) ) {
				$raffle_qtys[ $product_id ] = 0;
			}
			$raffle_qtys[ $product_id ] += $cart_item['quantity'];
		}

		if ( empty( $raffle_qtys ) ) {
			return;
		}

		// Second pass: set discounted prices and tag cart items with discount info.
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];

			if ( ! isset( $raffle_qtys[ $product_id ] ) ) {
				continue;
			}

			$product          = wc_get_product( $product_id );
			$original_price   = (float) $product->get_price();
			$total_qty        = $raffle_qtys[ $product_id ];
			$discounted_price = $product->get_bulk_discount_price( $total_qty );

			if ( $discounted_price < $original_price ) {
				$cart_item['data']->set_price( $discounted_price );

				$tiers    = $product->get_bulk_discounts();
				$tier_pct = 0;
				foreach ( $tiers as $tier ) {
					if ( $total_qty >= $tier['min_qty'] ) {
						$tier_pct = $tier['discount_pct'];
					}
				}

				$cart->cart_contents[ $cart_item_key ]['rfwc_bulk_discount'] = array(
					'original_price' => $original_price,
					'discount_pct'   => $tier_pct,
					'savings_per'    => round( $original_price - $discounted_price, wc_get_price_decimals() ),
				);
			} else {
				unset( $cart->cart_contents[ $cart_item_key ]['rfwc_bulk_discount'] );
			}
		}
	}

	/**
	 * Display the discounted unit price in the cart when a bulk tier is active.
	 *
	 * Shows the original price struck-through with the discounted price and
	 * percentage badge, e.g.: "<del>£10.00</del> £9.00 (10% off)".
	 *
	 * @since 1.0.8
	 *
	 * @param string $price         HTML price string.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item key.
	 *
	 * @return string Modified price HTML.
	 */
	public function display_bulk_cart_item_price( $price, $cart_item, $cart_item_key ) {
		$product_id = $cart_item['product_id'];
		$product    = wc_get_product( $product_id );

		if ( ! $product || 'raffle' !== $product->get_type() || ! $product->has_bulk_discounts() ) {
			return $price;
		}

		// Sum total qty for this raffle across all cart items.
		$total_qty = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === (int) $product_id ) {
				$total_qty += $item['quantity'];
			}
		}

		$original_price   = (float) $product->get_price();
		$discounted_price = $product->get_bulk_discount_price( $total_qty );

		if ( $discounted_price < $original_price ) {
			$tiers    = $product->get_bulk_discounts();
			$pct_used = 0;
			foreach ( $tiers as $tier ) {
				if ( $total_qty >= $tier['min_qty'] ) {
					$pct_used = $tier['discount_pct'];
				}
			}

			return sprintf(
				'<del>%s</del> %s <small class="rfwc-bulk-badge">(%s%% %s)</small>',
				wc_price( $original_price ),
				wc_price( $discounted_price ),
				esc_html( $pct_used ),
				esc_html__( 'off', 'raffle-for-woocommerce' )
			);
		}

		return $price;
	}

	/**
	 * Redirect to checkout after adding a raffle product when the per-product setting is on.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url        Default redirect URL.
	 * @param object $product    Product being added (WC 3.0+ passes the product; may be null on older versions).
	 * @return string Redirect URL.
	 */
	public function maybe_redirect_to_checkout( $url, $product = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_REQUEST['add-to-cart'] ) ? absint( $_REQUEST['add-to-cart'] ) : 0;

		if ( ! $product_id ) {
			return $url;
		}

		$raffle_product = wc_get_product( $product_id );
		if ( ! $raffle_product || 'raffle' !== $raffle_product->get_type() ) {
			return $url;
		}

		if ( 'yes' === $raffle_product->get_meta( '_rfwc_redirect_to_checkout', true ) ) {
			return wc_get_checkout_url();
		}

		return $url;
	}

	/**
	 * Raffle items don't need processing — they are virtual.
	 *
	 * Returning false for raffle items lets WooCommerce auto-complete orders
	 * that contain only raffle products (skips "Processing" status).
	 *
	 * @since 1.1.0
	 *
	 * @param bool       $needs_processing Whether the item needs processing.
	 * @param WC_Product $product          The product.
	 * @param int        $order_id         The order ID.
	 * @return bool
	 */
	public function raffle_item_needs_processing( $needs_processing, $product, $order_id ) {
		if ( 'raffle' === $product->get_type() ) {
			return false;
		}

		return $needs_processing;
	}

	/**
	 * Filter available payment gateways based on raffle product restrictions.
	 *
	 * When one or more raffle products in the cart have disabled gateways,
	 * removes those gateways from checkout to prevent use.
	 *
	 * @since 1.1.0
	 *
	 * @param array $gateways Available payment gateways.
	 * @return array Filtered gateways.
	 */
	public function filter_payment_gateways( $gateways ) {
		if ( is_admin() || ! WC()->cart ) {
			return $gateways;
		}

		$disabled = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = wc_get_product( $cart_item['product_id'] );
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$product_disabled = $product->get_meta( '_rfwc_disabled_gateways', true );
			if ( is_array( $product_disabled ) ) {
				foreach ( $product_disabled as $gw_id ) {
					$disabled[ $gw_id ] = true;
				}
			}

			// Instant win: auto-hide offline gateways when "require instant payment" is on.
			$iw_require_online = $product->get_meta( '_rfwc_instant_win_require_online_payment', true );
			if (
				'yes' === $product->get_meta( '_rfwc_instant_win_enabled', true ) &&
				'yes' === ( $iw_require_online ? $iw_require_online : 'yes' )
			) {
				$offline_gateways = array( 'bacs', 'cheque', 'cod' );
				foreach ( $offline_gateways as $offline_id ) {
					$disabled[ $offline_id ] = true;
				}
			}
		}

		if ( ! empty( $disabled ) ) {
			foreach ( array_keys( $disabled ) as $gw_id ) {
				unset( $gateways[ $gw_id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Get the current customer's email address.
	 *
	 * @since 1.1.0
	 *
	 * @return string Email address, or empty string if unknown.
	 */
	private function get_current_customer_email() {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return $user->user_email;
		}

		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
			if ( $email ) {
				return $email;
			}
		}

		// Fall back to session IP for guests.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Get number of tickets a customer has already purchased for a raffle.
	 *
	 * Counts self-purchased (non-gift) tickets and received (gift) tickets.
	 * Sent gifts do NOT count against the purchaser's limit.
	 *
	 * @since 1.1.0
	 *
	 * @param int $product_id Raffle product ID.
	 *
	 * @return int Number of existing tickets.
	 */
	private function get_customer_ticket_count( $product_id ) {
		$customer = WC()->customer;

		if ( ! $customer ) {
			return 0;
		}

		$email = $customer->get_billing_email();

		if ( ! $email ) {
			$user  = wp_get_current_user();
			$email = $user->ID ? $user->user_email : '';
		}

		if ( ! $email ) {
			return 0;
		}

		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// Count self-purchased (non-gift) tickets + received gift tickets.
		// Sent gifts (is_gift=1, purchaser=you) do NOT count against your limit.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE raffle_id = %d AND status != 'cancelled' AND ( ( purchaser_email = %s AND is_gift = 0 ) OR recipient_email = %s )",
				absint( $product_id ),
				sanitize_email( $email ),
				sanitize_email( $email )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return absint( $count );
	}
}
