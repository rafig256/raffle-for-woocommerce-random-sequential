<?php
/**
 * Automatic winner selection via WP-Cron.
 *
 * Checks for expired raffles and triggers automatic winner draws
 * when raffles are configured to select winners automatically.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs scheduled automatic winner draws via WP-Cron.
 *
 * @since 1.0.0
 */
class RFWC_Scheduler {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Schedule cron events on plugin load.
		add_action( 'init', array( $this, 'schedule_events' ) );

		// Handle scheduled winner selection.
		add_action( 'rfwc_check_expired_raffles', array( $this, 'process_expired_raffles' ) );

		// Handle individual raffle drawing.
		add_action( 'rfwc_auto_draw_raffle', array( $this, 'auto_draw_raffle' ), 10, 1 );
	}

	/**
	 * Schedule recurring events.
	 *
	 * @since 1.0.0
	 */
	public function schedule_events() {
		// Schedule hourly check for expired raffles.
		if ( ! wp_next_scheduled( 'rfwc_check_expired_raffles' ) ) {
			wp_schedule_event( time(), 'hourly', 'rfwc_check_expired_raffles' );
		}
	}

	/**
	 * Process expired raffles.
	 *
	 * Finds raffles that have ended and need winner selection.
	 *
	 * @since 1.0.0
	 */
	public function process_expired_raffles() {
		// Get raffles that have ended and haven't been drawn.
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => 'raffle',
				),
			),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => '_rfwc_auto_draw',
					'value'   => 'yes',
					'compare' => '=',
				),
				array(
					'key'     => '_rfwc_status',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$raffles = get_posts( $args );

		foreach ( $raffles as $raffle ) {
			$product = wc_get_product( $raffle->ID );

			if ( ! $product || 'raffle' !== $product->get_type() ) {
				continue;
			}

			// Check if raffle has ended.
			if ( ! $product->has_ended() ) {
				continue;
			}

			// Check if already has draw winners (instant win records should not block).
			if ( RFWC_Winner::raffle_has_draw_winners( $raffle->ID ) ) {
				continue;
			}

			// Schedule single event for this raffle draw.
			if ( ! wp_next_scheduled( 'rfwc_auto_draw_raffle', array( $raffle->ID ) ) ) {
				wp_schedule_single_event( time(), 'rfwc_auto_draw_raffle', array( $raffle->ID ) );
			}
		}
	}

	/**
	 * Automatically draw winners for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle product ID.
	 */
	public function auto_draw_raffle( $raffle_id ) {
		$product = wc_get_product( $raffle_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			$this->log_error( $raffle_id, 'Invalid raffle product.' );
			return;
		}

		/**
		 * Filter whether the automatic per-raffle draw should run.
		 *
		 * Extensions can return false to suppress the per-raffle draw,
		 * for example when this raffle belongs to a master drawing that
		 * handles winner selection across multiple raffles.
		 *
		 * @since 1.1.13
		 *
		 * @param bool $should_run Whether the auto draw should run for this raffle.
		 * @param int  $raffle_id  Raffle product ID.
		 */
		if ( false === apply_filters( 'rfwc_should_auto_draw', true, $raffle_id ) ) {
			return;
		}

		$logger = wc_get_logger();
		$ctx    = array( 'source' => 'raffle-for-woocommerce' );

		// Allow early draw for sold-out raffles; otherwise require ended.
		$is_early_draw = $product->is_sold_out() && ! $product->has_ended();
		if ( ! $product->has_ended() && ! $is_early_draw ) {
			$logger->debug( sprintf( 'Auto-draw skipped for raffle #%d: raffle has not ended yet.', $raffle_id ), $ctx );
			return;
		}

		// Check if already has draw winners (instant win records should not block).
		if ( RFWC_Winner::raffle_has_draw_winners( $raffle_id ) ) {
			$logger->debug( sprintf( 'Auto-draw skipped for raffle #%d: draw winners already exist.', $raffle_id ), $ctx );
			return;
		}

		// Must not already have a terminal status.
		$raffle_status = $product->get_meta( '_rfwc_status', true );
		if ( in_array( $raffle_status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			$logger->debug( sprintf( 'Auto-draw skipped for raffle #%d: terminal status "%s".', $raffle_id, $raffle_status ), $ctx );
			return;
		}

		// Skip auto-draw for instant-win-only raffles.
		if ( 'yes' === $product->get_meta( '_rfwc_instant_win_only', true ) ) {
			$logger->debug( sprintf( 'Auto-draw skipped for raffle #%d: instant-win-only raffle.', $raffle_id ), $ctx );
			return;
		}

		// Check minimum tickets threshold.
		$min_threshold = absint( $product->get_meta( '_rfwc_min_tickets_for_draw', true ) );
		if ( $min_threshold > 0 ) {
			$tickets_sold = $product->get_tickets_sold();
			if ( $tickets_sold < $min_threshold ) {
				$fail_message = sprintf(
					/* translators: 1: tickets sold, 2: minimum threshold */
					__( 'Auto-draw skipped: only %1$d of %2$d minimum tickets sold. Raffle marked as failed.', 'raffle-for-woocommerce' ),
					$tickets_sold,
					$min_threshold
				);

				$this->log_error( $raffle_id, $fail_message );

				$product->update_meta_data( '_rfwc_status', 'failed' );
				$product->save();

				if ( function_exists( 'WC' ) && WC()->mailer() ) {
					do_action( 'rfwc_draw_failed', $raffle_id, $fail_message, 'min_threshold_not_met' );
				}

				return;
			}
		}

		// Get number of winners and selection method.
		$num_winners = absint( $product->get_meta( '_rfwc_number_of_winners' ) );
		$num_winners = $num_winners > 0 ? $num_winners : 1;
		$method      = get_option( 'rfwc_default_selection_method', 'secure_random' );

		// Perform the draw.
		$result = RFWC_Winner_Selector::select_winners( $raffle_id, $num_winners, $method, true );

		if ( is_wp_error( $result ) ) {
			$this->log_error( $raffle_id, $result->get_error_message() );

			// Mark raffle as failed so the cron doesn't retry it endlessly.
			$product->update_meta_data( '_rfwc_status', 'failed' );
			$product->save();

			// Notify admin about the failed draw.
			if ( function_exists( 'WC' ) && WC()->mailer() ) {
				/**
				 * Triggers admin notification when a raffle draw fails.
				 *
				 * @since 1.1.0
				 *
				 * @param int    $raffle_id     Raffle product ID.
				 * @param string $error_message Error message.
				 * @param string $error_code    Error code.
				 */
				do_action( 'rfwc_draw_failed', $raffle_id, $result->get_error_message(), $result->get_error_code() );
			}

			return;
		}

		// Log success.
		$this->log_success(
			$raffle_id,
			sprintf(
				/* translators: %d: number of winners */
				_n(
					'Automatic draw completed: %d winner selected.',
					'Automatic draw completed: %d winners selected.',
					count( $result ),
					'raffle-for-woocommerce'
				),
				count( $result )
			)
		);
	}

	/**
	 * Schedule a single raffle draw.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $datetime  Datetime string for when to draw.
	 */
	public static function schedule_draw( $raffle_id, $datetime ) {
		// Parse datetime in WordPress timezone and convert to UTC timestamp
		// for wp_schedule_single_event().
		try {
			$dt        = new DateTime( $datetime, wp_timezone() );
			$timestamp = $dt->getTimestamp();
		} catch ( Exception $e ) {
			$timestamp = strtotime( $datetime );
		}

		if ( ! $timestamp || $timestamp < time() ) {
			return false;
		}

		// Clear any existing scheduled draws for this raffle.
		self::unschedule_draw( $raffle_id );

		// Schedule the new draw.
		return wp_schedule_single_event( $timestamp, 'rfwc_auto_draw_raffle', array( $raffle_id ) );
	}

	/**
	 * Unschedule a raffle draw.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 */
	public static function unschedule_draw( $raffle_id ) {
		$timestamp = wp_next_scheduled( 'rfwc_auto_draw_raffle', array( $raffle_id ) );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rfwc_auto_draw_raffle', array( $raffle_id ) );
		}
	}

	/**
	 * Log error to raffle audit trail.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $message   Error message.
	 */
	private function log_error( $raffle_id, $message ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			esc_sql( $wpdb->prefix . 'rfwc_log' ),
			array(
				'raffle_id' => $raffle_id,
				'action'    => 'auto_draw_error',
				'details'   => $message,
				'user_id'   => 0,
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Log success to raffle audit trail.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raffle_id Raffle ID.
	 * @param string $message   Success message.
	 */
	private function log_success( $raffle_id, $message ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			esc_sql( $wpdb->prefix . 'rfwc_log' ),
			array(
				'raffle_id' => $raffle_id,
				'action'    => 'auto_draw_success',
				'details'   => $message,
				'user_id'   => 0,
			),
			array( '%d', '%s', '%s', '%d' )
		);
	}
}
