<?php
/**
 * Admin Winner Alert Email (HTML).
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 *
 * @var RFWC_Ticket|null              $ticket             Winning ticket.
 * @var RFWC_Product_Raffle|null      $raffle             Raffle product.
 * @var string                        $email_heading      Email heading.
 * @var string                        $additional_content Additional content.
 * @var bool                          $sent_to_admin      Whether email is sent to admin.
 * @var bool                          $plain_text         Whether this is plain text.
 * @var RFWC_Email_Admin_Winner_Alert $email              Email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables from wc_get_template().

/*
 * @hooked WC_Emails::email_header() Output the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook. ?>

<?php /* translators: %s: raffle name */ ?>
<p><?php printf( esc_html__( 'A winner has been selected for the raffle: %s', 'raffle-for-woocommerce' ), '<strong>' . esc_html( $raffle ? $raffle->get_name() : __( 'Unknown Raffle', 'raffle-for-woocommerce' ) ) . '</strong>' ); ?></p>

<h2 style="color: #7f54b3; display: block; font-family: &quot;Helvetica Neue&quot;, Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px; text-align: left;">
	<?php esc_html_e( 'Winner Details', 'raffle-for-woocommerce' ); ?>
</h2>

<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<tbody>
			<tr>
				<th class="td" scope="row" style="text-align: left; width: 40%;"><?php esc_html_e( 'Winner Name', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( $ticket ? $ticket->get_holder_name() : '' ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Winner Email', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( $ticket ? $ticket->get_holder_email() : '' ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><code><?php echo esc_html( $ticket ? $ticket->get_formatted_ticket_number() : '' ); ?></code></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align: left;"><?php esc_html_e( 'Raffle', 'raffle-for-woocommerce' ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ) ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<?php if ( $raffle ) : ?>
<p>
	<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $raffle->get_id() ) . '&action=edit' ) ); ?>" style="background-color: #7f54b3; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;">
		<?php esc_html_e( 'Manage Winners', 'raffle-for-woocommerce' ); ?>
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
