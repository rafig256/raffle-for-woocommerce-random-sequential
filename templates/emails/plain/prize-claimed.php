<?php
/**
 * Prize Claimed Confirmation Email (Plain Text).
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 *
 * @var RFWC_Ticket              $ticket             Winning ticket object.
 * @var RFWC_Product_Raffle      $raffle             Raffle product.
 * @var string                   $email_heading      Email heading.
 * @var string                   $additional_content Additional content.
 * @var bool                     $sent_to_admin      Is sent to admin.
 * @var bool                     $plain_text         Is plain text.
 * @var RFWC_Email_Prize_Claimed $email              Email object.
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
echo '              ' . esc_html__( 'PRIZE CLAIMED!', 'raffle-for-woocommerce' ) . "\n";
echo "**************************************************\n\n";

printf(
	/* translators: %s: raffle name */
	esc_html__( 'Your prize from %s has been successfully claimed.', 'raffle-for-woocommerce' ),
	esc_html( $raffle_name )
);
echo "\n\n";

echo "----------\n";
echo esc_html__( 'CLAIM DETAILS', 'raffle-for-woocommerce' ) . "\n";
echo "----------\n\n";

echo esc_html__( 'Ticket Number:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $ticket_number ) . "\n";
echo esc_html__( 'Raffle:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle_name ) . "\n";

if ( ! $is_preview && method_exists( $raffle, 'get_prize_name' ) && $raffle->get_prize_name() ) {
	echo esc_html__( 'Prize:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $raffle->get_prize_name() ) . "\n";
}

echo esc_html__( 'Winner:', 'raffle-for-woocommerce' ) . ' ' . esc_html( $winner_name ) . "\n";
echo esc_html__( 'Status:', 'raffle-for-woocommerce' ) . ' ' . esc_html__( 'Claimed', 'raffle-for-woocommerce' ) . "\n";

echo "\n----------\n\n";

echo esc_html__( 'This email confirms that your prize has been marked as claimed. If you have any questions about your prize or the fulfillment process, please don\'t hesitate to contact us.', 'raffle-for-woocommerce' );
echo "\n\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
