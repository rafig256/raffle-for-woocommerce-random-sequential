<?php
/**
 * Admin Mass Refund Summary Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 *
 * @var RFWC_Product_Raffle|null              $raffle             Raffle product.
 * @var array                                 $summary            Refund summary data.
 * @var string                                $email_heading      Email heading.
 * @var string                                $additional_content Additional content.
 * @var bool                                  $sent_to_admin      Is sent to admin.
 * @var bool                                  $plain_text         Is plain text.
 * @var RFWC_Email_Admin_Mass_Refund_Summary  $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

$is_preview      = empty( $raffle ) || empty( $summary );
$raffle_name     = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$orders_refunded = $is_preview ? 5 : absint( $summary['orders_refunded'] );
$orders_failed   = $is_preview ? 1 : absint( $summary['orders_failed'] );
$total_amount    = $is_preview ? 250 : (float) $summary['total_amount'];
$failures        = $is_preview ? array() : ( isset( $summary['failures'] ) ? $summary['failures'] : array() );

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
?>

<p>
	<?php
	printf(
		/* translators: %s: raffle name */
		esc_html__( 'Mass refund has been completed for the raffle: %s', 'raffle-for-woocommerce' ),
		'<strong>' . esc_html( $raffle_name ) . '</strong>'
	);
	?>
</p>

<h2 style="margin-top: 30px;"><?php esc_html_e( 'Refund Summary', 'raffle-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<tbody>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8; width: 40%;">
				<?php esc_html_e( 'Orders Refunded', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo esc_html( $orders_refunded ); ?>
			</td>
		</tr>
		<?php if ( $orders_failed > 0 ) : ?>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Orders Failed', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; color: #a00;">
				<?php echo esc_html( $orders_failed ); ?>
			</td>
		</tr>
		<?php endif; ?>
		<tr>
			<th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Total Amount Refunded', 'raffle-for-woocommerce' ); ?>
			</th>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo wp_kses_post( wc_price( $total_amount ) ); ?>
			</td>
		</tr>
	</tbody>
</table>

<?php if ( ! empty( $failures ) ) : ?>
<h2><?php esc_html_e( 'Failed Refunds', 'raffle-for-woocommerce' ); ?></h2>
<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
	<thead>
		<tr>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Order', 'raffle-for-woocommerce' ); ?>
			</th>
			<th scope="col" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f8f8;">
				<?php esc_html_e( 'Error', 'raffle-for-woocommerce' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $failures as $failure ) : ?>
		<tr>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				#<?php echo esc_html( isset( $failure['order_id'] ) ? $failure['order_id'] : '' ); ?>
			</td>
			<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
				<?php echo esc_html( isset( $failure['message'] ) ? $failure['message'] : '' ); ?>
			</td>
		</tr>
		<?php endforeach; ?>
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
