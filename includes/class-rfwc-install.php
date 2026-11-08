<?php
/**
 * Plugin activation and database setup.
 *
 * Creates custom database tables for tickets, winners, and logs.
 * Runs on plugin activation and checks for schema updates.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Creates database tables and default options on activation.
 *
 * @since 1.0.0
 */
class RFWC_Install {

	/**
	 * Database version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Activate the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		// Check WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( RFWC_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Raffle for WooCommerce requires WooCommerce to be installed and activated.', 'raffle-for-woocommerce' ),
				esc_html__( 'Plugin Activation Error', 'raffle-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

		// Create database tables.
		self::create_tables();

		// Add default options.
		self::create_options();

		// Store installed version.
		update_option( 'rfwc_version', RFWC_VERSION );
		update_option( 'rfwc_db_version', self::DB_VERSION );

		// First install date, used by the one-time review prompt.
		add_option( 'rfwc_installed_at', time(), '', false );

		// Flag to flush rewrite rules on next init.
		update_option( 'rfwc_flush_rewrite_rules', true );

		// Add My Account endpoint.
		add_rewrite_endpoint( 'my-raffle-tickets', EP_ROOT | EP_PAGES );

		// Clear any cached data.
		wp_cache_flush();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Deactivate the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'rfwc_check_expired_raffles' );
		wp_unschedule_hook( 'rfwc_auto_draw_raffle' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Tickets table.
		$tickets_table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$tickets_sql   = "CREATE TABLE {$tickets_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ticket_number VARCHAR(50) NOT NULL,
			raffle_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NOT NULL,
			purchaser_id BIGINT UNSIGNED DEFAULT 0,
			purchaser_name VARCHAR(255) DEFAULT '',
			purchaser_email VARCHAR(255) DEFAULT '',
			recipient_name VARCHAR(255) NOT NULL,
			recipient_email VARCHAR(255) DEFAULT '',
			is_gift TINYINT(1) DEFAULT 0,
			gift_message TEXT,
			status VARCHAR(20) DEFAULT 'active',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY unique_ticket (raffle_id, ticket_number),
			KEY idx_raffle (raffle_id),
			KEY idx_order (order_id),
			KEY idx_status (status),
			KEY idx_recipient_email (recipient_email(191)),
			KEY idx_purchaser_email (purchaser_email(191)),
			KEY idx_is_gift (is_gift)
		) {$charset_collate};";

		// Winners table.
		$winners_table = esc_sql( $wpdb->prefix . 'rfwc_winners' );
		$winners_sql   = "CREATE TABLE {$winners_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			raffle_id BIGINT UNSIGNED NOT NULL,
			ticket_id BIGINT UNSIGNED NOT NULL,
			prize_tier INT DEFAULT 1,
			selection_method VARCHAR(50) DEFAULT 'auto',
			selection_proof TEXT,
			claimed TINYINT(1) DEFAULT 0,
			claimed_at DATETIME DEFAULT NULL,
			notified TINYINT(1) DEFAULT 0,
			notified_at DATETIME DEFAULT NULL,
			prize_order_id BIGINT UNSIGNED DEFAULT 0,
			prize_coupon_code VARCHAR(100) DEFAULT '',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_raffle (raffle_id),
			KEY idx_ticket (ticket_id),
			KEY idx_claimed (claimed)
		) {$charset_collate};";

		// Raffle log table for audit trail.
		$log_table = esc_sql( $wpdb->prefix . 'rfwc_log' );
		$log_sql   = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			raffle_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(100) NOT NULL,
			details TEXT,
			user_id BIGINT UNSIGNED DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_raffle (raffle_id),
			KEY idx_action (action)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $tickets_sql );
		dbDelta( $winners_sql );
		dbDelta( $log_sql );

		// Upgrade existing tables if needed.
		self::maybe_upgrade_tables();
	}

	/**
	 * Upgrade existing tables if needed.
	 *
	 * @since 1.0.0
	 */
	private static function maybe_upgrade_tables() {
		global $wpdb;

		$log_table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Check if details column exists, if not add it.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'details'",
				DB_NAME,
				$log_table
			)
		);

		if ( empty( $column_exists ) ) {
			$wpdb->query( "ALTER TABLE {$log_table} ADD COLUMN details TEXT AFTER action" );
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create default options.
	 *
	 * @since 1.0.0
	 */
	public static function create_options() {
		$defaults = array(
			'rfwc_enable_gift_purchase'     => 'yes',
			'rfwc_ticket_number_format'     => 'sequential',
			'rfwc_ticket_prefix'            => '',
			'rfwc_ticket_start_number'      => 1,
			'rfwc_enable_recipient_email'   => 'yes',
			'rfwc_generate_tickets_on'      => 'payment',
			'rfwc_show_countdown'           => 'yes',
			'rfwc_show_progress_bar'        => 'yes',
			'rfwc_show_participants_count'  => 'yes',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}

	/**
	 * Get database tables.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of custom tables.
	 */
	public static function get_tables() {
		global $wpdb;

		return array(
			esc_sql( $wpdb->prefix . 'rfwc_tickets' ),
			esc_sql( $wpdb->prefix . 'rfwc_winners' ),
			esc_sql( $wpdb->prefix . 'rfwc_log' ),
		);
	}

	/**
	 * Check if database needs update.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if update needed.
	 */
	public static function needs_db_update() {
		$current_db_version = get_option( 'rfwc_db_version', '0' );
		return version_compare( $current_db_version, self::DB_VERSION, '<' );
	}

	/**
	 * Update database if needed.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_update_db() {
		if ( self::needs_db_update() ) {
			self::create_tables();
			update_option( 'rfwc_db_version', self::DB_VERSION );
		}
	}
}
