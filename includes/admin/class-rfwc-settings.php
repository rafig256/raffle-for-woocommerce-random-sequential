<?php
/**
 * Raffle settings in WooCommerce.
 *
 * Adds a Raffle tab to WooCommerce Settings with options for
 * ticket numbering, gift purchases, and Random.org API.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce settings tab for raffle configuration.
 *
 * @since 1.0.0
 */
class RFWC_Settings {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_raffle', array( $this, 'settings_tab_content' ) );
		add_action( 'woocommerce_update_options_raffle', array( $this, 'save_settings' ) );
		add_action( 'woocommerce_sections_raffle', array( $this, 'output_sections' ) );

		// Custom field type for the question pool repeater.
		add_action( 'woocommerce_admin_field_rfwc_question_pool', array( $this, 'output_question_pool_field' ) );
	}

	/**
	 * Add settings tab to WooCommerce settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tabs Settings tabs.
	 * @return array Modified tabs.
	 */
	public function add_settings_tab( $tabs ) {
		$tabs['raffle'] = __( 'Raffle', 'raffle-for-woocommerce' );
		return $tabs;
	}

	/**
	 * Get settings sections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sections.
	 */
	public function get_sections() {
		$sections = array(
			''             => __( 'General', 'raffle-for-woocommerce' ),
			'modules'      => __( 'Modules', 'raffle-for-woocommerce' ),
			'tickets'      => __( 'Tickets', 'raffle-for-woocommerce' ),
			'display'      => __( 'Display', 'raffle-for-woocommerce' ),
			'compliance'   => __( 'Compliance', 'raffle-for-woocommerce' ),
			'print_ticket' => __( 'Print Ticket', 'raffle-for-woocommerce' ),
			'emails'       => __( 'Emails', 'raffle-for-woocommerce' ),
		);

		return apply_filters( 'rfwc_get_sections', $sections );
	}

	/**
	 * Output sections navigation.
	 *
	 * @since 1.0.0
	 */
	public function output_sections() {
		global $current_section;

		$sections = $this->get_sections();

		if ( empty( $sections ) || 1 === count( $sections ) ) {
			return;
		}

		echo '<ul class="subsubsub">';

		$array_keys = array_keys( $sections );

		foreach ( $sections as $id => $label ) {
			$url       = admin_url( 'admin.php?page=wc-settings&tab=raffle&section=' . sanitize_title( $id ) );
			$class     = ( $current_section === $id ) ? 'current' : '';
			$separator = ( end( $array_keys ) === $id ) ? '' : '|';

			printf(
				'<li><a href="%s" class="%s">%s</a> %s</li>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				esc_html( $separator )
			);
		}

		echo '</ul><br class="clear" />';
	}

	/**
	 * Output settings tab content.
	 *
	 * @since 1.0.0
	 */
	public function settings_tab_content() {
		global $current_section;

		$settings = $this->get_settings( $current_section );

		WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings.
	 *
	 * @since 1.0.0
	 */
	public function save_settings() {
		global $current_section;

		$settings = $this->get_settings( $current_section );

		WC_Admin_Settings::save_fields( $settings );

		// Handle custom question pool save for the compliance section.
		if ( 'compliance' === $current_section ) {
			$this->save_question_pool();
		}
	}

	/**
	 * Save the question pool repeater data.
	 *
	 * @since 1.1.0
	 */
	private function save_question_pool() {
		$pool = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST['rfwc_question_pool'] ) && is_array( $_POST['rfwc_question_pool'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_pool = wp_unslash( $_POST['rfwc_question_pool'] );

			foreach ( $raw_pool as $item ) {
				$question = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
				$type     = isset( $item['type'] ) && 'multiple_choice' === $item['type'] ? 'multiple_choice' : 'text';
				$answer   = isset( $item['answer'] ) ? sanitize_text_field( $item['answer'] ) : '';
				$choices  = '';

				if ( 'multiple_choice' === $type && isset( $item['choices'] ) ) {
					$choices = sanitize_textarea_field( $item['choices'] );
				}

				// Only save entries that have both a question and an answer.
				if ( '' !== $question && '' !== $answer ) {
					$pool[] = array(
						'question' => $question,
						'type'     => $type,
						'answer'   => $answer,
						'choices'  => $choices,
					);
				}
			}
		}

		update_option( 'rfwc_skill_question_pool', $pool );
	}

	/**
	 * Get settings for a section.
	 *
	 * @since 1.0.0
	 *
	 * @param string $section Section ID.
	 * @return array Settings.
	 */
	public function get_settings( $section = '' ) {
		switch ( $section ) {
			case 'modules':
				$settings = $this->get_module_settings();
				break;

			case 'tickets':
				$settings = $this->get_ticket_settings();
				break;

			case 'display':
				$settings = $this->get_display_settings();
				break;

			case 'compliance':
				$settings = $this->get_compliance_settings();
				break;

			case 'print_ticket':
				$settings = $this->get_print_ticket_settings();
				break;

			case 'emails':
				$settings = $this->get_email_settings();
				break;

			default:
				$settings = $this->get_general_settings();
				break;
		}

		return apply_filters( 'rfwc_get_settings_' . $section, $settings );
	}

	/**
	 * Get general settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	private function get_general_settings() {
		return array(
			array(
				'title' => __( 'General Settings', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure general raffle settings.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_general_options',
			),

			array(
				'title'    => __( 'Enable Gift Purchase', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Allow customers to buy tickets for someone else', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_enable_gift_purchase',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, customers can enter a recipient name and email at checkout.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Allow Self-Claim in Gift Orders', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Add a "This ticket is for me" option to each recipient field at checkout', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_enable_gift_self_claim',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Lets buyers mix self and gift tickets in a single order. Self tickets count against the per-customer limit; gift tickets stay exempt. Classic Checkout only — Block Checkout is not supported yet.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Generate Tickets On', 'raffle-for-woocommerce' ),
				'desc'     => __( 'When to generate raffle tickets for an order', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_generate_tickets_on',
				'default'  => 'payment',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'payment'    => __( 'Payment complete', 'raffle-for-woocommerce' ),
					'processing' => __( 'Order processing', 'raffle-for-woocommerce' ),
					'completed'  => __( 'Order completed', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Select at which order status tickets should be generated.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Early Draw When Sold Out', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Automatically draw winners when all tickets are sold', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_auto_close_sold_out',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, raffles with auto-draw will immediately select winners once all tickets sell out instead of waiting for the end date. Per-product setting can override this.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Mixed Checkout', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Prevent raffle tickets and non-raffle products from being purchased in the same cart', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_block_mixed_checkout',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, adding a raffle product to a cart that contains non-raffle items (or vice versa) will show an error. This ensures clean checkout flows.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Guest Access', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Control whether non-logged-in users can purchase raffle tickets', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_guest_access',
				'default'  => 'allow',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'allow'       => __( 'Allow guests (current behavior)', 'raffle-for-woocommerce' ),
					'block'       => __( 'Block — show "Login required" message', 'raffle-for-woocommerce' ),
					'force_login' => __( 'Force login — redirect to login before add-to-cart', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Controls guest access to raffle purchases. "Allow" keeps the current behavior. "Block" shows a login message on the product page. "Force login" redirects guests to the login page.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_general_options',
			),

			array(
				'title' => __( 'Winner Selection', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure winner selection settings.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_winner_options',
			),

			array(
				'title'    => __( 'Default Selection Method', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Default method for automatic winner selection', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_default_selection_method',
				'default'  => 'secure_random',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'php_random'    => __( 'Standard Random', 'raffle-for-woocommerce' ),
					'secure_random' => __( 'Cryptographically Secure - Recommended', 'raffle-for-woocommerce' ),
					'random_org'    => __( 'Random.org (True Random)', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Choose the default randomization method for drawing winners.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Random.org API Key', 'raffle-for-woocommerce' ),
				'desc'     => sprintf(
					/* translators: %s: Random.org API URL */
					__( 'API key for Random.org true random number generation. Get one at %s', 'raffle-for-woocommerce' ),
					'<a href="https://api.random.org/api-keys" target="_blank">api.random.org</a>'
				),
				'id'       => 'rfwc_random_org_api_key',
				'default'  => '',
				'type'     => 'password',
				'desc_tip' => __( 'Optional. Required only if using Random.org for winner selection.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Allow Manual Winner Selection', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Allow admins to manually select a specific ticket as the winner', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_allow_manual_selection',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When disabled, the manual selection section is hidden from the draw page and manual selection AJAX requests are rejected. Only random draw methods are available.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_winner_options',
			),
		);
	}

	/**
	 * Get module toggle settings.
	 *
	 * Controls which modules are active store-wide. Only enabled
	 * modules show their product-level settings. Pro modules are
	 * appended via the `rfwc_get_settings_modules` filter.
	 *
	 * @since 1.1.0
	 *
	 * @return array Settings.
	 */
	private function get_module_settings() {
		$settings = array(
			array(
				'title' => __( 'Modules', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Enable or disable modules. Only enabled modules appear in the product editor and on the frontend.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_modules',
			),

			array(
				'title'   => __( 'Bulk/Tiered Pricing', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Volume discounts — e.g. 10% off for 5+ tickets', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_enable_bulk_discounts',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Instant Win', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Evaluate tickets at purchase time — customers find out immediately if they won', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_enable_instant_win',
				'default' => 'no',
				'type'    => 'checkbox',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_modules',
			),
		);

		return $settings;
	}

	/**
	 * Get ticket settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	private function get_ticket_settings() {
		return array(
			array(
				'title' => __( 'Ticket Settings', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure default ticket settings for new raffles.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_ticket_options',
			),

			array(
				'title'    => __( 'Ticket Number Format', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Default format for ticket numbers', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_ticket_number_format',
				'default'  => 'sequential',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => apply_filters(
					'rfwc_ticket_format_options',
					array(
						'sequential' => __( 'Sequential (000001, 000002...)', 'raffle-for-woocommerce' ),
						'random'     => __( 'Random (A7X9B2, K3M5N8...)', 'raffle-for-woocommerce' ),
					)
				),
				'desc_tip' => __( 'Choose how ticket numbers should be generated by default.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Ticket Prefix', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Default prefix for ticket numbers', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_ticket_prefix',
				'default'  => '',
				'type'     => 'text',
				'desc_tip' => __( 'Optional prefix added before ticket numbers (e.g., RAFFLE-).', 'raffle-for-woocommerce' ),
			),

			array(
				'title'             => __( 'Starting Number', 'raffle-for-woocommerce' ),
				'desc'              => __( 'First ticket number for sequential format', 'raffle-for-woocommerce' ),
				'id'                => 'rfwc_ticket_start_number',
				'default'           => '1',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'desc_tip'          => __( 'The starting number for sequential ticket generation.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'             => __( 'Number Padding', 'raffle-for-woocommerce' ),
				'desc'              => __( 'Pad sequential numbers with zeros', 'raffle-for-woocommerce' ),
				'id'                => 'rfwc_ticket_number_padding',
				'default'           => '6',
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 0,
					'max' => 10,
				),
				'desc_tip'          => __( 'Number of digits for padding (e.g., 6 = 000001).', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_ticket_options',
			),
		);
	}

	/**
	 * Get display settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	private function get_display_settings() {
		return array(
			array(
				'title' => __( 'Display Settings', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure how raffle information is displayed on the frontend.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_display_options',
			),

			array(
				'title'   => __( 'Show Countdown Timer', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Display countdown timer on raffle product pages', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_countdown',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'    => __( 'Show Countdown on Shop Pages', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Display a compact countdown badge on shop and archive pages', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_show_shop_countdown',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Shows a compact countdown timer below the price on shop/category pages for raffle products.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'   => __( 'Show Progress Bar', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Display ticket sales progress bar', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_progress_bar',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Show Participants Count', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Display number of participants', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_participants_count',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Show Tickets Sold', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Display number of tickets sold', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_tickets_sold',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Show Prize Value', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Display the prize value on product pages', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_prize_value',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'    => __( 'Show Instant Win Badge', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Display the Instant Win badge on raffle product pages', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_show_instant_win_badge',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Shows a prominent badge on instant win raffle products so customers know they can find out if they won immediately after purchase.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Use Range Slider for Quantity', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Replace the default quantity input with a styled range slider on raffle product pages', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_use_range_slider',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Shows a draggable slider with min/max range and a live value indicator instead of the standard number input.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Show Raffle Image on Winners', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Display the raffle product thumbnail next to winner information', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_show_winner_product_image',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Shows the raffle listing image beside each winner. Most useful when winners are displayed outside the product page via shortcode, so visitors can see which raffle it was.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Show Prize Image on Winners', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Display the prize product image next to winner information', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_show_winner_prize_image',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When the raffle prize is a WooCommerce product, shows the prize product image beside each winner. Helps visitors see what was actually won. Only applies when the prize type is set to "Product".', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Instant Win Winners Display', 'raffle-for-woocommerce' ),
				'desc'     => __( 'When to show instant win winners on the product page and winners shortcode', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_instant_win_winners_display',
				'default'  => 'always',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'always'            => __( 'Always show', 'raffle-for-woocommerce' ),
					'after_raffle_ends' => __( 'Only after raffle ends', 'raffle-for-woocommerce' ),
					'never'             => __( 'Never show', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Controls visibility of instant win winners on the frontend. "Only after raffle ends" hides winners while the raffle is still active — useful to avoid discouraging ticket purchases when all instant prizes have been claimed early. Winners still receive their notification email regardless of this setting.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Winner Location Display', 'raffle-for-woocommerce' ),
				'desc'     => __( 'What location information to show for winners on the frontend', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_winner_location_display',
				'default'  => 'city',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'city'       => __( 'City', 'raffle-for-woocommerce' ),
					'state'      => __( 'State / Province', 'raffle-for-woocommerce' ),
					'city_state' => __( 'City + State', 'raffle-for-woocommerce' ),
					'country'    => __( 'Country', 'raffle-for-woocommerce' ),
					'none'       => __( 'None (hide location)', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Controls which location is displayed for winners. "City" is the default. "State" is better for national raffles. "Country" for international.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Instant Win Badge Alignment', 'raffle-for-woocommerce' ),
				'desc_tip' => __( 'Controls how the Instant Win badge is positioned on the product page.', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_instant_win_badge_align',
				'default'  => 'center',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'center'     => __( 'Center', 'raffle-for-woocommerce' ),
					'left'       => __( 'Left', 'raffle-for-woocommerce' ),
					'right'      => __( 'Right', 'raffle-for-woocommerce' ),
					'full-width' => __( 'Full Width', 'raffle-for-woocommerce' ),
				),
			),

			array(
				'title'   => __( 'Show Instant Win Ribbon on Images', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Show an "Instant Win" ribbon on product images (like the WooCommerce Sale badge)', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_show_instant_win_ribbon',
				'default' => 'no',
				'type'    => 'checkbox',
			),

			array(
				'title'    => __( 'Ribbon Position', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_instant_win_ribbon_position',
				'default'  => 'top-left',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'top-left'  => __( 'Top Left', 'raffle-for-woocommerce' ),
					'top-right' => __( 'Top Right', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Top Left avoids overlap with the WooCommerce gallery zoom icon.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_display_options',
			),

			array(
				'title' => __( 'Accent Color', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Customize the accent color used across raffle elements on the frontend: countdown timer, progress bar, prize badges, compliance tabs, and more.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_accent_color_options',
			),

			array(
				'title'    => __( 'Frontend Accent Color', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Primary accent color for all raffle UI elements on the storefront', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_frontend_accent_color',
				'default'  => '#7f54b3',
				'type'     => 'color',
				'desc_tip' => __( 'This color is used for countdown boxes, progress bar, prize tier badges, compliance tabs, skill question focus, gift toggle, and card buttons. Lighter and darker shades are generated automatically.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_accent_color_options',
			),
		);
	}

	/**
	 * Get print ticket settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	private function get_print_ticket_settings() {
		return array(
			array(
				'title' => __( 'Print Ticket Appearance', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Customize the appearance of printable raffle tickets.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_print_ticket_options',
			),

			array(
				'title'    => __( 'Primary Color', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Main color used for ticket header and accents', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_ticket_primary_color',
				'default'  => '#7f54b3',
				'type'     => 'color',
				'desc_tip' => __( 'This color is used for the ticket header background and ticket number highlight.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Footer Text', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Custom message displayed at the bottom of the ticket', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_ticket_footer_text',
				'default'  => __( 'Keep this ticket safe. You will need it to claim your prize if you win.', 'raffle-for-woocommerce' ),
				'type'     => 'textarea',
				'css'      => 'width: 100%; height: 80px;',
				'desc_tip' => __( 'This text appears in the footer of the printable ticket.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Badge Text', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Text displayed in the header badge', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_ticket_badge_text',
				'default'  => __( 'RAFFLE TICKET', 'raffle-for-woocommerce' ),
				'type'     => 'text',
				'desc_tip' => __( 'The badge text shown in the top-right corner of the ticket.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_print_ticket_options',
			),
		);
	}

	/**
	 * Get compliance settings.
	 *
	 * @since 1.1.0
	 *
	 * @return array Settings.
	 */
	private function get_compliance_settings() {
		return array(
			array(
				'title' => __( 'Skill-Testing Question', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Require customers to answer a skill-testing question before adding raffle tickets to cart. This is a legal requirement in some jurisdictions.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_skill_question_options',
			),

			array(
				'title'    => __( 'Enable Skill Question', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Require a skill-testing question before purchase', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_enable_skill_question',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, customers must correctly answer a question before they can add raffle tickets to cart.', 'raffle-for-woocommerce' ),
			),

			array(
				'title' => __( 'Question Pool', 'raffle-for-woocommerce' ),
				'desc'  => __( 'Add one or more questions. A random question is shown to customers on each page load.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_skill_question_pool',
				'type'  => 'rfwc_question_pool',
			),

			array(
				'title'             => __( 'Time Limit (seconds)', 'raffle-for-woocommerce' ),
				'desc'              => __( 'Maximum seconds a customer has to answer the skill question. Leave empty or 0 for no limit.', 'raffle-for-woocommerce' ),
				'id'                => 'rfwc_skill_question_time_limit',
				'default'           => '',
				'type'              => 'number',
				'desc_tip'          => __( 'When set, a countdown timer appears next to the question. If time runs out, the customer must refresh to get a new question.', 'raffle-for-woocommerce' ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			),

			array(
				'title'             => __( 'Max Attempts Per Product', 'raffle-for-woocommerce' ),
				'desc'              => __( 'Maximum wrong answers before the customer is locked out of this raffle. Leave empty or 0 for unlimited.', 'raffle-for-woocommerce' ),
				'id'                => 'rfwc_skill_question_max_attempts',
				'default'           => '',
				'type'              => 'number',
				'desc_tip'          => __( 'Tracked per customer email and product. After exceeding the limit, the add-to-cart form is disabled with a message.', 'raffle-for-woocommerce' ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			),

			array(
				'title'    => __( 'Multiple Choice Display', 'raffle-for-woocommerce' ),
				'desc'     => __( 'How multiple choice answers are displayed on the product page', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_skill_question_mc_display',
				'default'  => 'radio',
				'type'     => 'select',
				'options'  => array(
					'radio'    => __( 'Radio buttons (default)', 'raffle-for-woocommerce' ),
					'dropdown' => __( 'Dropdown select', 'raffle-for-woocommerce' ),
				),
				'desc_tip' => __( 'Radio buttons show all options at once. Dropdown saves space when there are many choices.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_skill_question_options',
			),

			array(
				'title' => __( 'Free Entry Route', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Provide a "no purchase necessary" alternative entry method as required by UK regulations.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_free_entry_options',
			),

			array(
				'title'    => __( 'Enable Free Entry', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Show a free entry route on raffle product pages', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_enable_free_entry',
				'default'  => 'no',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, customers will see a tab to switch between paid entry and free entry instructions.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Free Entry Instructions', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Default instructions shown to customers for the free entry route. Use [Your Address] as a placeholder. It will be replaced with your WooCommerce store address automatically. Basic HTML is allowed.', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_free_entry_text',
				'default'  => __( 'To enter for free, send a postcard with your name, email address, and the raffle name to: [Your Address]. One entry per person. Entries must be received before the raffle end date.', 'raffle-for-woocommerce' ),
				'type'     => 'textarea',
				'css'      => 'width: 100%; height: 120px;',
				'desc_tip' => __( 'This text is displayed when the customer selects the Free Entry tab. Can be overridden per product.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_free_entry_options',
			),
		);
	}

	/**
	 * Output the question pool repeater field for WooCommerce settings.
	 *
	 * @since 1.1.0
	 *
	 * @param array $value Field data from the settings array.
	 */
	public function output_question_pool_field( $value ) {
		$pool = get_option( 'rfwc_skill_question_pool', array() );
		if ( ! is_array( $pool ) ) {
			$pool = array();
		}

		$field_id = esc_attr( $value['id'] );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div id="<?php echo esc_attr( $field_id ); ?>_wrapper" class="rfwc-question-pool-wrapper">
					<div class="rfwc-question-pool-items">
						<?php
						if ( ! empty( $pool ) ) {
							foreach ( $pool as $index => $item ) {
								$this->render_question_pool_item( $index, $item );
							}
						}
						?>
					</div>
					<button type="button" class="button rfwc-add-question" style="margin-top: 10px;">
						<?php esc_html_e( '+ Add Question', 'raffle-for-woocommerce' ); ?>
					</button>
					<?php if ( $value['desc'] ) : ?>
						<p class="description"><?php echo esc_html( $value['desc'] ); ?></p>
					<?php endif; ?>
				</div>

				<script type="text/html" id="tmpl-rfwc-question-pool-item">
					<?php $this->render_question_pool_item( '{{INDEX}}', array() ); ?>
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a single question pool item row.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string $index Index of the item.
	 * @param array      $item  Item data with question, type, answer, choices.
	 */
	private function render_question_pool_item( $index, $item ) {
		$question = isset( $item['question'] ) ? $item['question'] : '';
		$type     = isset( $item['type'] ) ? $item['type'] : 'text';
		$answer   = isset( $item['answer'] ) ? $item['answer'] : '';
		$choices  = isset( $item['choices'] ) ? $item['choices'] : '';
		?>
		<div class="rfwc-question-pool-item" style="border: 1px solid #dcdcde; padding: 12px; margin-bottom: 10px; border-radius: 4px; background: #f9f9f9;">
			<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
				<strong style="color: #1d2327;"><?php esc_html_e( 'Question', 'raffle-for-woocommerce' ); ?></strong>
				<button type="button" class="button-link rfwc-remove-question" style="color: #b32d2e; text-decoration: none;">
					<?php esc_html_e( 'Remove', 'raffle-for-woocommerce' ); ?>
				</button>
			</div>
			<p>
				<input type="text" name="rfwc_question_pool[<?php echo esc_attr( $index ); ?>][question]" value="<?php echo esc_attr( $question ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g., What is 12 + 7?', 'raffle-for-woocommerce' ); ?>" />
			</p>
			<p>
				<label><?php esc_html_e( 'Answer Type', 'raffle-for-woocommerce' ); ?></label><br>
				<select name="rfwc_question_pool[<?php echo esc_attr( $index ); ?>][type]" class="rfwc-question-type-select" style="width: 200px;">
					<option value="text" <?php selected( $type, 'text' ); ?>><?php esc_html_e( 'Text', 'raffle-for-woocommerce' ); ?></option>
					<option value="multiple_choice" <?php selected( $type, 'multiple_choice' ); ?>><?php esc_html_e( 'Multiple Choice', 'raffle-for-woocommerce' ); ?></option>
				</select>
			</p>
			<p>
				<label><?php esc_html_e( 'Correct Answer', 'raffle-for-woocommerce' ); ?></label><br>
				<input type="text" name="rfwc_question_pool[<?php echo esc_attr( $index ); ?>][answer]" value="<?php echo esc_attr( $answer ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g., 19', 'raffle-for-woocommerce' ); ?>" />
			</p>
			<p class="rfwc-question-choices-wrap" style="<?php echo 'multiple_choice' !== $type ? 'display: none;' : ''; ?>">
				<label><?php esc_html_e( 'Choices (one per line, include the correct answer)', 'raffle-for-woocommerce' ); ?></label><br>
				<textarea name="rfwc_question_pool[<?php echo esc_attr( $index ); ?>][choices]" rows="4" style="width: 100%;" placeholder="<?php esc_attr_e( "19\n21\n25\n30", 'raffle-for-woocommerce' ); ?>"><?php echo esc_textarea( $choices ); ?></textarea>
			</p>
		</div>
		<?php
	}

	/**
	 * Get email settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	private function get_email_settings() {
		return array(
			array(
				'title' => __( 'Email Settings', 'raffle-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure raffle email notifications. Emails use WooCommerce email templates.', 'raffle-for-woocommerce' ),
				'id'    => 'rfwc_email_options',
			),

			array(
				'title'   => __( 'Send Ticket Confirmation', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Send ticket details email to purchaser', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_email_ticket_confirmation',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'    => __( 'Send Gift Notification', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Send notification email to gift recipients', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_email_gift_notification',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When someone buys a ticket for another person, notify the recipient.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'   => __( 'Send Winner Notification', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Send notification email to raffle winners', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_email_winner_notification',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Admin Winner Alert', 'raffle-for-woocommerce' ),
				'desc'    => __( 'Send email to admin when a winner is selected', 'raffle-for-woocommerce' ),
				'id'      => 'rfwc_email_admin_winner',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'    => __( 'Admin Draw Failed Alert', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Send email to admin when an automatic raffle draw fails', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_email_admin_draw_failed',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Alerts the admin when a scheduled draw cannot complete (e.g. no eligible tickets).', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Send Prize Claimed Confirmation', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Send confirmation email to winners when their prize is marked as claimed', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_email_prize_claimed',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'When enabled, winners will receive an email confirming their prize has been claimed.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Send Ticket Refunded Notification', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Send notification email to ticket holders when their tickets are refunded', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_email_ticket_refunded',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'Notifies the purchaser or gift recipient when their raffle tickets are refunded.', 'raffle-for-woocommerce' ),
			),

			array(
				'title'    => __( 'Admin Mass Refund Summary', 'raffle-for-woocommerce' ),
				'desc'     => __( 'Send summary email to admin after a mass refund completes', 'raffle-for-woocommerce' ),
				'id'       => 'rfwc_email_admin_mass_refund',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => __( 'After a mass refund finishes processing, the admin receives a summary with order counts and totals.', 'raffle-for-woocommerce' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'rfwc_email_options',
			),
		);
	}
}
