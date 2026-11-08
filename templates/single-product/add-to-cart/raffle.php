<?php
/**
 * Raffle product add to cart template.
 *
 * Displays ticket quantity selector and add to cart button for raffle products.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 *
 * @var RFWC_Product_Raffle $product The raffle product object.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hooks.

global $product;

$rfwc_status = $product->get_meta( '_rfwc_status', true );

if ( 'cancelled' === $rfwc_status ) {
	?>
	<div class="rfwc-raffle-notice rfwc-notice-ended rfwc-notice-cancelled">
		<p><?php esc_html_e( 'This raffle has been cancelled.', 'raffle-for-woocommerce' ); ?></p>
	</div>
	<?php
	return;
}

if ( 'failed' === $rfwc_status ) {
	?>
	<div class="rfwc-raffle-notice rfwc-notice-ended rfwc-notice-failed">
		<p><?php esc_html_e( 'This raffle did not reach its goal and has ended.', 'raffle-for-woocommerce' ); ?></p>
	</div>
	<?php
	return;
}

if ( 'completed' === $rfwc_status ) {
	?>
	<div class="rfwc-raffle-notice rfwc-notice-ended rfwc-notice-completed">
		<p><?php esc_html_e( 'This raffle is complete. Winners have been selected.', 'raffle-for-woocommerce' ); ?></p>
	</div>
	<?php
	return;
}

if ( ! $product->is_purchasable() ) {
	return;
}

// Get raffle status.
$has_started = $product->has_started();
$has_ended   = $product->has_ended();
$is_sold_out = $product->is_sold_out();

// Display status message if raffle is not available for purchase.
if ( ! $has_started ) {
	$start_timestamp = $product->get_start_timestamp();
	?>
	<div class="rfwc-raffle-notice rfwc-notice-not-started">
		<p>
			<?php
			if ( $start_timestamp ) {
				printf(
					/* translators: %s: raffle start date and time */
					esc_html__( 'This raffle starts on %s.', 'raffle-for-woocommerce' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start_timestamp ) )
				);
			} else {
				esc_html_e( 'This raffle has not started yet.', 'raffle-for-woocommerce' );
			}
			?>
		</p>
	</div>
	<?php
	return;
}

if ( $has_ended ) {
	?>
	<div class="rfwc-raffle-notice rfwc-notice-ended">
		<p><?php esc_html_e( 'This raffle has ended.', 'raffle-for-woocommerce' ); ?></p>
	</div>
	<?php
	return;
}

if ( $is_sold_out ) {
	?>
	<div class="rfwc-raffle-notice rfwc-notice-sold-out">
		<p><?php esc_html_e( 'Sorry, all tickets have been sold.', 'raffle-for-woocommerce' ); ?></p>
	</div>
	<?php
	return;
}

// Get ticket constraints.
$max_per_customer = $product->get_max_tickets_per_customer();
$available        = $product->get_available_tickets();

// Determine maximum quantity from actual product constraints.
// No artificial ceiling — the limit is derived from the per-customer max
// and available ticket count so bulk pricing tiers above 999 are reachable.
$qty_limits = array();
if ( $max_per_customer > 0 ) {
	$qty_limits[] = $max_per_customer;
}
if ( 'unlimited' !== $available ) {
	$qty_limits[] = (int) $available;
}
$max_qty = ! empty( $qty_limits ) ? min( $qty_limits ) : '';

// Compliance features.
$skill_question_enabled = $product->is_skill_question_enabled();
$free_entry_enabled     = $product->is_free_entry_enabled();
$show_compliance_tabs   = $skill_question_enabled || $free_entry_enabled;

// Get skill question data if enabled.
$question_data = null;
if ( $skill_question_enabled ) {
	$question_data = $product->get_skill_question_data();
}

echo wc_get_stock_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// Show tabs only when free entry is enabled (need a way to toggle between paid/free).
if ( $free_entry_enabled ) :
	?>
	<div class="rfwc-compliance-tabs">
		<button type="button" class="rfwc-compliance-tab rfwc-compliance-tab--active" data-tab="paid">
			<?php esc_html_e( 'Paid Entry', 'raffle-for-woocommerce' ); ?>
		</button>
		<button type="button" class="rfwc-compliance-tab" data-tab="free">
			<?php esc_html_e( 'Free Entry', 'raffle-for-woocommerce' ); ?>
		</button>
	</div>
<?php endif; ?>

<?php
// Show notice outside panels when there are no tabs (skill question only, no free entry).
if ( $skill_question_enabled && $question_data && ! $free_entry_enabled ) :
	?>
	<p class="rfwc-compliance-notice">
		<span class="rfwc-required-indicator" aria-hidden="true">*</span>
		<?php esc_html_e( 'Answering the skill-testing question correctly is required to participate.', 'raffle-for-woocommerce' ); ?>
	</p>
<?php endif; ?>

<?php if ( $free_entry_enabled ) : ?>
	<div class="rfwc-compliance-panel rfwc-compliance-panel--paid rfwc-compliance-panel--active">

	<?php
	// Show notice inside paid panel so it doesn't appear on the Free Entry tab.
	if ( $skill_question_enabled && $question_data ) :
		?>
		<p class="rfwc-compliance-notice">
			<span class="rfwc-required-indicator" aria-hidden="true">*</span>
			<?php esc_html_e( 'Answering the skill-testing question correctly is required to participate.', 'raffle-for-woocommerce' ); ?>
		</p>
	<?php endif; ?>

<?php endif; ?>

<?php do_action( 'woocommerce_before_add_to_cart_form' ); ?>

<form class="cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">

	<?php if ( $skill_question_enabled && $question_data ) : ?>
		<?php
		$rfwc_time_limit = absint( get_option( 'rfwc_skill_question_time_limit', 0 ) );
		$rfwc_mc_display = get_option( 'rfwc_skill_question_mc_display', 'radio' );
		?>
		<div class="rfwc-skill-question"<?php echo $rfwc_time_limit > 0 ? ' data-time-limit="' . esc_attr( $rfwc_time_limit ) . '"' : ''; ?>>
			<p class="rfwc-skill-question-text">
				<?php echo esc_html( $question_data['question'] ); ?>
				<?php if ( $rfwc_time_limit > 0 ) : ?>
					<span class="rfwc-skill-timer" data-seconds="<?php echo esc_attr( $rfwc_time_limit ); ?>"></span>
				<?php endif; ?>
			</p>

			<?php if ( 'multiple_choice' === $question_data['type'] && ! empty( $question_data['choices'] ) ) : ?>
				<?php
				$choices = array_filter( array_map( 'trim', explode( "\n", $question_data['choices'] ) ) );
				shuffle( $choices );
				?>

				<?php if ( 'dropdown' === $rfwc_mc_display ) : ?>
					<select name="rfwc_skill_answer" class="rfwc-skill-answer-dropdown" required>
						<option value=""><?php esc_html_e( 'Select your answer…', 'raffle-for-woocommerce' ); ?></option>
						<?php foreach ( $choices as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice ); ?>"><?php echo esc_html( $choice ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<div class="rfwc-skill-choices">
						<?php foreach ( $choices as $choice ) : ?>
							<label class="rfwc-skill-choice">
								<input type="radio" name="rfwc_skill_answer" value="<?php echo esc_attr( $choice ); ?>" required />
								<span><?php echo esc_html( $choice ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<input type="text" name="rfwc_skill_answer" class="rfwc-skill-answer-input" placeholder="<?php esc_attr_e( 'Your answer', 'raffle-for-woocommerce' ); ?>" autocomplete="off" required />
			<?php endif; ?>

			<?php // Hidden field: hashed correct answer so actual answer is never in page source. ?>
			<input type="hidden" name="rfwc_skill_expected" value="<?php echo esc_attr( wp_hash( strtolower( trim( $question_data['answer'] ) ) ) ); ?>" />
		</div>
	<?php endif; ?>

	<?php
	// Show the quantity input unless the admin explicitly capped it at 1.
	// Empty/0 means unlimited per customer, so the input must be visible
	// so customers can pick more than one ticket.
	$show_quantity = 1 !== $max_per_customer || $product->has_bulk_discounts();
	?>

	<?php do_action( 'woocommerce_before_add_to_cart_quantity' ); ?>

	<?php if ( ! $show_quantity ) : ?>
		<input type="hidden" name="quantity" value="1" />
	<?php endif; ?>

	<?php do_action( 'woocommerce_after_add_to_cart_quantity' ); ?>

	<?php if ( $product->is_gift_enabled() ) : ?>
		<div class="rfwc-gift-toggle">
			<label for="rfwc_is_gift">
				<input type="checkbox" id="rfwc_is_gift" name="rfwc_is_gift" value="1" />
				<?php esc_html_e( 'This is a gift for someone else', 'raffle-for-woocommerce' ); ?>
			</label>
			<span class="rfwc-gift-toggle-hint">
				<?php esc_html_e( 'You can add recipient details at checkout.', 'raffle-for-woocommerce' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<?php if ( $show_quantity ) : ?>
		<div class="rfwc-quantity-wrapper">
			<label for="rfwc-ticket-qty" class="rfwc-quantity-label">
				<?php esc_html_e( 'Number of tickets', 'raffle-for-woocommerce' ); ?>
			</label>
			<?php
			woocommerce_quantity_input(
				array(
					'min_value'   => 1,
					'max_value'   => $max_qty,
					'input_value' => 1,
					'input_name'  => 'quantity',
					'input_id'    => 'rfwc-ticket-qty',
				),
				$product
			);
			?>
		</div>
	<?php endif; ?>

	<button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>">
		<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
	</button>

	<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>

	<?php if ( $max_per_customer > 0 ) : ?>
		<span class="rfwc-max-tickets-notice">
			<?php
			printf(
				/* translators: %d: maximum tickets per customer */
				esc_html__( 'Maximum %d tickets per customer. Gift purchases do not count toward this limit.', 'raffle-for-woocommerce' ),
				absint( $max_per_customer )
			);
			?>
		</span>
	<?php endif; ?>
</form>

<?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>

<?php if ( $free_entry_enabled ) : ?>
	</div><!-- .rfwc-compliance-panel--paid -->

	<div class="rfwc-compliance-panel rfwc-compliance-panel--free">
		<div class="rfwc-free-entry-info">
			<div class="rfwc-free-entry-text">
				<?php echo wp_kses_post( wpautop( $product->get_free_entry_text() ) ); ?>
			</div>

		<?php if ( $skill_question_enabled && $question_data ) : ?>
			<div class="rfwc-free-entry-skill-question">
				<p class="rfwc-free-entry-skill-note">
					<?php esc_html_e( 'Your postal entry must also include the correct answer to the following question:', 'raffle-for-woocommerce' ); ?>
				</p>
				<p class="rfwc-free-entry-skill-text"><?php echo esc_html( $question_data['question'] ); ?></p>

				<?php if ( 'multiple_choice' === $question_data['type'] && ! empty( $question_data['choices'] ) ) : ?>
					<?php $free_choices = array_filter( array_map( 'trim', explode( "\n", $question_data['choices'] ) ) ); ?>
					<ul class="rfwc-free-entry-skill-choices">
						<?php foreach ( $free_choices as $free_choice ) : ?>
							<li><?php echo esc_html( $free_choice ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		</div>
	</div><!-- .rfwc-compliance-panel--free -->
<?php endif; ?>
