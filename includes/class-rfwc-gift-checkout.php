<?php
/**
 * Gift ticket purchasing at checkout.
 *
 * Adds per-ticket recipient fields to checkout when cart contains
 * gift-flagged raffle products. Stores gift data as order item meta
 * for per-ticket recipient assignment during ticket generation.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds gift recipient fields to WooCommerce checkout.
 *
 * @since 1.0.0
 */
class RFWC_Gift_Checkout {

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
		// Add gift fields to checkout.
		add_action( 'woocommerce_after_order_notes', array( $this, 'display_gift_fields' ) );

		// Validate gift fields.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_gift_fields' ) );

		// Save gift data to order items.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_gift_data' ), 10, 2 );

		// Also save per-ticket recipients to order items after they are created.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_gift_line_item_data' ), 10, 4 );

		// Enqueue checkout scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );

		// AJAX handler for updating cart item quantity from checkout.
		add_action( 'wp_ajax_rfwc_update_cart_quantity', array( $this, 'ajax_update_cart_quantity' ) );
		add_action( 'wp_ajax_nopriv_rfwc_update_cart_quantity', array( $this, 'ajax_update_cart_quantity' ) );
	}

	/**
	 * Check if cart contains raffle products with gift enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if cart has gift-enabled raffle products.
	 */
	private function cart_has_gift_raffle() {
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
	 * Get raffle items from cart that are gift-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of cart items that are raffle products with gift enabled.
	 */
	private function get_cart_gift_raffle_items() {
		$raffle_items = array();

		if ( ! WC()->cart ) {
			return $raffle_items;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && 'raffle' === $product->get_type() && $product->is_gift_enabled() ) {
				$raffle_items[ $cart_item_key ] = $cart_item;
			}
		}

		return $raffle_items;
	}

	/**
	 * Display per-ticket gift recipient fields on checkout page.
	 *
	 * Shows a gift checkbox and per-ticket recipient fields grouped by cart item.
	 * Items pre-flagged as gifts from the product page are auto-expanded. When
	 * `rfwc_enable_gift_self_claim` is on, each slot also gets a
	 * "This ticket is for me" toggle so the buyer can mix self and gift tickets
	 * in a single order.
	 *
	 * @since 1.0.0
	 * @since 1.1.0  Rewritten for per-ticket recipient fields.
	 * @since 1.1.11  Optional per-slot self-claim toggle.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function display_gift_fields( $checkout ) {
		if ( ! $this->cart_has_gift_raffle() ) {
			return;
		}

		$raffle_items = $this->get_cart_gift_raffle_items();

		if ( empty( $raffle_items ) ) {
			return;
		}

		// Check if any item is pre-flagged as gift from product page.
		$has_preflagged_gifts = false;
		foreach ( $raffle_items as $cart_item ) {
			if ( ! empty( $cart_item['rfwc_is_gift'] ) ) {
				$has_preflagged_gifts = true;
				break;
			}
		}

		$multiple_items   = count( $raffle_items ) > 1;
		$self_claim       = $this->is_self_claim_enabled();
		$buyer_label      = $this->get_buyer_self_label();
		$buyer_email_text = $this->get_buyer_billing_email();
		?>
		<div id="rfwc-gift-checkout" class="rfwc-gift-checkout" data-has-preflagged="<?php echo $has_preflagged_gifts ? '1' : '0'; ?>" data-self-claim="<?php echo $self_claim ? '1' : '0'; ?>">
			<h3><?php esc_html_e( 'Gift Purchase', 'raffle-for-woocommerce' ); ?></h3>

			<?php if ( $has_preflagged_gifts ) : ?>
				<p class="rfwc-gift-description">
					<?php esc_html_e( 'Enter recipient details for each gift ticket below.', 'raffle-for-woocommerce' ); ?>
				</p>
				<input type="hidden" name="rfwc_is_gift" value="1" />
			<?php else : ?>
				<p class="rfwc-gift-description">
					<?php esc_html_e( 'Purchasing raffle tickets for someone else? Check the box below and enter recipient details for each ticket.', 'raffle-for-woocommerce' ); ?>
				</p>
				<p class="form-row form-row-wide rfwc-is-gift-field">
					<label class="checkbox">
						<input type="checkbox" name="rfwc_is_gift" id="rfwc_is_gift" value="1" class="input-checkbox" />
						<?php esc_html_e( 'This is a gift for someone else', 'raffle-for-woocommerce' ); ?>
					</label>
				</p>
			<?php endif; ?>

			<div id="rfwc-gift-recipient-fields" class="rfwc-gift-recipient-fields<?php echo $has_preflagged_gifts ? '' : ' rfwc-hidden'; ?>">
				<?php
				foreach ( $raffle_items as $cart_item_key => $cart_item ) :
					$product  = $cart_item['data'];
					$quantity = $cart_item['quantity'];
					$is_gift  = ! empty( $cart_item['rfwc_is_gift'] );
					?>
					<div class="rfwc-gift-item-group" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" data-is-preflagged="<?php echo $is_gift ? '1' : '0'; ?>">
						<?php if ( $multiple_items ) : ?>
							<div class="rfwc-gift-item-label"><?php echo esc_html( $product->get_name() ); ?></div>
						<?php endif; ?>

						<div class="rfwc-gift-recipients-list">
							<?php for ( $i = 0; $i < $quantity; $i++ ) : ?>
								<?php
								$slot_id     = esc_attr( $cart_item_key ) . '_' . absint( $i );
								$is_self_id  = 'rfwc_recipient_self_' . $slot_id;
								$slot_prefix = 'rfwc_recipients[' . esc_attr( $cart_item_key ) . '][' . absint( $i ) . ']';
								$slot_idpfx  = 'rfwc_recipients_' . $slot_id;
								?>
								<div class="rfwc-gift-recipient-set" data-index="<?php echo absint( $i ); ?>">
									<div class="rfwc-gift-recipient-header">
										<span class="rfwc-recipient-number">
											<?php
											printf(
												/* translators: %d: recipient number */
												esc_html__( 'Recipient %d', 'raffle-for-woocommerce' ),
												absint( $i + 1 )
											);
											?>
										</span>
									</div>

									<?php if ( $self_claim ) : ?>
										<p class="form-row form-row-wide rfwc-self-claim-field">
											<label class="checkbox" for="<?php echo esc_attr( $is_self_id ); ?>">
												<input type="checkbox" class="input-checkbox rfwc-self-claim-toggle" name="<?php echo esc_attr( $slot_prefix ); ?>[is_self]" id="<?php echo esc_attr( $is_self_id ); ?>" value="1" />
												<?php echo esc_html( $buyer_label ); ?>
											</label>
										</p>
										<p class="rfwc-self-claim-note rfwc-hidden">
											<?php
											if ( $buyer_email_text ) {
												printf(
													/* translators: %s: buyer email address */
													esc_html__( 'This ticket goes to you (%s).', 'raffle-for-woocommerce' ),
													esc_html( $buyer_email_text )
												);
											} else {
												esc_html_e( 'This ticket goes to you.', 'raffle-for-woocommerce' );
											}
											?>
										</p>
									<?php endif; ?>

									<div class="rfwc-gift-recipient-fields-inner">
										<div class="rfwc-recipient-fields-row">
											<p class="form-row form-row-first">
												<label for="<?php echo esc_attr( $slot_idpfx ); ?>_first_name">
													<?php esc_html_e( 'First name', 'raffle-for-woocommerce' ); ?> <abbr class="required" title="required">*</abbr>
												</label>
												<input type="text" class="input-text" name="<?php echo esc_attr( $slot_prefix ); ?>[first_name]" id="<?php echo esc_attr( $slot_idpfx ); ?>_first_name" placeholder="<?php esc_attr_e( 'First name', 'raffle-for-woocommerce' ); ?>" />
											</p>
											<p class="form-row form-row-last">
												<label for="<?php echo esc_attr( $slot_idpfx ); ?>_last_name">
													<?php esc_html_e( 'Last name', 'raffle-for-woocommerce' ); ?> <abbr class="required" title="required">*</abbr>
												</label>
												<input type="text" class="input-text" name="<?php echo esc_attr( $slot_prefix ); ?>[last_name]" id="<?php echo esc_attr( $slot_idpfx ); ?>_last_name" placeholder="<?php esc_attr_e( 'Last name', 'raffle-for-woocommerce' ); ?>" />
											</p>
										</div>

										<p class="form-row form-row-wide">
											<label for="<?php echo esc_attr( $slot_idpfx ); ?>_email">
												<?php esc_html_e( 'Recipient email', 'raffle-for-woocommerce' ); ?> <abbr class="required" title="required">*</abbr>
											</label>
											<input type="email" class="input-text" name="<?php echo esc_attr( $slot_prefix ); ?>[email]" id="<?php echo esc_attr( $slot_idpfx ); ?>_email" placeholder="<?php esc_attr_e( 'email@example.com', 'raffle-for-woocommerce' ); ?>" />
										</p>

										<p class="form-row form-row-wide">
											<label for="<?php echo esc_attr( $slot_idpfx ); ?>_message">
												<?php esc_html_e( 'Gift message (optional)', 'raffle-for-woocommerce' ); ?>
											</label>
											<textarea class="input-text" name="<?php echo esc_attr( $slot_prefix ); ?>[message]" id="<?php echo esc_attr( $slot_idpfx ); ?>_message" placeholder="<?php esc_attr_e( 'Add a personal message...', 'raffle-for-woocommerce' ); ?>" rows="2"></textarea>
										</p>
									</div>
								</div>
							<?php endfor; ?>
						</div>

					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the per-slot self-claim toggle is enabled by the merchant.
	 *
	 * @since 1.1.11
	 *
	 * @return bool
	 */
	public function is_self_claim_enabled() {
		return 'yes' === get_option( 'rfwc_enable_gift_self_claim', 'no' );
	}

	/**
	 * Build the label shown next to the self-claim checkbox.
	 *
	 * @since 1.1.11
	 *
	 * @return string
	 */
	private function get_buyer_self_label() {
		return __( 'This ticket is for me', 'raffle-for-woocommerce' );
	}

	/**
	 * Resolve the buyer's billing email for the self-claim confirmation note.
	 *
	 * Falls back to the logged-in user's email, or empty string when neither
	 * is available (the note then shows without the email portion).
	 *
	 * @since 1.1.11
	 *
	 * @return string
	 */
	private function get_buyer_billing_email() {
		$email = '';

		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}

		if ( empty( $email ) && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$email = $user && ! empty( $user->user_email ) ? $user->user_email : '';
		}

		return is_email( $email ) ? sanitize_email( $email ) : '';
	}

	/**
	 * Validate per-ticket gift fields during checkout.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Rewritten for per-ticket validation.
	 */
	public function validate_gift_fields() {
		if ( ! $this->cart_has_gift_raffle() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook fires.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per field.
		$is_gift = isset( $_POST['rfwc_is_gift'] ) && '1' === $_POST['rfwc_is_gift'];

		if ( ! $is_gift ) {
			return;
		}

		$recipients = isset( $_POST['rfwc_recipients'] ) ? wp_unslash( $_POST['rfwc_recipients'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $recipients ) || ! is_array( $recipients ) ) {
			wc_add_notice( __( 'Please enter recipient details for your gift tickets.', 'raffle-for-woocommerce' ), 'error' );
			return;
		}

		$error_count  = 0;
		$self_enabled = $this->is_self_claim_enabled();

		foreach ( $recipients as $cart_item_key => $ticket_recipients ) {
			if ( ! is_array( $ticket_recipients ) ) {
				continue;
			}

			foreach ( $ticket_recipients as $index => $recipient ) {
				// Skip validation when the buyer claimed this slot for themselves.
				if ( $self_enabled && ! empty( $recipient['is_self'] ) ) {
					continue;
				}

				$first_name = isset( $recipient['first_name'] ) ? sanitize_text_field( wp_unslash( $recipient['first_name'] ) ) : '';
				$last_name  = isset( $recipient['last_name'] ) ? sanitize_text_field( wp_unslash( $recipient['last_name'] ) ) : '';
				$email      = isset( $recipient['email'] ) ? sanitize_email( wp_unslash( $recipient['email'] ) ) : '';

				$recipient_num = absint( $index ) + 1;

				if ( empty( $first_name ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %d: recipient number */
							__( 'Please enter the first name for recipient %d.', 'raffle-for-woocommerce' ),
							$recipient_num
						),
						'error'
					);
					++$error_count;
				}

				if ( empty( $last_name ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %d: recipient number */
							__( 'Please enter the last name for recipient %d.', 'raffle-for-woocommerce' ),
							$recipient_num
						),
						'error'
					);
					++$error_count;
				}

				if ( empty( $email ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %d: recipient number */
							__( 'Please enter the email address for recipient %d.', 'raffle-for-woocommerce' ),
							$recipient_num
						),
						'error'
					);
					++$error_count;
				} elseif ( ! is_email( $email ) ) {
					wc_add_notice(
						sprintf(
							/* translators: %d: recipient number */
							__( 'Please enter a valid email address for recipient %d.', 'raffle-for-woocommerce' ),
							$recipient_num
						),
						'error'
					);
					++$error_count;
				}

				// Stop after 5 errors to avoid flooding the notice area.
				if ( $error_count >= 5 ) {
					return;
				}
			}
		}

		// When self-claim is enabled, enforce per-customer max against the
		// self-flagged slots only. Add-to-cart can't run this check because
		// the gift/self split is decided here, at checkout.
		if ( $self_enabled ) {
			$this->validate_self_claim_max_per_customer( $recipients );
		}
	}

	/**
	 * Enforce per-customer max ticket count for self-claim slots.
	 *
	 * Counts slots that the buyer marked as "for me" per cart item, maps each
	 * cart item back to its raffle product, and compares against the buyer's
	 * existing tickets plus self-flagged cart quantity. Gift slots stay
	 * exempt — only the self portion counts toward the merchant's per-customer
	 * limit.
	 *
	 * @since 1.1.11
	 *
	 * @param array $recipients Posted recipients keyed by cart item key.
	 */
	private function validate_self_claim_max_per_customer( $recipients ) {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		// Sum the self slots per product across cart items.
		$self_per_product = array();

		foreach ( $recipients as $cart_item_key => $ticket_recipients ) {
			if ( ! is_array( $ticket_recipients ) ) {
				continue;
			}

			$cart_item = WC()->cart->get_cart_item( $cart_item_key );
			if ( ! $cart_item || empty( $cart_item['product_id'] ) ) {
				continue;
			}

			$product_id = (int) $cart_item['product_id'];
			$self_count = 0;

			foreach ( $ticket_recipients as $recipient ) {
				if ( is_array( $recipient ) && ! empty( $recipient['is_self'] ) ) {
					++$self_count;
				}
			}

			if ( $self_count <= 0 ) {
				continue;
			}

			if ( ! isset( $self_per_product[ $product_id ] ) ) {
				$self_per_product[ $product_id ] = 0;
			}

			$self_per_product[ $product_id ] += $self_count;
		}

		if ( empty( $self_per_product ) ) {
			return;
		}

		foreach ( $self_per_product as $product_id => $self_count ) {
			$product = wc_get_product( $product_id );

			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$max_per_customer = (int) $product->get_max_tickets_per_customer();
			if ( $max_per_customer <= 0 ) {
				continue;
			}

			$existing_count = $this->count_existing_customer_tickets( $product_id );
			$total          = $existing_count + $self_count;

			if ( $total > $max_per_customer ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: max tickets per customer, 2: product name */
						__( 'You can only keep %1$d ticket(s) for yourself for "%2$s". Please mark fewer slots as "This ticket is for me" or enter recipient details for the rest.', 'raffle-for-woocommerce' ),
						$max_per_customer,
						$product->get_name()
					),
					'error'
				);
			}
		}
	}

	/**
	 * Count tickets already held by the current customer for a raffle.
	 *
	 * Mirrors the count used in the cart-time validator so checkout-time
	 * self-claim limits stay consistent. Counts self-purchased and received
	 * gift tickets, ignores sent gifts.
	 *
	 * @since 1.1.11
	 *
	 * @param int $product_id Raffle product ID.
	 * @return int
	 */
	private function count_existing_customer_tickets( $product_id ) {
		$email = '';

		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}

		if ( empty( $email ) && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$email = $user && ! empty( $user->user_email ) ? $user->user_email : '';
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			return 0;
		}

		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

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

	/**
	 * Save gift data to order (order-level flag for backward compat).
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Now saves order-level flag only. Per-ticket data saved via save_gift_line_item_data().
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public function save_gift_data( $order, $data ) {
		if ( ! $this->cart_has_gift_raffle() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook fires.
		$is_gift = isset( $_POST['rfwc_is_gift'] ) && '1' === $_POST['rfwc_is_gift'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Save order-level flag for backward compat and quick checks.
		$order->update_meta_data( '_rfwc_is_gift', $is_gift ? 'yes' : 'no' );

		if ( ! $is_gift ) {
			return;
		}

		// Also store the first recipient at order level for backward compatibility.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$recipients = isset( $_POST['rfwc_recipients'] ) ? wp_unslash( $_POST['rfwc_recipients'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! empty( $recipients ) && is_array( $recipients ) ) {
			$first_item_recipients = reset( $recipients );
			if ( is_array( $first_item_recipients ) ) {
				$first_recipient = reset( $first_item_recipients );
				if ( is_array( $first_recipient ) ) {
					$first_name = isset( $first_recipient['first_name'] ) ? sanitize_text_field( wp_unslash( $first_recipient['first_name'] ) ) : '';
					$last_name  = isset( $first_recipient['last_name'] ) ? sanitize_text_field( wp_unslash( $first_recipient['last_name'] ) ) : '';
					$email      = isset( $first_recipient['email'] ) ? sanitize_email( wp_unslash( $first_recipient['email'] ) ) : '';
					$message    = isset( $first_recipient['message'] ) ? sanitize_textarea_field( wp_unslash( $first_recipient['message'] ) ) : '';

					$order->update_meta_data( '_rfwc_recipient_first_name', $first_name );
					$order->update_meta_data( '_rfwc_recipient_last_name', $last_name );
					$order->update_meta_data( '_rfwc_recipient_email', $email );
					$order->update_meta_data( '_rfwc_gift_message', $message );
					$order->update_meta_data( '_rfwc_recipient_name', trim( $first_name . ' ' . $last_name ) );
				}
			}
		}
	}

	/**
	 * Save per-ticket gift recipients to order line item meta.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 */
	public function save_gift_line_item_data( $item, $cart_item_key, $values, $order ) {
		$product = $values['data'];

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook fires.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$is_gift = isset( $_POST['rfwc_is_gift'] ) && '1' === $_POST['rfwc_is_gift'];

		if ( ! $is_gift ) {
			return;
		}

		$all_recipients = isset( $_POST['rfwc_recipients'] ) ? wp_unslash( $_POST['rfwc_recipients'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $all_recipients[ $cart_item_key ] ) || ! is_array( $all_recipients[ $cart_item_key ] ) ) {
			return;
		}

		$item_recipients = array();
		$self_enabled    = $this->is_self_claim_enabled();

		foreach ( $all_recipients[ $cart_item_key ] as $recipient ) {
			if ( ! is_array( $recipient ) ) {
				continue;
			}

			// Self-claim slot: store a marker entry with no recipient data.
			// The ticket generator routes this to the buyer's own (non-gift)
			// ticket pool while keeping the slot index aligned with quantity.
			if ( $self_enabled && ! empty( $recipient['is_self'] ) ) {
				$item_recipients[] = array(
					'is_self' => true,
				);
				continue;
			}

			$item_recipients[] = array(
				'first_name' => isset( $recipient['first_name'] ) ? sanitize_text_field( wp_unslash( $recipient['first_name'] ) ) : '',
				'last_name'  => isset( $recipient['last_name'] ) ? sanitize_text_field( wp_unslash( $recipient['last_name'] ) ) : '',
				'email'      => isset( $recipient['email'] ) ? sanitize_email( wp_unslash( $recipient['email'] ) ) : '',
				'message'    => isset( $recipient['message'] ) ? sanitize_textarea_field( wp_unslash( $recipient['message'] ) ) : '',
			);
		}

		if ( ! empty( $item_recipients ) ) {
			$item->add_meta_data( '_rfwc_is_gift', 'yes' );
			$item->add_meta_data( '_rfwc_gift_recipients', $item_recipients );
		}
	}

	/**
	 * Enqueue checkout scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_checkout_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		if ( ! $this->cart_has_gift_raffle() ) {
			return;
		}

		wp_enqueue_style(
			'rfwc-checkout',
			RFWC_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			RFWC_VERSION
		);

		wp_enqueue_script(
			'rfwc-checkout',
			RFWC_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			RFWC_VERSION,
			true
		);

		wp_localize_script(
			'rfwc-checkout',
			'rfwc_checkout_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'rfwc_update_cart_quantity' ),
				'i18n'     => array(
					/* translators: %d: recipient number */
					'recipient'  => __( 'Recipient %d', 'raffle-for-woocommerce' ),
					'remove'     => __( 'Remove', 'raffle-for-woocommerce' ),
					'first_name' => __( 'First name', 'raffle-for-woocommerce' ),
					'last_name'  => __( 'Last name', 'raffle-for-woocommerce' ),
					'email'      => __( 'Recipient email', 'raffle-for-woocommerce' ),
					'email_ph'   => __( 'email@example.com', 'raffle-for-woocommerce' ),
					'message'    => __( 'Gift message (optional)', 'raffle-for-woocommerce' ),
					'message_ph' => __( 'Add a personal message...', 'raffle-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * AJAX handler to update cart item quantity from checkout.
	 *
	 * Called when adding/removing recipient fields so the order
	 * summary updates automatically.
	 *
	 * @since 1.1.0
	 */
	public function ajax_update_cart_quantity() {
		check_ajax_referer( 'rfwc_update_cart_quantity', 'nonce' );

		$cart_key = isset( $_POST['cart_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_key'] ) ) : '';
		$quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 0;

		if ( empty( $cart_key ) || $quantity < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'raffle-for-woocommerce' ) ) );
		}

		$cart = WC()->cart;

		if ( ! $cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart not available.', 'raffle-for-woocommerce' ) ) );
		}

		// Validate raffle ticket availability before updating quantity.
		$cart_item = $cart->get_cart_item( $cart_key );
		if ( $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : wc_get_product( $cart_item['product_id'] );

			if ( $product && 'raffle' === $product->get_type() ) {
				// Check available tickets.
				$available = $product->get_available_tickets();
				if ( 'unlimited' !== $available && $quantity > (int) $available ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %d: available tickets */
								__( 'Only %d tickets are available.', 'raffle-for-woocommerce' ),
								(int) $available
							),
						)
					);
				}

				// Check raffle is still active.
				if ( ! $product->is_active() ) {
					wp_send_json_error( array( 'message' => __( 'This raffle is no longer active.', 'raffle-for-woocommerce' ) ) );
				}
			}
		}

		// This AJAX handler is only triggered from the gift checkout fields
		// (add/remove recipient).  Mark the cart item as a gift so that the
		// enforce_cart_item_quantity_limit hook does not block the update.
		if ( isset( $cart->cart_contents[ $cart_key ] ) ) {
			$cart->cart_contents[ $cart_key ]['rfwc_is_gift'] = true;
		}

		$result = $cart->set_quantity( $cart_key, $quantity, true );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Cart updated.', 'raffle-for-woocommerce' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update cart.', 'raffle-for-woocommerce' ) ) );
		}
	}
}
