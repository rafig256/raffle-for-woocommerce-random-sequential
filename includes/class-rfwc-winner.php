<?php
/**
 * Raffle winner data model.
 *
 * Stores and retrieves winner data from the rfwc_winners database table.
 * Links winning tickets to raffles and tracks prize fulfillment status.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raffle winner data model with database operations.
 *
 * @since 1.0.0
 */
class RFWC_Winner {

	/**
	 * Winner ID.
	 *
	 * @var int
	 */
	private $id = 0;

	/**
	 * Raffle ID.
	 *
	 * @var int
	 */
	private $raffle_id = 0;

	/**
	 * Ticket ID.
	 *
	 * @var int
	 */
	private $ticket_id = 0;

	/**
	 * Prize tier (1 = first prize, 2 = second, etc.).
	 *
	 * @var int
	 */
	private $prize_tier = 1;

	/**
	 * Selection method (manual, php_random, secure_random, random_org).
	 *
	 * @var string
	 */
	private $selection_method = 'manual';

	/**
	 * Selection proof (random seed, API response, etc.).
	 *
	 * @var string
	 */
	private $selection_proof = '';

	/**
	 * Whether prize has been claimed.
	 *
	 * @var bool
	 */
	private $claimed = false;

	/**
	 * Claimed timestamp.
	 *
	 * @var string|null
	 */
	private $claimed_at = null;

	/**
	 * Whether winner has been notified.
	 *
	 * @var bool
	 */
	private $notified = false;

	/**
	 * Notification timestamp.
	 *
	 * @var string|null
	 */
	private $notified_at = null;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	private $created_at = '';

	/**
	 * Prize order ID (for product prizes).
	 *
	 * @var int
	 */
	private $prize_order_id = 0;

	/**
	 * Prize coupon code (for store credit prizes).
	 *
	 * @var string
	 */
	private $prize_coupon_code = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int|object $winner Winner ID or database row object.
	 */
	public function __construct( $winner = 0 ) {
		if ( is_numeric( $winner ) && $winner > 0 ) {
			$this->read( $winner );
		} elseif ( is_object( $winner ) ) {
			$this->set_props_from_object( $winner );
		}
	}

	/**
	 * Get the table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name (escaped for SQL).
	 */
	public static function get_table_name() {
		global $wpdb;
		return esc_sql( $wpdb->prefix . 'rfwc_winners' );
	}

	/**
	 * Read winner from database.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Winner ID.
	 */
	private function read( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE id = %d', absint( $id ) )
		);

		if ( $row ) {
			$this->set_props_from_object( $row );
		}
	}

	/**
	 * Set properties from database object.
	 *
	 * @since 1.0.0
	 *
	 * @param object $obj Database row object.
	 */
	private function set_props_from_object( $obj ) {
		$this->id                = (int) $obj->id;
		$this->raffle_id         = (int) $obj->raffle_id;
		$this->ticket_id         = (int) $obj->ticket_id;
		$this->prize_tier        = (int) $obj->prize_tier;
		$this->selection_method  = $obj->selection_method;
		$this->selection_proof   = $obj->selection_proof;
		$this->claimed           = (bool) $obj->claimed;
		$this->claimed_at        = $obj->claimed_at;
		$this->notified          = (bool) $obj->notified;
		$this->notified_at       = $obj->notified_at;
		$this->prize_order_id    = isset( $obj->prize_order_id ) ? (int) $obj->prize_order_id : 0;
		$this->prize_coupon_code = isset( $obj->prize_coupon_code ) ? $obj->prize_coupon_code : '';
		$this->created_at        = $obj->created_at;
	}

	/**
	 * Save winner to database.
	 *
	 * @since 1.0.0
	 *
	 * @return int|false Winner ID or false on failure.
	 */
	public function save() {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . 'rfwc_winners' );
		$data       = array(
			'raffle_id'         => $this->raffle_id,
			'ticket_id'         => $this->ticket_id,
			'prize_tier'        => $this->prize_tier,
			'selection_method'  => $this->selection_method,
			'selection_proof'   => $this->selection_proof,
			'claimed'           => $this->claimed ? 1 : 0,
			'claimed_at'        => $this->claimed_at,
			'notified'          => $this->notified ? 1 : 0,
			'notified_at'       => $this->notified_at,
			'prize_order_id'    => $this->prize_order_id,
			'prize_coupon_code' => $this->prize_coupon_code,
		);

		$format = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' );

		if ( $this->id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table_name, $data, array( 'id' => $this->id ), $format, array( '%d' ) );
			return false !== $result ? $this->id : false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table_name, $data, $format );

		if ( $result ) {
			$this->id = $wpdb->insert_id;
			return $this->id;
		}

		return false;
	}

	/**
	 * Delete winner from database.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public function delete() {
		global $wpdb;

		if ( ! $this->id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( esc_sql( $wpdb->prefix . 'rfwc_winners' ), array( 'id' => $this->id ), array( '%d' ) );
	}

	// Getters.

	/**
	 * Get winner ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get raffle ID.
	 *
	 * @return int
	 */
	public function get_raffle_id() {
		return $this->raffle_id;
	}

	/**
	 * Get ticket ID.
	 *
	 * @return int
	 */
	public function get_ticket_id() {
		return $this->ticket_id;
	}

	/**
	 * Get prize tier.
	 *
	 * @return int
	 */
	public function get_prize_tier() {
		return $this->prize_tier;
	}

	/**
	 * Get selection method.
	 *
	 * @return string
	 */
	public function get_selection_method() {
		return $this->selection_method;
	}

	/**
	 * Get selection proof.
	 *
	 * @return string
	 */
	public function get_selection_proof() {
		return $this->selection_proof;
	}

	/**
	 * Check if prize is claimed.
	 *
	 * @return bool
	 */
	public function is_claimed() {
		return $this->claimed;
	}

	/**
	 * Get claimed timestamp.
	 *
	 * @return string|null
	 */
	public function get_claimed_at() {
		return $this->claimed_at;
	}

	/**
	 * Check if winner is notified.
	 *
	 * @return bool
	 */
	public function is_notified() {
		return $this->notified;
	}

	/**
	 * Get notification timestamp.
	 *
	 * @return string|null
	 */
	public function get_notified_at() {
		return $this->notified_at;
	}

	/**
	 * Get creation timestamp.
	 *
	 * @return string
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Get the winning ticket object.
	 *
	 * @return RFWC_Ticket|null
	 */
	public function get_ticket() {
		if ( ! $this->ticket_id ) {
			return null;
		}
		return new RFWC_Ticket( $this->ticket_id );
	}

	/**
	 * Get the raffle product.
	 *
	 * @return RFWC_Product_Raffle|null
	 */
	public function get_raffle() {
		if ( ! $this->raffle_id ) {
			return null;
		}
		return wc_get_product( $this->raffle_id );
	}

	// Setters.

	/**
	 * Set raffle ID.
	 *
	 * @param int $raffle_id Raffle ID.
	 */
	public function set_raffle_id( $raffle_id ) {
		$this->raffle_id = (int) $raffle_id;
	}

	/**
	 * Set ticket ID.
	 *
	 * @param int $ticket_id Ticket ID.
	 */
	public function set_ticket_id( $ticket_id ) {
		$this->ticket_id = (int) $ticket_id;
	}

	/**
	 * Set prize tier.
	 *
	 * @param int $tier Prize tier.
	 */
	public function set_prize_tier( $tier ) {
		$this->prize_tier = (int) $tier;
	}

	/**
	 * Set selection method.
	 *
	 * @param string $method Selection method.
	 */
	public function set_selection_method( $method ) {
		$this->selection_method = sanitize_text_field( $method );
	}

	/**
	 * Set selection proof.
	 *
	 * @param string $proof Selection proof data.
	 */
	public function set_selection_proof( $proof ) {
		$this->selection_proof = $proof;
	}

	/**
	 * Set claimed status.
	 *
	 * @param bool $claimed Claimed status.
	 */
	public function set_claimed( $claimed ) {
		$this->claimed = (bool) $claimed;
		if ( $claimed && ! $this->claimed_at ) {
			$this->claimed_at = current_time( 'mysql' );
		}
	}

	/**
	 * Set notified status.
	 *
	 * @param bool $notified Notified status.
	 */
	public function set_notified( $notified ) {
		$this->notified = (bool) $notified;
		if ( $notified && ! $this->notified_at ) {
			$this->notified_at = current_time( 'mysql' );
		}
	}

	/**
	 * Get prize order ID.
	 *
	 * @return int Prize order ID.
	 */
	public function get_prize_order_id() {
		return $this->prize_order_id;
	}

	/**
	 * Set prize order ID.
	 *
	 * @param int $order_id Order ID.
	 */
	public function set_prize_order_id( $order_id ) {
		$this->prize_order_id = (int) $order_id;
	}

	/**
	 * Get prize coupon code.
	 *
	 * @return string Coupon code.
	 */
	public function get_prize_coupon_code() {
		return $this->prize_coupon_code;
	}

	/**
	 * Set prize coupon code.
	 *
	 * @param string $code Coupon code.
	 */
	public function set_prize_coupon_code( $code ) {
		$this->prize_coupon_code = sanitize_text_field( $code );
	}

	/**
	 * Get the prize order object.
	 *
	 * @return WC_Order|null Order object or null.
	 */
	public function get_prize_order() {
		if ( ! $this->prize_order_id ) {
			return null;
		}
		return wc_get_order( $this->prize_order_id );
	}

	// Static query methods.

	/**
	 * Get winners by raffle ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return RFWC_Winner[] Array of winners.
	 */
	public static function get_by_raffle( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE raffle_id = %d ORDER BY prize_tier ASC, created_at ASC',
				absint( $raffle_id )
			)
		);

		$winners = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$winners[] = new self( $row );
			}
		}

		return $winners;
	}

	/**
	 * Get winner by ticket ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $ticket_id Ticket ID.
	 *
	 * @return RFWC_Winner|null Winner or null.
	 */
	public static function get_by_ticket( $ticket_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE ticket_id = %d', absint( $ticket_id ) )
		);

		return $row ? new self( $row ) : null;
	}

	/**
	 * Check if a raffle has winners (any type, including instant win).
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return bool True if raffle has winners.
	 */
	public static function raffle_has_winners( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE raffle_id = %d', absint( $raffle_id ) )
		);

		return $count > 0;
	}

	/**
	 * Check if a raffle has main-draw winners (excludes instant win).
	 *
	 * Use this guard to decide whether the main draw has already run.
	 * Instant win records should never block the scheduled/manual draw.
	 *
	 * @since 1.1.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return bool True if raffle has draw (non-instant-win) winners.
	 */
	public static function raffle_has_draw_winners( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . " WHERE raffle_id = %d AND selection_method != 'instant_win'",
				absint( $raffle_id )
			)
		);

		return $count > 0;
	}

	/**
	 * Get winner count for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return int Winner count.
	 */
	public static function get_winner_count( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$winner_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'rfwc_winners' ) . ' WHERE raffle_id = %d', absint( $raffle_id ) )
		);
		return $winner_count;
	}

	/**
	 * Get all winners across all raffles with optional filtering.
	 *
	 * @since 1.0.4
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $raffle_id Filter by raffle ID.
	 *     @type string $claimed   Filter: 'yes', 'no', or '' (all).
	 *     @type int    $limit     Max results.
	 * }
	 * @return RFWC_Winner[] Array of winner objects.
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'raffle_id' => 0,
			'claimed'   => '',
			'search'    => '',
			'limit'     => 200,
		);
		$args     = wp_parse_args( $args, $defaults );

		$winners_table = esc_sql( $wpdb->prefix . 'rfwc_winners' );
		$tickets_table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$where         = array();
		$values        = array();
		$needs_join    = ! empty( $args['search'] );

		if ( $args['raffle_id'] ) {
			$where[]  = 'w.raffle_id = %d';
			$values[] = absint( $args['raffle_id'] );
		}

		if ( 'yes' === $args['claimed'] ) {
			$where[]  = 'w.claimed = %d';
			$values[] = 1;
		} elseif ( 'no' === $args['claimed'] ) {
			$where[]  = 'w.claimed = %d';
			$values[] = 0;
		}

		if ( $needs_join ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(t.purchaser_name LIKE %s OR t.purchaser_email LIKE %s OR t.ticket_number LIKE %s OR t.recipient_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names via esc_sql().
		$base_sql = 'SELECT w.* FROM ' . $winners_table . ' AS w';
		if ( $needs_join ) {
			$base_sql .= ' INNER JOIN ' . $tickets_table . ' AS t ON w.ticket_id = t.id'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! empty( $where ) ) {
			$base_sql .= ' WHERE ' . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clauses use %d/%s placeholders.
		}
		$base_sql .= ' ORDER BY w.created_at DESC';

		if ( $args['limit'] > 0 ) {
			$base_sql .= ' LIMIT %d';
			$values[]  = absint( $args['limit'] );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built from esc_sql() table and prepared placeholders.
		if ( ! empty( $values ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $base_sql, $values ) );
		} else {
			$rows = $wpdb->get_results( $base_sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$winners = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$winners[] = new self( $row );
			}
		}

		return $winners;
	}

	/**
	 * Count winners by claimed/unclaimed status.
	 *
	 * @since 1.0.4
	 *
	 * @param int $raffle_id Optional raffle ID filter.
	 * @return array Associative array: all, claimed, unclaimed.
	 */
	public static function get_claim_counts( $raffle_id = 0 ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'rfwc_winners' );

		if ( $raffle_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table via esc_sql().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT claimed, COUNT(*) as cnt FROM ' . $table . ' WHERE raffle_id = %d GROUP BY claimed', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					absint( $raffle_id )
				)
			);
		} else {
			$rows = $wpdb->get_results( 'SELECT claimed, COUNT(*) as cnt FROM ' . $table . ' GROUP BY claimed' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table via esc_sql().
		}

		$counts = array(
			'all'       => 0,
			'claimed'   => 0,
			'unclaimed' => 0,
		);

		foreach ( $rows as $row ) {
			$count          = (int) $row->cnt;
			$counts['all'] += $count;
			if ( (int) $row->claimed ) {
				$counts['claimed'] = $count;
			} else {
				$counts['unclaimed'] = $count;
			}
		}

		return $counts;
	}

	/**
	 * Delete all winners for a raffle.
	 *
	 * @since 1.0.0
	 *
	 * @param int $raffle_id Raffle ID.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_by_raffle( $raffle_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->delete( esc_sql( $wpdb->prefix . 'rfwc_winners' ), array( 'raffle_id' => absint( $raffle_id ) ), array( '%d' ) );
	}

	/**
	 * Get formatted selection method label.
	 *
	 * @since 1.0.0
	 *
	 * @return string Human-readable method name.
	 */
	public function get_selection_method_label() {
		$methods = array(
			'manual'        => __( 'Manual', 'raffle-for-woocommerce' ),
			'php_random'    => __( 'Random', 'raffle-for-woocommerce' ),
			'secure_random' => __( 'Secure Random', 'raffle-for-woocommerce' ),
			'random_org'    => __( 'Random.org', 'raffle-for-woocommerce' ),
			'instant_win'   => __( 'Instant Win', 'raffle-for-woocommerce' ),
		);

		return isset( $methods[ $this->selection_method ] ) ? $methods[ $this->selection_method ] : $this->selection_method;
	}
}
