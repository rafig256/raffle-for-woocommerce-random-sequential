<?php
/**
 * QR Code generator class.
 *
 * Handles QR code generation for raffle tickets using QR Server API.
 * No external PHP libraries required - uses free public API.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RFWC_QR_Code class.
 *
 * Generates QR codes using the free QR Server API.
 *
 * @since 1.0.0
 */
class RFWC_QR_Code {

	/**
	 * Default QR code size in pixels.
	 *
	 * @var int
	 */
	const DEFAULT_SIZE = 150;

	/**
	 * QR Server API base URL.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.qrserver.com/v1/create-qr-code/';

	/**
	 * Get the configured QR code size.
	 *
	 * @since 1.0.0
	 *
	 * @return int QR code size in pixels.
	 */
	public static function get_size() {
		return absint( get_option( 'rfwc_qr_code_size', self::DEFAULT_SIZE ) );
	}

	/**
	 * Generate QR code URL using QR Server API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Data to encode in the QR code.
	 * @param int    $size Size in pixels (default 150).
	 * @return string QR code image URL.
	 */
	public static function get_qr_url( $data, $size = self::DEFAULT_SIZE ) {
		$params = array(
			'size'   => $size . 'x' . $size,
			'data'   => $data,
			'format' => 'png',
			'margin' => 10,
		);

		return self::API_URL . '?' . http_build_query( $params );
	}

	/**
	 * Generate QR code as base64 data URI.
	 *
	 * Fetches the QR code image and converts to base64 for embedding.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Data to encode.
	 * @param int    $size Size in pixels.
	 * @return string|false Base64 data URI or false on failure.
	 */
	public static function get_base64( $data, $size = self::DEFAULT_SIZE ) {
		$url = self::get_qr_url( $data, $size );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'image/png',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for data URI.
		return 'data:image/png;base64,' . base64_encode( $body );
	}

	/**
	 * Generate verification URL for a ticket.
	 *
	 * Creates a URL that can be encoded in a QR code for ticket validation.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @return string Verification URL.
	 */
	public static function get_verification_url( $ticket ) {
		$hash = self::generate_ticket_hash( $ticket );

		return add_query_arg(
			array(
				'rfwc_validate' => 1,
				'ticket'        => $ticket->get_formatted_ticket_number(),
				'hash'          => $hash,
			),
			home_url()
		);
	}

	/**
	 * Generate a secure hash for ticket validation.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @return string 16-character hash string.
	 */
	public static function generate_ticket_hash( $ticket ) {
		$data = $ticket->get_id() . '|' . $ticket->get_formatted_ticket_number() . '|' . $ticket->get_raffle_id();
		return substr( wp_hash( $data ), 0, 16 );
	}

	/**
	 * Generate QR code URL for a ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @param int         $size   Size in pixels.
	 * @return string QR code image URL.
	 */
	public static function get_ticket_qr_url( $ticket, $size = self::DEFAULT_SIZE ) {
		$verification_url = self::get_verification_url( $ticket );
		return self::get_qr_url( $verification_url, $size );
	}

	/**
	 * Generate QR code HTML image tag for a ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @param int         $size   Size in pixels.
	 * @param array       $attrs  Additional HTML attributes.
	 * @return string HTML img tag.
	 */
	public static function get_ticket_qr_img( $ticket, $size = self::DEFAULT_SIZE, $attrs = array() ) {
		$url           = self::get_ticket_qr_url( $ticket, $size );
		$ticket_number = $ticket->get_formatted_ticket_number();

		$default_attrs = array(
			'src'    => $url,
			'alt'    => sprintf(
				/* translators: %s: ticket number */
				__( 'QR Code for ticket %s', 'raffle-for-woocommerce' ),
				$ticket_number
			),
			'width'  => $size,
			'height' => $size,
			'class'  => 'rfwc-qr-code',
		);

		$attrs = wp_parse_args( $attrs, $default_attrs );

		$attr_string = '';
		foreach ( $attrs as $key => $value ) {
			$attr_string .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return '<img' . $attr_string . ' />';
	}

	/**
	 * Render QR code with download information.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket object.
	 * @param int         $size   Size in pixels.
	 * @return string HTML output.
	 */
	public static function render_with_info( $ticket, $size = self::DEFAULT_SIZE ) {
		$qr_url        = self::get_ticket_qr_url( $ticket, $size );
		$ticket_number = $ticket->get_formatted_ticket_number();

		ob_start();
		?>
		<div class="rfwc-qr-container">
			<img src="<?php echo esc_url( $qr_url ); ?>" 
				alt="<?php esc_attr_e( 'Ticket QR Code', 'raffle-for-woocommerce' ); ?>" 
				width="<?php echo esc_attr( $size ); ?>" 
				height="<?php echo esc_attr( $size ); ?>" 
				class="rfwc-qr-code" />
			<p class="rfwc-qr-ticket-code">
				<strong><?php esc_html_e( 'Ticket Number:', 'raffle-for-woocommerce' ); ?></strong>
				<code><?php echo esc_html( $ticket_number ); ?></code>
			</p>
			<p class="rfwc-qr-instructions">
				<?php esc_html_e( 'Present this QR code if required for verification.', 'raffle-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}

