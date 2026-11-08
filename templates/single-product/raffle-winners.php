<?php
/**
 * Raffle Winners Display.
 *
 * Shows winners on the raffle product page, separated into instant win
 * and main draw sections for clarity.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Product_Raffle $product         Raffle product.
 * @var array               $winners         All RFWC_Winner objects.
 * @var array               $instant_winners RFWC_Winner objects from instant win.
 * @var array               $draw_winners    RFWC_Winner objects from the main draw.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

if ( empty( $winners ) ) {
	return;
}

$instant_winners = isset( $instant_winners ) ? $instant_winners : array();
$draw_winners    = isset( $draw_winners ) ? $draw_winners : $winners;

$show_winner_details = apply_filters( 'rfwc_show_winner_details', true, $product );
$is_name_masked      = apply_filters( 'rfwc_mask_winner_name', false, null );
$show_avatar         = isset( $show_avatar ) ? $show_avatar : true;
$show_city           = isset( $show_city ) ? $show_city : apply_filters( 'rfwc_show_winner_city', true );
$show_ticket         = isset( $show_ticket ) ? $show_ticket : true;
$show_date           = isset( $show_date ) ? $show_date : true;
$show_link           = isset( $show_link ) ? $show_link : true;
$show_product_image  = isset( $show_product_image ) ? $show_product_image : ( 'yes' === get_option( 'rfwc_show_winner_product_image', 'yes' ) );
$show_prize_image    = isset( $show_prize_image ) ? $show_prize_image : ( 'yes' === get_option( 'rfwc_show_winner_prize_image', 'yes' ) );
$location_display    = get_option( 'rfwc_winner_location_display', 'city' );

if ( $is_name_masked || 'none' === $location_display ) {
	$show_city = false;
}
?>

<div class="rfwc-winners-section">

	<?php if ( ! empty( $instant_winners ) ) : ?>
		<div class="rfwc-winners-announcement rfwc-winners-instant-win">
			<div class="rfwc-winners-header">
				<span class="rfwc-trophy-icon">⚡</span>
				<span class="rfwc-winners-title">
					<?php
					printf(
						esc_html(
							/* translators: %d: number of instant winners */
							_n(
								'%d Instant Win Winner',
								'%d Instant Win Winners',
								count( $instant_winners ),
								'raffle-for-woocommerce'
							)
						),
						count( $instant_winners )
					);
					?>
				</span>
			</div>

			<div class="rfwc-winners-list">
				<?php
				foreach ( $instant_winners as $winner ) :
					$ticket = $winner->get_ticket();
					if ( ! $ticket ) {
						continue;
					}

					$holder_name  = $ticket->get_holder_name();
					$holder_email = $ticket->get_holder_email();

					if ( apply_filters( 'rfwc_mask_winner_name', false, $winner ) ) {
						$parts = explode( ' ', $holder_name );
						if ( count( $parts ) > 1 ) {
							$last_name   = array_pop( $parts );
							$holder_name = implode( ' ', $parts ) . ' ' . substr( $last_name, 0, 1 ) . '.';
						}
					}

					$holder_location = ( $show_city && $show_winner_details ) ? $ticket->get_holder_location() : '';
					?>
					<div class="rfwc-winner-item rfwc-winner-method-instant-win">
						<?php if ( $show_avatar && $show_winner_details ) : ?>
							<span class="rfwc-winner-avatar">
								<?php echo get_avatar( $holder_email, 48, '', $holder_name ); ?>
							</span>
						<?php endif; ?>

						<span class="rfwc-winner-tier">
							<span class="rfwc-iw-bolt">⚡</span>
						</span>

						<div class="rfwc-winner-info">
							<?php if ( $show_winner_details ) : ?>
								<span class="rfwc-winner-name"><?php echo esc_html( $holder_name ); ?></span>
								<?php if ( $holder_location ) : ?>
									<span class="rfwc-winner-city"><?php echo esc_html( $holder_location ); ?></span>
								<?php endif; ?>
								<?php if ( $show_ticket ) : ?>
									<span class="rfwc-winner-ticket">
										<?php
										printf(
											/* translators: %s: ticket number */
											esc_html__( 'Ticket: %s', 'raffle-for-woocommerce' ),
											esc_html( $ticket->get_formatted_ticket_number() )
										);
										?>
									</span>
								<?php endif; ?>
							<?php else : ?>
								<span class="rfwc-winner-name"><?php esc_html_e( 'Instant Winner', 'raffle-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( $show_winner_details ) : ?>
							<?php
							$raffle_product    = wc_get_product( $winner->get_raffle_id() );
							$winner_prize_tier = $winner->get_prize_tier();
							$prize_product     = null;

							if ( $show_prize_image && $raffle_product && 'product' === $raffle_product->get_prize_type() ) {
								$prize_product = $raffle_product->get_prize_product( $winner_prize_tier );
								if ( $prize_product && ! $prize_product->get_image_id() ) {
									$prize_product = null;
								}
							}

							$has_prize_image  = $prize_product && $show_prize_image;
							$has_raffle_image = ! $has_prize_image && $show_product_image && $raffle_product && $raffle_product->get_image_id();
							?>
							<?php if ( $has_prize_image || $has_raffle_image ) : ?>
								<div class="rfwc-winner-images">
									<?php if ( $has_prize_image ) : ?>
										<a href="<?php echo esc_url( get_permalink( $prize_product->get_id() ) ); ?>" class="rfwc-winner-prize-image" title="<?php echo esc_attr( $prize_product->get_name() ); ?>">
											<?php echo $prize_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC method returns safe HTML. ?>
										</a>
									<?php elseif ( $has_raffle_image ) : ?>
										<span class="rfwc-winner-product-image">
											<?php echo $raffle_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC method returns safe HTML. ?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $winner->is_claimed() ) : ?>
							<span class="rfwc-winner-claimed" title="<?php esc_attr_e( 'Prize Claimed', 'raffle-for-woocommerce' ); ?>">✓</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $draw_winners ) ) : ?>
		<div class="rfwc-winners-announcement rfwc-winners-main-draw">
			<div class="rfwc-winners-header">
				<span class="rfwc-trophy-icon">🏆</span>
				<span class="rfwc-winners-title">
					<?php
					printf(
						esc_html(
							/* translators: %d: number of winners */
							_n(
								'Congratulations to our %d winner!',
								'Congratulations to our %d winners!',
								count( $draw_winners ),
								'raffle-for-woocommerce'
							)
						),
						count( $draw_winners )
					);
					?>
				</span>
			</div>

			<div class="rfwc-winners-list">
				<?php
				foreach ( $draw_winners as $winner ) :
					$ticket = $winner->get_ticket();
					if ( ! $ticket ) {
						continue;
					}

					$holder_name  = $ticket->get_holder_name();
					$holder_email = $ticket->get_holder_email();

					if ( apply_filters( 'rfwc_mask_winner_name', false, $winner ) ) {
						$parts = explode( ' ', $holder_name );
						if ( count( $parts ) > 1 ) {
							$last_name   = array_pop( $parts );
							$holder_name = implode( ' ', $parts ) . ' ' . substr( $last_name, 0, 1 ) . '.';
						}
					}

					$holder_location = ( $show_city && $show_winner_details ) ? $ticket->get_holder_location() : '';
					?>
					<div class="rfwc-winner-item rfwc-winner-method-<?php echo esc_attr( $winner->get_selection_method() ); ?>">
						<?php if ( $show_avatar && $show_winner_details ) : ?>
							<span class="rfwc-winner-avatar">
								<?php echo get_avatar( $holder_email, 48, '', $holder_name ); ?>
							</span>
						<?php endif; ?>

						<span class="rfwc-winner-tier">
							<?php if ( $winner->get_prize_tier() === 1 ) : ?>
								<span class="rfwc-medal rfwc-medal-gold">🥇</span>
							<?php elseif ( $winner->get_prize_tier() === 2 ) : ?>
								<span class="rfwc-medal rfwc-medal-silver">🥈</span>
							<?php elseif ( $winner->get_prize_tier() === 3 ) : ?>
								<span class="rfwc-medal rfwc-medal-bronze">🥉</span>
							<?php else : ?>
								<span class="rfwc-tier-number"><?php echo esc_html( $winner->get_prize_tier() ); ?></span>
							<?php endif; ?>
						</span>

						<div class="rfwc-winner-info">
							<?php if ( $show_winner_details ) : ?>
								<span class="rfwc-winner-name"><?php echo esc_html( $holder_name ); ?></span>
								<?php if ( $holder_location ) : ?>
									<span class="rfwc-winner-city"><?php echo esc_html( $holder_location ); ?></span>
								<?php endif; ?>
								<?php if ( $show_ticket ) : ?>
									<span class="rfwc-winner-ticket">
										<?php
										printf(
											/* translators: %s: ticket number */
											esc_html__( 'Ticket: %s', 'raffle-for-woocommerce' ),
											esc_html( $ticket->get_formatted_ticket_number() )
										);
										?>
									</span>
								<?php endif; ?>
							<?php else : ?>
								<span class="rfwc-winner-name"><?php esc_html_e( 'Winner Selected', 'raffle-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( $show_winner_details ) : ?>
							<?php
							$raffle_product    = wc_get_product( $winner->get_raffle_id() );
							$winner_prize_tier = $winner->get_prize_tier();
							$prize_product     = null;

							if ( $show_prize_image && $raffle_product && 'product' === $raffle_product->get_prize_type() ) {
								$prize_product = $raffle_product->get_prize_product( $winner_prize_tier );
								if ( $prize_product && ! $prize_product->get_image_id() ) {
									$prize_product = null;
								}
							}

							$has_prize_image  = $prize_product && $show_prize_image;
							$has_raffle_image = ! $has_prize_image && $show_product_image && $raffle_product && $raffle_product->get_image_id();
							?>
							<?php if ( $has_prize_image || $has_raffle_image ) : ?>
								<div class="rfwc-winner-images">
									<?php if ( $has_prize_image ) : ?>
										<a href="<?php echo esc_url( get_permalink( $prize_product->get_id() ) ); ?>" class="rfwc-winner-prize-image" title="<?php echo esc_attr( $prize_product->get_name() ); ?>">
											<?php echo $prize_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC method returns safe HTML. ?>
										</a>
									<?php elseif ( $has_raffle_image ) : ?>
										<span class="rfwc-winner-product-image">
											<?php echo $raffle_product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC method returns safe HTML. ?>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $winner->is_claimed() ) : ?>
							<span class="rfwc-winner-claimed" title="<?php esc_attr_e( 'Prize Claimed', 'raffle-for-woocommerce' ); ?>">✓</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			$draw_date = $product->get_meta( '_rfwc_draw_date' );
			if ( $show_date && $draw_date ) :
				?>
				<p class="rfwc-draw-date">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Drawn on: %s', 'raffle-for-woocommerce' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $draw_date ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $show_link ) : ?>
				<?php $product_url = get_permalink( $product->get_id() ); ?>
				<?php if ( $product_url ) : ?>
					<p class="rfwc-winner-product-link">
						<a href="<?php echo esc_url( $product_url ); ?>">
							<?php echo esc_html( $product->get_name() ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

</div>
