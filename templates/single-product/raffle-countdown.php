<?php
/**
 * Raffle countdown timer template.
 *
 * Displays a professional countdown timer for raffle products.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Product_Raffle $product         The raffle product object.
 * @var int|null          $end_timestamp   The raffle end timestamp.
 * @var int|null          $start_timestamp The raffle start timestamp.
 * @var bool              $has_started     Whether the raffle has started.
 * @var bool              $has_ended       Whether the raffle has ended.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

if ( $has_ended ) {
	return;
}

$countdown_timestamp = null;
$countdown_label     = '';

if ( ! $has_started && $start_timestamp ) {
	$countdown_timestamp = $start_timestamp;
	$countdown_label     = __( 'Raffle starts in', 'raffle-for-woocommerce' );
} elseif ( $has_started && $end_timestamp ) {
	$countdown_timestamp = $end_timestamp;
	$countdown_label     = __( 'Raffle ends in', 'raffle-for-woocommerce' );
}

if ( ! $countdown_timestamp ) {
	return;
}
?>
<?php
/**
 * Filters additional CSS classes on the countdown wrapper.
 *
 * Pro addons use this to apply alternative visual styles.
 *
 * @since 1.1.0
 *
 * @param string $class Extra CSS class(es) to append.
 */
$rfwc_countdown_extra_class = apply_filters( 'rfwc_countdown_wrapper_class', '' );
?>
<div class="rfwc-countdown-wrapper<?php echo $rfwc_countdown_extra_class ? ' ' . esc_attr( $rfwc_countdown_extra_class ) : ''; ?>">
	<p class="rfwc-countdown-label"><?php echo esc_html( $countdown_label ); ?></p>
	<div class="rfwc-countdown" data-end="<?php echo esc_attr( $countdown_timestamp ); ?>">
		<div class="rfwc-countdown-item">
			<span class="rfwc-countdown-value rfwc-countdown-days">--</span>
			<span class="rfwc-countdown-unit"><?php esc_html_e( 'Days', 'raffle-for-woocommerce' ); ?></span>
		</div>
		<div class="rfwc-countdown-item">
			<span class="rfwc-countdown-value rfwc-countdown-hours">--</span>
			<span class="rfwc-countdown-unit"><?php esc_html_e( 'Hours', 'raffle-for-woocommerce' ); ?></span>
		</div>
		<div class="rfwc-countdown-item">
			<span class="rfwc-countdown-value rfwc-countdown-minutes">--</span>
			<span class="rfwc-countdown-unit"><?php esc_html_e( 'Mins', 'raffle-for-woocommerce' ); ?></span>
		</div>
		<div class="rfwc-countdown-item">
			<span class="rfwc-countdown-value rfwc-countdown-seconds">--</span>
			<span class="rfwc-countdown-unit"><?php esc_html_e( 'Secs', 'raffle-for-woocommerce' ); ?></span>
		</div>
	</div>
</div>
