<?php
/**
 * Printable Ticket Generator Class.
 *
 * Generates printable HTML tickets for raffle entries.
 * Uses browser print functionality for PDF output - no external libraries required.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates printable HTML tickets with QR codes.
 *
 * @since 1.0.0
 */
class RFWC_PDF_Ticket {

	/**
	 * Ticket object.
	 *
	 * @var RFWC_Ticket
	 */
	private $ticket;

	/**
	 * Raffle product.
	 *
	 * @var RFWC_Product_Raffle
	 */
	private $raffle;

	/**
	 * Return URL for close button.
	 *
	 * @var string
	 */
	private $return_url = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'handle_download_request' ) );
	}

	/**
	 * Handle ticket download/print request.
	 *
	 * @since 1.0.0
	 */
	public function handle_download_request() {
		if ( ! isset( $_GET['rfwc_download_ticket'] ) || ! isset( $_GET['ticket_id'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rfwc_download_ticket' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'raffle-for-woocommerce' ) );
		}

		$ticket_id = absint( $_GET['ticket_id'] );
		$ticket    = RFWC_Ticket::get_by_id( $ticket_id );

		if ( ! $ticket ) {
			wp_die( esc_html__( 'Ticket not found.', 'raffle-for-woocommerce' ) );
		}

		// Verify user has access to this ticket.
		if ( ! $this->user_can_access_ticket( $ticket ) ) {
			wp_die( esc_html__( 'You do not have permission to download this ticket.', 'raffle-for-woocommerce' ) );
		}

		// Store return URL for close button.
		$this->return_url = $this->get_return_url();

		$this->output_html( $ticket );
		exit;
	}

	/**
	 * Get the return URL for the close button.
	 *
	 * @since 1.0.0
	 *
	 * @return string Return URL.
	 */
	private function get_return_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading referer.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// Default to My Tickets page.
		$my_tickets_url = wc_get_account_endpoint_url( 'my-raffle-tickets' );

		// Use referer if it's from the same site.
		if ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $referer;
		}

		return $my_tickets_url;
	}

	/**
	 * Check if current user can access the ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @return bool True if user can access.
	 */
	private function user_can_access_ticket( $ticket ) {
		// Admin can access all tickets.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$current_user = wp_get_current_user();
		if ( ! $current_user->ID ) {
			return false;
		}

		// Check if user is the purchaser or recipient.
		$user_email = $current_user->user_email;
		return ( $ticket->get_purchaser_email() === $user_email || $ticket->get_recipient_email() === $user_email );
	}

	/**
	 * Output printable HTML ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 */
	public function output_html( $ticket ) {
		$this->ticket = $ticket;
		$this->raffle = wc_get_product( $ticket->get_raffle_id() );

		if ( ! $this->raffle ) {
			wp_die( esc_html__( 'Raffle not found.', 'raffle-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress header warnings.
		@header( 'Content-Type: text/html; charset=utf-8' );

		$this->render_ticket( true );
		exit;
	}

	/**
	 * Render the ticket HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $full_page Include full HTML page structure.
	 */
	private function render_ticket( $full_page = false ) {
		$ticket        = $this->ticket;
		$raffle        = $this->raffle;
		$site_name     = get_bloginfo( 'name' );
		$site_logo     = $this->get_site_logo();
		$ticket_number = $ticket->get_formatted_ticket_number();
		$holder_name   = $ticket->get_holder_name();
		$raffle_name   = $raffle->get_name();
		$draw_date     = $raffle->get_end_timestamp();
		$qr_code_url   = RFWC_QR_Code::get_ticket_qr_url( $ticket, 150 );
		$order         = wc_get_order( $ticket->get_order_id() );

		// Get prize info.
		$prize_info = $this->get_prize_display();

		if ( $full_page ) {
			?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<?php /* translators: %s: ticket number */ ?>
	<title><?php echo esc_html( sprintf( __( 'Raffle Ticket - %s', 'raffle-for-woocommerce' ), $ticket_number ) ); ?></title>
			<?php
			// Output CSS using wp_add_inline_style approach for the print page.
			// The CSS is plugin-controlled with only a validated hex color variable.
			// Using wp_strip_all_tags() is appropriate for CSS as esc_html() would break the CSS.
			?>
	<style id="rfwc-ticket-styles">
			<?php echo wp_strip_all_tags( $this->get_ticket_css() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content is sanitized by wp_strip_all_tags and color is validated. ?>
	</style>
</head>
<body>
	<div class="rfwc-ticket-actions no-print">
		<button onclick="window.print();" class="rfwc-btn-print"><?php esc_html_e( 'Print Ticket', 'raffle-for-woocommerce' ); ?></button>
		<a href="<?php echo esc_url( $this->return_url ); ?>" class="rfwc-btn-close"><?php esc_html_e( 'Back to Tickets', 'raffle-for-woocommerce' ); ?></a>
	</div>
			<?php
		}
		?>
	<div class="rfwc-ticket">
		<div class="rfwc-ticket-header">
			<?php if ( $site_logo ) : ?>
			<img src="<?php echo esc_url( $site_logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" class="rfwc-ticket-logo">
			<?php else : ?>
			<div class="rfwc-ticket-site-name"><?php echo esc_html( $site_name ); ?></div>
			<?php endif; ?>
			<div class="rfwc-ticket-badge"><?php echo esc_html( get_option( 'rfwc_ticket_badge_text', __( 'RAFFLE TICKET', 'raffle-for-woocommerce' ) ) ); ?></div>
		</div>

		<div class="rfwc-ticket-body">
			<div class="rfwc-ticket-main">
				<h1 class="rfwc-ticket-raffle-name"><?php echo esc_html( $raffle_name ); ?></h1>

				<div class="rfwc-ticket-details">
					<div class="rfwc-ticket-detail rfwc-ticket-number-display">
						<span class="rfwc-ticket-label"><?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?></span>
						<span class="rfwc-ticket-value rfwc-ticket-number-large"><?php echo esc_html( $ticket_number ); ?></span>
					</div>

					<div class="rfwc-ticket-detail">
						<span class="rfwc-ticket-label"><?php esc_html_e( 'Ticket Holder', 'raffle-for-woocommerce' ); ?></span>
						<span class="rfwc-ticket-value"><?php echo esc_html( $holder_name ); ?></span>
					</div>

					<div class="rfwc-ticket-detail">
						<span class="rfwc-ticket-label"><?php esc_html_e( 'Draw Date', 'raffle-for-woocommerce' ); ?></span>
						<span class="rfwc-ticket-value">
							<?php
							if ( $draw_date ) {
								echo esc_html( wp_date( get_option( 'date_format' ), $draw_date ) );
							} else {
								esc_html_e( 'To Be Announced', 'raffle-for-woocommerce' );
							}
							?>
						</span>
					</div>

					<?php if ( $prize_info ) : ?>
					<div class="rfwc-ticket-detail">
						<span class="rfwc-ticket-label"><?php esc_html_e( 'Prize', 'raffle-for-woocommerce' ); ?></span>
						<span class="rfwc-ticket-value"><?php echo esc_html( $prize_info ); ?></span>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="rfwc-ticket-qr">
				<img src="<?php echo esc_url( $qr_code_url ); ?>" alt="<?php esc_attr_e( 'QR Code', 'raffle-for-woocommerce' ); ?>" class="rfwc-ticket-qr-image">
				<div class="rfwc-ticket-code"><?php echo esc_html( $ticket_number ); ?></div>
				<div class="rfwc-ticket-scan-text"><?php esc_html_e( 'Scan to verify', 'raffle-for-woocommerce' ); ?></div>
			</div>
		</div>

		<div class="rfwc-ticket-footer">
			<p><?php echo esc_html( get_option( 'rfwc_ticket_footer_text', __( 'Keep this ticket safe. You will need it to claim your prize if you win.', 'raffle-for-woocommerce' ) ) ); ?></p>
			<?php if ( $order ) : ?>
				<?php /* translators: %s: order number */ ?>
			<p class="rfwc-ticket-order"><?php printf( esc_html__( 'Order #%s', 'raffle-for-woocommerce' ), esc_html( $order->get_order_number() ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>
		<?php
		if ( $full_page ) {
			?>
</body>
</html>
			<?php
		}
	}

	/**
	 * Get prize display text.
	 *
	 * Only shows prize information if this ticket is a winning ticket.
	 * This prevents showing potentially many prize tiers on non-winning tickets
	 * and shows the specific prize the winner actually won.
	 *
	 * @since 1.0.0
	 *
	 * @return string Prize description, or empty if not a winner.
	 */
	private function get_prize_display() {
		// Check if this ticket is a winning ticket.
		$winner = RFWC_Winner::get_by_ticket( $this->ticket->get_id() );

		// If not a winner, don't show any prize info.
		if ( ! $winner ) {
			return '';
		}

		// Get the prize tier this ticket won.
		$prize_tier = $winner->get_prize_tier();
		$prize_type = $this->raffle->get_prize_type();

		switch ( $prize_type ) {
			case 'product':
				$prize_product = $this->raffle->get_prize_product( $prize_tier );
				if ( $prize_product ) {
					$price = $prize_product->get_price();
					if ( $price > 0 ) {
						return sprintf(
							'%s (%s)',
							$prize_product->get_name(),
							$this->format_price( $price )
						);
					}
					return $prize_product->get_name();
				}
				break;

			case 'store_credit':
				$credit_amount = $this->raffle->get_prize_credit_amount( $prize_tier );
				if ( $credit_amount > 0 ) {
					return sprintf(
						/* translators: %s: credit amount */
						__( '%s Store Credit', 'raffle-for-woocommerce' ),
						$this->format_price( $credit_amount )
					);
				}
				break;

			default: // Custom prize.
				$prize_desc  = $this->raffle->get_prize_description( $prize_tier );
				$prize_value = $this->raffle->get_prize_value( $prize_tier );

				if ( $prize_desc && $prize_value > 0 ) {
					return sprintf(
						'%s (%s)',
						$prize_desc,
						$this->format_price( $prize_value )
					);
				}
				return $prize_desc;
		}

		return '';
	}

	/**
	 * Format price for display.
	 *
	 * @since 1.0.0
	 *
	 * @param float $amount The amount to format.
	 * @return string Formatted price string.
	 */
	private function format_price( $amount ) {
		$formatted = wp_strip_all_tags( wc_price( $amount ) );
		return html_entity_decode( $formatted, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Get ticket CSS styles.
	 *
	 * @since 1.0.0
	 *
	 * @return string CSS content.
	 */
	private function get_ticket_css() {
		$primary_color = get_option( 'rfwc_ticket_primary_color', '#7f54b3' );
		$primary_color = apply_filters( 'rfwc_ticket_primary_color', $primary_color );

		// Sanitize color to prevent any injection - must be a valid hex color.
		if ( ! preg_match( '/^#[a-fA-F0-9]{6}$/', $primary_color ) ) {
			$primary_color = '#7f54b3';
		}

		return '
* {
	box-sizing: border-box;
	margin: 0;
	padding: 0;
}

body {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	background: #f5f5f5;
	padding: 20px;
	color: #333;
}

.rfwc-ticket-actions {
	max-width: 700px;
	margin: 0 auto 20px;
	display: flex;
	gap: 10px;
}

.rfwc-ticket-actions button,
.rfwc-ticket-actions a {
	padding: 12px 24px;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
	transition: all 0.2s ease;
	text-decoration: none;
	display: inline-block;
}

.rfwc-btn-print {
	background: ' . $primary_color . ';
	color: #fff;
}

.rfwc-btn-print:hover {
	opacity: 0.9;
	transform: translateY(-1px);
}

.rfwc-btn-close {
	background: #e5e7eb;
	color: #374151;
}

.rfwc-btn-close:hover {
	background: #d1d5db;
}

.rfwc-ticket {
	max-width: 700px;
	margin: 0 auto;
	background: #fff;
	border-radius: 16px;
	box-shadow: 0 4px 24px rgba(0,0,0,0.1);
	overflow: hidden;
}

.rfwc-ticket-header {
	background: ' . $primary_color . ';
	color: #fff;
	padding: 24px 30px;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.rfwc-ticket-logo {
	max-height: 50px;
	max-width: 200px;
}

.rfwc-ticket-site-name {
	font-size: 22px;
	font-weight: 700;
	letter-spacing: 0.5px;
}

.rfwc-ticket-badge {
	background: rgba(255,255,255,0.2);
	padding: 8px 16px;
	border-radius: 20px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 1.5px;
}

.rfwc-ticket-body {
	display: flex;
	padding: 30px;
	gap: 30px;
}

.rfwc-ticket-main {
	flex: 1;
}

.rfwc-ticket-raffle-name {
	font-size: 26px;
	font-weight: 700;
	color: #111;
	margin-bottom: 24px;
	line-height: 1.3;
}

.rfwc-ticket-details {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.rfwc-ticket-detail {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rfwc-ticket-label {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	color: #6b7280;
	letter-spacing: 0.5px;
}

.rfwc-ticket-value {
	font-size: 16px;
	color: #111;
}

.rfwc-ticket-number-large {
	font-size: 24px;
	font-weight: 700;
	font-family: "SF Mono", Monaco, "Courier New", monospace;
	color: ' . $primary_color . ';
}

.rfwc-ticket-qr {
	width: 180px;
	text-align: center;
	border-left: 2px dashed #e5e7eb;
	padding-left: 30px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
}

.rfwc-ticket-qr-image {
	width: 150px;
	height: 150px;
	border-radius: 8px;
}

.rfwc-ticket-code {
	margin-top: 12px;
	font-family: "SF Mono", Monaco, "Courier New", monospace;
	font-size: 14px;
	font-weight: 600;
	color: #111;
	background: #f3f4f6;
	padding: 8px 16px;
	border-radius: 6px;
}

.rfwc-ticket-scan-text {
	margin-top: 8px;
	font-size: 11px;
	color: #9ca3af;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.rfwc-ticket-footer {
	background: #f9fafb;
	padding: 20px 30px;
	text-align: center;
	border-top: 1px solid #e5e7eb;
}

.rfwc-ticket-footer p {
	font-size: 13px;
	color: #6b7280;
	margin: 4px 0;
}

.rfwc-ticket-order {
	font-weight: 600;
	color: #374151 !important;
}

@media print {
	body {
		background: #fff;
		padding: 0;
	}

	.no-print {
		display: none !important;
	}

	.rfwc-ticket {
		box-shadow: none;
		max-width: 100%;
		border-radius: 0;
	}

	.rfwc-ticket-header {
		-webkit-print-color-adjust: exact;
		print-color-adjust: exact;
	}
}

@media (max-width: 600px) {
	.rfwc-ticket-body {
		flex-direction: column;
	}

	.rfwc-ticket-qr {
		width: 100%;
		border-left: none;
		border-top: 2px dashed #e5e7eb;
		padding-left: 0;
		padding-top: 30px;
	}

	.rfwc-ticket-raffle-name {
		font-size: 20px;
	}

	.rfwc-ticket-actions {
		flex-direction: column;
	}
}';
	}

	/**
	 * Get site logo URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false Logo URL or false if not set.
	 */
	private function get_site_logo() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );

		if ( $custom_logo_id ) {
			$logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			if ( $logo_url ) {
				return $logo_url;
			}
		}

		return false;
	}

	/**
	 * Generate download URL for a ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @return string The download URL.
	 */
	public static function get_download_url( $ticket ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'rfwc_download_ticket' => 1,
					'ticket_id'            => $ticket->get_id(),
				),
				home_url()
			),
			'rfwc_download_ticket'
		);
	}

	/**
	 * Generate bulk print URL for multiple tickets.
	 *
	 * @since 1.0.0
	 *
	 * @param array $ticket_ids Array of ticket IDs.
	 * @return string The print URL.
	 */
	public static function get_bulk_print_url( $ticket_ids ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'rfwc_print_tickets' => 1,
					'ticket_ids'         => implode( ',', array_map( 'absint', $ticket_ids ) ),
				),
				admin_url( 'admin.php?page=rfwc-tickets' )
			),
			'rfwc_print_tickets'
		);
	}
}
