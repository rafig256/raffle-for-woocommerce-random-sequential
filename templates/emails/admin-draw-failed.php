<?php
/**
 * Admin Draw Failed Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 *
 * @var WC_Product|null              $raffle             Raffle product.
 * @var string                       $error_message      Error message from the failed draw.
 * @var string                       $email_heading      Email heading.
 * @var string                       $additional_content Additional content.
 * @var bool                         $sent_to_admin      Whether email is sent to admin.
 * @var bool                         $plain_text         Whether this is plain text.
 * @var RFWC_Email_Admin_Draw_Failed $email              Email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables from wc_get_template().

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook. ?>

<p><?php esc_html_e( 'An automatic raffle draw has failed. Details are below.', 'raffle-for-woocommerce' ); ?></p>

<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<tbody>
			<tr>
				<th class="td" scope="row" style="text-align: left; width: 40%;"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ) ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Reason', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left; color: #e2401c;"><?php echo esc_html( $error_message ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Time', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( wp_date( 'F j, Y g:i a' ) ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<?php if ( $raffle ) : ?>
<p>
	<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $raffle->get_id() ) . '&action=edit' ) ); ?>" style="background-color: #e2401c; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;">
		<?php esc_html_e( 'Review Raffle Settings', 'raffle-for-woocommerce' ); ?>
	</a>
</p>
<?php endif; ?>

<?php if ( $additional_content ) : ?>
	<div style="margin-top: 20px;"><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></div>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer.
 */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
