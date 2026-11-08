<?php
/**
 * Free (postal) entries.
 *
 * Adds a "Free entries" tab to the Raffle Tickets admin page where a
 * shop manager records a no-purchase-necessary entry, such as a postal
 * entry, received for a raffle. Each entry becomes a completed £0.00
 * WooCommerce order so the regular ticket generator issues the tickets
 * and the entrant gets the same ticket email as a paying customer.
 *
 * @package RaffleForWooCommerce
 * @since   1.1.17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RFWC_Admin_Free_Entries
 */
class RFWC_Admin_Free_Entries {

	/**
	 * Slug of the tab on the Raffle Tickets page.
	 *
	 * @var string
	 */
	const VIEW_SLUG = 'free-entries';

	/**
	 * Order `created_via` value for free entries.
	 *
	 * @var string
	 */
	const CREATED_VIA = 'rfwc_free_entry';

	/**
	 * Payment method key stored on free entry orders.
	 *
	 * @var string
	 */
	const PAYMENT_METHOD = 'rfwc_free_entry';

	/**
	 * Capability needed to record free entries.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Constructor.
	 *
	 * @since 1.1.17
	 */
	public function __construct() {
		add_filter( 'rfwc_admin_tickets_extra_tabs', array( $this, 'register_tab' ), 5, 2 );
		add_filter( 'rfwc_admin_tickets_render_view', array( $this, 'maybe_render_view' ), 10, 2 );
		add_action( 'admin_post_rfwc_record_free_entry', array( $this, 'handle_record' ) );
	}

	/**
	 * Add the Free entries tab.
	 *
	 * @since 1.1.17
	 *
	 * @param array  $tabs         Existing extra tabs.
	 * @param string $current_view Active view slug.
	 * @return array
	 */
	public function register_tab( $tabs, $current_view ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$tabs[ self::VIEW_SLUG ] = array(
			'label' => __( 'Free entries', 'raffle-for-woocommerce' ),
		);

		return $tabs;
	}

	/**
	 * Render the tab when it is active.
	 *
	 * @since 1.1.17
	 *
	 * @param bool   $handled      Whether the view was already rendered.
	 * @param string $current_view Active view slug.
	 * @return bool
	 */
	public function maybe_render_view( $handled, $current_view ) {
		if ( $handled || self::VIEW_SLUG !== $current_view ) {
			return $handled;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to record free entries.', 'raffle-for-woocommerce' ) );
		}

		$raffles = self::get_open_raffles();
		?>
		<div class="wrap woocommerce rfwc-free-entries">
			<?php $this->render_notices(); ?>

			<p class="description" style="max-width:800px;">
				<?php esc_html_e( 'Record a free entry, such as a postal entry, when you receive it. Each entry creates a completed order at 0.00 and issues the tickets the same way a paid order does. The entrant gets the raffle ticket email, but no WooCommerce order emails.', 'raffle-for-woocommerce' ); ?>
			</p>

			<?php if ( empty( $raffles ) ) : ?>
				<p><?php esc_html_e( 'There are no open raffles to add free entries to.', 'raffle-for-woocommerce' ); ?></p>
			<?php else : ?>
				<?php $this->render_form( $raffles ); ?>
			<?php endif; ?>

			<?php
			/**
			 * Fires after the free entry form, so add-ons can add more ways to record entries.
			 *
			 * @since 1.1.17
			 *
			 * @param array $raffles Open raffles as ID => label.
			 */
			do_action( 'rfwc_free_entries_after_form', $raffles );

			$this->render_recent_entries();
			?>
		</div>
		<?php

		return true;
	}

	/**
	 * Render the single entry form.
	 *
	 * @since 1.1.17
	 *
	 * @param array $raffles Open raffles as ID => label.
	 */
	private function render_form( $raffles ) {
		$questions = array();
		foreach ( array_keys( $raffles ) as $raffle_id ) {
			$question = self::get_skill_question_text( $raffle_id );
			if ( '' !== $question ) {
				$questions[ $raffle_id ] = $question;
			}
		}
		?>
		<h2><?php esc_html_e( 'Record a free entry', 'raffle-for-woocommerce' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'rfwc_record_free_entry', 'rfwc_free_entry_nonce' ); ?>
			<input type="hidden" name="action" value="rfwc_record_free_entry" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rfwc-fe-raffle"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></label></th>
					<td>
						<select name="raffle_id" id="rfwc-fe-raffle" class="wc-enhanced-select" style="min-width:300px;" required>
							<option value=""><?php esc_html_e( 'Select a raffle...', 'raffle-for-woocommerce' ); ?></option>
							<?php foreach ( $raffles as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-first-name"><?php esc_html_e( 'First name', 'raffle-for-woocommerce' ); ?></label></th>
					<td><input type="text" name="first_name" id="rfwc-fe-first-name" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-last-name"><?php esc_html_e( 'Last name', 'raffle-for-woocommerce' ); ?></label></th>
					<td><input type="text" name="last_name" id="rfwc-fe-last-name" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-email"><?php esc_html_e( 'Email', 'raffle-for-woocommerce' ); ?></label></th>
					<td>
						<input type="email" name="email" id="rfwc-fe-email" class="regular-text" required />
						<p class="description"><?php esc_html_e( 'Used for the ticket email and to find the entrant in winner notifications.', 'raffle-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-phone"><?php esc_html_e( 'Phone', 'raffle-for-woocommerce' ); ?></label></th>
					<td><input type="text" name="phone" id="rfwc-fe-phone" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-quantity"><?php esc_html_e( 'Entries', 'raffle-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" name="quantity" id="rfwc-fe-quantity" value="1" min="1" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of tickets to issue for this entry. Usually 1.', 'raffle-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rfwc-fe-reference"><?php esc_html_e( 'Reference', 'raffle-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" name="reference" id="rfwc-fe-reference" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Optional. For example, the date the envelope arrived. Saved as an order note.', 'raffle-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr id="rfwc-fe-skill-row" style="display:none;">
					<th scope="row"><?php esc_html_e( 'Skill question', 'raffle-for-woocommerce' ); ?></th>
					<td>
						<p id="rfwc-fe-skill-question" style="margin-top:0;"></p>
						<label>
							<input type="checkbox" name="answer_checked" id="rfwc-fe-answer-checked" value="1" />
							<?php esc_html_e( 'The entrant answered this question correctly', 'raffle-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ticket email', 'raffle-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="send_email" value="1" checked />
							<?php esc_html_e( 'Email the entrant their ticket numbers', 'raffle-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Record free entry', 'raffle-for-woocommerce' ) ); ?>
		</form>

		<script>
			( function ( $ ) {
				var questions = <?php echo wp_json_encode( $questions ); ?>;
				$( function () {
					var $raffle = $( '#rfwc-fe-raffle' );
					function sync() {
						var question = questions[ $raffle.val() ];
						$( '#rfwc-fe-skill-row' ).toggle( !! question );
						$( '#rfwc-fe-skill-question' ).text( question || '' );
						$( '#rfwc-fe-answer-checked' ).prop( 'required', !! question );
					}
					$raffle.on( 'change', sync );
					sync();
				} );
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Render the most recent free entries.
	 *
	 * @since 1.1.17
	 */
	private function render_recent_entries() {
		$orders = wc_get_orders(
			array(
				'limit'       => 50,
				'created_via' => self::CREATED_VIA,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Recent free entries', 'raffle-for-woocommerce' ); ?></h2>

		<?php if ( empty( $orders ) ) : ?>
			<p class="description"><?php esc_html_e( 'No free entries recorded yet.', 'raffle-for-woocommerce' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'raffle-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Date', 'raffle-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Entrant', 'raffle-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Tickets', 'raffle-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Reference', 'raffle-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $orders as $order ) :
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					$items   = $order->get_items( 'line_item' );
					$item    = $items ? reset( $items ) : null;
					$raffle  = $item ? $item->get_name() : '';
					$numbers = array();
					foreach ( RFWC_Ticket::get_by_order( $order->get_id() ) as $ticket ) {
						$numbers[] = $ticket->get_ticket_number();
					}
					$date = $order->get_date_created();
					?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo $date ? esc_html( $date->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : ''; ?></td>
						<td>
							<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><br />
							<span class="description"><?php echo esc_html( $order->get_billing_email() ); ?></span>
						</td>
						<td><?php echo esc_html( $raffle ); ?></td>
						<td><?php echo esc_html( $numbers ? implode( ', ', $numbers ) : __( 'None', 'raffle-for-woocommerce' ) ); ?></td>
						<td><?php echo esc_html( $order->get_meta( '_rfwc_free_entry_reference' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle the single entry form submission.
	 *
	 * @since 1.1.17
	 */
	public function handle_record() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to record free entries.', 'raffle-for-woocommerce' ) );
		}

		check_admin_referer( 'rfwc_record_free_entry', 'rfwc_free_entry_nonce' );

		$result = self::record_entry(
			array(
				'raffle_id'      => isset( $_POST['raffle_id'] ) ? absint( $_POST['raffle_id'] ) : 0,
				'first_name'     => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'last_name'      => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
				'email'          => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'phone'          => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
				'quantity'       => isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1,
				'reference'      => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
				'answer_checked' => ! empty( $_POST['answer_checked'] ),
				'send_email'     => ! empty( $_POST['send_email'] ),
				'source'         => 'form',
			)
		);

		$args = array(
			'page' => 'rfwc-tickets',
			'view' => self::VIEW_SLUG,
		);

		if ( is_wp_error( $result ) ) {
			$args['rfwc_fe_error'] = $result->get_error_code();
		} else {
			$args['rfwc_fe_order'] = $result;
		}

		wp_safe_redirect( admin_url( add_query_arg( $args, 'admin.php' ) ) );
		exit;
	}

	/**
	 * Record one free entry as a completed 0.00 order.
	 *
	 * Shared by the single entry form and by add-ons (the Pro CSV import).
	 *
	 * @since 1.1.17
	 *
	 * @param array $args {
	 *     Entry data. Values are expected to be sanitized already.
	 *
	 *     @type int    $raffle_id      Raffle product ID. Required.
	 *     @type string $first_name     Entrant first name. Required.
	 *     @type string $last_name      Entrant last name.
	 *     @type string $email          Entrant email. Required.
	 *     @type string $phone          Entrant phone.
	 *     @type int    $quantity       Tickets to issue. Default 1.
	 *     @type string $reference      Free text saved as an order note.
	 *     @type bool   $answer_checked Whether the skill question answer was checked. Required when the raffle has one.
	 *     @type bool   $send_email     Whether to send the raffle ticket email. Default true.
	 *     @type string $source         Where the entry came from, for the activity log. Default 'form'.
	 * }
	 * @return int|WP_Error Order ID on success.
	 */
	public static function record_entry( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'raffle_id'      => 0,
				'first_name'     => '',
				'last_name'      => '',
				'email'          => '',
				'phone'          => '',
				'quantity'       => 1,
				'reference'      => '',
				'answer_checked' => false,
				'send_email'     => true,
				'source'         => 'form',
			)
		);

		$quantity = max( 1, absint( $args['quantity'] ) );
		$product  = wc_get_product( absint( $args['raffle_id'] ) );

		if ( ! $product instanceof RFWC_Product_Raffle ) {
			return new WP_Error( 'invalid-raffle', __( 'Select a valid raffle.', 'raffle-for-woocommerce' ) );
		}

		if ( ! self::is_open( $product ) ) {
			return new WP_Error( 'raffle-closed', __( 'This raffle is no longer taking entries.', 'raffle-for-woocommerce' ) );
		}

		if ( '' === trim( $args['first_name'] ) ) {
			return new WP_Error( 'missing-name', __( 'First name is required.', 'raffle-for-woocommerce' ) );
		}

		if ( ! is_email( $args['email'] ) ) {
			return new WP_Error( 'invalid-email', __( 'A valid email address is required.', 'raffle-for-woocommerce' ) );
		}

		if ( '' !== self::get_skill_question_text( $product->get_id() ) && ! $args['answer_checked'] ) {
			return new WP_Error( 'answer-not-checked', __( 'Confirm the entrant answered the skill question correctly.', 'raffle-for-woocommerce' ) );
		}

		$max_per = (int) $product->get_max_tickets_per_customer();
		if ( $max_per > 0 && $quantity > $max_per ) {
			return new WP_Error( 'per-customer-limit', __( 'The number of entries is over the per-customer limit for this raffle.', 'raffle-for-woocommerce' ) );
		}

		$total = $product->get_total_tickets();
		if ( 'unlimited' !== $total ) {
			$available = max( 0, (int) $total - (int) $product->get_tickets_sold() );
			if ( $available <= 0 ) {
				return new WP_Error( 'sold-out', __( 'This raffle is sold out.', 'raffle-for-woocommerce' ) );
			}
			if ( $quantity > $available ) {
				return new WP_Error( 'not-enough-tickets', __( 'Not enough tickets are left on this raffle.', 'raffle-for-woocommerce' ) );
			}
		}

		$order = wc_create_order(
			array(
				'created_via' => self::CREATED_VIA,
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
			return new WP_Error( 'order-failed', __( 'The order could not be created. Please try again.', 'raffle-for-woocommerce' ) );
		}

		$item_id = $order->add_product(
			$product,
			$quantity,
			array(
				'subtotal' => 0,
				'total'    => 0,
			)
		);

		if ( ! $item_id ) {
			$order->delete( true );
			return new WP_Error( 'order-failed', __( 'The order could not be created. Please try again.', 'raffle-for-woocommerce' ) );
		}

		wc_update_order_item_meta( $item_id, '_rfwc_free_entry', 'yes' );

		$order->set_address(
			array(
				'first_name' => $args['first_name'],
				'last_name'  => $args['last_name'],
				'email'      => $args['email'],
				'phone'      => $args['phone'],
			),
			'billing'
		);

		$customer = get_user_by( 'email', $args['email'] );
		if ( $customer ) {
			$order->set_customer_id( $customer->ID );
		}

		$order->set_payment_method( self::PAYMENT_METHOD );
		$order->set_payment_method_title( __( 'Free entry', 'raffle-for-woocommerce' ) );
		$order->update_meta_data( '_rfwc_free_entry', 'yes' );

		if ( '' !== $args['reference'] ) {
			$order->update_meta_data( '_rfwc_free_entry_reference', $args['reference'] );
			$order->add_order_note(
				/* translators: %s: reference text */
				sprintf( __( 'Free entry reference: %s', 'raffle-for-woocommerce' ), $args['reference'] )
			);
		}

		$order->calculate_totals( false );
		$order->save();

		// A free entry is not a purchase, so the WooCommerce order emails stay off.
		$email_filters = array(
			'woocommerce_email_enabled_new_order',
			'woocommerce_email_enabled_customer_processing_order',
			'woocommerce_email_enabled_customer_completed_order',
		);
		if ( ! $args['send_email'] ) {
			$email_filters[] = 'woocommerce_email_enabled_rfwc_ticket_confirmation';
		}
		foreach ( $email_filters as $filter ) {
			add_filter( $filter, '__return_false', 99 );
		}

		$order->update_status( 'completed', __( 'Free entry recorded by shop manager.', 'raffle-for-woocommerce' ) );

		foreach ( $email_filters as $filter ) {
			remove_filter( $filter, '__return_false', 99 );
		}

		if ( function_exists( 'rfwc_log_activity' ) ) {
			rfwc_log_activity(
				$product->get_id(),
				'free_entry_recorded',
				array(
					'order_id' => $order->get_id(),
					'entrant'  => trim( $args['first_name'] . ' ' . $args['last_name'] ),
					'quantity' => $quantity,
					'source'   => sanitize_key( $args['source'] ),
				)
			);
		}

		/**
		 * Fires after a free entry is recorded.
		 *
		 * @since 1.1.17
		 *
		 * @param int   $order_id Order ID.
		 * @param array $args     Entry data.
		 */
		do_action( 'rfwc_free_entry_recorded', $order->get_id(), $args );

		return $order->get_id();
	}

	/**
	 * Whether a raffle can still take free entries.
	 *
	 * An ended raffle that has not been drawn yet still can, so entries
	 * posted before the closing date can be added before the draw.
	 *
	 * @since 1.1.17
	 *
	 * @param RFWC_Product_Raffle $product Raffle product.
	 * @return bool
	 */
	public static function is_open( $product ) {
		$status = $product->get_meta( '_rfwc_status', true );
		if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return false;
		}

		return $product->has_started();
	}

	/**
	 * Get raffles that can take free entries.
	 *
	 * @since 1.1.17
	 *
	 * @return array Raffle ID => label.
	 */
	public static function get_open_raffles() {
		$products = wc_get_products(
			array(
				'type'   => 'raffle',
				'status' => array( 'publish', 'private' ),
				'limit'  => 200,
				'return' => 'objects',
			)
		);

		$raffles = array();
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof RFWC_Product_Raffle || ! self::is_open( $product ) ) {
				continue;
			}

			$label = $product->get_name();
			if ( $product->has_ended() ) {
				/* translators: %s: raffle name */
				$label = sprintf( __( '%s (ended, not drawn yet)', 'raffle-for-woocommerce' ), $label );
			}

			$raffles[ $product->get_id() ] = $label;
		}

		return $raffles;
	}

	/**
	 * Get the skill question a free entrant has to answer, if any.
	 *
	 * @since 1.1.17
	 *
	 * @param int $raffle_id Raffle product ID.
	 * @return string Question text, or an empty string when there is none.
	 */
	public static function get_skill_question_text( $raffle_id ) {
		$product = wc_get_product( $raffle_id );
		if ( ! $product instanceof RFWC_Product_Raffle || ! $product->is_skill_question_enabled() ) {
			return '';
		}

		$data = $product->get_skill_question_data();

		return ( is_array( $data ) && ! empty( $data['question'] ) ) ? (string) $data['question'] : '';
	}

	/**
	 * Render the result notice after a redirect.
	 *
	 * @since 1.1.17
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags.
		$order_id = isset( $_GET['rfwc_fe_order'] ) ? absint( $_GET['rfwc_fe_order'] ) : 0;
		$error    = isset( $_GET['rfwc_fe_error'] ) ? sanitize_key( wp_unslash( $_GET['rfwc_fe_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $order_id ) {
			$order   = wc_get_order( $order_id );
			$numbers = array();
			foreach ( RFWC_Ticket::get_by_order( $order_id ) as $ticket ) {
				$numbers[] = $ticket->get_ticket_number();
			}

			$message = $numbers
				/* translators: %s: comma separated ticket numbers */
				? sprintf( __( 'Free entry recorded. Ticket numbers: %s.', 'raffle-for-woocommerce' ), implode( ', ', $numbers ) )
				: __( 'Free entry recorded, but no tickets were issued. Check the order notes.', 'raffle-for-woocommerce' );

			if ( $order ) {
				$message .= ' <a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'View order', 'raffle-for-woocommerce' ) . '</a>';
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				$numbers ? 'success' : 'warning',
				wp_kses_post( $message )
			);
			return;
		}

		if ( '' === $error ) {
			return;
		}

		$messages = array(
			'invalid-raffle'     => __( 'Select a valid raffle.', 'raffle-for-woocommerce' ),
			'raffle-closed'      => __( 'This raffle is no longer taking entries.', 'raffle-for-woocommerce' ),
			'missing-name'       => __( 'First name is required.', 'raffle-for-woocommerce' ),
			'invalid-email'      => __( 'A valid email address is required.', 'raffle-for-woocommerce' ),
			'answer-not-checked' => __( 'Confirm the entrant answered the skill question correctly.', 'raffle-for-woocommerce' ),
			'per-customer-limit' => __( 'The number of entries is over the per-customer limit for this raffle.', 'raffle-for-woocommerce' ),
			'sold-out'           => __( 'This raffle is sold out.', 'raffle-for-woocommerce' ),
			'not-enough-tickets' => __( 'Not enough tickets are left on this raffle.', 'raffle-for-woocommerce' ),
			'order-failed'       => __( 'The order could not be created. Please try again.', 'raffle-for-woocommerce' ),
		);

		if ( isset( $messages[ $error ] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $messages[ $error ] ) );
		}
	}
}
