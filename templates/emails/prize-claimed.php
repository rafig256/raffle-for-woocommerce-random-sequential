<?php
/**
 * Prize Claimed Confirmation Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 *
 * @var RFWC_Ticket              $ticket             Winning ticket object.
 * @var RFWC_Product_Raffle      $raffle             Raffle product.
 * @var string                   $email_heading      Email heading.
 * @var string                   $additional_content Additional content.
 * @var bool                     $sent_to_admin      Is sent to admin.
 * @var bool                     $plain_text         Is plain text.
 * @var RFWC_Email_Prize_Claimed $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

// Handle preview mode with sample data.
$is_preview    = empty( $ticket ) || empty( $raffle );
$winner_name   = $is_preview ? __( 'John Doe', 'raffle-for-woocommerce' ) : $ticket->get_holder_name();
$ticket_number = $is_preview ? '0001' : $ticket->get_formatted_ticket_number();
$raffle_name   = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
?>

<p>
	<?php
	printf(
		/* translators: %s: Winner name */
		esc_html__( 'Dear %s,', 'raffle-for-woocommerce' ),
		esc_html( $winner_name )
	);
	?>
</p>

<div style="text-align: center; padding: 30px 20px; background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%); border-radius: 8px; margin: 20px 0;">
	<p style="font-size: 48px; margin: 0 0 10px;">&#x2705;</p>
	<h2 style="margin: 0 0 15px; color: #fff; font-size: 28px;">
		<?php esc_html_e( 'Prize Claimed!', 'raffle-for-woocommerce' ); ?>
	</h2>
	<p style="margin: 0; color: #dcfce7; font-size: 18px;">
		<?php
		printf(
			/* translators: %s: raffle name */
			esc_html__( 'Your prize from %s has been successfully claimed.', 'raffle-for-woocommerce' ),
			'<strong style="color: #fff;">' . esc_html( $raffle_name ) . '</strong>'
		);
		?>
	</p>
</div>

<h2 style="margin-top: 30px;"><?php esc_html_e( 'Claim Details', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<tbody>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8; width: 30%;">
				<?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<strong style="font-family: monospace; font-size: 18px; color: #7f54b3;">
					<?php echo esc_html( $ticket_number ); ?>
				</strong>
			</td>
		</tr>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo esc_html( $raffle_name ); ?>
			</td>
		</tr>
		<?php if ( ! $is_preview && method_exists( $raffle, 'get_prize_name' ) && $raffle->get_prize_name() ) : ?>
			<tr>
				<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
					<?php esc_html_e( 'Prize', 'raffle-for-woocommerce' ); ?>
				</th>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong><?php echo esc_html( $raffle->get_prize_name() ); ?></strong>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Winner', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo esc_html( $winner_name ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Status', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<span style="display: inline-block; padding: 4px 12px; background: #dcfce7; color: #166534; border-radius: 999px; font-size: 13px; font-weight: 600;">
					<?php esc_html_e( 'Claimed', 'raffle-for-woocommerce' ); ?>
				</span>
			</td>
		</tr>
	</tbody>
</table>

<div style="background-color: #f0fdf4; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #16a34a;">
	<p style="margin: 0; color: #555;">
		<?php esc_html_e( 'This email confirms that your prize has been marked as claimed. If you have any questions about your prize or the fulfillment process, please don\'t hesitate to contact us.', 'raffle-for-woocommerce' ); ?>
	</p>
</div>

<?php
/**
 * Show additional content.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer.
 */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
