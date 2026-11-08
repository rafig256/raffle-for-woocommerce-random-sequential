<?php
/**
 * Gift Received Email.
 *
 * Sent to the gift recipient when someone purchases raffle tickets for them.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Gift_Received' ) ) :

	/**
	 * Gift Received Email class.
	 *
	 * @since 1.0.0
	 * @extends WC_Email
	 */
	class RFWC_Email_Gift_Received extends WC_Email {

		/**
		 * Tickets for this email.
		 *
		 * @var array
		 */
		public $tickets = array();

		/**
		 * Raffle product.
		 *
		 * @var RFWC_Product_Raffle|null
		 */
		public $raffle = null;

		/**
		 * Gift message from purchaser.
		 *
		 * @var string
		 */
		public $gift_message = '';

		/**
		 * Purchaser name.
		 *
		 * @var string
		 */
		public $purchaser_name = '';

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->id             = 'rfwc_gift_received';
			$this->customer_email = true;
			$this->title          = __( 'Raffle Gift Received', 'raffle-for-woocommerce' );
			$this->description    = __( 'Sent to recipients when someone gifts them raffle tickets.', 'raffle-for-woocommerce' );
			$this->template_html  = 'emails/gift-received.php';
			$this->template_plain = 'emails/plain/gift-received.php';
			$this->placeholders   = array(
				'{recipient_name}' => '',
				'{purchaser_name}' => '',
				'{raffle_name}'    => '',
				'{ticket_count}'   => '',
				'{site_title}'     => $this->get_blogname(),
			);

			// Triggers.
			// Hook to the WooCommerce-dispatched `_notification` variant rather
			// than the source action so the handler runs even when WC_Emails is
			// initialised by this same do_action (the source-action callback
			// list is snapshotted before our class registers itself, which
			// otherwise drops the very first gift email of the request).
			add_action( 'rfwc_gift_tickets_generated_notification', array( $this, 'trigger' ), 10, 4 );

			// Parent constructor.
			parent::__construct();

			// Template paths.
			$this->template_base = RFWC_PLUGIN_PATH . 'templates/';
		}

		/**
		 * Get email subject.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( '{purchaser_name} sent you raffle tickets for {raffle_name}!', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( "You've Received Raffle Tickets!", 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id        Order ID.
		 * @param array  $tickets         Array of RFWC_Ticket objects.
		 * @param int    $raffle_id       Raffle product ID.
		 * @param string $recipient_email Recipient email address.
		 */
		public function trigger( $order_id, $tickets, $raffle_id, $recipient_email ) {
			$this->setup_locale();

			// Check the Raffle settings toggle.
			if ( 'yes' !== get_option( 'rfwc_email_gift_notification', 'yes' ) ) {
				$this->restore_locale();
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order || empty( $tickets ) || empty( $recipient_email ) ) {
				$this->restore_locale();
				return;
			}

			$this->object         = $order;
			$this->tickets        = $tickets;
			$this->raffle         = wc_get_product( $raffle_id );
			$this->recipient      = $recipient_email;
			$this->purchaser_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

			// Get recipient name and gift message from the first ticket in this group.
			// Each recipient may have a different message (per-ticket gift messages in v1.6.0+).
			$recipient_name = '';
			$gift_message   = '';

			if ( ! empty( $tickets ) ) {
				$first_ticket   = reset( $tickets );
				$recipient_name = $first_ticket->get_recipient_name();
				$gift_message   = $first_ticket->get_gift_message();
			}

			// Fallback to order-level gift message if ticket has none.
			if ( empty( $gift_message ) ) {
				$gift_message = $order->get_meta( '_rfwc_gift_message' );
			}

			$this->gift_message = $gift_message;

			$this->placeholders['{recipient_name}'] = $recipient_name;
			$this->placeholders['{purchaser_name}'] = trim( $this->purchaser_name );
			$this->placeholders['{raffle_name}']    = $this->raffle ? $this->raffle->get_name() : '';
			$this->placeholders['{ticket_count}']   = count( $tickets );

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content HTML.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				array(
					'order'              => $this->object,
					'tickets'            => $this->tickets,
					'raffle'             => $this->raffle,
					'gift_message'       => $this->gift_message,
					'purchaser_name'     => $this->purchaser_name,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Get content plain text.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				array(
					'order'              => $this->object,
					'tickets'            => $this->tickets,
					'raffle'             => $this->raffle,
					'gift_message'       => $this->gift_message,
					'purchaser_name'     => $this->purchaser_name,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Default content to show below main email content.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'Good luck! We will notify you if you win.', 'raffle-for-woocommerce' );
		}

		/**
		 * Initialize settings form fields.
		 *
		 * @since 1.0.0
		 */
		public function init_form_fields() {
			/* translators: %s: list of placeholders */
			$placeholder_text = sprintf( __( 'Available placeholders: %s', 'raffle-for-woocommerce' ), '<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>' );

			$this->form_fields = array(
				'enabled'            => array(
					'title'   => __( 'Enable/Disable', 'raffle-for-woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'raffle-for-woocommerce' ),
					'default' => 'yes',
				),
				'subject'            => array(
					'title'       => __( 'Subject', 'raffle-for-woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_subject(),
					'default'     => '',
				),
				'heading'            => array(
					'title'       => __( 'Email heading', 'raffle-for-woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_heading(),
					'default'     => '',
				),
				'additional_content' => array(
					'title'       => __( 'Additional content', 'raffle-for-woocommerce' ),
					'description' => __( 'Text to appear below the main email content.', 'raffle-for-woocommerce' ) . ' ' . $placeholder_text,
					'css'         => 'width:400px; height: 75px;',
					'placeholder' => $this->get_default_additional_content(),
					'type'        => 'textarea',
					'default'     => $this->get_default_additional_content(),
					'desc_tip'    => true,
				),
				'email_type'         => array(
					'title'       => __( 'Email type', 'raffle-for-woocommerce' ),
					'type'        => 'select',
					'description' => __( 'Choose which format of email to send.', 'raffle-for-woocommerce' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $this->get_email_type_options(),
					'desc_tip'    => true,
				),
			);
		}
	}

endif;
