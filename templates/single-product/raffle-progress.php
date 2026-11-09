<?php
/**
 * Raffle progress bar template.
 *
 * Displays ticket sales progress for raffle products.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Product_Raffle $product    The raffle product object.
 * @var int|string        $total      Total tickets available or 'unlimited'.
 * @var int               $sold       Number of tickets sold.
 * @var int|string        $available  Number of tickets available or 'unlimited'.
 * @var int               $percentage Progress percentage (0-100).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables from wc_get_template().
?>
<?php if ( 'unlimited' !== $available ) : ?>
    <div class="rfwc-remaining-capacity">
        <span>ظرفیت باقی‌مانده:</span>
        <strong><?php echo esc_html( number_format_i18n( $available ) ); ?></strong>
        <span>نفر</span>
    </div>
<?php endif; ?>
<div class="rfwc-progress-wrapper">
	<?php if ( 'unlimited' === $total ) : ?>
		<div class="rfwc-tickets-info">
			<strong><?php echo esc_html( number_format_i18n( $sold ) ); ?></strong>
			<?php esc_html_e( 'tickets sold', 'raffle-for-woocommerce' ); ?>
		</div>
	<?php else : ?>
		<div class="rfwc-progress">
			<div class="rfwc-progress-bar">
				<div class="rfwc-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
			</div>
			<div class="rfwc-progress-text">
				<span><strong><?php echo esc_html( number_format_i18n( $sold ) ); ?></strong> <?php esc_html_e( 'sold', 'raffle-for-woocommerce' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $available ) ); ?></strong> <?php esc_html_e( 'remaining', 'raffle-for-woocommerce' ); ?></span>
			</div>
		</div>
	<?php endif; ?>
</div>
