<?php
/**
 * WooCommerce Blocks integration.
 *
 * Adds gift purchase fields to the WooCommerce Block checkout using
 * Store API hooks and vanilla JavaScript (no build required).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks integration class.
 *
 * @since 1.0.0
 */
class RFWC_Blocks_Integration {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks for block checkout integration.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Save gift data when order is created via Store API.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_gift_data_store_api' ) );

		// Save per-ticket gift recipients to order line items (Store API orders).
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_gift_line_item_data_store_api' ), 20, 4 );

		// Patch any missing gift data from the checkout request body (belt-and-suspenders).
		// This runs AFTER order + line items are fully created, so it can fix incomplete data
		// caused by WC session race conditions.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'patch_gift_data_from_request' ), 10, 2 );

		// Clear session data after order is fully created.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clear_gift_session_data' ) );

		// Enqueue block checkout assets.
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_block_checkout_scripts' ) );
	}

	/**
	 * Check if cart contains gift-enabled raffle products.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if cart has gift-enabled raffle products.
	 */
	public function cart_has_gift_raffle() {
		if ( ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && 'raffle' === $product->get_type() && $product->is_gift_enabled() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if any cart item was pre-flagged as a gift from the product page.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if any item is pre-flagged.
	 */
	private function has_preflagged_gifts() {
		if ( ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && 'raffle' === $product->get_type() && $product->is_gift_enabled() && ! empty( $cart_item['rfwc_is_gift'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get structured cart item data for gift-enabled raffle products.
	 *
	 * Returns an array of items with their cart keys, product names,
	 * quantities, and pre-flagged status for the JS to render per-ticket
	 * recipient fields.
	 *
	 * @since 1.1.0
	 *
	 * @return array Cart items data for JS.
	 */
	private function get_cart_items_data() {
		$items = array();

		if ( ! WC()->cart ) {
			return $items;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product || 'raffle' !== $product->get_type() || ! $product->is_gift_enabled() ) {
				continue;
			}

			$items[] = array(
				'cartKey'      => $cart_item_key,
				'productName'  => $product->get_name(),
				'productId'    => $product->get_id(),
				'quantity'     => $cart_item['quantity'],
				'isPreflagged' => ! empty( $cart_item['rfwc_is_gift'] ),
			);
		}

		return $items;
	}

	/**
	 * Register Store API update callback and checkout extension schema.
	 *
	 * This is called directly from woocommerce_blocks_loaded hook to ensure
	 * the namespace is registered before checkout attempts to use it.
	 *
	 * The extension schema registration enables setExtensionData() on the JS side,
	 * which embeds gift data directly in the checkout POST body as a reliable
	 * alternative to the session-based extensionCartUpdate approach.
	 *
	 * @since 1.0.0
	 * @since 1.0.5 Added checkout extension schema registration.
	 */
	public static function register_store_api_callback() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'raffle-for-woocommerce',
				'callback'  => array( __CLASS__, 'handle_gift_data_update' ),
			)
		);

		// Register checkout extension schema so setExtensionData() data
		// is included in the checkout POST body under extensions['raffle-for-woocommerce'].
		if ( class_exists( 'Automattic\WooCommerce\StoreApi\StoreApi' ) ) {
			try {
				$extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(
					\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class
				);
				$extend->register_endpoint_data(
					array(
						'endpoint'        => 'checkout',
						'namespace'       => 'raffle-for-woocommerce',
						'data_callback'   => function () {
							return array();
						},
						'schema_callback' => function () {
							return array(
								'isGift'     => array(
									'description' => 'Whether this is a gift purchase',
									'type'        => 'boolean',
								),
								'recipients' => array(
									'description' => 'Gift recipients keyed by cart item key',
									'type'        => 'object',
								),
							);
						},
					)
				);
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Silently fail — older WC versions may not support this.
			}
		}
	}

	/**
	 * Handle gift data update from client.
	 *
	 * Static callback for Store API update. Also propagates the gift flag
	 * to cart item data so that enforcement hooks (quantity limits) and
	 * get_max_purchase_quantity() recognise items as gifts even when the
	 * customer toggled gift mode at checkout rather than on the product page.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Propagate gift flag to cart item data.
	 *
	 * @param array $data Gift data from client.
	 */
	public static function handle_gift_data_update( $data ) {
		if ( WC()->session ) {
			WC()->session->set( 'rfwc_gift_data', $data );
		}

		// Propagate the gift flag to cart item data so that quantity
		// enforcement hooks and get_max_purchase_quantity() see it.
		if ( WC()->cart ) {
			$is_gift    = ! empty( $data['isGift'] );
			$recipients = isset( $data['recipients'] ) ? (array) $data['recipients'] : array();

			foreach ( WC()->cart->cart_contents as $cart_key => &$cart_item ) {
				$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
				if ( ! $product || 'raffle' !== $product->get_type() ) {
					continue;
				}

				if ( $is_gift && isset( $recipients[ $cart_key ] ) ) {
					WC()->cart->cart_contents[ $cart_key ]['rfwc_is_gift'] = true;
				} else {
					unset( WC()->cart->cart_contents[ $cart_key ]['rfwc_is_gift'] );
				}
			}
			unset( $cart_item );
		}
	}

	/**
	 * Save gift data when order is created via Store API.
	 *
	 * Handles per-ticket recipient arrays from the block checkout JS.
	 * Stores order-level flag and first recipient for backward compatibility.
	 * Per-ticket recipients are saved to line items via save_gift_line_item_data_store_api().
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Updated for per-ticket recipient support.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function save_gift_data_store_api( $order ) {
		// Get gift data from session.
		$gift_data = WC()->session ? WC()->session->get( 'rfwc_gift_data' ) : null;

		if ( empty( $gift_data ) ) {
			$order->update_meta_data( '_rfwc_is_gift', 'no' );
			return;
		}

		$is_gift = ! empty( $gift_data['isGift'] );
		$order->update_meta_data( '_rfwc_is_gift', $is_gift ? 'yes' : 'no' );

		if ( ! $is_gift ) {
			// Clear session data.
			if ( WC()->session ) {
				WC()->session->set( 'rfwc_gift_data', null );
			}
			return;
		}

		// Save first recipient at order level for backward compatibility.
		$recipients = isset( $gift_data['recipients'] ) ? $gift_data['recipients'] : array();

		if ( ! empty( $recipients ) && is_array( $recipients ) ) {
			// Get the first recipient from the first item group.
			$first_item_recipients = reset( $recipients );
			if ( is_array( $first_item_recipients ) ) {
				$first_recipient = reset( $first_item_recipients );
				if ( is_array( $first_recipient ) ) {
					$first_name = isset( $first_recipient['firstName'] ) ? sanitize_text_field( $first_recipient['firstName'] ) : '';
					$last_name  = isset( $first_recipient['lastName'] ) ? sanitize_text_field( $first_recipient['lastName'] ) : '';
					$email      = isset( $first_recipient['email'] ) ? sanitize_email( $first_recipient['email'] ) : '';
					$message    = isset( $first_recipient['message'] ) ? sanitize_textarea_field( $first_recipient['message'] ) : '';

					$order->update_meta_data( '_rfwc_recipient_first_name', $first_name );
					$order->update_meta_data( '_rfwc_recipient_last_name', $last_name );
					$order->update_meta_data( '_rfwc_recipient_email', $email );
					$order->update_meta_data( '_rfwc_gift_message', $message );
					$order->update_meta_data( '_rfwc_recipient_name', trim( $first_name . ' ' . $last_name ) );
				}
			}
		}

		// Note: per-ticket recipients are saved on line items via save_gift_line_item_data_store_api().
		// Do NOT clear session data here; it's needed by the line item hook.
	}

	/**
	 * Save per-ticket gift recipients to order line items for Store API orders.
	 *
	 * Runs at priority 20 so the classic checkout handler (priority 10) runs first
	 * and this only handles Store API orders where POST data is absent.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 */
	public function save_gift_line_item_data_store_api( $item, $cart_item_key, $values, $order ) {
		// Only handle Store API orders (skip if classic checkout already handled it via POST).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['rfwc_is_gift'] ) ) {
			return;
		}

		$product = $values['data'];
		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		// Get gift data from session.
		$gift_data = WC()->session ? WC()->session->get( 'rfwc_gift_data' ) : null;

		if ( empty( $gift_data ) || empty( $gift_data['isGift'] ) ) {
			return;
		}

		$all_recipients = isset( $gift_data['recipients'] ) ? $gift_data['recipients'] : array();

		if ( empty( $all_recipients[ $cart_item_key ] ) || ! is_array( $all_recipients[ $cart_item_key ] ) ) {
			return;
		}

		$item_recipients = array();

		foreach ( $all_recipients[ $cart_item_key ] as $recipient ) {
			if ( ! is_array( $recipient ) ) {
				continue;
			}

			$item_recipients[] = array(
				'first_name' => isset( $recipient['firstName'] ) ? sanitize_text_field( $recipient['firstName'] ) : '',
				'last_name'  => isset( $recipient['lastName'] ) ? sanitize_text_field( $recipient['lastName'] ) : '',
				'email'      => isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '',
				'message'    => isset( $recipient['message'] ) ? sanitize_textarea_field( $recipient['message'] ) : '',
			);
		}

		if ( ! empty( $item_recipients ) ) {
			$item->add_meta_data( '_rfwc_is_gift', 'yes' );
			$item->add_meta_data( '_rfwc_gift_recipients', $item_recipients );

			// If recipient count exceeds line item quantity (async cart quantity
			// update didn't complete), correct the quantity and line item
			// totals now so the order total is charged correctly.
			if ( count( $item_recipients ) > $item->get_quantity() ) {
				$old_qty       = max( 1, $item->get_quantity() );
				$new_qty       = count( $item_recipients );
				$unit_price    = (float) $item->get_subtotal() / $old_qty;
				$unit_price_ex = (float) $item->get_total() / $old_qty;

				$item->set_quantity( $new_qty );
				$item->set_subtotal( round( $unit_price * $new_qty, 2 ) );
				$item->set_total( round( $unit_price_ex * $new_qty, 2 ) );
			}
		}
	}

	/**
	 * Clear gift session data after order is fully processed.
	 *
	 * @since 1.1.0
	 */
	public function clear_gift_session_data() {
		if ( WC()->session ) {
			WC()->session->set( 'rfwc_gift_data', null );
		}
	}

	/**
	 * Patch missing gift data from the checkout request body.
	 *
	 * This is the belt-and-suspenders safety net. It runs AFTER the order and
	 * all line items have been created. If the WC session was stale (race condition)
	 * and save_gift_data_store_api / save_gift_line_item_data_store_api saved
	 * incomplete data, this method reads the gift data from the checkout POST body's
	 * extensions field (set via setExtensionData in JS) and patches the order.
	 *
	 * @since 1.0.5
	 *
	 * @param WC_Order        $order   Order object.
	 * @param WP_REST_Request $request Checkout request.
	 */
	public function patch_gift_data_from_request( $order, $request ) {
		// Read gift data from the checkout request body extensions.
		$extensions = $request->get_param( 'extensions' );
		if ( empty( $extensions ) || empty( $extensions['raffle-for-woocommerce'] ) ) {
			return;
		}

		$gift_data = $extensions['raffle-for-woocommerce'];
		$is_gift   = ! empty( $gift_data['isGift'] );

		if ( ! $is_gift ) {
			return;
		}

		$all_recipients = isset( $gift_data['recipients'] ) ? $gift_data['recipients'] : array();
		if ( empty( $all_recipients ) || ! is_array( $all_recipients ) ) {
			return;
		}

		$order_changed = false;

		// --- Patch order-level meta if missing ---
		if ( 'yes' !== $order->get_meta( '_rfwc_is_gift' ) ) {
			$order->update_meta_data( '_rfwc_is_gift', 'yes' );
			$order_changed = true;

			// Save first recipient at order level for backward compatibility.
			$first_item_recipients = reset( $all_recipients );
			if ( is_array( $first_item_recipients ) ) {
				$first_recipient = reset( $first_item_recipients );
				if ( is_array( $first_recipient ) ) {
					$first_name = isset( $first_recipient['firstName'] ) ? sanitize_text_field( $first_recipient['firstName'] ) : '';
					$last_name  = isset( $first_recipient['lastName'] ) ? sanitize_text_field( $first_recipient['lastName'] ) : '';
					$order->update_meta_data( '_rfwc_recipient_first_name', $first_name );
					$order->update_meta_data( '_rfwc_recipient_last_name', $last_name );
					$order->update_meta_data( '_rfwc_recipient_email', isset( $first_recipient['email'] ) ? sanitize_email( $first_recipient['email'] ) : '' );
					$order->update_meta_data( '_rfwc_gift_message', isset( $first_recipient['message'] ) ? sanitize_textarea_field( $first_recipient['message'] ) : '' );
					$order->update_meta_data( '_rfwc_recipient_name', trim( $first_name . ' ' . $last_name ) );
				}
			}
		}

		// --- Patch line item recipients if incomplete ---
		// Build a product_id => recipients mapping from request data.
		// We match by product_id since cart item keys are not stored on order items.
		$product_recipients = array();
		foreach ( $all_recipients as $cart_key => $cart_recipients ) {
			if ( ! is_array( $cart_recipients ) ) {
				continue;
			}
			// Resolve product ID from the cart (if still available).
			$product_id = $this->resolve_product_id_from_cart_key( $cart_key );
			if ( $product_id ) {
				$product_recipients[ $product_id ] = $cart_recipients;
			} else {
				// Can't resolve — use cart_key as-is for a fallback pass.
				$product_recipients[ 'key_' . $cart_key ] = $cart_recipients;
			}
		}

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$existing = $item->get_meta( '_rfwc_gift_recipients', true );

			// Find matching recipients by product ID.
			$pid         = $product->get_id();
			$matched_key = null;
			if ( isset( $product_recipients[ $pid ] ) ) {
				$matched_key = $pid;
			} else {
				// Fallback: try the first unmatched entry.
				foreach ( $product_recipients as $key => $recipients ) {
					if ( is_array( $recipients ) && ! empty( $recipients ) ) {
						$matched_key = $key;
						break;
					}
				}
			}

			// Determine how many recipients the request says we should have.
			$request_count = ( null !== $matched_key && is_array( $product_recipients[ $matched_key ] ) )
				? count( $product_recipients[ $matched_key ] )
				: 0;

			// Check if recipients are already complete.
			// Compare against the REQUEST count (not line item quantity, which
			// may be wrong due to the Block Checkout async quantity issue).
			// Only skip if EVERY recipient has data. If even one is empty
			// (session race condition), re-save from the request body.
			if ( is_array( $existing ) && count( $existing ) >= max( $item->get_quantity(), $request_count ) ) {
				$all_complete = true;
				foreach ( $existing as $r ) {
					if ( empty( $r['email'] ) && empty( $r['first_name'] ) ) {
						$all_complete = false;
						break;
					}
				}
				if ( $all_complete ) {
					continue;
				}
			}

			if ( null === $matched_key || empty( $product_recipients[ $matched_key ] ) ) {
				continue;
			}

			// Sanitize and save.
			$sanitized = array();
			foreach ( $product_recipients[ $matched_key ] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$sanitized[] = array(
					'first_name' => isset( $r['firstName'] ) ? sanitize_text_field( $r['firstName'] ) : '',
					'last_name'  => isset( $r['lastName'] ) ? sanitize_text_field( $r['lastName'] ) : '',
					'email'      => isset( $r['email'] ) ? sanitize_email( $r['email'] ) : '',
					'message'    => isset( $r['message'] ) ? sanitize_textarea_field( $r['message'] ) : '',
				);
			}

			if ( ! empty( $sanitized ) ) {
				$item->update_meta_data( '_rfwc_is_gift', 'yes' );
				$item->update_meta_data( '_rfwc_gift_recipients', $sanitized );

				// Critical: if the recipient count exceeds the line item quantity
				// (Block Checkout's async changeCartItemQuantity failed), correct
				// the quantity and line item totals. The recipient list is the
				// source of truth for gift purchases.
				if ( count( $sanitized ) > $item->get_quantity() ) {
					$old_qty       = max( 1, $item->get_quantity() );
					$new_qty       = count( $sanitized );
					$unit_subtotal = (float) $item->get_subtotal() / $old_qty;
					$unit_total    = (float) $item->get_total() / $old_qty;

					$item->set_quantity( $new_qty );
					$item->set_subtotal( round( $unit_subtotal * $new_qty, 2 ) );
					$item->set_total( round( $unit_total * $new_qty, 2 ) );
					$item->save();

					$order->calculate_totals( false );
				}

				$item->save();
				$order_changed = true;

				// Remove matched entry so it's not reused for another item.
				unset( $product_recipients[ $matched_key ] );
			}
		}

		if ( $order_changed ) {
			$order->save();
		}
	}

	/**
	 * Resolve a product ID from a WooCommerce cart item key.
	 *
	 * @since 1.0.5
	 *
	 * @param string $cart_key Cart item key hash.
	 * @return int|null Product ID or null if not found.
	 */
	private function resolve_product_id_from_cart_key( $cart_key ) {
		if ( ! WC()->cart ) {
			return null;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_key );
		if ( ! empty( $cart_item ) && isset( $cart_item['product_id'] ) ) {
			return (int) $cart_item['product_id'];
		}

		return null;
	}

	/**
	 * Enqueue block checkout scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_block_checkout_scripts() {
		if ( ! $this->cart_has_gift_raffle() ) {
			return;
		}

		wp_enqueue_style(
			'rfwc-blocks-checkout',
			RFWC_PLUGIN_URL . 'assets/css/blocks-checkout.css',
			array(),
			RFWC_VERSION
		);

		wp_enqueue_script(
			'rfwc-blocks-checkout',
			RFWC_PLUGIN_URL . 'assets/js/blocks-checkout.js',
			array(),
			RFWC_VERSION,
			true
		);

		wp_localize_script(
			'rfwc-blocks-checkout',
			'rfwcBlocksCheckout',
			array(
				'cartItems'          => $this->get_cart_items_data(),
				'hasPreflaggedGifts' => $this->has_preflagged_gifts(),
				'i18n'               => array(
					'title'                => __( 'Gift Purchase', 'raffle-for-woocommerce' ),
					'description'          => __( 'Purchasing raffle tickets for someone else? Enter their details below.', 'raffle-for-woocommerce' ),
					'descriptionPreflag'   => __( 'Enter recipient details for each gift ticket below.', 'raffle-for-woocommerce' ),
					'isGiftLabel'          => __( 'This is a gift for someone else', 'raffle-for-woocommerce' ),
					'firstNameLabel'       => __( 'First name', 'raffle-for-woocommerce' ),
					'lastNameLabel'        => __( 'Last name', 'raffle-for-woocommerce' ),
					'emailLabel'           => __( 'Recipient email', 'raffle-for-woocommerce' ),
					'emailDescription'     => __( 'Ticket confirmation will be sent to this email.', 'raffle-for-woocommerce' ),
					'messageLabel'         => __( 'Gift message (optional)', 'raffle-for-woocommerce' ),
					'messagePlaceholder'   => __( 'Add a personal message...', 'raffle-for-woocommerce' ),
					'firstNamePlaceholder' => __( 'First name', 'raffle-for-woocommerce' ),
					'lastNamePlaceholder'  => __( 'Last name', 'raffle-for-woocommerce' ),
					'emailPlaceholder'     => __( 'email@example.com', 'raffle-for-woocommerce' ),
					'firstNameRequired'    => __( 'Please enter the recipient\'s first name.', 'raffle-for-woocommerce' ),
					'lastNameRequired'     => __( 'Please enter the recipient\'s last name.', 'raffle-for-woocommerce' ),
					'emailRequired'        => __( 'Please enter the recipient\'s email address.', 'raffle-for-woocommerce' ),
					'emailInvalid'         => __( 'Please enter a valid email address.', 'raffle-for-woocommerce' ),
					/* translators: %d: recipient number */
					'recipientLabel'       => __( 'Recipient %d', 'raffle-for-woocommerce' ),
					/* translators: 1: product name, 2: ticket count */
					'ticketCount'          => __( '%1$s (%2$d ticket(s))', 'raffle-for-woocommerce' ),
					'addRecipient'         => __( '+ Add another recipient', 'raffle-for-woocommerce' ),
					'remove'               => __( 'Remove', 'raffle-for-woocommerce' ),
				),
			)
		);
	}
}

/**
 * Register Store API callback early on woocommerce_blocks_loaded.
 *
 * This must happen before the checkout block tries to use the namespace,
 * so we register it directly on the hook instead of waiting for class instantiation.
 */
add_action(
	'woocommerce_blocks_loaded',
	array( 'RFWC_Blocks_Integration', 'register_store_api_callback' )
);
