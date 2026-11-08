<?php
/**
 * Admin order functionality.
 *
 * Handles displaying gift recipient information and ticket numbers in admin order details.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin order class.
 *
 * @since 1.0.0
 */
class RFWC_Admin_Order {

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
		// Add raffle info metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_raffle_metabox' ) );

		// Add custom column to orders list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_columns' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_columns' ) );

		// Populate custom column.
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_columns' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_columns_hpos' ), 10, 2 );
	}

	/**
	 * Add raffle info metabox.
	 *
	 * @since 1.0.0
	 */
	public function add_raffle_metabox() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'rfwc-raffle-info',
			'<span class="rfwc-metabox-title"><span class="dashicons dashicons-tickets-alt"></span>' . __( 'Raffle Information', 'raffle-for-woocommerce' ) . '</span>',
			array( $this, 'render_raffle_metabox' ),
			$screen,
			'normal',
			'high'
		);
	}

	/**
	 * Render raffle metabox content.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public function render_raffle_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'raffle-for-woocommerce' ) . '</p>';
			return;
		}

		$this->display_raffle_info( $order );
	}

	/**
	 * Display raffle information in order details.
	 *
	 * Shows gift info and per-ticket recipient assignments.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Now shows all per-ticket recipients with ticket numbers.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_raffle_info( $order ) {
		// Check if order has raffle products.
		$has_raffle = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && 'raffle' === $product->get_type() ) {
				$has_raffle = true;
				break;
			}
		}

		if ( ! $has_raffle ) {
			echo '<p class="rfwc-no-raffle">' . esc_html__( 'This order does not contain raffle products.', 'raffle-for-woocommerce' ) . '</p>';
			return;
		}

		$is_gift = 'yes' === $order->get_meta( '_rfwc_is_gift' );

		// Get tickets for this order.
		$tickets = class_exists( 'RFWC_Ticket' ) ? RFWC_Ticket::get_by_order( $order->get_id() ) : array();

		// Separate gift tickets from non-gift tickets.
		$gift_tickets   = array();
		$normal_tickets = array();

		foreach ( $tickets as $ticket ) {
			if ( $ticket->is_gift() ) {
				$gift_tickets[] = $ticket;
			} else {
				$normal_tickets[] = $ticket;
			}
		}

		// Fallback for when tickets haven't been generated yet.
		$gift_entries_from_meta = array();
		if ( $is_gift && empty( $gift_tickets ) ) {
			$gift_entries_from_meta = $this->get_gift_entries_from_order_items( $order );
		}
		?>
		<div class="rfwc-metabox-content">
			<?php if ( $is_gift ) : ?>
				<div class="rfwc-gift-badge">
					<span class="dashicons dashicons-heart"></span>
					<?php esc_html_e( 'Gift Purchase', 'raffle-for-woocommerce' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $gift_tickets ) ) : ?>
				<table class="rfwc-info-table rfwc-recipients-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ticket', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Recipient', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Email', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Message', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gift_tickets as $ticket ) : ?>
							<tr>
								<td><code class="rfwc-ticket-code"><?php echo esc_html( $ticket->get_formatted_ticket_number() ); ?></code></td>
								<td><?php echo esc_html( $ticket->get_holder_name() ); ?></td>
								<td><a href="mailto:<?php echo esc_attr( $ticket->get_recipient_email() ); ?>"><?php echo esc_html( $ticket->get_recipient_email() ); ?></a></td>
								<td><?php echo ! empty( $ticket->get_gift_message() ) ? '<em>' . esc_html( $ticket->get_gift_message() ) . '</em>' : '-'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( ! empty( $gift_entries_from_meta ) ) : ?>
				<p class="description"><?php esc_html_e( 'Tickets not yet generated. Recipient data from checkout:', 'raffle-for-woocommerce' ); ?></p>
				<table class="rfwc-info-table rfwc-recipients-table">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Recipient', 'raffle-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Email', 'raffle-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gift_entries_from_meta as $index => $entry ) : ?>
							<tr>
								<td><?php echo absint( $index + 1 ); ?></td>
								<td><?php echo esc_html( $entry['name'] ); ?></td>
								<td><a href="mailto:<?php echo esc_attr( $entry['email'] ); ?>"><?php echo esc_html( $entry['email'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		<?php
		// Show non-gift tickets if any.
		$non_gift_numbers = array();
		foreach ( $normal_tickets as $ticket ) {
			$non_gift_numbers[] = $ticket->get_formatted_ticket_number();
		}
		if ( ! empty( $non_gift_numbers ) ) :
			// Use "Other Tickets" only when there are also gift tickets; otherwise "Raffle Tickets".
			$tickets_label = ( $is_gift && ! empty( $gift_tickets ) )
				? __( 'Other Tickets', 'raffle-for-woocommerce' )
				: __( 'Raffle Tickets', 'raffle-for-woocommerce' );
			?>
			<table class="rfwc-info-table" style="margin-top: 10px;">
				<tr>
					<th><?php echo esc_html( $tickets_label ); ?></th>
						<td>
							<?php foreach ( $non_gift_numbers as $num ) : ?>
								<code class="rfwc-ticket-code"><?php echo esc_html( $num ); ?></code>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<?php $this->display_skill_question_audit( $order ); ?>
		</div>
		<?php
	}

	/**
	 * Display skill question audit trail for raffle line items.
	 *
	 * Shows a compact compliance record proving the customer answered a skill
	 * question before purchasing. Only renders when audit data exists on the
	 * order item meta.
	 *
	 * @since 1.0.9
	 *
	 * @param WC_Order $order Order object.
	 */
	private function display_skill_question_audit( $order ) {
		$audit_items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$audit = $item->get_meta( '_rfwc_skill_question_audit', true );
			if ( ! empty( $audit ) && is_array( $audit ) ) {
				$audit['product_name'] = $item->get_name();
				$audit_items[]         = $audit;
			}
		}

		if ( empty( $audit_items ) ) {
			return;
		}
		?>
		<div class="rfwc-skill-audit" style="margin-top: 12px; padding: 10px 12px; background: #f0f7f0; border-left: 3px solid #46b450; border-radius: 3px;">
			<strong style="display: block; margin-bottom: 6px;">
				<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span>
				<?php esc_html_e( 'Skill Question Compliance', 'raffle-for-woocommerce' ); ?>
			</strong>
			<?php foreach ( $audit_items as $audit ) : ?>
				<table class="rfwc-info-table" style="margin-top: 4px;">
					<?php if ( count( $audit_items ) > 1 ) : ?>
						<tr>
							<th><?php esc_html_e( 'Product', 'raffle-for-woocommerce' ); ?></th>
							<td><?php echo esc_html( $audit['product_name'] ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Question', 'raffle-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $audit['question'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Type', 'raffle-for-woocommerce' ); ?></th>
						<td>
							<?php
							echo 'multiple_choice' === $audit['type']
								? esc_html__( 'Multiple choice', 'raffle-for-woocommerce' )
								: esc_html__( 'Text answer', 'raffle-for-woocommerce' );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Result', 'raffle-for-woocommerce' ); ?></th>
						<td><span style="color: #46b450; font-weight: 600;"><?php esc_html_e( 'Passed', 'raffle-for-woocommerce' ); ?> &#10003;</span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Answered', 'raffle-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $audit['timestamp'] ); ?></td>
					</tr>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get gift entries from order line item meta (before tickets are generated).
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Order $order Order object.
	 * @return array Array of gift entry arrays with name and email keys.
	 */
	private function get_gift_entries_from_order_items( $order ) {
		$entries = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			$item_recipients = $item->get_meta( '_rfwc_gift_recipients', true );

			if ( is_array( $item_recipients ) && ! empty( $item_recipients ) ) {
				foreach ( $item_recipients as $recipient ) {
					$first     = isset( $recipient['first_name'] ) ? $recipient['first_name'] : '';
					$last      = isset( $recipient['last_name'] ) ? $recipient['last_name'] : '';
					$entries[] = array(
						'name'  => trim( $first . ' ' . $last ),
						'email' => isset( $recipient['email'] ) ? $recipient['email'] : '',
					);
				}
			}
		}

		// Fallback: order-level single recipient.
		if ( empty( $entries ) ) {
			$name  = $order->get_meta( '_rfwc_recipient_name' );
			$email = $order->get_meta( '_rfwc_recipient_email' );
			if ( ! empty( $name ) || ! empty( $email ) ) {
				$entries[] = array(
					'name'  => $name,
					'email' => $email,
				);
			}
		}

		return $entries;
	}

	/**
	 * Add custom columns to orders list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_order_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Add gift column after order status.
			if ( 'order_status' === $key ) {
				$new_columns['rfwc_gift'] = __( 'Gift', 'raffle-for-woocommerce' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom order columns (legacy).
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_order_columns( $column, $post_id ) {
		if ( 'rfwc_gift' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		$this->render_gift_column( $order );
	}

	/**
	 * Render custom order columns (HPOS).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $column Column name.
	 * @param WC_Order $order  Order object.
	 */
	public function render_order_columns_hpos( $column, $order ) {
		if ( 'rfwc_gift' !== $column ) {
			return;
		}

		$this->render_gift_column( $order );
	}

	/**
	 * Render gift column content.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order|false $order Order object.
	 */
	private function render_gift_column( $order ) {
		if ( ! $order ) {
			echo '-';
			return;
		}

		$is_gift = $order->get_meta( '_rfwc_is_gift' );

		if ( 'yes' !== $is_gift ) {
			echo '-';
			return;
		}

		// Try to get unique recipient count from tickets.
		$tickets    = class_exists( 'RFWC_Ticket' ) ? RFWC_Ticket::get_by_order( $order->get_id() ) : array();
		$recipients = array();

		foreach ( $tickets as $ticket ) {
			if ( $ticket->is_gift() ) {
				$email = strtolower( trim( $ticket->get_recipient_email() ) );
				if ( ! isset( $recipients[ $email ] ) ) {
					$recipients[ $email ] = $ticket->get_holder_name();
				}
			}
		}

		$count = count( $recipients );

		if ( $count > 1 ) {
			printf(
				'<span class="dashicons dashicons-heart rfwc-gift-icon" title="%s"></span> %s',
				esc_attr__( 'Gift Purchase', 'raffle-for-woocommerce' ),
				sprintf(
					/* translators: %d: number of recipients */
					esc_html__( '%d recipients', 'raffle-for-woocommerce' ),
					absint( $count )
				)
			);
		} elseif ( 1 === $count ) {
			printf(
				'<span class="dashicons dashicons-heart rfwc-gift-icon" title="%s"></span> %s',
				esc_attr__( 'Gift Purchase', 'raffle-for-woocommerce' ),
				esc_html( reset( $recipients ) )
			);
		} else {
			// Fallback to order-level data.
			$recipient_name = $order->get_meta( '_rfwc_recipient_name' );
			printf(
				'<span class="dashicons dashicons-heart rfwc-gift-icon" title="%s"></span> %s',
				esc_attr__( 'Gift Purchase', 'raffle-for-woocommerce' ),
				esc_html( $recipient_name ? $recipient_name : __( 'Gift', 'raffle-for-woocommerce' ) )
			);
		}
	}
}

