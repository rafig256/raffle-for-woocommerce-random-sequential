<?php
/**
 * Storefront display for raffle products.
 *
 * Renders countdown timers, ticket progress bars, prize details,
 * and add to cart forms on raffle product pages.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend class.
 *
 * @since 1.0.0
 */
class RFWC_Frontend {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks for frontend functionality.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Enqueue frontend scripts and styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add raffle data to product page.
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_instant_win_badge' ), 14 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_raffle_countdown' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_raffle_progress' ), 16 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_raffle_prize' ), 17 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_raffle_winners' ), 18 );
		if ( rfwc_feature_enabled( 'rfwc_enable_bulk_discounts' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'display_bulk_discount_tiers' ), 19 );
		}

		// Compact countdown on shop/archive pages.
		if ( 'yes' === get_option( 'rfwc_show_shop_countdown', 'no' ) ) {
			add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'display_shop_countdown' ), 15 );
		}

		// Instant Win ribbon on product images (like WooCommerce's Sale! badge).
		if ( 'yes' === get_option( 'rfwc_show_instant_win_ribbon', 'no' ) ) {
			add_action( 'woocommerce_product_thumbnails', array( $this, 'render_instant_win_ribbon' ) );
			add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_instant_win_ribbon' ), 9 );
		}

		// Use custom add to cart template for raffle products.
		add_action( 'woocommerce_raffle_add_to_cart', array( $this, 'add_to_cart_template' ) );

		// Modify add to cart button text.
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'add_to_cart_text' ), 10, 2 );

		// Add body class for raffle products.
		add_filter( 'body_class', array( $this, 'add_body_class' ) );

		// Template loader for custom templates.
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 10, 3 );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		$shop_countdown = 'yes' === get_option( 'rfwc_show_shop_countdown', 'no' );
		$show_ribbon    = 'yes' === get_option( 'rfwc_show_instant_win_ribbon', 'no' );

		// [product_page id="X"] renders the full single-product template on a
		// regular page where is_product() returns false. Detect that case so
		// the countdown JS and picker still get their assets.
		$has_raffle_shortcode = ! empty( rfwc_get_current_raffle_ids() ) && ! is_product();

		// Load CSS on WooCommerce pages and shop/archive/front page when shop countdown or ribbon is enabled.
		$load_css = is_product() || is_cart() || is_checkout() || is_account_page() || $has_raffle_shortcode;
		if ( ( $shop_countdown || $show_ribbon ) && ( is_shop() || is_product_taxonomy() || is_front_page() ) ) {
			$load_css = true;
		}

		if ( $load_css ) {
			wp_enqueue_style(
				'rfwc-frontend',
				RFWC_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				RFWC_VERSION
			);

			$this->maybe_add_accent_color_css();
		}

		// Load JS on product pages and shop/archive/front page when shop countdown is enabled.
		$load_js = is_product() || $has_raffle_shortcode;
		if ( $shop_countdown && ( is_shop() || is_product_taxonomy() || is_front_page() ) ) {
			$load_js = true;
		}

		if ( ! $load_js ) {
			return;
		}

		$this->enqueue_countdown_script();
	}

	/**
	 * Enqueue the countdown script and localization.
	 *
	 * Can be called from enqueue_scripts or from shortcode rendering.
	 *
	 * @since 1.0.4
	 */
	public function enqueue_countdown_script() {
		// Avoid double-enqueuing.
		if ( wp_script_is( 'rfwc-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'rfwc-frontend',
			RFWC_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			RFWC_VERSION
		);

		$this->maybe_add_accent_color_css();

		wp_enqueue_script(
			'rfwc-frontend',
			RFWC_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			RFWC_VERSION,
			true
		);

		wp_localize_script(
			'rfwc-frontend',
			'rfwc_params',
			array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'rfwc-frontend-nonce' ),
				'currency_symbol'  => html_entity_decode( get_woocommerce_currency_symbol() ),
				'use_range_slider'          => get_option( 'rfwc_use_range_slider', 'no' ),
				'skill_question_time_limit' => absint( get_option( 'rfwc_skill_question_time_limit', 0 ) ),
				'i18n'             => array(
					'days'     => __( 'Days', 'raffle-for-woocommerce' ),
					'hours'    => __( 'Hours', 'raffle-for-woocommerce' ),
					'minutes'  => __( 'Minutes', 'raffle-for-woocommerce' ),
					'seconds'  => __( 'Seconds', 'raffle-for-woocommerce' ),
					'ended'    => __( 'Ended', 'raffle-for-woocommerce' ),
					'started'  => __( 'Raffle has started', 'raffle-for-woocommerce' ),
					'you_save'     => __( 'You save', 'raffle-for-woocommerce' ),
					'time_expired' => __( 'Time expired. Please refresh the page to try again.', 'raffle-for-woocommerce' ),
				),
			)
		);

		/**
		 * Fires after core frontend scripts and styles are enqueued.
		 *
		 * Addons can hook here to enqueue additional frontend JS/CSS.
		 *
		 * @since 1.1.0
		 */
		do_action( 'rfwc_frontend_scripts' );
	}

	/**
	 * Display "Instant Win!" badge on product page for instant win raffles.
	 *
	 * @since 1.1.0
	 */
	public function display_instant_win_badge() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( 'yes' !== get_option( 'rfwc_show_instant_win_badge', 'yes' ) ) {
			return;
		}

		if ( 'yes' !== $product->get_meta( '_rfwc_instant_win_enabled', true ) ) {
			return;
		}

		if ( ! $product->is_active() ) {
			return;
		}

		/**
		 * Filters additional CSS classes on the instant win badge.
		 *
		 * @since 1.1.0
		 *
		 * @param string $class Extra CSS class(es) to append.
		 */
		$badge_extra_class = apply_filters( 'rfwc_badge_class', '' );
		$badge_align       = get_option( 'rfwc_instant_win_badge_align', 'center' );
		$badge_class       = 'rfwc-instant-win-badge rfwc-badge-align-' . $badge_align;
		if ( $badge_extra_class ) {
			$badge_class .= ' ' . esc_attr( $badge_extra_class );
		}

		echo '<div class="' . esc_attr( $badge_class ) . '">';
		echo '<span class="rfwc-iw-badge-title">';
		echo '<span class="rfwc-iw-badge-icon">&#9889;</span>';
		echo '<span class="rfwc-iw-badge-text">' . esc_html__( 'Instant Win!', 'raffle-for-woocommerce' ) . '</span>';
		echo '</span>';
		echo '<small class="rfwc-iw-badge-sub">' . esc_html__( 'Find out if you won immediately after purchase', 'raffle-for-woocommerce' ) . '</small>';
		echo '</div>';
	}

	/**
	 * Render the instant win ribbon on product images.
	 *
	 * Outputs a small overlay tag on the product image, similar to
	 * WooCommerce's "Sale!" badge. Uses `woocommerce_product_thumbnails`
	 * for single product (inside the gallery wrapper) and
	 * `woocommerce_before_shop_loop_item_title` for archive/shop pages.
	 *
	 * @since 1.2.0
	 */
	public function render_instant_win_ribbon() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( 'yes' !== $product->get_meta( '_rfwc_instant_win_enabled', true ) ) {
			return;
		}

		if ( ! $product->is_active() ) {
			return;
		}

		$position = get_option( 'rfwc_instant_win_ribbon_position', 'top-left' );
		$text     = apply_filters( 'rfwc_instant_win_ribbon_text', __( 'Instant Win', 'raffle-for-woocommerce' ) );

		printf(
			'<span class="rfwc-iw-ribbon rfwc-iw-ribbon--%s">%s</span>',
			esc_attr( $position ),
			esc_html( $text )
		);
	}

	/**
	 * Display countdown timer on product page.
	 *
	 * Shows time remaining until raffle starts or ends, depending on current status.
	 *
	 * @since 1.0.0
	 */
	public function display_raffle_countdown() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( 'yes' !== get_option( 'rfwc_show_countdown', 'yes' ) ) {
			return;
		}

		$end_timestamp   = $product->get_end_timestamp();
		$start_timestamp = $product->get_start_timestamp();
		$has_started     = $product->has_started();
		$has_ended       = $product->has_ended();

		// Also treat the raffle as ended if it was completed, failed, or cancelled
		// (before the scheduled end date). The _rfwc_status meta is set by the
		// winner selector, scheduler, or admin manual actions.
		if ( ! $has_ended ) {
			$status = $product->get_meta( '_rfwc_status', true );
			if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
				$has_ended = true;
			}
		}

		// Pass variables to template.
		wc_get_template(
			'single-product/raffle-countdown.php',
			array(
				'product'         => $product,
				'end_timestamp'   => $end_timestamp,
				'start_timestamp' => $start_timestamp,
				'has_started'     => $has_started,
				'has_ended'       => $has_ended,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Display compact countdown timer on shop/archive pages.
	 *
	 * Renders a slim inline countdown badge for raffle products in the shop loop.
	 * Also ensures the countdown JS is enqueued — the hook can fire on pages
	 * (homepage, custom pages) where enqueue_scripts() didn't preload assets.
	 *
	 * @since 1.0.4
	 */
	public function display_shop_countdown() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		$this->enqueue_countdown_script();
		$this->render_compact_countdown( $product );
	}

	/**
	 * Display full countdown timer for a specific product.
	 *
	 * Used by shortcodes to render the full 4-box countdown on non-product pages.
	 *
	 * @since 1.0.4
	 *
	 * @param RFWC_Product_Raffle $product Raffle product object.
	 */
	public function display_raffle_countdown_for( $product ) {
		$end_timestamp   = $product->get_end_timestamp();
		$start_timestamp = $product->get_start_timestamp();
		$has_started     = $product->has_started();
		$has_ended       = $product->has_ended();

		// Check for completed/failed/cancelled status.
		if ( ! $has_ended ) {
			$status = $product->get_meta( '_rfwc_status', true );
			if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
				$has_ended = true;
			}
		}

		wc_get_template(
			'single-product/raffle-countdown.php',
			array(
				'product'         => $product,
				'end_timestamp'   => $end_timestamp,
				'start_timestamp' => $start_timestamp,
				'has_started'     => $has_started,
				'has_ended'       => $has_ended,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Render compact countdown template for a raffle product.
	 *
	 * Reusable by shop loop display and shortcodes.
	 *
	 * @since 1.0.4
	 *
	 * @param RFWC_Product_Raffle $product Raffle product object.
	 */
	public function render_compact_countdown( $product ) {
		$end_timestamp   = $product->get_end_timestamp();
		$start_timestamp = $product->get_start_timestamp();
		$has_started     = $product->has_started();
		$has_ended       = $product->has_ended();

		$rfwc_status  = $product->get_meta( '_rfwc_status', true );
		$is_completed = 'completed' === $rfwc_status;
		$is_terminal  = in_array( $rfwc_status, array( 'completed', 'failed', 'cancelled' ), true );

		if ( $is_terminal && ! $has_ended ) {
			$has_ended = true;
		}

		wc_get_template(
			'loop/raffle-countdown.php',
			array(
				'product'         => $product,
				'end_timestamp'   => $end_timestamp,
				'start_timestamp' => $start_timestamp,
				'has_started'     => $has_started,
				'has_ended'       => $has_ended,
				'is_completed'    => $is_completed,
				'rfwc_status'     => $rfwc_status,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Display progress bar on product page.
	 *
	 * Shows ticket sales progress with sold and remaining counts.
	 *
	 * @since 1.0.0
	 */
	public function display_raffle_progress() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( 'yes' !== get_option( 'rfwc_show_progress_bar', 'yes' ) ) {
			return;
		}

		$total      = $product->get_total_tickets();
		$sold       = $product->get_tickets_sold();
		$available  = $product->get_available_tickets();
		$percentage = $product->get_progress_percentage();

		wc_get_template(
			'single-product/raffle-progress.php',
			array(
				'product'    => $product,
				'total'      => $total,
				'sold'       => $sold,
				'available'  => $available,
				'percentage' => $percentage,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Display prize information on product page.
	 *
	 * Shows prize description and optional monetary value for all prize tiers.
	 *
	 * @since 1.0.0
	 */
	public function display_raffle_prize() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		$prize_type  = $product->get_prize_type();
		$num_winners = $product->get_number_of_winners();
		$show_value  = 'yes' === get_option( 'rfwc_show_prize_value', 'yes' );

		// Build prize data for all tiers.
		$prize_descriptions = array();
		$prize_values       = array();
		$prize_credits      = array();

		for ( $tier = 1; $tier <= $num_winners; $tier++ ) {
			$prize_descriptions[ $tier ] = $product->get_prize_description( $tier );
			$prize_values[ $tier ]       = $product->get_prize_value( $tier );
			$prize_credits[ $tier ]      = $product->get_prize_credit_amount( $tier );
		}

		// For backward compatibility, also pass single values (tier 1).
		$prize_description = $product->get_prize_description();
		$prize_value       = $product->get_prize_value();

		// Exit early if nothing to display for custom type.
		if ( 'custom' === $prize_type ) {
			$has_description = false;
			$has_value       = false;
			foreach ( $prize_descriptions as $desc ) {
				if ( ! empty( $desc ) ) {
					$has_description = true;
					break;
				}
			}
			foreach ( $prize_values as $val ) {
				if ( $val > 0 ) {
					$has_value = true;
					break;
				}
			}
			if ( ! $has_description && ( ! $show_value || ! $has_value ) ) {
				return;
			}
		}

		wc_get_template(
			'single-product/raffle-prize.php',
			array(
				'product'            => $product,
				'prize_description'  => $prize_description,
				'prize_value'        => $prize_value,
				'prize_descriptions' => $prize_descriptions,
				'prize_values'       => $prize_values,
				'prize_credits'      => $prize_credits,
				'show_value'         => $show_value,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Display raffle winners on product page.
	 *
	 * Shows the winners after a raffle has been drawn.
	 *
	 * @since 1.0.0
	 */
	public function display_raffle_winners() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( ! RFWC_Winner::raffle_has_winners( $product->get_id() ) ) {
			return;
		}

		$winners = RFWC_Winner::get_by_raffle( $product->get_id() );

		if ( empty( $winners ) ) {
			return;
		}

		$instant_winners = array();
		$draw_winners    = array();

		foreach ( $winners as $winner ) {
			if ( 'instant_win' === $winner->get_selection_method() ) {
				$instant_winners[] = $winner;
			} else {
				$draw_winners[] = $winner;
			}
		}

		$iw_display = get_option( 'rfwc_instant_win_winners_display', 'always' );

		if ( 'never' === $iw_display ) {
			$display_instant = false;
		} elseif ( 'after_raffle_ends' === $iw_display ) {
			$display_instant = $product->has_ended();
		} else {
			$display_instant = true;
		}

		/**
		 * Filter whether to display the instant winners section on the product page.
		 *
		 * @since 1.1.0
		 *
		 * @param bool        $display Whether to display instant winners. Default based on setting.
		 * @param WC_Product  $product The raffle product.
		 * @param array       $instant_winners Array of instant RFWC_Winner objects.
		 */
		$show_instant_winners = apply_filters( 'rfwc_display_instant_winners', $display_instant, $product, $instant_winners );

		if ( ! $show_instant_winners ) {
			$instant_winners = array();
		}

		wc_get_template(
			'single-product/raffle-winners.php',
			array(
				'product'         => $product,
				'winners'         => $winners,
				'instant_winners' => $instant_winners,
				'draw_winners'    => $draw_winners,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Display bulk discount tiers on the product page.
	 *
	 * Shows a compact pricing table so customers see the volume incentive
	 * before choosing a quantity. Also outputs a data attribute used by
	 * the frontend JS for dynamic price previews.
	 *
	 * @since 1.0.8
	 */
	public function display_bulk_discount_tiers() {
		global $product;

		if ( ! $product instanceof \WC_Product || 'raffle' !== $product->get_type() ) {
			return;
		}

		if ( ! $product->is_active() ) {
			return;
		}

		$tiers = $product->get_bulk_discounts();

		if ( empty( $tiers ) ) {
			return;
		}

		$base_price     = (float) $product->get_price();
		$currency       = get_woocommerce_currency_symbol();
		$tiers_json     = wp_json_encode( $tiers );
		$price_decimals = wc_get_price_decimals();
		?>
		<div class="rfwc-bulk-tiers" data-tiers="<?php echo esc_attr( $tiers_json ); ?>" data-base-price="<?php echo esc_attr( $base_price ); ?>" data-decimals="<?php echo esc_attr( $price_decimals ); ?>">
			<p class="rfwc-bulk-tiers-title"><?php esc_html_e( 'Buy more, save more!', 'raffle-for-woocommerce' ); ?></p>
			<div class="rfwc-bulk-tiers-list">
				<?php
				foreach ( $tiers as $tier ) :
					$discounted = round( $base_price * ( 1 - $tier['discount_pct'] / 100 ), $price_decimals );
					?>
					<div class="rfwc-bulk-tier-item" data-min="<?php echo esc_attr( $tier['min_qty'] ); ?>">
						<span class="rfwc-bulk-tier-qty">
							<?php
							printf(
								/* translators: %d: minimum quantity for the tier */
								esc_html__( '%d+ tickets', 'raffle-for-woocommerce' ),
								absint( $tier['min_qty'] )
							);
							?>
						</span>
						<span class="rfwc-bulk-tier-discount">
							<?php
							printf(
								/* translators: %s: discount percentage */
								esc_html__( '%s%% off', 'raffle-for-woocommerce' ),
								esc_html( $tier['discount_pct'] )
							);
							?>
						</span>
						<span class="rfwc-bulk-tier-price"><?php echo wp_kses_post( wc_price( $discounted ) ); ?> <?php esc_html_e( 'each', 'raffle-for-woocommerce' ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="rfwc-bulk-price-preview rfwc-hidden"></div>
		</div>
		<?php
	}

	/**
	 * Output the add to cart template for raffle products.
	 *
	 * This is hooked to woocommerce_raffle_add_to_cart action.
	 *
	 * @since 1.0.0
	 */
	public function add_to_cart_template() {
		global $product;

		// Guest access gate: "block" hides the form and shows a login prompt.
		// "force_login" lets the form render but the server-side validation
		// in validate_add_to_cart() will redirect to login on submit.
		if ( ! is_user_logged_in() && 'block' === get_option( 'rfwc_guest_access', 'allow' ) ) {
			$login_url = wp_login_url( get_permalink() );

			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$myaccount_url = wc_get_page_permalink( 'myaccount' );
				if ( $myaccount_url ) {
					$login_url = add_query_arg( 'redirect_to', rawurlencode( get_permalink() ), $myaccount_url );
				}
			}

			echo '<div class="rfwc-guest-login-notice">';
			echo '<p>' . esc_html__( 'You must be logged in to purchase raffle tickets.', 'raffle-for-woocommerce' ) . '</p>';
			echo '<a href="' . esc_url( $login_url ) . '" class="button rfwc-login-button">' . esc_html__( 'Log in to enter', 'raffle-for-woocommerce' ) . '</a>';
			echo '</div>';
			return;
		}

		wc_get_template(
			'single-product/add-to-cart/raffle.php',
			array( 'product' => $product ),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Modify add to cart button text for raffle products.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text    Default button text.
	 * @param WC_Product $product Product object.
	 * @return string Modified button text for raffle products.
	 */
	public function add_to_cart_text( $text, $product ) {
		if ( 'raffle' !== $product->get_type() ) {
			return $text;
		}

		// If the raffle has ended or winners selected, show "View Raffle" on archive pages.
		if ( ! $product->is_active() ) {
			return __( 'View Raffle', 'raffle-for-woocommerce' );
		}

		return __( 'Buy Ticket', 'raffle-for-woocommerce' );
	}

	/**
	 * Add body class for raffle product pages.
	 *
	 * @since 1.0.0
	 *
	 * @param array $classes Existing body classes.
	 * @return array Modified body classes.
	 */
	public function add_body_class( $classes ) {
		if ( is_product() ) {
			global $post;

			// Get product object - global $product might not be set yet during body_class.
			$product = wc_get_product( $post );

			if ( $product && 'raffle' === $product->get_type() ) {
				$classes[] = 'rfwc-raffle-product';

				$rfwc_status = $product->get_meta( '_rfwc_status', true );

				if ( 'cancelled' === $rfwc_status ) {
					$classes[] = 'rfwc-raffle-ended';
					$classes[] = 'rfwc-raffle-cancelled';
				} elseif ( 'failed' === $rfwc_status ) {
					$classes[] = 'rfwc-raffle-ended';
					$classes[] = 'rfwc-raffle-failed';
				} elseif ( $product->has_ended() || 'completed' === $rfwc_status ) {
					$classes[] = 'rfwc-raffle-ended';
				} elseif ( ! $product->has_started() ) {
					$classes[] = 'rfwc-raffle-not-started';
				} elseif ( $product->is_sold_out() ) {
					$classes[] = 'rfwc-raffle-sold-out';
				} else {
					$classes[] = 'rfwc-raffle-active';
				}
			}
		}
		return $classes;
	}

	/**
	 * Locate plugin templates before theme templates.
	 *
	 * Allows themes to override plugin templates by placing them in
	 * woocommerce/raffle-for-woocommerce/ directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template      Located template path.
	 * @param string $template_name Template name.
	 * @param string $template_path Template path.
	 * @return string Modified template path.
	 */
	public function locate_template( $template, $template_name, $template_path ) {
		// Only handle our templates.
		if ( strpos( $template_name, 'raffle' ) === false ) {
			return $template;
		}

		// Check in theme first.
		$theme_template = locate_template(
			array(
				trailingslashit( $template_path ) . $template_name,
				'raffle-for-woocommerce/' . $template_name,
			)
		);

		if ( $theme_template ) {
			return $theme_template;
		}

		// Check in plugin.
		$plugin_template = RFWC_PLUGIN_PATH . 'templates/' . $template_name;

		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Add inline CSS to override accent color custom properties when customized.
	 *
	 * Reads the rfwc_frontend_accent_color option and, if it differs from the
	 * default, generates a :root block with the accent color and all derived
	 * tints/shades. The frontend.css file ships with sensible defaults, so this
	 * inline override is only needed when the store owner picks a custom color.
	 *
	 * @since 1.0.7
	 */
	private function maybe_add_accent_color_css() {
		$accent  = get_option( 'rfwc_frontend_accent_color', '#7f54b3' );
		$default = '#7f54b3';

		// Normalize to lowercase for comparison.
		$accent = strtolower( trim( $accent ) );

		if ( $accent === $default ) {
			return;
		}

		$rgb = $this->hex_to_rgb( $accent );

		if ( false === $rgb ) {
			return;
		}

		list( $r, $g, $b ) = $rgb;

		$dark         = $this->darken_color( $rgb, 0.16 );
		$light        = $this->lighten_color( $rgb, 0.22 );
		$darker       = $this->darken_color( $rgb, 0.28 );
		$bg_light     = $this->lighten_color( $rgb, 0.95 );
		$bg_medium    = $this->lighten_color( $rgb, 0.90 );
		$border_solid = $this->lighten_color( $rgb, 0.82 );
		$bg_hover     = $this->lighten_color( $rgb, 0.97 );

		$css = sprintf(
			':root {
	--rfwc-accent: %s;
	--rfwc-accent-rgb: %d, %d, %d;
	--rfwc-accent-dark: %s;
	--rfwc-accent-light: %s;
	--rfwc-accent-darker: %s;
	--rfwc-accent-bg-light: %s;
	--rfwc-accent-bg-medium: %s;
	--rfwc-accent-border-solid: %s;
	--rfwc-accent-bg-hover: %s;
}',
			$accent,
			$r,
			$g,
			$b,
			$this->rgb_to_hex( $dark ),
			$this->rgb_to_hex( $light ),
			$this->rgb_to_hex( $darker ),
			$this->rgb_to_hex( $bg_light ),
			$this->rgb_to_hex( $bg_medium ),
			$this->rgb_to_hex( $border_solid ),
			$this->rgb_to_hex( $bg_hover )
		);

		wp_add_inline_style( 'rfwc-frontend', $css );
	}

	/**
	 * Convert a hex color string to an RGB array.
	 *
	 * @since 1.0.7
	 *
	 * @param string $hex Hex color (with or without leading #).
	 * @return array|false Array of [ R, G, B ] or false on invalid input.
	 */
	private function hex_to_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return false;
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Darken an RGB color by a given factor.
	 *
	 * @since 1.0.7
	 *
	 * @param array $rgb    RGB array [ R, G, B ].
	 * @param float $amount Factor between 0 and 1 (0.15 = 15% darker).
	 * @return array Darkened RGB array.
	 */
	private function darken_color( $rgb, $amount ) {
		return array(
			max( 0, (int) round( $rgb[0] * ( 1 - $amount ) ) ),
			max( 0, (int) round( $rgb[1] * ( 1 - $amount ) ) ),
			max( 0, (int) round( $rgb[2] * ( 1 - $amount ) ) ),
		);
	}

	/**
	 * Lighten an RGB color by mixing with white.
	 *
	 * @since 1.0.7
	 *
	 * @param array $rgb    RGB array [ R, G, B ].
	 * @param float $amount Factor between 0 and 1 (0.95 = 95% toward white).
	 * @return array Lightened RGB array.
	 */
	private function lighten_color( $rgb, $amount ) {
		return array(
			min( 255, (int) round( $rgb[0] + ( 255 - $rgb[0] ) * $amount ) ),
			min( 255, (int) round( $rgb[1] + ( 255 - $rgb[1] ) * $amount ) ),
			min( 255, (int) round( $rgb[2] + ( 255 - $rgb[2] ) * $amount ) ),
		);
	}

	/**
	 * Convert an RGB array to a hex color string.
	 *
	 * @since 1.0.7
	 *
	 * @param array $rgb RGB array [ R, G, B ].
	 * @return string Hex color string (e.g. #7f54b3).
	 */
	private function rgb_to_hex( $rgb ) {
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
