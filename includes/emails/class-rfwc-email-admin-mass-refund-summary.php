<?php
/**
 * Admin Mass Refund Summary Email.
 *
 * Sent to the admin after a mass refund completes with a summary of
 * orders refunded, total amount, and any failures.
 *
 * @package RaffleForWooCommerce
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RFWC_Email_Admin_Mass_Refund_Summary' ) ) :

	/**
	 * Admin Mass Refund Summary Email class.
	 *
	 * @since 1.1.0
	 * @extends WC_Email
	 */
	class RFWC_Email_Admin_Mass_Refund_Summary extends WC_Email {

		/**
		 * Raffle product.
		 *
		 * @var RFWC_Product_Raffle|null
		 */
		public $raffle = null;

		/**
		 * Refund summary data.
		 *
		 * @var array
		 */
		public $summary = array();

		/**
		 * Constructor.
		 *
		 * @since 1.1.0
		 */
		public function __construct() {
			$this->id          = 'rfwc_admin_mass_refund_summary';
			$this->title       = __( 'Raffle Mass Refund Summary', 'raffle-for-woocommerce' );
			$this->description = __( 'Sent to the admin after a mass refund is completed for a raffle.', 'raffle-for-woocommerce' );

			$this->template_html  = 'emails/admin-mass-refund-summary.php';
			$this->template_plain = 'emails/plain/admin-mass-refund-summary.php';
			$this->placeholders   = array(
				'{raffle_name}'     => '',
				'{orders_refunded}' => '',
				'{total_amount}'    => '',
			);

			// Hook to the WooCommerce-dispatched `_notification` variant so the
			// handler runs even when WC_Emails is initialised by this same
			// do_action.
			add_action( 'rfwc_mass_refund_completed_notification', array( $this, 'trigger' ), 10, 2 );

			parent::__construct();

			$this->recipient     = $this->get_option( 'recipient', get_option( 'admin_email' ) );
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
			return __( 'Mass refund completed for {raffle_name}', 'raffle-for-woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since 1.1.0
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Mass Refund Summary', 'raffle-for-woocommerce' );
		}

		/**
		 * Trigger the email.
		 *
		 * @since 1.1.0
		 *
		 * @param int   $raffle_id Raffle product ID.
		 * @param array $summary   Refund summary: orders_refunded, orders_failed, total_amount, failures.
		 */
		public function trigger( $raffle_id, $summary ) {
			$this->setup_locale();

			if ( 'yes' !== get_option( 'rfwc_email_admin_mass_refund', 'yes' ) ) {
				$this->restore_locale();
				return;
			}

			$this->raffle  = wc_get_product( $raffle_id );
			$this->summary = wp_parse_args(
				$summary,
				array(
					'orders_refunded' => 0,
					'orders_failed'   => 0,
					'total_amount'    => 0,
					'failures'        => array(),
				)
			);

			$this->placeholders['{raffle_name}']     = $this->raffle ? $this->raffle->get_name() : __( 'Unknown Raffle', 'raffle-for-woocommerce' );
			$this->placeholders['{orders_refunded}'] = $this->summary['orders_refunded'];
			$this->placeholders['{total_amount}']    = wp_strip_all_tags( wc_price( $this->summary['total_amount'] ) );

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
					'raffle'             => $this->raffle,
					'summary'            => $this->summary,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => true,
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
					'raffle'             => $this->raffle,
					'summary'            => $this->summary,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => true,
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
			return '';
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
				'recipient'          => array(
					'title'       => __( 'Recipient(s)', 'raffle-for-woocommerce' ),
					'type'        => 'text',
					/* translators: %s: default recipient email */
					'description' => sprintf( __( 'Enter recipients (comma separated). Defaults to %s.', 'raffle-for-woocommerce' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
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
