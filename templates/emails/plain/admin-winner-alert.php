<?php
/**
 * Admin Winner Alert Email (Plain Text).
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

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: raffle name */
printf( esc_html__( 'A winner has been selected for the raffle: %s', 'raffle-for-woocommerce' ), esc_html( $raffle ? $raffle->get_name() : __( 'Unknown Raffle', 'raffle-for-woocommerce' ) ) );
echo "\n\n";

echo esc_html__( 'Winner Details', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Winner Name', 'raffle-for-woocommerce' ) . ': ' . esc_html( $ticket ? $ticket->get_holder_name() : '' ) . "\n";
echo esc_html__( 'Winner Email', 'raffle-for-woocommerce' ) . ': ' . esc_html( $ticket ? $ticket->get_holder_email() : '' ) . "\n";
echo esc_html__( 'Ticket Number', 'raffle-for-woocommerce' ) . ': ' . esc_html( $ticket ? $ticket->get_formatted_ticket_number() : '' ) . "\n";
echo esc_html__( 'Raffle', 'raffle-for-woocommerce' ) . ': ' . esc_html( $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ) ) . "\n\n";

if ( $raffle ) {
	echo esc_html__( 'Manage Winners', 'raffle-for-woocommerce' ) . ': ' . esc_url( admin_url( 'post.php?post=' . absint( $raffle->get_id() ) . '&action=edit' ) ) . "\n\n";
}

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}
