<?php
/**
 * Ticket Confirmation Email.
 *
 * Sent to the purchaser when raffle tickets are generated.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Ticket_Confirmation' ) ) :

	/**
	 * Ticket Confirmation Email class.
	 *
	 * @since 1.0.0
	 * @extends WC_Email
	 */
	class RFWC_Email_Ticket_Confirmation extends WC_Email {

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
		 * Constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->id             = 'rfwc_ticket_confirmation';
			$this->customer_email = true;
			$this->title          = __( 'Raffle Ticket Confirmation', 'raffle-for-woocommerce' );
			$this->description    = __( 'Sent to customers when their raffle tickets are confirmed after a successful order.', 'raffle-for-woocommerce' );
			$this->template_html  = 'emails/ticket-confirmation.php';
			$this->template_plain = 'emails/plain/ticket-confirmation.php';
			$this->placeholders   = array(
				'{order_date}'   => '',
				'{order_number}' => '',
				'{raffle_name}'  => '',
				'{ticket_count}' => '',
			);

			// Triggers.
			// Hook to the WooCommerce-dispatched `_notification` variant so
			// the handler runs even when WC_Emails is initialised by this same
			// do_action (the source-action callback list is snapshotted before
			// our class registers itself).
			add_action( 'rfwc_tickets_generated_notification', array( $this, 'trigger' ), 10, 3 );

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
			return __( 'Your raffle tickets for {raffle_name} - Order #{order_number}', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Your Raffle Tickets Are Confirmed!', 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $order_id  Order ID.
		 * @param array $tickets   Array of RFWC_Ticket objects.
		 * @param int   $raffle_id Raffle product ID.
		 */
		public function trigger( $order_id, $tickets, $raffle_id ) {
			$this->setup_locale();

			// Check the Raffle settings toggle.
			if ( 'yes' !== get_option( 'rfwc_email_ticket_confirmation', 'yes' ) ) {
				$this->restore_locale();
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order || empty( $tickets ) ) {
				$this->restore_locale();
				return;
			}

			// Filter tickets to only include non-gift tickets (purchaser keeps these).
			$purchaser_tickets = array_filter(
				$tickets,
				function ( $ticket ) {
					return ! $ticket->is_gift();
				}
			);

			// If all tickets were gifts, don't send this email to purchaser.
			if ( empty( $purchaser_tickets ) ) {
				$this->restore_locale();
				return;
			}

			$this->object                         = $order;
			$this->tickets                        = $purchaser_tickets;
			$this->raffle                         = wc_get_product( $raffle_id );
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{raffle_name}']  = $this->raffle ? $this->raffle->get_name() : '';
			$this->placeholders['{ticket_count}'] = count( $purchaser_tickets );

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
			return __( 'Good luck! We will notify you when the winner is drawn.', 'raffle-for-woocommerce' );
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
