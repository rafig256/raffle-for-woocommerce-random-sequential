<?php
/**
 * Plugin Name:       Raffle for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/raffle-for-woocommerce
 * Description:       Run raffles with WooCommerce. Sell tickets and let customers buy tickets for friends and family. PDF tickets, QR validation, and winner selection included.
 * Version:           1.1.17
 * Author:            Themology
 * Author URI:        https://themology.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       raffle-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1.0
 *
 * @package RaffleForWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'RFWC_VERSION', '1.1.17' );

/**
 * Plugin file path.
 *
 * @var string
 */
define( 'RFWC_PLUGIN_FILE', __FILE__ );

/**
 * Plugin directory path.
 *
 * @var string
 */
define( 'RFWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 *
 * @var string
 */
define( 'RFWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin directory path (alias for RFWC_PLUGIN_DIR).
 *
 * @var string
 */
define( 'RFWC_PLUGIN_PATH', RFWC_PLUGIN_DIR );

/**
 * Plugin basename.
 *
 * @var string
 */
define( 'RFWC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum WooCommerce version required.
 *
 * @var string
 */
define( 'RFWC_WC_MIN_VERSION', '8.0' );

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class Raffle_For_WooCommerce {

	/**
	 * Single instance of the class.
	 *
	 * @var Raffle_For_WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * Plugin loader instance.
	 *
	 * @var RFWC_Loader|null
	 */
	public $loader = null;

	/**
	 * Admin instance.
	 *
	 * @var RFWC_Admin|null
	 */
	public $admin = null;

	/**
	 * Admin notices instance.
	 *
	 * @var RFWC_Admin_Notices|null
	 */
	public $admin_notices = null;

	/**
	 * Returns the single instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return Raffle_For_WooCommerce Single instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();

		// Initialize admin notices early (doesn't depend on WooCommerce).
		if ( is_admin() ) {
			$this->admin_notices = new RFWC_Admin_Notices();
			new RFWC_Admin_Review_Prompt();
		}
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'raffle-for-woocommerce' ), '1.0.0' );
	}

	/**
	 * Unserializing instances is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances is forbidden.', 'raffle-for-woocommerce' ), '1.0.0' );
	}

	/**
	 * Define additional constants if needed.
	 *
	 * @since 1.0.0
	 */
	private function define_constants() {
		// Additional constants can be defined here.
	}

	/**
	 * Include required files.
	 *
	 * Only loads files that don't depend on WooCommerce here.
	 * WooCommerce-dependent files are loaded in init() after WC is available.
	 *
	 * @since 1.0.0
	 */
	private function includes() {
		// Core classes that don't depend on WooCommerce.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-install.php';
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-loader.php';

		// Blocks integration - must load early to register Store API callback
		// before woocommerce_blocks_loaded fires.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-blocks-integration.php';

		// Admin classes that don't depend on WooCommerce.
		if ( is_admin() ) {
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-notices.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-review-prompt.php';
		}
	}

	/**
	 * Include WooCommerce-dependent files.
	 *
	 * Called after WooCommerce is loaded.
	 *
	 * @since 1.0.0
	 */
	private function includes_wc() {
		// Product class - extends WC_Product, must load after WooCommerce.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-product-raffle.php';

		// Ticket management classes.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-ticket.php';
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-ticket-generator.php';
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-my-account.php';

		// Winner management classes.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-winner.php';
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-winner-selector.php';
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-scheduler.php';

		// Email classes.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-emails.php';

		// Admin classes that depend on WooCommerce.
		if ( is_admin() ) {
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-settings.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-order.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-tickets.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-free-entries.php';
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-winner.php';
		}

		// Frontend classes.
		if ( ! is_admin() || defined( 'DOING_AJAX' ) ) {
			require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-frontend.php';
			require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-shortcodes.php';
			require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-gift-checkout.php';
		}

		// Instant win evaluator (evaluates tickets at generation time).
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-instant-win-evaluator.php';

		// Prize fulfillment (handles auto-orders for winners).
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-prize-fulfillment.php';

		// QR code generation (using QR Server API - no external library required).
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-qr-code.php';

		// Printable ticket generation (HTML-based - no external library required).
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-pdf-ticket.php';

		// QR code validation.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-qr-validator.php';

		// Privacy and GDPR compliance.
		require_once RFWC_PLUGIN_DIR . 'includes/class-rfwc-privacy.php';

		// Analytics dashboard (admin only).
		if ( is_admin() ) {
			require_once RFWC_PLUGIN_DIR . 'includes/admin/class-rfwc-admin-analytics.php';
		}

		// Note: RFWC_Blocks_Integration is loaded early in includes() to ensure
		// Store API callback is registered before woocommerce_blocks_loaded fires.
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Check WooCommerce dependency.
		add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ), 1 );

		// Initialize plugin after WooCommerce is loaded.
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );

		// Load text domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Declare HPOS compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		// Plugin action links.
		add_filter( 'plugin_action_links_' . RFWC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		// Plugin row meta.
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		// Activation and deactivation hooks.
		register_activation_hook( RFWC_PLUGIN_FILE, array( 'RFWC_Install', 'activate' ) );
		register_deactivation_hook( RFWC_PLUGIN_FILE, array( 'RFWC_Install', 'deactivate' ) );
	}

	/**
	 * Check if WooCommerce is active and meets minimum version.
	 *
	 * @since 1.0.0
	 */
	public function check_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( version_compare( WC_VERSION, RFWC_WC_MIN_VERSION, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
			return;
		}
	}

	/**
	 * Display notice if WooCommerce is not installed.
	 *
	 * @since 1.0.0
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: WooCommerce link */
					esc_html__( 'Raffle for WooCommerce requires %s to be installed and activated.', 'raffle-for-woocommerce' ),
					'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display notice if WooCommerce version is too old.
	 *
	 * @since 1.0.0
	 */
	public function woocommerce_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: WooCommerce minimum version */
					esc_html__( 'Raffle for WooCommerce requires WooCommerce %s or higher.', 'raffle-for-woocommerce' ),
					esc_html( RFWC_WC_MIN_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Initialize the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Only initialize if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Include WooCommerce-dependent files.
		$this->includes_wc();

		// Check database version and upgrade if needed.
		$this->maybe_upgrade_database();

		// Initialize loader.
		$this->loader = new RFWC_Loader();

		// Initialize ticket management (works on both admin and frontend).
		new RFWC_Ticket_Generator();
		new RFWC_My_Account();

		// Initialize instant win evaluator.
		new RFWC_Instant_Win_Evaluator();

		// Initialize scheduler for automatic winner draws.
		new RFWC_Scheduler();

		// Initialize prize fulfillment (auto-creates orders for winners).
		new RFWC_Prize_Fulfillment();

		// Initialize emails (registers with WooCommerce email system).
		new RFWC_Emails();

		// Initialize PDF ticket generator.
		new RFWC_PDF_Ticket();

		// Initialize QR code validator.
		new RFWC_QR_Validator();

		// Initialize privacy and GDPR compliance.
		new RFWC_Privacy();

		// Initialize admin.
		if ( is_admin() ) {
			$this->admin = new RFWC_Admin();
			new RFWC_Admin_Order();
			new RFWC_Admin_Tickets();
			new RFWC_Admin_Free_Entries();
			new RFWC_Admin_Winner();
			new RFWC_Admin_Analytics();
		}

		// Initialize frontend.
		if ( ! is_admin() || defined( 'DOING_AJAX' ) ) {
			$frontend = new RFWC_Frontend();
			new RFWC_Shortcodes( $frontend );
			new RFWC_Gift_Checkout();
			new RFWC_Blocks_Integration();
		}

		/**
		 * Fires after all core classes are instantiated.
		 *
		 * Addons (e.g. Raffle for WooCommerce Pro) should hook here to
		 * initialize, knowing all core classes and hooks are available.
		 *
		 * @since 1.1.0
		 *
		 * @param Raffle_For_WooCommerce $this Main plugin instance.
		 */
		do_action( 'rfwc_loaded', $this );
	}

	/**
	 * Check and upgrade database if needed.
	 *
	 * @since 1.0.0
	 */
	private function maybe_upgrade_database() {
		$db_version = get_option( 'rfwc_db_version', '1.0.0' );

		// Version 1.0.1: Add details column to log table.
		if ( version_compare( $db_version, '1.0.1', '<' ) ) {
			global $wpdb;
			$log_table = esc_sql( $wpdb->prefix . 'rfwc_log' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Check if details column exists.
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

			update_option( 'rfwc_db_version', '1.0.1' );
		}
	}

	/**
	 * Load plugin text domain.
	 *
	 * Note: For plugins hosted on WordPress.org, translations are loaded
	 * automatically since WordPress 4.6. This method is kept for compatibility
	 * with self-hosted installations or custom translation loading.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		// Translations are automatically loaded by WordPress.org for hosted plugins.
		// No manual loading required since WordPress 4.6.
	}

	/**
	 * Declare HPOS compatibility.
	 *
	 * @since 1.0.0
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				RFWC_PLUGIN_FILE,
				true
			);
		}
	}

	/**
	 * Add plugin action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=raffle' );
		$action_links = array(
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'raffle-for-woocommerce' )
			),
		);

		return array_merge( $action_links, $links );
	}

	/**
	 * Add plugin row meta links.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $links Existing links.
	 * @param string $file  Plugin file.
	 * @return array Modified links.
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( RFWC_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		$row_meta = array(
			'docs'    => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://themology.com/docs/raffle-for-woocommerce' ),
				esc_html__( 'Documentation', 'raffle-for-woocommerce' )
			),
			'support' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://wordpress.org/support/plugin/raffle-for-woocommerce/' ),
				esc_html__( 'Support', 'raffle-for-woocommerce' )
			),
		);

		return array_merge( $links, $row_meta );
	}

	/**
	 * Get the plugin path.
	 *
	 * @since 1.0.0
	 *
	 * @return string Plugin path.
	 */
	public function plugin_path() {
		return untrailingslashit( RFWC_PLUGIN_DIR );
	}

	/**
	 * Get the plugin URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string Plugin URL.
	 */
	public function plugin_url() {
		return untrailingslashit( RFWC_PLUGIN_URL );
	}
}

/**
 * Returns the main instance of Raffle_For_WooCommerce.
 *
 * @since 1.0.0
 *
 * @return Raffle_For_WooCommerce Main instance.
 */
function rfwc() {
	return Raffle_For_WooCommerce::instance();
}

/**
 * Check whether the Pro addon is active and has a valid license.
 *
 * @since 1.1.0
 *
 * @return bool True if Pro is active with a valid license.
 */
function rfwc_is_pro_active() {
	if ( ! defined( 'RFWC_PRO_VERSION' ) ) {
		return false;
	}

	if ( class_exists( 'RFWC_Pro_License' ) && method_exists( 'RFWC_Pro_License', 'is_valid' ) ) {
		return RFWC_Pro_License::is_valid();
	}

	return true;
}

/**
 * Check whether a feature module is globally enabled.
 *
 * Reads the WP option for the given feature key and returns true/false.
 * All 1.1.0+ features default to 'no' (opt-in). Pre-existing features
 * like bulk discounts default to 'yes' (backward compatible).
 *
 * @since 1.1.0
 *
 * @param string $feature Option key, e.g. 'rfwc_enable_instant_win'.
 * @return bool
 */
function rfwc_feature_enabled( $feature ) {
	$defaults = array(
		'rfwc_enable_bulk_discounts' => 'yes',
	);

	$default = isset( $defaults[ $feature ] ) ? $defaults[ $feature ] : 'no';

	/**
	 * Filters whether a feature module is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param bool   $enabled Whether the feature is enabled.
	 * @param string $feature The feature option key.
	 */
	return apply_filters( 'rfwc_feature_enabled', get_option( $feature, $default ) === 'yes', $feature );
}

/**
 * Write an entry to the Raffle Activity Log.
 *
 * Shared helper for both the free plugin and the Pro add-on so every
 * raffle-related action ends up on the Analytics > Activity Log
 * timeline. Uses the same `wp_rfwc_log` table the free plugin already
 * writes to from the admin tickets / winner screens.
 *
 * Pass `$raffle_id = 0` for system-level events that aren't tied to a
 * single raffle (e.g. master draws spanning multiple raffles). The
 * activity log UI handles `0` by rendering a "System" label.
 *
 * @since 1.1.14
 *
 * @param int          $raffle_id Raffle ID, or 0 for system-level events.
 * @param string       $action    Action slug (e.g. 'manual_sale_recorded').
 * @param string|array $details   Human-readable string, or an array to be
 *                                JSON-encoded for structured display.
 * @param int          $user_id   Optional. Acting user ID. Defaults to current.
 * @return int|false  Inserted row ID on success, false on failure.
 */
function rfwc_log_activity( $raffle_id, $action, $details = '', $user_id = 0 ) {
	global $wpdb;

	$action = sanitize_key( $action );
	if ( '' === $action ) {
		return false;
	}

	if ( is_array( $details ) ) {
		$details = wp_json_encode( $details );
	} else {
		$details = (string) $details;
	}

	$user_id = $user_id > 0 ? absint( $user_id ) : get_current_user_id();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit log writes are intentionally uncached.
	$inserted = $wpdb->insert(
		esc_sql( $wpdb->prefix . 'rfwc_log' ),
		array(
			'raffle_id' => absint( $raffle_id ),
			'action'    => $action,
			'details'   => $details,
			'user_id'   => $user_id,
		),
		array( '%d', '%s', '%s', '%d' )
	);

	return $inserted ? (int) $wpdb->insert_id : false;
}

/**
 * Get the maximum number of prize tiers allowed.
 *
 * @since 1.1.7
 *
 * @return int
 */
function rfwc_max_prize_tiers() {
	/**
	 * Filters the maximum number of prize tiers (winners with individual prize config).
	 *
	 * @since 1.1.7
	 *
	 * @param int $max Maximum prize tiers. Default 20.
	 */
	return (int) apply_filters( 'rfwc_max_prize_tiers', 20 );
}

/**
 * Get all raffle product IDs that are rendered on the current frontend page.
 *
 * Returns IDs in this order:
 *
 * 1. The queried product when on a real product page (`is_product()` true).
 * 2. Raffle products referenced via the `[product_page id="X"]` shortcode in
 *    the current singular post's content.
 *
 * The WooCommerce `[product_page]` shortcode renders the full single-product
 * template inside a regular page, but `is_product()` still returns false for
 * that page. This helper lets enqueue and render logic recognize that case so
 * raffle assets and per-feature checks behave consistently.
 *
 * Result is cached per request.
 *
 * @since 1.1.11
 *
 * @return int[] Unique raffle product IDs detected on the current page.
 */
function rfwc_get_current_raffle_ids() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();

	if ( is_admin() && ! wp_doing_ajax() ) {
		return $cache;
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		return $cache;
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product && 'raffle' === $product->get_type() ) {
			$cache[] = (int) $product->get_id();
		}
	}

	if ( is_singular() ) {
		$post = get_post();

		if ( $post instanceof WP_Post && ! empty( $post->post_content )
			&& has_shortcode( $post->post_content, 'product_page' )
		) {
			$pattern = get_shortcode_regex( array( 'product_page' ) );

			if ( $pattern && preg_match_all( '/' . $pattern . '/s', $post->post_content, $matches ) ) {
				foreach ( $matches[3] as $atts_string ) {
					$atts = shortcode_parse_atts( $atts_string );

					if ( empty( $atts['id'] ) ) {
						continue;
					}

					$shortcode_product = wc_get_product( absint( $atts['id'] ) );

					if ( $shortcode_product && 'raffle' === $shortcode_product->get_type() ) {
						$cache[] = (int) $shortcode_product->get_id();
					}
				}
			}
		}
	}

	$cache = array_values( array_unique( $cache ) );

	return $cache;
}

/**
 * Find the first raffle product on the current page that matches a callback.
 *
 * Iterates raffle products detected by `rfwc_get_current_raffle_ids()` and
 * returns the first one for which `$check` returns true. Returns the first
 * detected raffle when `$check` is null. Used by Pro features to locate the
 * applicable raffle when `[product_page]` renders a raffle on a regular page,
 * where `get_the_ID()` would otherwise return the surrounding page ID.
 *
 * @since 1.1.11
 *
 * @param callable|null $check Optional callback receiving a `WC_Product`,
 *                             returning bool. Default null.
 * @return WC_Product|null First matching raffle product, or null.
 */
function rfwc_get_current_raffle( $check = null ) {
	foreach ( rfwc_get_current_raffle_ids() as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || 'raffle' !== $product->get_type() ) {
			continue;
		}

		if ( null === $check || call_user_func( $check, $product ) ) {
			return $product;
		}
	}

	return null;
}

/**
 * Get ordinal label for a number (1st, 2nd, 3rd, 4th, etc.).
 *
 * @since 1.1.7
 *
 * @param int $number Tier number.
 * @return string Ordinal string.
 */
function rfwc_get_ordinal( $number ) {
	$number = absint( $number );

	switch ( $number ) {
		case 1:
			return __( '1st', 'raffle-for-woocommerce' );
		case 2:
			return __( '2nd', 'raffle-for-woocommerce' );
		case 3:
			return __( '3rd', 'raffle-for-woocommerce' );
		default:
			/* translators: %d: tier number */
			return sprintf( __( '%dth', 'raffle-for-woocommerce' ), $number );
	}
}

// Initialize the plugin.
rfwc();

