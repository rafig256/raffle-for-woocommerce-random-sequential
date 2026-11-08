<?php
/**
 * Review prompt.
 *
 * One dismissible notice, on the plugin's own screens only, asking for a
 * WordPress.org review after 14 days of use or 10 completed raffles.
 * Dismissal is per user and permanent; "Maybe later" snoozes once for
 * seven days. No external requests, no incentives.
 *
 * @package RaffleForWooCommerce
 * @since   1.1.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RFWC_Admin_Review_Prompt
 */
class RFWC_Admin_Review_Prompt {

	const OPTION_INSTALLED = 'rfwc_installed_at';
	const OPTION_COMPLETED = 'rfwc_completed_raffles_count';
	const META_DISMISSED   = 'rfwc_review_prompt_dismissed';
	const META_REMIND_AT   = 'rfwc_review_prompt_remind_at';
	const DAYS_BEFORE_ASK  = 14;
	const RAFFLES_BEFORE   = 10;
	const SNOOZE_DAYS      = 7;
	const REVIEW_URL       = 'https://wordpress.org/support/plugin/raffle-for-woocommerce/reviews/#new-post';

	/**
	 * Constructor.
	 *
	 * @since 1.1.15
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_set_installed_at' ) );
		add_action( 'rfwc_raffle_completed', array( $this, 'count_completed_raffle' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_rfwc_review_prompt', array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Existing installs never re-run activation, so record the first admin
	 * visit as the install date.
	 *
	 * @since 1.1.15
	 */
	public function maybe_set_installed_at() {
		if ( ! get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, time(), '', false );
		}
	}

	/**
	 * Bump the completed raffle counter.
	 *
	 * @since 1.1.15
	 */
	public function count_completed_raffle() {
		update_option( self::OPTION_COMPLETED, (int) get_option( self::OPTION_COMPLETED, 0 ) + 1, false );
	}

	/**
	 * Whether the notice should show for a user.
	 *
	 * @since 1.1.15
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function should_show( $user_id ) {
		if ( ! $user_id || get_user_meta( $user_id, self::META_DISMISSED, true ) ) {
			return false;
		}

		$remind_at = (int) get_user_meta( $user_id, self::META_REMIND_AT, true );
		if ( $remind_at > time() ) {
			return false;
		}

		$installed   = (int) get_option( self::OPTION_INSTALLED, 0 );
		$old_enough  = $installed > 0 && ( time() - $installed ) >= self::DAYS_BEFORE_ASK * DAY_IN_SECONDS;
		$busy_enough = (int) get_option( self::OPTION_COMPLETED, 0 ) >= self::RAFFLES_BEFORE;

		return $old_enough || $busy_enough;
	}

	/**
	 * Only the plugin's own screens: the Raffle Tickets page and the Raffle settings tab.
	 *
	 * @since 1.1.15
	 *
	 * @return bool
	 */
	public function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		if ( 'woocommerce_page_rfwc-tickets' === $screen->id ) {
			return true;
		}

		if ( 'woocommerce_page_wc-settings' === $screen->id ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
			return 'raffle' === $tab;
		}

		return false;
	}

	/**
	 * Output the notice.
	 *
	 * @since 1.1.15
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->is_plugin_screen() || ! $this->should_show( get_current_user_id() ) ) {
			return;
		}

		$later   = $this->action_url( 'later' );
		$dismiss = $this->action_url( 'dismiss' );
		?>
		<div class="notice notice-info is-dismissible rfwc-review-prompt" data-dismiss-url="<?php echo esc_url( $dismiss ); ?>">
			<p>
				<?php esc_html_e( 'If Raffle for WooCommerce is working well for your store, a short review on WordPress.org helps other store owners find it.', 'raffle-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Leave a review', 'raffle-for-woocommerce' ); ?></a>
				<a class="button" href="<?php echo esc_url( $later ); ?>"><?php esc_html_e( 'Maybe later', 'raffle-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Don\'t ask again', 'raffle-for-woocommerce' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Signed admin-post URL for a prompt action.
	 *
	 * @since 1.1.15
	 *
	 * @param string $choice later|dismiss.
	 * @return string
	 */
	private function action_url( $choice ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'rfwc_review_prompt',
					'do'     => $choice,
				),
				admin_url( 'admin-post.php' )
			),
			'rfwc_review_prompt'
		);
	}

	/**
	 * Handle Maybe later and Don't ask again.
	 *
	 * @since 1.1.15
	 */
	public function handle() {
		check_admin_referer( 'rfwc_review_prompt' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'raffle-for-woocommerce' ), 403 );
		}

		$choice  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$user_id = get_current_user_id();

		if ( 'later' === $choice && ! get_user_meta( $user_id, self::META_REMIND_AT, true ) ) {
			update_user_meta( $user_id, self::META_REMIND_AT, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS );
		} else {
			// A second "later" or an explicit dismiss ends it for good.
			update_user_meta( $user_id, self::META_DISMISSED, 1 );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=rfwc-tickets' ) );
		exit;
	}

	/**
	 * Make the notice's X button count as "Don't ask again".
	 *
	 * @since 1.1.15
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'woocommerce_page_rfwc-tickets', 'woocommerce_page_wc-settings' ), true ) ) {
			return;
		}

		wp_add_inline_script(
			'jquery',
			'jQuery(function($){$(document).on("click",".rfwc-review-prompt .notice-dismiss",function(){var u=$(this).closest(".rfwc-review-prompt").data("dismiss-url");if(u){$.get(u);}});});'
		);
	}
}
