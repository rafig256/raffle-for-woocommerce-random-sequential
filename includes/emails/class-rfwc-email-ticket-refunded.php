<?php
/**
 * Ticket Refunded Email.
 *
 * Sent to the ticket holder (purchaser or gift recipient) when their
 * raffle ticket is refunded via selective or mass refund.
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Ticket_Refunded' ) ) :

	/**
	 * Ticket Refunded Email class.
	 *
	 * @since 1.1.0
	 * @extends WC_Email
	 */
	class RFWC_Email_Ticket_Refunded extends WC_Email {

		/**
		 * Refunded tickets for this email.
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
		 * Refund amount.
		 *
		 * @var float
		 */
		public $refund_amount = 0;

		/**
		 * Refund reason.
		 *
		 * @var string
		 */
		public $refund_reason = '';

		/**
		 * Constructor.
		 *
		 * @since 1.1.0
		 */
		public function __construct() {
			$this->id             = 'rfwc_ticket_refunded';
			$this->customer_email = true;
			$this->title          = __( 'Raffle Ticket Refunded', 'raffle-for-woocommerce' );
			$this->description    = __( 'Sent to ticket holders when their raffle tickets are refunded.', 'raffle-for-woocommerce' );
			$this->template_html  = 'emails/ticket-refunded.php';
			$this->template_plain = 'emails/plain/ticket-refunded.php';
			$this->placeholders   = array(
				'{raffle_name}'    => '',
				'{ticket_count}'   => '',
				'{refund_amount}'  => '',
				'{refund_reason}'  => '',
			);

			// Hook to the WooCommerce-dispatched `_notification` variant of
			// `rfwc_ticket_refunded` so the handler runs after WC_Emails has
			// loaded the email classes for the request.
			add_action( 'rfwc_ticket_refunded_notification', array( $this, 'trigger' ), 10, 4 );

			parent::__construct();

			$this->template_base = RFWC_PLUGIN_PATH . 'templates/';
		}

		/**
		 * Get email subject.
		 *
		 * @since 1.1.0
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( 'Your raffle tickets for {raffle_name} have been refunded', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.1.0
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Raffle Ticket Refund', 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.1.0
		 *
		 * @param string $recipient_email Recipient email address.
		 * @param array  $tickets         Array of ticket data arrays with 'ticket_number' keys.
		 * @param int    $raffle_id       Raffle product ID.
		 * @param array  $args            Additional arguments: refund_amount, refund_reason, order_id.
		 */
		public function trigger( $recipient_email, $tickets, $raffle_id, $args = array() ) {
			$this->setup_locale();

			if ( 'yes' !== get_option( 'rfwc_email_ticket_refunded', 'yes' ) ) {
				$this->restore_locale();
				return;
			}

			if ( empty( $recipient_email ) || empty( $tickets ) ) {
				$this->restore_locale();
				return;
			}

			$this->raffle        = wc_get_product( $raffle_id );
			$this->tickets       = $tickets;
			$this->recipient     = $recipient_email;
			$this->refund_amount = isset( $args['refund_amount'] ) ? (float) $args['refund_amount'] : 0;
			$this->refund_reason = isset( $args['refund_reason'] ) ? $args['refund_reason'] : '';

			if ( isset( $args['order_id'] ) ) {
				$this->object = wc_get_order( $args['order_id'] );
			}

			$this->placeholders['{raffle_name}']   = $this->raffle ? $this->raffle->get_name() : '';
			$this->placeholders['{ticket_count}']  = count( $tickets );
			$this->placeholders['{refund_amount}'] = $this->refund_amount > 0 ? wp_strip_all_tags( wc_price( $this->refund_amount ) ) : '';
			$this->placeholders['{refund_reason}'] = $this->refund_reason;

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content HTML.
		 *
		 * @since 1.1.0
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
					'refund_amount'      => $this->refund_amount,
					'refund_reason'      => $this->refund_reason,
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
		 * @since 1.1.0
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
					'refund_amount'      => $this->refund_amount,
					'refund_reason'      => $this->refund_reason,
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
		 * @since 1.1.0
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'If you have any questions about this refund, please contact us.', 'raffle-for-woocommerce' );
		}

		/**
		 * Initialize settings form fields.
		 *
		 * @since 1.1.0
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
