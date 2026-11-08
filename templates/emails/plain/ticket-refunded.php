<?php
/**
 * Ticket Refunded Email (Plain Text).
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

$is_preview   = empty( $tickets ) || empty( $raffle );
$raffle_name  = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$ticket_count = $is_preview ? 2 : count( $tickets );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__( 'Hello,', 'raffle-for-woocommerce' ) . "\n\n";

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
	esc_html( $raffle_name )
);
echo "\n\n";

if ( ! empty( $refund_reason ) ) {
	echo esc_html__( 'Reason:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $refund_reason ) . "\n\n";
}

echo "----------\n";
echo esc_html__( 'REFUNDED TICKETS', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

if ( $is_preview ) {
	echo '0001 - ' . esc_html__( 'Refunded', 'raffle-for-woocommerce' ) . "\n";
	echo '0002 - ' . esc_html__( 'Refunded', 'raffle-for-woocommerce' ) . "\n";
} else {
	foreach ( $tickets as $ticket ) {
		$ticket_number = '';
		if ( is_object( $ticket ) && method_exists( $ticket, 'get_formatted_ticket_number' ) ) {
			$ticket_number = $ticket->get_formatted_ticket_number();
		} elseif ( is_array( $ticket ) && isset( $ticket['ticket_number'] ) ) {
			$ticket_number = $ticket['ticket_number'];
		}
		echo esc_html( $ticket_number ) . ' - ' . esc_html__( 'Refunded', 'raffle-for-woocommerce' ) . "\n";
	}
}

echo "\n";

if ( ! $is_preview && $refund_amount > 0 ) {
	echo "----------\n";
	echo esc_html__( 'REFUND DETAILS', 'raffle-for-woocommerce' ) . "\n";
	echo "----------\n\n";

	echo esc_html__( 'Raffle:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle_name ) . "\n";
	echo esc_html__( 'Refund Amount:', 'raffle-for-woocommerce' ) . ' ' . esc_html( wp_strip_all_tags( wc_price( $refund_amount ) ) ) . "\n";
	echo "\n";
}

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
