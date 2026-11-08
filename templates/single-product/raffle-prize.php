<?php
/**
 * Raffle prize information template.
 *
 * Displays prize information for raffle products based on prize type.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Product_Raffle $product            The raffle product object.
 * @var string              $prize_description  Prize description text (tier 1, backward compat).
 * @var float               $prize_value        Prize monetary value (tier 1, backward compat).
 * @var array               $prize_descriptions Prize descriptions for all tiers.
 * @var array               $prize_values       Prize values for all tiers.
 * @var array               $prize_credits      Prize credit amounts for all tiers.
 * @var bool                $show_value         Whether to display prize value.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

$prize_type     = $product->get_prize_type();
$prize_products = $product->get_prize_products();
$num_winners    = $product->get_number_of_winners();

// Ensure arrays are set for backward compatibility.
if ( ! isset( $prize_descriptions ) || ! is_array( $prize_descriptions ) ) {
	$prize_descriptions = array( 1 => $prize_description );
}
if ( ! isset( $prize_values ) || ! is_array( $prize_values ) ) {
	$prize_values = array( 1 => $prize_value );
}
if ( ! isset( $prize_credits ) || ! is_array( $prize_credits ) ) {
	$prize_credits = array( 1 => $product->get_prize_credit_amount() );
}

/**
 * Helper function to get tier label.
 *
 * @param int  $tier        The tier number.
 * @param int  $num_winners Total number of winners.
 * @param bool $with_colon  Whether to append colon.
 * @return string
 */
$get_tier_label = function ( $tier, $num_winners, $with_colon = true ) {
	if ( $num_winners <= 1 ) {
		return $with_colon ? __( 'Prize:', 'raffle-for-woocommerce' ) : __( 'Prize', 'raffle-for-woocommerce' );
	}

	$suffix = $with_colon ? ':' : '';
	if ( 1 === $tier ) {
		/* translators: %s: optional colon */
		return sprintf( __( '1st Prize%s', 'raffle-for-woocommerce' ), $suffix );
	} elseif ( 2 === $tier ) {
		/* translators: %s: optional colon */
		return sprintf( __( '2nd Prize%s', 'raffle-for-woocommerce' ), $suffix );
	} elseif ( 3 === $tier ) {
		/* translators: %s: optional colon */
		return sprintf( __( '3rd Prize%s', 'raffle-for-woocommerce' ), $suffix );
	} else {
		/* translators: 1: tier number, 2: optional colon */
		return sprintf( __( '%1$dth Prize%2$s', 'raffle-for-woocommerce' ), $tier, $suffix );
	}
};
?>
<div class="rfwc-prize-info">
	<?php if ( 'product' === $prize_type && ! empty( $prize_products ) ) : ?>
		<?php
		// Display prize product(s).
		foreach ( $prize_products as $tier => $product_id ) :
			if ( ! $product_id ) {
				continue;
			}

			$prize_product = wc_get_product( $product_id );
			if ( ! $prize_product ) {
				continue;
			}
			?>
			<div class="rfwc-prize-product">
				<span class="rfwc-prize-tier"><?php echo esc_html( $get_tier_label( $tier, $num_winners ) ); ?></span>

				<div class="rfwc-prize-product-details">
					<?php if ( $prize_product->get_image_id() ) : ?>
						<a href="<?php echo esc_url( get_permalink( $prize_product->get_id() ) ); ?>" class="rfwc-prize-product-image">
							<?php echo $prize_product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					<?php endif; ?>

					<div class="rfwc-prize-product-info">
						<a href="<?php echo esc_url( get_permalink( $prize_product->get_id() ) ); ?>" class="rfwc-prize-product-name">
							<?php echo esc_html( $prize_product->get_name() ); ?>
						</a>
						<?php if ( $prize_product->get_price() ) : ?>
							<span class="rfwc-prize-product-value">
								<?php
								printf(
									/* translators: %s: product price */
									esc_html__( 'Value: %s', 'raffle-for-woocommerce' ),
									wp_kses_post( wc_price( $prize_product->get_price() ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>

	<?php elseif ( 'store_credit' === $prize_type ) : ?>
		<?php
		// Display store credit prizes for all tiers.
		for ( $tier = 1; $tier <= $num_winners; $tier++ ) :
			$credit_amount = isset( $prize_credits[ $tier ] ) ? $prize_credits[ $tier ] : 0;
			if ( $credit_amount <= 0 ) {
				continue;
			}
			?>
			<div class="rfwc-prize-item">
				<div class="rfwc-prize-item-header">
					<span class="rfwc-prize-tier"><?php echo esc_html( $get_tier_label( $tier, $num_winners, false ) ); ?></span>
					<span class="rfwc-prize-item-value"><?php echo wp_kses_post( wc_price( $credit_amount ) ); ?></span>
				</div>
				<div class="rfwc-prize-item-description"><?php esc_html_e( 'Store Credit', 'raffle-for-woocommerce' ); ?></div>
			</div>
		<?php endfor; ?>

	<?php else : ?>
		<?php
		// Custom prize - show description and value for all tiers.
		for ( $tier = 1; $tier <= $num_winners; $tier++ ) :
			$tier_description = isset( $prize_descriptions[ $tier ] ) ? $prize_descriptions[ $tier ] : '';
			$tier_value       = isset( $prize_values[ $tier ] ) ? $prize_values[ $tier ] : 0;

			// Skip if no description and no value to show.
			if ( empty( $tier_description ) && ( ! $show_value || $tier_value <= 0 ) ) {
				continue;
			}
			?>
			<div class="rfwc-prize-item">
				<div class="rfwc-prize-item-header">
					<span class="rfwc-prize-tier"><?php echo esc_html( $get_tier_label( $tier, $num_winners, false ) ); ?></span>
					<?php if ( $show_value && $tier_value > 0 ) : ?>
						<span class="rfwc-prize-item-value"><?php echo wp_kses_post( wc_price( $tier_value ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $tier_description ) ) : ?>
					<div class="rfwc-prize-item-description"><?php echo esc_html( $tier_description ); ?></div>
				<?php endif; ?>
			</div>
		<?php endfor; ?>
	<?php endif; ?>
</div>
