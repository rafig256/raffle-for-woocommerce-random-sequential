<?php
/**
 * Admin notices handler.
 *
 * Manages admin notices for plugin activation and important updates.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin notices class.
 *
 * @since 1.0.0
 */
class RFWC_Admin_Notices {

	/**
	 * Tracks whether notice has been rendered in current request.
	 *
	 * @var bool
	 */
	private static $notice_shown = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'wp_ajax_rfwc_dismiss_welcome_notice', array( $this, 'ajax_dismiss_welcome_notice' ) );
	}

	/**
	 * Display admin notices.
	 *
	 * @since 1.0.0
	 */
	public function show_notices() {
		if ( self::$notice_shown ) {
			return;
		}

		$this->show_welcome_notice();
	}

	/**
	 * Display welcome notice for new installations.
	 *
	 * @since 1.0.0
	 */
	private function show_welcome_notice() {
		if ( get_option( 'rfwc_welcome_notice_dismissed' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		self::$notice_shown = true;

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=raffle' );
		$docs_url     = 'https://themology.com/docs/raffle-for-woocommerce/';
		?>
		<div class="notice notice-info is-dismissible" data-notice="rfwc-welcome">
			<p>
				<strong><?php esc_html_e( 'Raffle for WooCommerce', 'raffle-for-woocommerce' ); ?></strong> &ndash;
				<?php esc_html_e( 'Thank you for installing! Get started by configuring your settings.', 'raffle-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'raffle-for-woocommerce' ); ?></a> |
				<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'raffle-for-woocommerce' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to dismiss the welcome notice.
	 *
	 * @since 1.0.0
	 */
	public function ajax_dismiss_welcome_notice() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'rfwc_dismiss_notice' ) ) {
			wp_die( -1, 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1, 403 );
		}

		update_option( 'rfwc_welcome_notice_dismissed', true );
		wp_die();
	}
}

