<?php
/**
 * Winner Notification Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Ticket         $ticket             Winning ticket object.
 * @var RFWC_Product_Raffle  $raffle             Raffle product.
 * @var string             $email_heading      Email heading.
 * @var string             $additional_content Additional content.
 * @var bool               $sent_to_admin      Is sent to admin.
 * @var bool               $plain_text         Is plain text.
 * @var RFWC_Email_Winner_Notification $email   Email object.
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

<div style="text-align: center; padding: 30px 20px; background: linear-gradient(135deg, #ffd700 0%, #ffed4a 100%); border-radius: 8px; margin: 20px 0;">
	<p style="font-size: 48px; margin: 0 0 10px;">🎉</p>
	<h2 style="margin: 0 0 15px; color: #333; font-size: 28px;">
		<?php esc_html_e( 'Congratulations!', 'raffle-for-woocommerce' ); ?>
	</h2>
	<p style="margin: 0; color: #555; font-size: 18px;">
		<?php
		printf(
			/* translators: %s: raffle name */
			esc_html__( 'You are the winner of %s!', 'raffle-for-woocommerce' ),
			'<strong>' . esc_html( $raffle_name ) . '</strong>'
		);
		?>
	</p>
</div>

<h2 style="margin-top: 30px;"><?php esc_html_e( 'Winning Details', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<tbody>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8; width: 30%;">
				<?php esc_html_e( 'Winning Ticket', 'raffle-for-woocommerce' ); ?>
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
		<?php
		$display_prize_name  = $is_preview ? '' : ( isset( $email->prize_name ) ? $email->prize_name : ( method_exists( $raffle, 'get_prize_name' ) ? $raffle->get_prize_name() : '' ) );
		$display_prize_value = $is_preview ? 0 : ( isset( $email->prize_value ) ? $email->prize_value : ( method_exists( $raffle, 'get_prize_value' ) ? $raffle->get_prize_value() : 0 ) );
		?>
		<?php if ( ! empty( $display_prize_name ) ) : ?>
			<tr>
				<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
					<?php esc_html_e( 'Prize', 'raffle-for-woocommerce' ); ?>
				</th>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<strong><?php echo esc_html( $display_prize_name ); ?></strong>
				</td>
			</tr>
		<?php endif; ?>
		<?php if ( ! empty( $display_prize_value ) ) : ?>
			<tr>
				<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
					<?php esc_html_e( 'Prize Value', 'raffle-for-woocommerce' ); ?>
				</th>
				<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
					<?php echo wp_kses_post( wc_price( $display_prize_value ) ); ?>
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
	</tbody>
</table>

<div style="background-color: #f0f8ff; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #2196f3;">
	<h3 style="margin: 0 0 10px; color: #333;">
		<?php esc_html_e( 'What happens next?', 'raffle-for-woocommerce' ); ?>
	</h3>
	<p style="margin: 0; color: #555;">
		<?php esc_html_e( 'Our team will contact you shortly at this email address with instructions on how to claim your prize. Please ensure you respond within the timeframe provided.', 'raffle-for-woocommerce' ); ?>
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
