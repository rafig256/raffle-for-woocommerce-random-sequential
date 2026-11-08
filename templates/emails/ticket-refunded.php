<?php
/**
 * Ticket Refunded Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 *
 * @var WC_Order|null              $order              Order object.
 * @var array                      $tickets            Array of ticket data arrays.
 * @var RFWC_Product_Raffle|null   $raffle             Raffle product.
 * @var float                      $refund_amount      Refund amount.
 * @var string                     $refund_reason      Refund reason.
 * @var string                     $email_heading      Email heading.
 * @var string                     $additional_content Additional content.
 * @var bool                       $sent_to_admin      Is sent to admin.
 * @var bool                       $plain_text         Is plain text.
 * @var RFWC_Email_Ticket_Refunded $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

$is_preview   = empty( $tickets ) || empty( $raffle );
$raffle_name  = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$ticket_count = $is_preview ? 2 : count( $tickets );

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
?>

<p>
	<?php esc_html_e( 'Hello,', 'raffle-for-woocommerce' ); ?>
</p>

<p>
	<?php
	printf(
		esc_html(
			/* translators: %1$d: number of tickets, %2$s: raffle name */
			_n(
				'We are writing to let you know that %1$d ticket for %2$s has been refunded.',
				'We are writing to let you know that %1$d tickets for %2$s have been refunded.',
				$ticket_count,
				'raffle-for-woocommerce'
			)
		),
		(int) $ticket_count,
		'<strong>' . esc_html( $raffle_name ) . '</strong>'
	);
	?>
</p>

<?php if ( ! empty( $refund_reason ) ) : ?>
<p>
	<strong><?php esc_html_e( 'Reason:', 'raffle-for-woocommerce' ); ?></strong>
	<?php echo esc_html( $refund_reason ); ?>
</p>
<?php endif; ?>

<h2 style="margin-top: 30px;"><?php esc_html_e( 'Refunded Tickets', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<thead>
		<tr>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?>
			</th>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php if ( $is_preview ) : ?>
			<tr>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong style="font-family: monospace; font-size: 16px;">0001</strong>
				</td>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<span style="color: #a00;"><?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?></span>
				</td>
			</tr>
			<tr>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong style="font-family: monospace; font-size: 16px;">0002</strong>
				</td>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<span style="color: #a00;"><?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?></span>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $tickets as $ticket ) : ?>
				<?php
				$ticket_number = '';
				if ( is_object( $ticket ) && method_exists( $ticket, 'get_formatted_ticket_number' ) ) {
					$ticket_number = $ticket->get_formatted_ticket_number();
				} elseif ( is_array( $ticket ) && isset( $ticket['ticket_number'] ) ) {
					$ticket_number = $ticket['ticket_number'];
				}
				?>
				<tr>
					<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
						<strong style="font-family: monospace; font-size: 16px;">
							<?php echo esc_html( $ticket_number ); ?>
						</strong>
					</td>
					<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
						<span style="color: #a00;">
							<?php esc_html_e( 'Refunded', 'raffle-for-woocommerce' ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( ! $is_preview && $refund_amount > 0 ) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<tbody>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8; width: 30%;">
				<?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo esc_html( $raffle_name ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Refund Amount', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo wp_kses_post( wc_price( $refund_amount ) ); ?>
			</td>
		</tr>
	</tbody>
</table>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer.
 */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
