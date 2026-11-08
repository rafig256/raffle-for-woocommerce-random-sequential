<?php
/**
 * Admin Mass Refund Summary Email (Plain Text).
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

$is_preview      = empty( $raffle ) || empty( $summary );
$raffle_name     = $is_preview ? __( 'Sample Raffle', 'raffle-for-woocommerce' ) : $raffle->get_name();
$orders_refunded = $is_preview ? 5 : absint( $summary['orders_refunded'] );
$orders_failed   = $is_preview ? 1 : absint( $summary['orders_failed'] );
$total_amount    = $is_preview ? 250 : (float) $summary['total_amount'];
$failures        = $is_preview ? array() : ( isset( $summary['failures'] ) ? $summary['failures'] : array() );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

printf(
	/* translators: %s: raffle name */
	esc_html__( 'Mass refund has been completed for the raffle: %s', 'raffle-for-woocommerce' ),
	esc_html( $raffle_name )
);
echo "\n\n";

echo "----------\n";
echo esc_html__( 'REFUND SUMMARY', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Orders Refunded:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $orders_refunded ) . "\n";
if ( $orders_failed > 0 ) {
	echo esc_html__( 'Orders Failed:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $orders_failed ) . "\n";
}
echo esc_html__( 'Total Amount Refunded:', 'raffle-for-woocommerce' ) . ' ' . esc_html( wp_strip_all_tags( wc_price( $total_amount ) ) ) . "\n";

if ( ! empty( $failures ) ) {
	echo "\n----------\n";
	echo esc_html__( 'FAILED REFUNDS', 'raffle-for-woocommerce' ) . "\n";
	echo "----------\n\n";

	foreach ( $failures as $failure ) {
		$f_order   = isset( $failure['order_id'] ) ? $failure['order_id'] : '';
		$f_message = isset( $failure['message'] ) ? $failure['message'] : '';
		/* translators: 1: order ID, 2: error message */
		printf( esc_html__( 'Order #%1$s: %2$s', 'raffle-for-woocommerce' ), esc_html( $f_order ), esc_html( $f_message ) );
		echo "\n";
	}
}

echo "\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
