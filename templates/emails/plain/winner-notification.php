<?php
/**
 * Winner Notification Email (Plain Text).
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

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

printf(
	/* translators: %s: Winner name */
	esc_html__( 'Dear %s,', 'raffle-for-woocommerce' ),
	esc_html( $winner_name )
);
echo "\n\n";

echo "**************************************************\n";
echo '              🎉 ' . esc_html__( 'CONGRATULATIONS!', 'raffle-for-woocommerce' ) . " 🎉\n";
echo "**************************************************\n\n";

printf(
	/* translators: %s: raffle name */
	esc_html__( 'You are the winner of %s!', 'raffle-for-woocommerce' ),
	esc_html( $raffle_name )
);
echo "\n\n";

echo "----------\n";
echo esc_html__( 'WINNING DETAILS', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Winning Ticket:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $ticket_number ) . "\n";
echo esc_html__( 'Raffle:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle_name ) . "\n";

$display_prize_name  = $is_preview ? '' : ( isset( $email->prize_name ) ? $email->prize_name : ( method_exists( $raffle, 'get_prize_name' ) ? $raffle->get_prize_name() : '' ) );
$display_prize_value = $is_preview ? 0 : ( isset( $email->prize_value ) ? $email->prize_value : ( method_exists( $raffle, 'get_prize_value' ) ? $raffle->get_prize_value() : 0 ) );

if ( ! empty( $display_prize_name ) ) {
	echo esc_html__( 'Prize:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $display_prize_name ) . "\n";
}

if ( ! empty( $display_prize_value ) ) {
	echo esc_html__( 'Prize Value:', 'raffle-for-woocommerce' ) . ' ' . esc_html( wp_strip_all_tags( wc_price( $display_prize_value ) ) ) . "\n";
}

echo esc_html__( 'Winner:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $winner_name ) . "\n";

echo "\n----------\n";
echo esc_html__( 'WHAT HAPPENS NEXT?', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Our team will contact you shortly at this email address with instructions on how to claim your prize. Please ensure you respond within the timeframe provided.', 'raffle-for-woocommerce' );
echo "\n\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
