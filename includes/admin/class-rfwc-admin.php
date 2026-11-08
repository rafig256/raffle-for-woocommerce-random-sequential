<?php
/**
 * WooCommerce product admin integration.
 *
 * Adds raffle settings tabs to the product edit screen and saves
 * raffle configuration like dates, ticket limits, and prizes.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 *
 * @since 1.0.0
 */
class RFWC_Admin {

	/**
	 * Admin notices instance.
	 *
	 * @var RFWC_Admin_Notices
	 */
	private $notices;

	/**
	 * Settings instance.
	 *
	 * @var RFWC_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// RFWC_Admin_Notices is already instantiated in the main plugin file for early WC checks.
		$this->settings = new RFWC_Settings();

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add product data tabs.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ) );

		// Save product meta.
		add_action( 'woocommerce_process_product_meta_raffle', array( $this, 'save_product_meta' ) );

		// The 'show_if_raffle' / 'hide_if_raffle' CSS classes are toggled by the
		// plugin's own admin JS (WooCommerce core doesn't know about the raffle
		// product type). Only attach those classes to tabs that are truly raffle-only.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'hide_default_tabs' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		$screen = get_current_screen();

		// Only load on relevant pages.
		if ( ! $screen ) {
			return;
		}

		// Load on product edit screens, settings, and order pages.
		$load_styles = 'product' === $screen->post_type
			|| 'woocommerce_page_wc-settings' === $screen->id
			|| 'shop_order' === $screen->post_type
			|| 'woocommerce_page_wc-orders' === $screen->id;

		if ( $load_styles ) {
			wp_enqueue_style(
				'rfwc-admin',
				RFWC_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				RFWC_VERSION
			);

			// Enqueue jQuery UI datepicker (WooCommerce uses this).
			wp_enqueue_script( 'jquery-ui-datepicker' );

			wp_enqueue_script(
				'rfwc-admin',
				RFWC_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery', 'select2' ),
				RFWC_VERSION,
				true
			);

			wp_localize_script(
				'rfwc-admin',
				'rfwc_admin_params',
				array(
					'ajax_url'             => admin_url( 'admin-ajax.php' ),
					'nonce'                => wp_create_nonce( 'rfwc-admin-nonce' ),
					'dismiss_nonce'        => wp_create_nonce( 'rfwc_dismiss_notice' ),
					'global_skill_enabled' => get_option( 'rfwc_enable_skill_question', 'no' ),
					'global_free_enabled'  => get_option( 'rfwc_enable_free_entry', 'no' ),
					'max_prize_tiers'      => rfwc_max_prize_tiers(),
					'i18n'                 => array(
						'confirm_draw' => __( 'Are you sure you want to draw a winner? This action cannot be undone.', 'raffle-for-woocommerce' ),
					),
				)
			);
		}
	}

	/**
	 * Add raffle product data tab.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tabs Product data tabs.
	 * @return array Modified tabs.
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['raffle'] = array(
			'label'    => __( 'Raffle Settings', 'raffle-for-woocommerce' ),
			'target'   => 'raffle_product_data',
			'class'    => array( 'show_if_raffle', 'raffle_options' ),
			'priority' => 21,
		);

		/**
		 * Filter the raffle product data tabs.
		 *
		 * Addons can register additional sub-tabs within the product editor
		 * for features that need significant UI space (e.g. ticket picker config).
		 *
		 * @since 1.1.0
		 *
		 * @param array $tabs WooCommerce product data tabs (already includes 'raffle').
		 */
		$tabs = apply_filters( 'rfwc_product_tabs', $tabs );

		return $tabs;
	}

	/**
	 * Modify default tabs for raffle products.
	 *
	 * Hides the Shipping tab when the product type is "raffle". The General
	 * tab is force-shown for raffles via JS (see assets/js/admin.js) so it can
	 * keep its default visibility for every other product type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tabs Product data tabs.
	 * @return array Modified tabs.
	 */
	public function hide_default_tabs( $tabs ) {
		// Hide shipping tab for raffles.
		if ( isset( $tabs['shipping'] ) ) {
			if ( ! isset( $tabs['shipping']['class'] ) || ! is_array( $tabs['shipping']['class'] ) ) {
				$tabs['shipping']['class'] = array();
			}
			$tabs['shipping']['class'][] = 'hide_if_raffle';
		}

		return $tabs;
	}

	/**
	 * Show CSS class for raffle product type.
	 *
	/**
	 * Output raffle product data panel.
	 *
	 * @since 1.0.0
	 */
	public function product_data_panel() {
		global $post;

		$product = wc_get_product( $post->ID );
		?>
		<div id="raffle_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_start_date',
						'label'             => __( 'Start date', 'raffle-for-woocommerce' ),
						'placeholder'       => 'YYYY-MM-DD',
						'class'             => 'short date-picker',
						'desc_tip'          => true,
						'description'       => __( 'The date when the raffle starts. Leave empty to start immediately.', 'raffle-for-woocommerce' ),
						'value'             => $product ? $product->get_meta( '_rfwc_start_date', true ) : '',
						'custom_attributes' => array(
							'maxlength' => 10,
							'pattern'   => '[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])',
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_start_time',
						'label'             => __( 'Start time', 'raffle-for-woocommerce' ),
						'placeholder'       => 'HH:MM',
						'class'             => 'short',
						'desc_tip'          => true,
						'description'       => __( 'The time when the raffle starts (24-hour format). Defaults to 00:00 if empty.', 'raffle-for-woocommerce' ),
						'value'             => $product ? $product->get_meta( '_rfwc_start_time', true ) : '',
						'custom_attributes' => array(
							'maxlength' => 5,
							'pattern'   => '([01]?[0-9]|2[0-3]):[0-5][0-9]',
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_end_date',
						'label'             => __( 'End date', 'raffle-for-woocommerce' ),
						'placeholder'       => 'YYYY-MM-DD',
						'class'             => 'short date-picker',
						'desc_tip'          => true,
						'description'       => __( 'The date when the raffle ends. Leave empty for no end date.', 'raffle-for-woocommerce' ),
						'value'             => $product ? $product->get_meta( '_rfwc_end_date', true ) : '',
						'custom_attributes' => array(
							'maxlength' => 10,
							'pattern'   => '[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])',
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_end_time',
						'label'             => __( 'End time', 'raffle-for-woocommerce' ),
						'placeholder'       => 'HH:MM',
						'class'             => 'short',
						'desc_tip'          => true,
						'description'       => __( 'The time when the raffle ends (24-hour format). Defaults to 23:59 if empty.', 'raffle-for-woocommerce' ),
						'value'             => $product ? $product->get_meta( '_rfwc_end_time', true ) : '',
						'custom_attributes' => array(
							'maxlength' => 5,
							'pattern'   => '([01]?[0-9]|2[0-3]):[0-5][0-9]',
						),
					)
				);
				?>
			</div>

			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_total_tickets',
						'label'             => __( 'Total tickets', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Maximum number of tickets available. Leave empty for unlimited.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_total_tickets', true ) : '',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_max_tickets_per_customer',
						'label'             => __( 'Max per customer', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Maximum tickets one customer can buy. Leave empty for unlimited.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_max_tickets_per_customer', true ) : '',
					)
				);

				$ticket_format_options = array(
					''               => __( 'Use global setting', 'raffle-for-woocommerce' ),
					'sequential'     => __( 'Sequential (000001, 000002...)', 'raffle-for-woocommerce' ),
					'random'         => __( 'Random (A7X9B2, K3M5N8...)', 'raffle-for-woocommerce' ),
					'random_numeric' => __( 'Random numeric (001, 002, 003...)', 'raffle-for-woocommerce' ),
				);

				/**
				 * Filter the ticket format options on the product edit screen.
				 *
				 * @since 1.1.0
				 *
				 * @param array $options Format key => label.
				 */
				$ticket_format_options = apply_filters( 'rfwc_ticket_format_options', $ticket_format_options );

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_ticket_format',
						'label'       => __( 'Ticket format', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'How ticket numbers should be generated.', 'raffle-for-woocommerce' ),
						'options'     => $ticket_format_options,
						'value'       => $product ? $product->get_meta( '_rfwc_ticket_format', true ) : '',
						'class'       => 'select short',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_rfwc_ticket_prefix',
						'label'       => __( 'Ticket prefix', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Optional prefix for ticket numbers (e.g., RAFFLE-).', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_ticket_prefix', true ) : '',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_rfwc_ticket_suffix',
						'label'       => __( 'Ticket suffix', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Optional suffix appended to ticket numbers (e.g., -2026).', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_ticket_suffix', true ) : '',
					)
				);
				?>
			</div>

		<?php if ( rfwc_feature_enabled( 'rfwc_enable_bulk_discounts' ) ) : ?>
		<div class="options_group rfwc-bulk-discount-settings">
			<p class="form-field">
				<label><?php esc_html_e( 'Bulk ticket discounts', 'raffle-for-woocommerce' ); ?></label>
					<span class="description"><?php esc_html_e( 'Offer percentage discounts when customers buy multiple tickets.', 'raffle-for-woocommerce' ); ?></span>
				</p>
				<?php
				$bulk_discounts = $product ? $product->get_meta( '_rfwc_bulk_discounts', true ) : array();
				if ( ! is_array( $bulk_discounts ) ) {
					$bulk_discounts = array();
				}

				// Pre-render up to 5 tier rows.
				for ( $tier = 0; $tier < 5; $tier++ ) :
					$min_qty      = isset( $bulk_discounts[ $tier ]['min_qty'] ) ? absint( $bulk_discounts[ $tier ]['min_qty'] ) : '';
					$discount_pct = isset( $bulk_discounts[ $tier ]['discount_pct'] ) ? floatval( $bulk_discounts[ $tier ]['discount_pct'] ) : '';
					$is_active    = '' !== $min_qty && '' !== $discount_pct;
					?>
					<p class="form-field rfwc-bulk-tier-row<?php echo ! $is_active && $tier > 0 ? ' rfwc-hidden' : ''; ?>" data-tier="<?php echo esc_attr( $tier ); ?>">
						<label>
							<?php
							printf(
								/* translators: %d: tier number */
								esc_html__( 'Tier %d', 'raffle-for-woocommerce' ),
								absint( $tier + 1 )
							);
							?>
						</label>
						<span class="rfwc-bulk-tier-fields">
							<input type="number" name="_rfwc_bulk_discounts[<?php echo esc_attr( $tier ); ?>][min_qty]" value="<?php echo esc_attr( $min_qty ); ?>" placeholder="<?php esc_attr_e( 'Min qty', 'raffle-for-woocommerce' ); ?>" min="2" step="1" class="short rfwc-bulk-min-qty" />
							<span class="rfwc-bulk-tier-sep"><?php esc_html_e( 'tickets →', 'raffle-for-woocommerce' ); ?></span>
							<input type="number" name="_rfwc_bulk_discounts[<?php echo esc_attr( $tier ); ?>][discount_pct]" value="<?php echo esc_attr( $discount_pct ); ?>" placeholder="<?php esc_attr_e( '% off', 'raffle-for-woocommerce' ); ?>" min="1" max="99" step="any" class="short rfwc-bulk-pct" />
							<span class="rfwc-bulk-tier-sep">%</span>
							<?php if ( $tier > 0 ) : ?>
								<button type="button" class="rfwc-bulk-remove-tier" title="<?php esc_attr_e( 'Remove tier', 'raffle-for-woocommerce' ); ?>">&times;</button>
							<?php endif; ?>
						</span>
					</p>
				<?php endfor; ?>
				<p class="form-field">
					<label>&nbsp;</label>
					<button type="button" class="button rfwc-bulk-add-tier"><?php esc_html_e( '+ Add tier', 'raffle-for-woocommerce' ); ?></button>
				</p>
				<?php
				$max_per = $product ? absint( $product->get_meta( '_rfwc_max_tickets_per_customer', true ) ) : 0;
				if ( 1 === $max_per ) :
					?>
					<p class="form-field rfwc-bulk-notice">
						<label>&nbsp;</label>
						<span class="description"><em><?php esc_html_e( 'Note: With max per customer set to 1, bulk discounts will only apply to gift purchases.', 'raffle-for-woocommerce' ); ?></em></span>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

			<div class="options_group">
				<?php
				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_enable_gift',
						'label'       => __( 'Gift purchase', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Allow customers to buy tickets for someone else.', 'raffle-for-woocommerce' ),
						'options'     => array(
							''    => __( 'Use global setting', 'raffle-for-woocommerce' ),
							'yes' => __( 'Yes', 'raffle-for-woocommerce' ),
							'no'  => __( 'No', 'raffle-for-woocommerce' ),
						),
						'value'       => $product ? $product->get_meta( '_rfwc_enable_gift', true ) : '',
						'class'       => 'select short',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_enable_skill_question',
						'label'       => __( 'Skill question', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Require a skill-testing question before purchase.', 'raffle-for-woocommerce' ),
						'options'     => array(
							''    => __( 'Use global setting', 'raffle-for-woocommerce' ),
							'yes' => __( 'Yes', 'raffle-for-woocommerce' ),
							'no'  => __( 'No', 'raffle-for-woocommerce' ),
						),
						'value'       => $product ? $product->get_meta( '_rfwc_enable_skill_question', true ) : '',
						'class'       => 'select short',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_skill_question_source',
						'label'       => __( 'Question source', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Use the global question pool or define a custom question for this product.', 'raffle-for-woocommerce' ),
						'options'     => array(
							'global' => __( 'Use global pool', 'raffle-for-woocommerce' ),
							'custom' => __( 'Custom question', 'raffle-for-woocommerce' ),
						),
						'value'       => $product ? ( $product->get_meta( '_rfwc_skill_question_source', true ) ? $product->get_meta( '_rfwc_skill_question_source', true ) : 'global' ) : 'global',
						'class'       => 'select short',
					)
				);

				// Show pool question selector when using global pool.
				$source_meta    = $product ? $product->get_meta( '_rfwc_skill_question_source', true ) : '';
				$current_source = $source_meta ? $source_meta : 'global';
				$pinned_index   = $product ? $product->get_meta( '_rfwc_skill_question_pool_index', true ) : '';
				$pool           = get_option( 'rfwc_skill_question_pool', array() );

				// Build options for the Select2 dropdown.
				$pool_options = array( '' => __( '- Auto-assign on first visit -', 'raffle-for-woocommerce' ) );
				if ( is_array( $pool ) ) {
					foreach ( $pool as $idx => $q ) {
						if ( ! empty( $q['question'] ) ) {
							$pool_options[ $idx ] = $q['question'];
						}
					}
				}

				woocommerce_wp_select(
					array(
						'id'                => '_rfwc_skill_question_pool_index',
						'label'             => __( 'Pinned question', 'raffle-for-woocommerce' ),
						'desc_tip'          => true,
						'description'       => __( 'Choose a specific question from the global pool for this product. If left on auto-assign, a random question will be selected on the first customer visit and kept permanently.', 'raffle-for-woocommerce' ),
						'options'           => $pool_options,
						'value'             => ( '' !== $pinned_index && is_numeric( $pinned_index ) ) ? (string) $pinned_index : '',
						'class'             => 'select rfwc-pool-question-select',
						'wrapper_class'     => 'rfwc-pool-question-field',
						'custom_attributes' => array(
							'data-placeholder' => __( 'Auto-assign on first visit', 'raffle-for-woocommerce' ),
						),
					)
				);
				?>
				<?php

				woocommerce_wp_textarea_input(
					array(
						'id'          => '_rfwc_skill_question',
						'label'       => __( 'Custom question', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'The question text shown to customers.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_skill_question', true ) : '',
						'placeholder' => __( 'e.g., What is 12 + 7?', 'raffle-for-woocommerce' ),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_skill_question_type',
						'label'       => __( 'Question type', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Text requires the customer to type the answer. Multiple choice shows radio buttons.', 'raffle-for-woocommerce' ),
						'options'     => array(
							'text'            => __( 'Text', 'raffle-for-woocommerce' ),
							'multiple_choice' => __( 'Multiple Choice', 'raffle-for-woocommerce' ),
						),
						'value'       => $product ? ( $product->get_meta( '_rfwc_skill_question_type', true ) ? $product->get_meta( '_rfwc_skill_question_type', true ) : 'text' ) : 'text',
						'class'       => 'select short',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_rfwc_skill_answer',
						'label'       => __( 'Correct answer', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'The correct answer (case-insensitive for text type).', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_skill_answer', true ) : '',
					)
				);

				woocommerce_wp_textarea_input(
					array(
						'id'          => '_rfwc_skill_choices',
						'label'       => __( 'MC choices', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'One choice per line. Include the correct answer.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_skill_choices', true ) : '',
						'placeholder' => "19\n21\n25\n30",
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'          => '_rfwc_redirect_to_checkout',
						'label'       => __( 'Skip cart page', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Redirect customers directly to checkout after adding this raffle to cart.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_redirect_to_checkout', true ) : 'no',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_enable_free_entry',
						'label'       => __( 'Free entry route', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Show a free entry option on the product page.', 'raffle-for-woocommerce' ),
						'options'     => array(
							''    => __( 'Use global setting', 'raffle-for-woocommerce' ),
							'yes' => __( 'Yes', 'raffle-for-woocommerce' ),
							'no'  => __( 'No', 'raffle-for-woocommerce' ),
						),
						'value'       => $product ? $product->get_meta( '_rfwc_enable_free_entry', true ) : '',
						'class'       => 'select short',
					)
				);

				woocommerce_wp_textarea_input(
					array(
						'id'          => '_rfwc_free_entry_text',
						'label'       => __( 'Free entry instructions', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Custom instructions for free entry. Leave empty to use the global default.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_free_entry_text', true ) : '',
					)
				);
				?>
			</div>

			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_number_of_winners',
						'label'             => __( 'Number of winners', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'How many winners to select. Each winner can have individual prize configuration.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 1,
							'max'  => rfwc_max_prize_tiers(),
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_number_of_winners', true ) : '1',
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'          => '_rfwc_auto_draw',
						'label'       => __( 'Automatic draw', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Automatically draw winners when the raffle ends.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_auto_draw', true ) : 'no',
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'          => '_rfwc_unique_winners',
						'label'       => __( 'Unique winners only', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Prevent the same customer from winning multiple prizes in this raffle. Each person can win at most once.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_unique_winners', true ) : 'no',
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'          => '_rfwc_early_draw_on_sellout',
						'label'       => __( 'Early draw when sold out', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Draw winners immediately when all tickets sell out instead of waiting for the end date. Requires auto-draw to be enabled.', 'raffle-for-woocommerce' ),
						'value'       => $product ? $product->get_meta( '_rfwc_early_draw_on_sellout', true ) : 'no',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_min_tickets_for_draw',
						'label'             => __( 'Minimum tickets for draw', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Minimum tickets that must be sold before auto-draw proceeds. If not met, raffle auto-fails. 0 = no minimum.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_min_tickets_for_draw', true ) : '0',
					)
				);
				?>
			</div>

		<?php if ( rfwc_feature_enabled( 'rfwc_enable_instant_win' ) ) : ?>
		<div class="options_group rfwc-instant-win-settings">
			<?php
			$iw_enabled = $product ? $product->get_meta( '_rfwc_instant_win_enabled', true ) : 'no';

				woocommerce_wp_checkbox(
					array(
						'id'          => '_rfwc_instant_win_enabled',
						'label'       => __( 'Enable Instant Win', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Evaluate each ticket at purchase time. Customers find out immediately whether they have won.', 'raffle-for-woocommerce' ),
						'value'       => $iw_enabled,
					)
				);

				$iw_only = $product ? $product->get_meta( '_rfwc_instant_win_only', true ) : 'no';

				woocommerce_wp_checkbox(
					array(
						'id'            => '_rfwc_instant_win_only',
						'label'         => __( 'Instant win only', 'raffle-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'All prizes are distributed through instant win. No separate draw at the end date. The end date will simply close ticket sales.', 'raffle-for-woocommerce' ),
						'value'         => $iw_only,
						'wrapper_class' => 'rfwc-iw-field' . ( 'yes' !== $iw_enabled ? ' rfwc-hidden' : '' ),
					)
				);

				$iw_mode = $product ? $product->get_meta( '_rfwc_instant_win_mode', true ) : 'probability';
			if ( ! $iw_mode ) {
				$iw_mode = 'probability';
			}

				woocommerce_wp_select(
					array(
						'id'            => '_rfwc_instant_win_mode',
						'label'         => __( 'Instant win mode', 'raffle-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Probability: each ticket has an independent random chance. Guaranteed: exactly 1 winner per every N tickets.', 'raffle-for-woocommerce' ),
						'options'       => array(
							'probability' => __( 'Probability-based (e.g. 1 in 50 chance)', 'raffle-for-woocommerce' ),
							'guaranteed'  => __( 'Guaranteed interval (1 winner per N tickets)', 'raffle-for-woocommerce' ),
						),
						'value'         => $iw_mode,
						'class'         => 'select short rfwc-iw-mode-select',
						'wrapper_class' => 'rfwc-iw-field' . ( 'yes' !== $iw_enabled ? ' rfwc-hidden' : '' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_instant_win_probability',
						'label'             => __( 'Win probability (1 in X)', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Each ticket has a 1 in X chance of winning. For example, enter 50 for a 1 in 50 chance (2%).', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 2,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_instant_win_probability', true ) : '50',
						'placeholder'       => '50',
						'wrapper_class'     => 'rfwc-iw-field rfwc-iw-probability-field' . ( 'yes' !== $iw_enabled || 'probability' !== $iw_mode ? ' rfwc-hidden' : '' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_instant_win_interval',
						'label'             => __( '1 winner per every N tickets', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Guarantees exactly 1 winner within every N tickets sold. The winning position is randomly determined.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 2,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_instant_win_interval', true ) : '25',
						'placeholder'       => '25',
						'wrapper_class'     => 'rfwc-iw-field rfwc-iw-interval-field' . ( 'yes' !== $iw_enabled || 'guaranteed' !== $iw_mode ? ' rfwc-hidden' : '' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'                => '_rfwc_instant_win_max_winners',
						'label'             => __( 'Max instant winners', 'raffle-for-woocommerce' ),
						'type'              => 'number',
						'desc_tip'          => true,
						'description'       => __( 'Maximum number of instant winners for this raffle. Leave empty or 0 for unlimited.', 'raffle-for-woocommerce' ),
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'value'             => $product ? $product->get_meta( '_rfwc_instant_win_max_winners', true ) : '',
						'wrapper_class'     => 'rfwc-iw-field' . ( 'yes' !== $iw_enabled ? ' rfwc-hidden' : '' ),
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'            => '_rfwc_instant_win_require_online_payment',
						'label'         => __( 'Require instant payment', 'raffle-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Hide offline payment gateways (BACS, Check, COD) so results can be revealed immediately.', 'raffle-for-woocommerce' ),
						'value'         => $product ? ( $product->get_meta( '_rfwc_instant_win_require_online_payment', true ) ? $product->get_meta( '_rfwc_instant_win_require_online_payment', true ) : 'yes' ) : 'yes',
						'wrapper_class' => 'rfwc-iw-field' . ( 'yes' !== $iw_enabled ? ' rfwc-hidden' : '' ),
					)
				);
			?>
			</div>
		<?php else : ?>
			<?php
			$iw_product_enabled = $product ? $product->get_meta( '_rfwc_instant_win_enabled', true ) : 'no';
			if ( 'yes' === $iw_product_enabled ) :
				$settings_url = admin_url( 'admin.php?page=wc-settings&tab=raffle' );
				?>
			<div class="options_group">
				<p class="form-field" style="padding: 8px 12px; margin: 9px 12px; background: #fcf0f1; border-left: 4px solid #d63638; color: #2c3338;">
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to WooCommerce Raffle settings */
							__( 'This raffle has Instant Win enabled, but the global Instant Win module is turned off. Instant win will not work until you enable it in <a href="%s">WooCommerce &gt; Settings &gt; Raffle</a>.', 'raffle-for-woocommerce' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( $settings_url )
					);
					?>
				</p>
			</div>
			<?php endif; ?>
		<?php endif; ?>

			<div class="options_group">
				<?php
				$all_gateways    = WC()->payment_gateways()->payment_gateways();
				$gateway_options = array();
				foreach ( $all_gateways as $gateway_id => $gateway ) {
					$gateway_options[ $gateway_id ] = $gateway->get_title();
				}

				$disabled_gateways = $product ? $product->get_meta( '_rfwc_disabled_gateways', true ) : array();
				if ( ! is_array( $disabled_gateways ) ) {
					$disabled_gateways = array();
				}
				?>
				<p class="form-field">
					<label for="_rfwc_disabled_gateways"><?php esc_html_e( 'Disabled payment gateways', 'raffle-for-woocommerce' ); ?></label>
					<select id="_rfwc_disabled_gateways" name="_rfwc_disabled_gateways[]" class="wc-enhanced-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'No restrictions (all gateways allowed)', 'raffle-for-woocommerce' ); ?>" style="width: 50%;">
						<?php foreach ( $gateway_options as $gw_id => $gw_title ) : ?>
							<option value="<?php echo esc_attr( $gw_id ); ?>" <?php echo in_array( $gw_id, $disabled_gateways, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $gw_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php echo wp_kses_post( wc_help_tip( __( 'Select payment gateways to hide when this raffle product is in the cart. Useful for disabling Cash on Delivery or other offline methods.', 'raffle-for-woocommerce' ) ) ); ?>
				</p>
			</div>

			<div class="options_group rfwc-prize-settings">
				<?php
				$current_prize_type = $product ? $product->get_meta( '_rfwc_prize_type', true ) : 'custom';
				if ( ! $current_prize_type ) {
					$current_prize_type = 'custom';
				}

				woocommerce_wp_select(
					array(
						'id'          => '_rfwc_prize_type',
						'label'       => __( 'Prize type', 'raffle-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'How the prize will be awarded to winners.', 'raffle-for-woocommerce' ),
						'options'     => array(
							'custom'       => __( 'Custom', 'raffle-for-woocommerce' ),
							'product'      => __( 'Product from store', 'raffle-for-woocommerce' ),
							'store_credit' => __( 'Store credit', 'raffle-for-woocommerce' ),
						),
						'value'       => $current_prize_type,
						'class'       => 'select short rfwc-prize-type-select',
					)
				);
				?>

				<div class="rfwc-prize-product-fields<?php echo 'product' !== $current_prize_type ? ' rfwc-hidden' : ''; ?>">
					<?php
					// Get number of winners for prize tiers.
					$num_winners    = $product ? absint( $product->get_meta( '_rfwc_number_of_winners', true ) ) : 1;
					$num_winners    = max( 1, $num_winners );
					$prize_products = $product ? $product->get_meta( '_rfwc_prize_products', true ) : array();
					if ( ! is_array( $prize_products ) ) {
						$prize_products = array();
					}

					$max_tiers = rfwc_max_prize_tiers();

					for ( $tier = 1; $tier <= $max_tiers; $tier++ ) :
						$selected_product_id = isset( $prize_products[ $tier ] ) ? $prize_products[ $tier ] : '';
						$selected_product    = $selected_product_id ? wc_get_product( $selected_product_id ) : null;

						woocommerce_wp_select(
							array(
								'id'                => '_rfwc_prize_product_' . $tier,
								'name'              => '_rfwc_prize_products[' . $tier . ']',
								/* translators: %s: ordinal (1st, 2nd, etc.) */
								'label'             => sprintf( __( '%s prize product', 'raffle-for-woocommerce' ), rfwc_get_ordinal( $tier ) ),
								'class'             => 'wc-product-search',
								'options'           => $selected_product ? array( $selected_product_id => $selected_product->get_formatted_name() ) : array( '' => '' ),
								'value'             => $selected_product_id,
								'custom_attributes' => array(
									'data-placeholder'  => __( 'Search for a product&hellip;', 'raffle-for-woocommerce' ),
									'data-action'       => 'woocommerce_json_search_products',
									'data-exclude_type' => 'raffle',
								),
							)
						);
					endfor;

					woocommerce_wp_checkbox(
						array(
							'id'          => '_rfwc_auto_fulfill',
							'label'       => __( 'Auto-fulfill prize', 'raffle-for-woocommerce' ),
							'desc_tip'    => true,
							'description' => __( 'Automatically create a free order for the winner with the prize product.', 'raffle-for-woocommerce' ),
							'value'       => $product ? $product->get_meta( '_rfwc_auto_fulfill', true ) : 'no',
						)
					);
					?>
				</div>

				<div class="rfwc-prize-credit-fields<?php echo 'store_credit' !== $current_prize_type ? ' rfwc-hidden' : ''; ?>">
					<?php
					// Get stored credit amounts array.
					$prize_credits = $product ? $product->get_meta( '_rfwc_prize_credits', true ) : array();
					if ( ! is_array( $prize_credits ) ) {
						// Backward compatibility: migrate single value to array.
						$old_credit    = $product ? $product->get_meta( '_rfwc_prize_credit_amount', true ) : '';
						$prize_credits = array( 1 => $old_credit );
					}

					for ( $tier = 1; $tier <= $max_tiers; $tier++ ) :
						$credit_value = isset( $prize_credits[ $tier ] ) ? $prize_credits[ $tier ] : '';

						woocommerce_wp_text_input(
							array(
								'id'                => '_rfwc_prize_credit_' . $tier,
								'name'              => '_rfwc_prize_credits[' . $tier . ']',
								/* translators: %1$s: ordinal (1st, 2nd, etc.), %2$s: currency symbol */
								'label'             => sprintf( __( '%1$s place credit amount (%2$s)', 'raffle-for-woocommerce' ), rfwc_get_ordinal( $tier ), get_woocommerce_currency_symbol() ),
								'type'              => 'number',
								'desc_tip'          => true,
								'description'       => __( 'Amount to award as store credit or coupon.', 'raffle-for-woocommerce' ),
								'custom_attributes' => array(
									'min'  => 0,
									'step' => 'any',
								),
								'value'             => $credit_value,
							)
						);
					endfor;
					?>
				</div>

				<div class="rfwc-prize-custom-fields<?php echo 'custom' !== $current_prize_type ? ' rfwc-hidden' : ''; ?>">
					<?php
					// Get stored custom prizes arrays.
					$prize_descriptions = $product ? $product->get_meta( '_rfwc_prize_descriptions', true ) : array();
					$prize_values       = $product ? $product->get_meta( '_rfwc_prize_values', true ) : array();

					if ( ! is_array( $prize_descriptions ) ) {
						// Backward compatibility: migrate single values to arrays.
						$old_desc           = $product ? $product->get_meta( '_rfwc_prize_description', true ) : '';
						$prize_descriptions = array( 1 => $old_desc );
					}
					if ( ! is_array( $prize_values ) ) {
						$old_value    = $product ? $product->get_meta( '_rfwc_prize_value', true ) : '';
						$prize_values = array( 1 => $old_value );
					}

					for ( $tier = 1; $tier <= $max_tiers; $tier++ ) :
						$description = isset( $prize_descriptions[ $tier ] ) ? $prize_descriptions[ $tier ] : '';
						$value       = isset( $prize_values[ $tier ] ) ? $prize_values[ $tier ] : '';

						woocommerce_wp_textarea_input(
							array(
								'id'          => '_rfwc_prize_description_' . $tier,
								'name'        => '_rfwc_prize_descriptions[' . $tier . ']',
								/* translators: %s: ordinal (1st, 2nd, etc.) */
								'label'       => sprintf( __( '%s place description', 'raffle-for-woocommerce' ), rfwc_get_ordinal( $tier ) ),
								'desc_tip'    => true,
								'description' => __( 'Describe the prize for this tier.', 'raffle-for-woocommerce' ),
								'value'       => $description,
							)
						);

						woocommerce_wp_text_input(
							array(
								'id'                => '_rfwc_prize_value_' . $tier,
								'name'              => '_rfwc_prize_values[' . $tier . ']',
								/* translators: %1$s: ordinal (1st, 2nd, etc.), %2$s: currency symbol */
								'label'             => sprintf( __( '%1$s place value (%2$s)', 'raffle-for-woocommerce' ), rfwc_get_ordinal( $tier ), get_woocommerce_currency_symbol() ),
								'type'              => 'number',
								'desc_tip'          => true,
								'description'       => __( 'Estimated value for display purposes.', 'raffle-for-woocommerce' ),
								'custom_attributes' => array(
									'min'  => 0,
									'step' => 'any',
								),
								'value'             => $value,
							)
						);
					endfor;
					?>
				</div>
			</div>

			<?php
			/**
			 * Fires at the end of the Raffle Data product panel.
			 *
			 * Addons can hook here to inject additional per-product settings
			 * (e.g. advanced instant win, ticket picker config).
			 *
			 * @since 1.1.0
			 *
			 * @param WC_Product|null $product Current product being edited.
			 */
			do_action( 'rfwc_admin_product_options', $product );
			?>
		</div>
		<?php
	}

	/**
	 * Save raffle product meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product post ID.
	 */
	public function save_product_meta( $post_id ) {
		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$fields = array(
			'_rfwc_start_date',
			'_rfwc_start_time',
			'_rfwc_end_date',
			'_rfwc_end_time',
			'_rfwc_total_tickets',
			'_rfwc_max_tickets_per_customer',
			'_rfwc_ticket_format',
			'_rfwc_ticket_prefix',
			'_rfwc_ticket_suffix',
			'_rfwc_enable_gift',
			'_rfwc_number_of_winners',
			'_rfwc_prize_type',
			'_rfwc_enable_skill_question',
			'_rfwc_skill_question_source',
			'_rfwc_skill_question',
			'_rfwc_skill_question_type',
			'_rfwc_skill_answer',
			'_rfwc_enable_free_entry',
		);

		foreach ( $fields as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

				// Field-specific validation.
				if ( in_array( $field, array( '_rfwc_start_date', '_rfwc_end_date' ), true ) ) {
					// Validate date format (YYYY-MM-DD) or allow empty.
					if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						continue; // Skip invalid date.
					}
				} elseif ( in_array( $field, array( '_rfwc_start_time', '_rfwc_end_time' ), true ) ) {
					// Validate time format (HH:MM) or allow empty.
					if ( '' !== $value && ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
						continue; // Skip invalid time.
					}
				} elseif ( in_array( $field, array( '_rfwc_total_tickets', '_rfwc_max_tickets_per_customer' ), true ) ) {
					$value = '' !== $value ? (string) absint( $value ) : '';
				} elseif ( '_rfwc_number_of_winners' === $field ) {
					$value = (string) max( 1, min( rfwc_max_prize_tiers(), absint( $value ) ) );
				}

				$product->update_meta_data( $field, $value );
			}
		}

		// Cross-field validation: ensure end datetime is after start datetime.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$start_date = isset( $_POST['_rfwc_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfwc_start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$end_date = isset( $_POST['_rfwc_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfwc_end_date'] ) ) : '';

		if ( $start_date && $end_date ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$start_time = isset( $_POST['_rfwc_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfwc_start_time'] ) ) : '00:00';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$end_time = isset( $_POST['_rfwc_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfwc_end_time'] ) ) : '23:59';

			$start_str = $start_date . ' ' . $start_time;
			$end_str   = $end_date . ' ' . $end_time;

			if ( strtotime( $end_str ) <= strtotime( $start_str ) ) {
				// Reset end date to prevent saving an invalid range.
				$product->update_meta_data( '_rfwc_end_date', '' );
				$product->update_meta_data( '_rfwc_end_time', '' );

				// Show admin notice.
				set_transient( 'rfwc_admin_notice_' . get_current_user_id(), __( 'Raffle end date/time must be after the start date/time. End date has been cleared.', 'raffle-for-woocommerce' ), 30 );
			}
		}

		// Cross-field validation: max per customer cannot exceed total tickets.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce.
		$total_tickets_post = isset( $_POST['_rfwc_total_tickets'] ) ? absint( wp_unslash( $_POST['_rfwc_total_tickets'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce.
		$max_per_post = isset( $_POST['_rfwc_max_tickets_per_customer'] ) ? absint( wp_unslash( $_POST['_rfwc_max_tickets_per_customer'] ) ) : 0;

		if ( $total_tickets_post > 0 && $max_per_post > $total_tickets_post ) {
			$product->update_meta_data( '_rfwc_max_tickets_per_customer', (string) $total_tickets_post );

			set_transient(
				'rfwc_admin_notice_' . get_current_user_id(),
				sprintf(
					/* translators: %d: total tickets value */
					__( 'Max per customer cannot exceed Total tickets. Value reduced to %d.', 'raffle-for-woocommerce' ),
					$total_tickets_post
				),
				30
			);
		}

		// Handle checkbox fields.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$auto_draw = isset( $_POST['_rfwc_auto_draw'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_auto_draw', $auto_draw );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$auto_fulfill = isset( $_POST['_rfwc_auto_fulfill'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_auto_fulfill', $auto_fulfill );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$unique_winners = isset( $_POST['_rfwc_unique_winners'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_unique_winners', $unique_winners );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$early_draw = isset( $_POST['_rfwc_early_draw_on_sellout'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_early_draw_on_sellout', $early_draw );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$min_tickets = isset( $_POST['_rfwc_min_tickets_for_draw'] ) ? absint( $_POST['_rfwc_min_tickets_for_draw'] ) : 0;
		$product->update_meta_data( '_rfwc_min_tickets_for_draw', (string) $min_tickets );

		// Handle textarea fields for compliance.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_rfwc_skill_choices'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( '_rfwc_skill_choices', sanitize_textarea_field( wp_unslash( $_POST['_rfwc_skill_choices'] ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['_rfwc_free_entry_text'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( '_rfwc_free_entry_text', sanitize_textarea_field( wp_unslash( $_POST['_rfwc_free_entry_text'] ) ) );
		}

		// Handle prize products array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_prize_products'] ) && is_array( $_POST['_rfwc_prize_products'] ) ) {
			$prize_products = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized with absint below.
			$raw_products = wp_unslash( $_POST['_rfwc_prize_products'] );
			foreach ( $raw_products as $tier => $product_id ) {
				$prize_products[ absint( $tier ) ] = absint( $product_id );
			}
			$product->update_meta_data( '_rfwc_prize_products', $prize_products );
		}

		// Handle tiered store credit amounts array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_prize_credits'] ) && is_array( $_POST['_rfwc_prize_credits'] ) ) {
			$prize_credits = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized below.
			$raw_credits = wp_unslash( $_POST['_rfwc_prize_credits'] );
			foreach ( $raw_credits as $tier => $amount ) {
				$prize_credits[ absint( $tier ) ] = floatval( $amount );
			}
			$product->update_meta_data( '_rfwc_prize_credits', $prize_credits );
		}

		// Handle tiered custom prize descriptions array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_prize_descriptions'] ) && is_array( $_POST['_rfwc_prize_descriptions'] ) ) {
			$prize_descriptions = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized below.
			$raw_descriptions = wp_unslash( $_POST['_rfwc_prize_descriptions'] );
			foreach ( $raw_descriptions as $tier => $desc ) {
				$prize_descriptions[ absint( $tier ) ] = sanitize_textarea_field( $desc );
			}
			$product->update_meta_data( '_rfwc_prize_descriptions', $prize_descriptions );
		}

		// Handle tiered custom prize values array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_prize_values'] ) && is_array( $_POST['_rfwc_prize_values'] ) ) {
			$prize_values = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized below.
			$raw_values = wp_unslash( $_POST['_rfwc_prize_values'] );
			foreach ( $raw_values as $tier => $value ) {
				$prize_values[ absint( $tier ) ] = floatval( $value );
			}
			$product->update_meta_data( '_rfwc_prize_values', $prize_values );
		}

		// Handle bulk discount tiers array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_bulk_discounts'] ) && is_array( $_POST['_rfwc_bulk_discounts'] ) ) {
			$bulk_discounts = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values sanitized below.
			$raw_tiers = wp_unslash( $_POST['_rfwc_bulk_discounts'] );
			foreach ( $raw_tiers as $tier ) {
				if ( ! is_array( $tier ) ) {
					continue;
				}
				$min_qty      = isset( $tier['min_qty'] ) ? absint( $tier['min_qty'] ) : 0;
				$discount_pct = isset( $tier['discount_pct'] ) ? floatval( $tier['discount_pct'] ) : 0;

				if ( $min_qty >= 2 && $discount_pct > 0 && $discount_pct < 100 ) {
					$bulk_discounts[] = array(
						'min_qty'      => $min_qty,
						'discount_pct' => $discount_pct,
					);
				}
			}

			// Sort by min_qty ascending.
			usort(
				$bulk_discounts,
				function ( $a, $b ) {
					return $a['min_qty'] - $b['min_qty'];
				}
			);

			$product->update_meta_data( '_rfwc_bulk_discounts', $bulk_discounts );
		} else {
			$product->update_meta_data( '_rfwc_bulk_discounts', array() );
		}

		// Handle redirect to checkout checkbox.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect_checkout = isset( $_POST['_rfwc_redirect_to_checkout'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_redirect_to_checkout', $redirect_checkout );

		// Handle instant win settings.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$iw_enabled = isset( $_POST['_rfwc_instant_win_enabled'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_instant_win_enabled', $iw_enabled );

		$iw_fields = array(
			'_rfwc_instant_win_mode',
			'_rfwc_instant_win_probability',
			'_rfwc_instant_win_interval',
			'_rfwc_instant_win_max_winners',
		);

		foreach ( $iw_fields as $iw_field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $iw_field ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$product->update_meta_data( $iw_field, sanitize_text_field( wp_unslash( $_POST[ $iw_field ] ) ) );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$iw_only = isset( $_POST['_rfwc_instant_win_only'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_instant_win_only', $iw_only );

		if ( 'yes' === $iw_enabled && 'yes' === $iw_only ) {
			$product->update_meta_data( '_rfwc_auto_draw', 'no' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$iw_online_payment = isset( $_POST['_rfwc_instant_win_require_online_payment'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rfwc_instant_win_require_online_payment', $iw_online_payment );

		// Handle disabled payment gateways multiselect.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['_rfwc_disabled_gateways'] ) && is_array( $_POST['_rfwc_disabled_gateways'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$disabled_gateways = array_map( 'sanitize_text_field', wp_unslash( $_POST['_rfwc_disabled_gateways'] ) );
			$product->update_meta_data( '_rfwc_disabled_gateways', $disabled_gateways );
		} else {
			$product->update_meta_data( '_rfwc_disabled_gateways', array() );
		}

		// Handle pinned pool question index from the dropdown.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved_source = isset( $_POST['_rfwc_skill_question_source'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfwc_skill_question_source'] ) ) : 'global';

		if ( 'custom' === $saved_source ) {
			// Clear pinned pool index when switching to custom source.
			$product->delete_meta_data( '_rfwc_skill_question_pool_index' );
		} elseif ( isset( $_POST['_rfwc_skill_question_pool_index'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Save the admin's chosen pool index (or empty for auto-assign on first visit).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$pool_index = sanitize_text_field( wp_unslash( $_POST['_rfwc_skill_question_pool_index'] ) );

			if ( '' === $pool_index ) {
				// Admin chose "auto-assign" — clear existing pinned index.
				$product->delete_meta_data( '_rfwc_skill_question_pool_index' );
			} else {
				$product->update_meta_data( '_rfwc_skill_question_pool_index', absint( $pool_index ) );
			}
		}

		/**
		 * Fires after core raffle product meta is processed but before save.
		 *
		 * Addons can hook here to save their own per-product meta fields
		 * added via the rfwc_admin_product_options action.
		 *
		 * @since 1.1.0
		 *
		 * @param int        $post_id Product post ID.
		 * @param WC_Product $product Product object (meta already updated, not yet saved).
		 */
		do_action( 'rfwc_process_product_meta', $post_id, $product );

		$product->set_virtual( true );
		$product->save();

		// Schedule or unschedule the precise auto-draw cron event.
		$final_auto_draw = $product->get_meta( '_rfwc_auto_draw', true );
		$final_end_date  = $product->get_meta( '_rfwc_end_date', true );

		if ( 'yes' === $final_auto_draw && $final_end_date ) {
			$final_end_time = $product->get_meta( '_rfwc_end_time', true );
			$draw_datetime  = $final_end_date . ' ' . ( $final_end_time ? $final_end_time : '23:59' );
			RFWC_Scheduler::schedule_draw( $post_id, $draw_datetime );
		} else {
			RFWC_Scheduler::unschedule_draw( $post_id );
		}
	}
}

