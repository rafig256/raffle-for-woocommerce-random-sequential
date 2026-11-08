<?php
/**
 * Prize Claimed Confirmation Email.
 *
 * Sent to the winner when an admin marks their prize as claimed.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Prize_Claimed' ) ) :

	/**
	 * Prize Claimed Confirmation Email class.
	 *
	 * @since 1.0.4
	 * @extends WC_Email
	 */
	class RFWC_Email_Prize_Claimed extends WC_Email {

		/**
		 * Winning ticket.
		 *
		 * @var RFWC_Ticket|null
		 */
		public $ticket = null;

		/**
		 * Raffle product.
		 *
		 * @var RFWC_Product_Raffle|null
		 */
		public $raffle = null;

		/**
		 * Constructor.
		 *
		 * @since 1.0.4
		 */
		public function __construct() {
			$this->id             = 'rfwc_prize_claimed';
			$this->customer_email = true;
			$this->title          = __( 'Prize Claimed Confirmation', 'raffle-for-woocommerce' );
			$this->description    = __( 'Sent to winners when their prize is marked as claimed by an admin.', 'raffle-for-woocommerce' );
			$this->template_html  = 'emails/prize-claimed.php';
			$this->template_plain = 'emails/plain/prize-claimed.php';
			$this->placeholders   = array(
				'{winner_name}'   => '',
				'{raffle_name}'   => '',
				'{ticket_number}' => '',
				'{site_title}'    => $this->get_blogname(),
			);

			// Trigger.
			// Hook to the WooCommerce-dispatched `_notification` variant so the
			// handler runs even when WC_Emails is initialised by this same
			// do_action.
			add_action( 'rfwc_prize_claimed_notification', array( $this, 'trigger' ), 10, 2 );

			// Parent constructor.
			parent::__construct();

			// Template paths.
			$this->template_base = RFWC_PLUGIN_PATH . 'templates/';
		}

		/**
		 * Get email subject.
		 *
		 * @since 1.0.4
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( 'Your prize from {raffle_name} has been claimed', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.0.4
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Prize Claimed!', 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.0.4
		 *
		 * @param RFWC_Ticket $ticket    Winning ticket object.
		 * @param int         $raffle_id Raffle product ID.
		 */
		public function trigger( $ticket, $raffle_id ) {
			$this->setup_locale();

			// Check the Raffle settings toggle.
			if ( 'yes' !== get_option( 'rfwc_email_prize_claimed', 'yes' ) ) {
				$this->restore_locale();
				return;
			}

			if ( ! $ticket ) {
				$this->restore_locale();
				return;
			}

			$this->ticket    = $ticket;
			$this->raffle    = wc_get_product( $raffle_id );
			$this->recipient = $ticket->get_holder_email();

			$this->placeholders['{winner_name}']   = $ticket->get_holder_name();
			$this->placeholders['{raffle_name}']   = $this->raffle ? $this->raffle->get_name() : '';
			$this->placeholders['{ticket_number}'] = $ticket->get_formatted_ticket_number();

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content HTML.
		 *
		 * @since 1.0.4
		 *
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				array(
					'ticket'             => $this->ticket,
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
		 * @since 1.0.4
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				array(
					'ticket'             => $this->ticket,
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
		 * @since 1.0.4
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'Thank you for participating. We hope to see you in our next raffle!', 'raffle-for-woocommerce' );
		}

		/**
		 * Initialize settings form fields.
		 *
		 * @since 1.0.4
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
