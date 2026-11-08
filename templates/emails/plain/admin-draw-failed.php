<?php
/**
 * Admin Draw Failed Email (Plain Text).
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

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__( 'An automatic raffle draw has failed. Details are below.', 'raffle-for-woocommerce' ) . "\n\n";

echo esc_html__( 'Raffle', 'raffle-for-woocommerce' ) . ': ' . esc_html( $raffle ? $raffle->get_name() : __( 'Unknown', 'raffle-for-woocommerce' ) ) . "\n";
echo esc_html__( 'Reason', 'raffle-for-woocommerce' ) . ': ' . esc_html( $error_message ) . "\n";
echo esc_html__( 'Time', 'raffle-for-woocommerce' ) . ': ' . esc_html( wp_date( 'F j, Y g:i a' ) ) . "\n\n";

if ( $raffle ) {
	echo esc_html__( 'Review Raffle Settings', 'raffle-for-woocommerce' ) . ': ' . esc_url( admin_url( 'post.php?post=' . absint( $raffle->get_id() ) . '&action=edit' ) ) . "\n\n";
}

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}
