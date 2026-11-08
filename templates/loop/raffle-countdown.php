<?php
/**
 * Compact raffle countdown for shop/archive loops and shortcodes.
 *
 * Displays a slim inline countdown badge for raffle products.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 *
 * @var RFWC_Product_Raffle $product         The raffle product object.
 * @var int|null            $end_timestamp   The raffle end timestamp.
 * @var int|null            $start_timestamp The raffle start timestamp.
 * @var bool                $has_started     Whether the raffle has started.
 * @var bool                $has_ended       Whether the raffle has ended.
 * @var bool                $is_completed    Whether winners have been drawn.
 * @var string              $rfwc_status     Raffle status (completed, failed, cancelled, or empty).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.

$rfwc_status = isset( $rfwc_status ) ? $rfwc_status : '';

if ( 'cancelled' === $rfwc_status ) {
	?>
	<span class="rfwc-countdown-compact rfwc-countdown-compact--ended">
		<span class="rfwc-countdown-compact-label"><?php esc_html_e( 'Cancelled', 'raffle-for-woocommerce' ); ?></span>
	</span>
	<?php
	return;
}

if ( 'failed' === $rfwc_status ) {
	?>
	<span class="rfwc-countdown-compact rfwc-countdown-compact--ended">
		<span class="rfwc-countdown-compact-label"><?php esc_html_e( 'Failed', 'raffle-for-woocommerce' ); ?></span>
	</span>
	<?php
	return;
}

if ( $is_completed ) {
	?>
	<span class="rfwc-countdown-compact rfwc-countdown-compact--completed">
		<span class="rfwc-countdown-compact-icon" aria-hidden="true">&#x2713;</span>
		<span class="rfwc-countdown-compact-label"><?php esc_html_e( 'Winner Selected', 'raffle-for-woocommerce' ); ?></span>
	</span>
	<?php
	return;
}

if ( $has_ended ) {
	?>
	<span class="rfwc-countdown-compact rfwc-countdown-compact--ended">
		<span class="rfwc-countdown-compact-label"><?php esc_html_e( 'Ended', 'raffle-for-woocommerce' ); ?></span>
	</span>
	<?php
	return;
}

$countdown_timestamp = null;
$countdown_label     = '';

if ( ! $has_started && $start_timestamp ) {
	$countdown_timestamp = $start_timestamp;
	$countdown_label     = __( 'Starts in:', 'raffle-for-woocommerce' );
} elseif ( $has_started && $end_timestamp ) {
	$countdown_timestamp = $end_timestamp;
	$countdown_label     = __( 'Ends in:', 'raffle-for-woocommerce' );
}

if ( ! $countdown_timestamp ) {
	return;
}

// Determine urgency: if less than 3 days remaining, add urgency modifier.
$remaining   = $countdown_timestamp - time();
$urgency_cls = '';
if ( $remaining > 0 && $remaining < ( 3 * DAY_IN_SECONDS ) ) {
	$urgency_cls = ' rfwc-countdown-compact--urgent';
}
?>
<span class="rfwc-countdown-compact<?php echo esc_attr( $urgency_cls ); ?>">
	<span class="rfwc-countdown-compact-icon" aria-hidden="true">&#x23F1;</span>
	<span class="rfwc-countdown-compact-label"><?php echo esc_html( $countdown_label ); ?></span>
	<span class="rfwc-countdown" data-end="<?php echo esc_attr( $countdown_timestamp ); ?>">
		<span class="rfwc-countdown-days">--</span><?php esc_html_e( 'd', 'raffle-for-woocommerce' ); ?>
		<span class="rfwc-countdown-hours">--</span><?php esc_html_e( 'h', 'raffle-for-woocommerce' ); ?>
		<span class="rfwc-countdown-minutes">--</span><?php esc_html_e( 'm', 'raffle-for-woocommerce' ); ?>
		<span class="rfwc-countdown-seconds">--</span><?php esc_html_e( 's', 'raffle-for-woocommerce' ); ?>
	</span>
</span>
