<?php
/**
 * WooCommerce email registration.
 *
 * Registers ticket confirmation, gift received, and winner notification
 * emails with the WooCommerce email system.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers raffle emails with WooCommerce.
 *
 * @since 1.0.0
 */
class RFWC_Emails {

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
		// Register email classes with WooCommerce.
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );

		// Add email actions for WooCommerce.
		add_filter( 'woocommerce_email_actions', array( $this, 'register_email_actions' ) );
	}

	/**
	 * Register email classes with WooCommerce.
	 *
	 * Email classes are loaded here because WC_Email is only available
	 * at this point in the WooCommerce initialization.
	 *
	 * @since 1.0.0
	 *
	 * @param array $email_classes Existing email classes.
	 *
	 * @return array Modified email classes.
	 */
	public function register_email_classes( $email_classes ) {
		// Include email classes now that WC_Email is available.
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-ticket-confirmation.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-gift-received.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-winner-notification.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-admin-winner-alert.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-admin-draw-failed.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-prize-claimed.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-ticket-refunded.php';
		require_once RFWC_PLUGIN_DIR . 'includes/emails/class-rfwc-email-admin-mass-refund-summary.php';

		// Register our email classes.
		$email_classes['RFWC_Email_Ticket_Confirmation']       = new RFWC_Email_Ticket_Confirmation();
		$email_classes['RFWC_Email_Gift_Received']             = new RFWC_Email_Gift_Received();
		$email_classes['RFWC_Email_Winner_Notification']       = new RFWC_Email_Winner_Notification();
		$email_classes['RFWC_Email_Admin_Winner_Alert']        = new RFWC_Email_Admin_Winner_Alert();
		$email_classes['RFWC_Email_Admin_Draw_Failed']         = new RFWC_Email_Admin_Draw_Failed();
		$email_classes['RFWC_Email_Prize_Claimed']             = new RFWC_Email_Prize_Claimed();
		$email_classes['RFWC_Email_Ticket_Refunded']           = new RFWC_Email_Ticket_Refunded();
		$email_classes['RFWC_Email_Admin_Mass_Refund_Summary'] = new RFWC_Email_Admin_Mass_Refund_Summary();

		return $email_classes;
	}

	/**
	 * Register email actions with WooCommerce.
	 *
	 * These actions trigger email sending.
	 *
	 * @since 1.0.0
	 *
	 * @param array $actions Existing email actions.
	 *
	 * @return array Modified email actions.
	 */
	public function register_email_actions( $actions ) {
		$actions[] = 'rfwc_tickets_generated';
		$actions[] = 'rfwc_gift_tickets_generated';
		$actions[] = 'rfwc_winner_selected';
		$actions[] = 'rfwc_resend_winner_notification';
		$actions[] = 'rfwc_draw_failed';
		$actions[] = 'rfwc_prize_claimed';
		$actions[] = 'rfwc_ticket_refunded';
		$actions[] = 'rfwc_mass_refund_completed';

		return $actions;
	}
}
