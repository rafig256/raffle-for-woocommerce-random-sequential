<?php
/**
 * Shortcodes for displaying raffle data on any page.
 *
 * Provides [raffle_card], [raffle_cards], [raffle_countdown],
 * [raffle_progress], [raffle_prize], [raffle_status], and [raffle_winners] shortcodes.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes class.
 *
 * @since 1.0.4
 */
class RFWC_Shortcodes {

	/**
	 * Frontend instance for asset enqueuing.
	 *
	 * @var RFWC_Frontend
	 */
	private $frontend;

	/**
	 * Constructor.
	 *
	 * @since 1.0.4
	 *
	 * @param RFWC_Frontend $frontend Frontend instance.
	 */
	public function __construct( $frontend ) {
		$this->frontend = $frontend;
		$this->register_shortcodes();
	}

	/**
	 * Register all shortcodes.
	 *
	 * @since 1.0.4
	 */
	private function register_shortcodes() {
		add_shortcode( 'raffle_card', array( $this, 'shortcode_card' ) );
		add_shortcode( 'raffle_cards', array( $this, 'shortcode_cards' ) );
		add_shortcode( 'raffle_countdown', array( $this, 'shortcode_countdown' ) );
		add_shortcode( 'raffle_progress', array( $this, 'shortcode_progress' ) );
		add_shortcode( 'raffle_prize', array( $this, 'shortcode_prize' ) );
		add_shortcode( 'raffle_status', array( $this, 'shortcode_status' ) );
		add_shortcode( 'raffle_winners', array( $this, 'shortcode_winners' ) );
	}

	/**
	 * Get a raffle product by ID or keyword.
	 *
	 * Supports numeric IDs, "latest", and "active" keywords.
	 *
	 * @since 1.0.4
	 *
	 * @param string|int $id Product ID or keyword.
	 * @return RFWC_Product_Raffle|null Product or null.
	 */
	private function get_raffle_product( $id ) {
		if ( empty( $id ) ) {
			// Try to auto-detect from WooCommerce product loop.
			global $product;
			if ( $product instanceof \WC_Product && 'raffle' === $product->get_type() ) {
				return $product;
			}
			return null;
		}

		// Support "latest" and "active" keywords.
		if ( 'latest' === $id || 'active' === $id ) {
			return $this->get_raffle_by_keyword( $id );
		}

		$rfwc_product = wc_get_product( absint( $id ) );

		if ( ! $rfwc_product || 'raffle' !== $rfwc_product->get_type() ) {
			return null;
		}

		return $rfwc_product;
	}

	/**
	 * Get raffle by keyword (latest or active).
	 *
	 * @since 1.0.4
	 *
	 * @param string $keyword "latest" or "active".
	 * @return RFWC_Product_Raffle|null Product or null.
	 */
	private function get_raffle_by_keyword( $keyword ) {
		$args = array(
			'type'    => 'raffle',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => 'publish',
		);

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return null;
		}

		if ( 'active' === $keyword ) {
			// Find the first active raffle.
			foreach ( $products as $product ) {
				if ( $product->is_active() ) {
					return $product;
				}
			}
			// If no active raffle found, query more.
			$args['limit'] = 20;
			$products      = wc_get_products( $args );
			foreach ( $products as $product ) {
				if ( $product->is_active() ) {
					return $product;
				}
			}
			return null;
		}

		return $products[0];
	}

	/**
	 * Find the most recently drawn raffle that has at least one winner.
	 *
	 * @since 1.0.10
	 *
	 * @return RFWC_Product_Raffle|null Product or null.
	 */
	private function get_latest_raffle_with_winners() {
		global $wpdb;

		$winners_table = esc_sql( $wpdb->prefix . 'rfwc_winners' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raffle_id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a known safe prefix constant.
			"SELECT raffle_id FROM {$winners_table} ORDER BY created_at DESC LIMIT 1"
		);

		if ( ! $raffle_id ) {
			return null;
		}

		$product = wc_get_product( $raffle_id );
		if ( $product && 'raffle' === $product->get_type() ) {
			return $product;
		}

		return null;
	}

	/**
	 * [raffle_card] — Full product card with image, countdown, prize, and CTA.
	 *
	 * @since 1.0.4
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_card( $atts ) {
		$atts = shortcode_atts(
			array( 'id' => '' ),
			$atts,
			'raffle_card'
		);

		$product = $this->get_raffle_product( $atts['id'] );
		if ( ! $product ) {
			return '';
		}

		$this->frontend->enqueue_countdown_script();

		return $this->render_single_card( $product );
	}

	/**
	 * [raffle_cards] — Display multiple raffle cards in a responsive grid.
	 *
	 * Usage examples:
	 *   [raffle_cards ids="123,456,789"]
	 *   [raffle_cards status="active" limit="6" columns="3"]
	 *   [raffle_cards category="prizes" limit="4" orderby="date"]
	 *   [raffle_cards status="all" columns="2" orderby="random"]
	 *
	 * @since 1.0.5
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_cards( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'      => '',       // Comma-separated product IDs.
				'category' => '',       // Product category slug.
				'status'   => 'active', // active | ended | all.
				'limit'    => '6',      // Max cards to show.
				'columns'  => '3',      // Grid columns (1-4).
				'orderby'  => 'date',   // date | price | title | random.
				'order'    => 'DESC',   // ASC | DESC.
			),
			$atts,
			'raffle_cards'
		);

		$products = $this->query_raffle_products( $atts );

		if ( empty( $products ) ) {
			return '<p class="rfwc-cards-empty">' . esc_html__( 'No raffles found.', 'raffle-for-woocommerce' ) . '</p>';
		}

		$this->frontend->enqueue_countdown_script();

		$columns = max( 1, min( 4, absint( $atts['columns'] ) ) );

		ob_start();
		?>
		<div class="rfwc-cards-grid rfwc-cards-grid--cols-<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $products as $product ) : ?>
				<?php echo $this->render_single_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_single_card returns escaped HTML. ?>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Query raffle products based on shortcode attributes.
	 *
	 * @since 1.0.5
	 *
	 * @param array $atts Parsed shortcode attributes.
	 * @return RFWC_Product_Raffle[] Array of raffle products.
	 */
	private function query_raffle_products( $atts ) {
		// If specific IDs are provided, fetch them directly.
		if ( ! empty( $atts['ids'] ) ) {
			return $this->get_raffles_by_ids( $atts['ids'] );
		}

		$limit = max( 1, min( 50, absint( $atts['limit'] ) ) );
		$order = in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true )
			? strtoupper( $atts['order'] )
			: 'DESC';

		$args = array(
			'type'    => 'raffle',
			'status'  => 'publish',
			'limit'   => $limit,
			'order'   => $order,
			'orderby' => 'date',
			'return'  => 'objects',
		);

		// Map orderby parameter.
		switch ( $atts['orderby'] ) {
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'title':
				$args['orderby'] = 'title';
				break;
			case 'random':
				$args['orderby'] = 'rand';
				break;
			default:
				$args['orderby'] = 'date';
				break;
		}

		// Filter by category.
		if ( ! empty( $atts['category'] ) ) {
			$args['category'] = array_map( 'sanitize_title', explode( ',', $atts['category'] ) );
		}

		$products = wc_get_products( $args );

		// Filter by status if needed.
		$status = sanitize_text_field( $atts['status'] );
		if ( 'all' !== $status && ! empty( $products ) ) {
			$products = array_filter(
				$products,
				function ( $product ) use ( $status ) {
					return $this->product_matches_status( $product, $status );
				}
			);
			$products = array_values( $products ); // Re-index.
		}

		return $products;
	}

	/**
	 * Get raffle products by comma-separated IDs.
	 *
	 * Preserves the order specified in the IDs string.
	 *
	 * @since 1.0.5
	 *
	 * @param string $ids_string Comma-separated product IDs.
	 * @return RFWC_Product_Raffle[] Array of raffle products.
	 */
	private function get_raffles_by_ids( $ids_string ) {
		$ids      = array_map( 'absint', explode( ',', $ids_string ) );
		$ids      = array_filter( $ids ); // Remove zeros.
		$products = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && 'raffle' === $product->get_type() && 'publish' === $product->get_status() ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Check if a raffle product matches the requested status filter.
	 *
	 * @since 1.0.5
	 *
	 * @param RFWC_Product_Raffle $product The raffle product.
	 * @param string              $status  Status filter (active, ended).
	 * @return bool
	 */
	private function product_matches_status( $product, $status ) {
		$rfwc_status = $product->get_meta( '_rfwc_status', true );
		$is_terminal = in_array( $rfwc_status, array( 'completed', 'failed', 'cancelled' ), true );

		switch ( $status ) {
			case 'active':
				return ! $product->has_ended() && ! $is_terminal;
			case 'ended':
			case 'completed':
				return $product->has_ended() || $is_terminal;
			default:
				return true;
		}
	}

	/**
	 * Render a single raffle card HTML.
	 *
	 * Shared between [raffle_card] (single) and [raffle_cards] (grid).
	 *
	 * @since 1.0.5
	 *
	 * @param RFWC_Product_Raffle $product The raffle product.
	 * @return string Card HTML.
	 */
	private function render_single_card( $product ) {
		$status      = $product->get_meta( '_rfwc_status', true );
		$is_terminal = in_array( $status, array( 'completed', 'failed', 'cancelled' ), true );
		$has_ended   = $product->has_ended() || $is_terminal;
		$permalink   = get_permalink( $product->get_id() );
		$image       = $product->get_image( 'woocommerce_thumbnail' );
		$prize_desc  = $product->get_prize_description();

		ob_start();
		?>
		<div class="rfwc-card">
			<a href="<?php echo esc_url( $permalink ); ?>" class="rfwc-card-image">
				<?php echo wp_kses_post( $image ); ?>
			</a>
			<div class="rfwc-card-body">
				<h3 class="rfwc-card-title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
				</h3>
				<div class="rfwc-card-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
				<?php $this->frontend->render_compact_countdown( $product ); ?>
				<?php if ( ! empty( $prize_desc ) ) : ?>
					<div class="rfwc-card-prize">
						<strong><?php esc_html_e( 'Prize:', 'raffle-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $prize_desc ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $has_ended ) : ?>
					<span class="rfwc-card-button rfwc-card-button--ended"><?php esc_html_e( 'Ended', 'raffle-for-woocommerce' ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( $permalink ); ?>" class="rfwc-card-button"><?php esc_html_e( 'Buy Ticket', 'raffle-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [raffle_countdown] — Countdown timer (compact or full).
	 *
	 * @since 1.0.4
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_countdown( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => '',
				'format' => 'compact',
			),
			$atts,
			'raffle_countdown'
		);

		$product = $this->get_raffle_product( $atts['id'] );
		if ( ! $product ) {
			return '';
		}

		$this->frontend->enqueue_countdown_script();

		ob_start();

		if ( 'full' === $atts['format'] ) {
			$this->frontend->display_raffle_countdown_for( $product );
		} else {
			$this->frontend->render_compact_countdown( $product );
		}

		return ob_get_clean();
	}

	/**
	 * [raffle_progress] — Ticket sales progress bar.
	 *
	 * @since 1.0.4
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_progress( $atts ) {
		$atts = shortcode_atts(
			array( 'id' => '' ),
			$atts,
			'raffle_progress'
		);

		$product = $this->get_raffle_product( $atts['id'] );
		if ( ! $product ) {
			return '';
		}

		$this->frontend->enqueue_countdown_script();

		$total      = $product->get_total_tickets();
		$sold       = $product->get_tickets_sold();
		$available  = $product->get_available_tickets();
		$percentage = $product->get_progress_percentage();

		ob_start();
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
		return ob_get_clean();
	}

	/**
	 * [raffle_prize] — Prize information display.
	 *
	 * @since 1.0.4
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_prize( $atts ) {
		$atts = shortcode_atts(
			array( 'id' => '' ),
			$atts,
			'raffle_prize'
		);

		$product = $this->get_raffle_product( $atts['id'] );
		if ( ! $product ) {
			return '';
		}

		$this->frontend->enqueue_countdown_script();

		$num_winners        = $product->get_number_of_winners();
		$show_value         = 'yes' === get_option( 'rfwc_show_prize_value', 'yes' );
		$prize_descriptions = array();
		$prize_values       = array();
		$prize_credits      = array();

		for ( $tier = 1; $tier <= $num_winners; $tier++ ) {
			$prize_descriptions[ $tier ] = $product->get_prize_description( $tier );
			$prize_values[ $tier ]       = $product->get_prize_value( $tier );
			$prize_credits[ $tier ]      = $product->get_prize_credit_amount( $tier );
		}

		ob_start();
		wc_get_template(
			'single-product/raffle-prize.php',
			array(
				'product'            => $product,
				'prize_description'  => $product->get_prize_description(),
				'prize_value'        => $product->get_prize_value(),
				'prize_descriptions' => $prize_descriptions,
				'prize_values'       => $prize_values,
				'prize_credits'      => $prize_credits,
				'show_value'         => $show_value,
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
		return ob_get_clean();
	}

	/**
	 * [raffle_status] — Status badge (Active / Ended / Winner Selected).
	 *
	 * @since 1.0.4
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_status( $atts ) {
		$atts = shortcode_atts(
			array( 'id' => '' ),
			$atts,
			'raffle_status'
		);

		$product = $this->get_raffle_product( $atts['id'] );
		if ( ! $product ) {
			return '';
		}

		$status = $product->get_meta( '_rfwc_status', true );

		if ( 'cancelled' === $status ) {
			$label = __( 'Cancelled', 'raffle-for-woocommerce' );
			$class = 'rfwc-countdown-compact--ended';
		} elseif ( 'failed' === $status ) {
			$label = __( 'Failed', 'raffle-for-woocommerce' );
			$class = 'rfwc-countdown-compact--ended';
		} elseif ( 'completed' === $status ) {
			$label = __( 'Winner Selected', 'raffle-for-woocommerce' );
			$class = 'rfwc-countdown-compact--completed';
		} elseif ( $product->has_ended() ) {
			$label = __( 'Ended', 'raffle-for-woocommerce' );
			$class = 'rfwc-countdown-compact--ended';
		} elseif ( ! $product->has_started() ) {
			$label = __( 'Coming Soon', 'raffle-for-woocommerce' );
			$class = '';
		} elseif ( $product->is_sold_out() ) {
			$label = __( 'Sold Out', 'raffle-for-woocommerce' );
			$class = 'rfwc-countdown-compact--ended';
		} else {
			$label = __( 'Active', 'raffle-for-woocommerce' );
			$class = '';
		}

		$this->frontend->enqueue_countdown_script();

		return sprintf(
			'<span class="rfwc-countdown-compact %s"><span class="rfwc-countdown-compact-label">%s</span></span>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * [raffle_winners] — Display winner announcement banner for a raffle.
	 *
	 * Usage examples:
	 *   [raffle_winners id="123"]
	 *   [raffle_winners id="latest" show_avatar="true" show_city="true"]
	 *   [raffle_winners id="123" limit="3" show_link="false"]
	 *
	 * @since 1.0.10
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_winners( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'          => '',
				'show_avatar' => 'true',
				'show_city'   => 'true',
				'show_ticket' => 'true',
				'show_date'   => 'true',
				'show_link'   => 'true',
				'limit'       => '0',
			),
			$atts,
			'raffle_winners'
		);

		$product = $this->get_raffle_product( $atts['id'] );

		// When no ID is provided and auto-detect fails, find the latest raffle with winners.
		if ( ! $product && empty( $atts['id'] ) ) {
			$product = $this->get_latest_raffle_with_winners();
		}

		if ( ! $product ) {
			return '';
		}

		if ( ! RFWC_Winner::raffle_has_winners( $product->get_id() ) ) {
			return '';
		}

		$winners = RFWC_Winner::get_by_raffle( $product->get_id() );
		if ( empty( $winners ) ) {
			return '';
		}

		$limit = absint( $atts['limit'] );
		if ( $limit > 0 ) {
			$winners = array_slice( $winners, 0, $limit );
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

		/** This filter is documented in includes/class-rfwc-frontend.php */
		$show_instant = apply_filters( 'rfwc_display_instant_winners', true, $product, $instant_winners );
		if ( ! $show_instant ) {
			$instant_winners = array();
		}

		$this->frontend->enqueue_countdown_script();

		ob_start();
		wc_get_template(
			'single-product/raffle-winners.php',
			array(
				'product'         => $product,
				'winners'         => $winners,
				'instant_winners' => $instant_winners,
				'draw_winners'    => $draw_winners,
				'show_avatar'     => filter_var( $atts['show_avatar'], FILTER_VALIDATE_BOOLEAN ),
				'show_city'       => filter_var( $atts['show_city'], FILTER_VALIDATE_BOOLEAN ),
				'show_ticket'     => filter_var( $atts['show_ticket'], FILTER_VALIDATE_BOOLEAN ),
				'show_date'       => filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN ),
				'show_link'       => filter_var( $atts['show_link'], FILTER_VALIDATE_BOOLEAN ),
			),
			'',
			RFWC_PLUGIN_PATH . 'templates/'
		);
		return ob_get_clean();
	}
}
