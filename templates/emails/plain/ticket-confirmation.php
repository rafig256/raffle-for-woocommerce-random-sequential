<?php
/**
 * Ticket Confirmation Email (Plain Text).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var WC_Order           $order              Order object.
 * @var array              $tickets            Array of RFWC_Ticket objects.
 * @var RFWC_Product_Raffle  $raffle             Raffle product.
 * @var string             $email_heading      Email heading.
 * @var string             $additional_content Additional content.
 * @var bool               $sent_to_admin      Is sent to admin.
 * @var bool               $plain_text         Is plain text.
 * @var RFWC_Email_Ticket_Confirmation $email   Email object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

// Handle preview mode with sample data.
$is_preview    = empty( $order ) || empty( $tickets ) || empty( $raffle );
$customer_name = $is_preview ? __( 'John', 'raffle-for-woocommerce' ) : $order->get_billing_first_name();
$raffle_name   = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$raffle_link   = $is_preview ? home_url() : get_permalink( $raffle->get_id() );
$ticket_count  = $is_preview ? 2 : count( $tickets );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

printf(
	/* translators: %s: Customer first name */
	esc_html__( 'Hi %s,', 'raffle-for-woocommerce' ),
	esc_html( $customer_name )
);
echo "\n\n";

printf(
	esc_html(
		/* translators: %1$s: raffle name, %2$d: number of tickets */
		_n(
			'Great news! Your %2$d ticket for %1$s has been confirmed.',
			'Great news! Your %2$d tickets for %1$s have been confirmed.',
			$ticket_count,
			'raffle-for-woocommerce'
		)
	),
	esc_html( $raffle_name ),
	(int) $ticket_count
);
echo "\n\n";

echo "----------\n";
echo esc_html__( 'YOUR TICKET NUMBERS', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

if ( $is_preview ) {
	echo '0001 - ' . esc_html__( 'Active', 'raffle-for-woocommerce' ) . "\n";
	echo '0002 - ' . esc_html__( 'Active', 'raffle-for-woocommerce' ) . "\n";
} else {
	foreach ( $tickets as $ticket ) {
		echo esc_html( $ticket->get_formatted_ticket_number() ) . ' - ' . esc_html__( 'Active', 'raffle-for-woocommerce' ) . "\n";
	}
}

echo "\n----------\n";
echo esc_html__( 'RAFFLE DETAILS', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Raffle:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle_name ) . "\n";
echo esc_html__( 'Link:', 'raffle-for-woocommerce' ) . ' ' . esc_url( $raffle_link ) . "\n";

if ( ! $is_preview && method_exists( $raffle, 'get_end_date' ) && $raffle->get_end_date() ) {
	echo esc_html__( 'Draw Date:', 'raffle-for-woocommerce' ) . ' ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $raffle->get_end_date() ) ) ) . "\n";
}

if ( ! $is_preview && method_exists( $raffle, 'get_prize_name' ) && $raffle->get_prize_name() ) {
	echo esc_html__( 'Prize:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle->get_prize_name() ) . "\n";
}

echo "\n";

printf(
	/* translators: %s: My Account tickets URL */
	esc_html__( 'View your tickets: %s', 'raffle-for-woocommerce' ),
	esc_url( wc_get_endpoint_url( 'my-raffle-tickets', '', wc_get_page_permalink( 'myaccount' ) ) )
);
echo "\n\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
