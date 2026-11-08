<?php
/**
 * Winner Notification Email.
 *
 * Sent to the winner when they are selected in a raffle draw.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Winner_Notification' ) ) :

	/**
	 * Winner Notification Email class.
	 *
	 * @since 1.0.0
	 * @extends WC_Email
	 */
	class RFWC_Email_Winner_Notification extends WC_Email {

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
		 * Resolved prize name (filterable, may differ for IW vs draw winners).
		 *
		 * @var string
		 */
		public $prize_name = '';

		/**
		 * Resolved prize value (filterable, may differ for IW vs draw winners).
		 *
		 * @var float
		 */
		public $prize_value = 0;

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->id             = 'rfwc_winner_notification';
			$this->customer_email = true;
			$this->title          = __( 'Raffle Winner Notification', 'raffle-for-woocommerce' );
			$this->description    = __( 'Sent to winners when they are selected in a raffle draw.', 'raffle-for-woocommerce' );
			$this->template_html  = 'emails/winner-notification.php';
			$this->template_plain = 'emails/plain/winner-notification.php';
			$this->placeholders   = array(
				'{winner_name}'   => '',
				'{raffle_name}'   => '',
				'{ticket_number}' => '',
				'{prize_name}'    => '',
				'{site_title}'    => $this->get_blogname(),
			);

			// Triggers.
			// Hook to the WooCommerce-dispatched `_notification` variants so
			// the handler runs even when WC_Emails is initialised by this same
			// do_action.
			add_action( 'rfwc_winner_selected_notification', array( $this, 'trigger' ), 10, 2 );
			add_action( 'rfwc_resend_winner_notification_notification', array( $this, 'trigger' ), 10, 2 );

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
			return __( '🎉 Congratulations! You won {raffle_name}!', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( "You're a Winner!", 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.0.0
		 *
		 * @param RFWC_Ticket $ticket    Winning ticket object.
		 * @param int         $raffle_id Raffle product ID.
		 */
		public function trigger( $ticket, $raffle_id ) {
			$this->setup_locale();

			// Check the Raffle settings toggle.
			if ( 'yes' !== get_option( 'rfwc_email_winner_notification', 'yes' ) ) {
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

			// Get prize name and value, filterable so Pro can swap in IW-specific prizes.
			$prize_name  = '';
			$prize_value = 0;
			if ( $this->raffle ) {
				if ( method_exists( $this->raffle, 'get_prize_name' ) ) {
					$prize_name = $this->raffle->get_prize_name();
				}
				if ( method_exists( $this->raffle, 'get_prize_value' ) ) {
					$prize_value = $this->raffle->get_prize_value();
				}
			}

			/**
			 * Filters the prize name shown in the winner notification email.
			 *
			 * @since 1.1.2
			 *
			 * @param string              $prize_name Prize display name.
			 * @param RFWC_Ticket         $ticket     The winning ticket.
			 * @param RFWC_Product_Raffle $raffle     The raffle product.
			 */
			$this->prize_name = apply_filters( 'rfwc_winner_email_prize_name', $prize_name, $ticket, $this->raffle );

			/**
			 * Filters the prize value shown in the winner notification email.
			 *
			 * @since 1.1.2
			 *
			 * @param float               $prize_value Prize monetary value.
			 * @param RFWC_Ticket         $ticket      The winning ticket.
			 * @param RFWC_Product_Raffle $raffle      The raffle product.
			 */
			$this->prize_value = apply_filters( 'rfwc_winner_email_prize_value', $prize_value, $ticket, $this->raffle );

			$this->placeholders['{winner_name}']   = $ticket->get_holder_name();
			$this->placeholders['{raffle_name}']   = $this->raffle ? $this->raffle->get_name() : '';
			$this->placeholders['{ticket_number}'] = $ticket->get_formatted_ticket_number();
			$this->placeholders['{prize_name}']    = $this->prize_name;

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
		 * @since 1.0.0
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
		 * @since 1.0.0
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'We will contact you shortly with details on how to claim your prize. Congratulations again!', 'raffle-for-woocommerce' );
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
