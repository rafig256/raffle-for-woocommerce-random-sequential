<?php
/**
 * QR code ticket validation.
 *
 * Provides an admin scanner page to validate tickets at events.
 * Accepts QR codes or manual ticket number entry.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin ticket scanner for event check-in.
 *
 * @since 1.0.0
 */
class RFWC_QR_Validator {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'handle_validation_request' ) );
		add_action( 'admin_menu', array( $this, 'add_scanner_page' ), 51 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scanner_assets' ) );
		add_action( 'wp_ajax_rfwc_validate_ticket', array( $this, 'ajax_validate_ticket' ) );
		add_action( 'wp_ajax_rfwc_checkin_ticket', array( $this, 'ajax_checkin_ticket' ) );
	}

	/**
	 * Enqueue scanner page assets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scanner_assets( $hook ) {
		// Only load on our scanner page.
		if ( 'woocommerce_page_rfwc-ticket-scanner' !== $hook ) {
			return;
		}

		// Register and enqueue scanner styles.
		wp_register_style( 'rfwc-scanner', false, array(), RFWC_VERSION );
		wp_enqueue_style( 'rfwc-scanner' );
		wp_add_inline_style( 'rfwc-scanner', $this->get_scanner_css() );

		// Register and enqueue scanner script.
		wp_register_script(
			'rfwc-scanner',
			false,
			array( 'jquery' ),
			RFWC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script( 'rfwc-scanner' );
		wp_add_inline_script( 'rfwc-scanner', $this->get_scanner_js() );
	}

	/**
	 * Get scanner page CSS.
	 *
	 * @since 1.0.0
	 *
	 * @return string CSS content.
	 */
	private function get_scanner_css() {
		return '
			.rfwc-stop-scanner-hidden,
			.rfwc-validation-result-hidden {
				display: none;
			}
			.rfwc-scanner-layout {
				display: flex;
				gap: 20px;
				margin-top: 20px;
			}
			.rfwc-scanner-main {
				flex: 1;
				max-width: 700px;
			}
			.rfwc-scanner-sidebar {
				width: 280px;
				flex-shrink: 0;
			}
			.rfwc-scanner-info {
				background: #fff;
				padding: 20px;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				position: sticky;
				top: 32px;
			}
			.rfwc-scanner-info h3 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.rfwc-scanner-info h4 {
				margin-bottom: 10px;
			}
			.rfwc-scanner-info ul {
				list-style: disc;
				margin-left: 20px;
				margin-bottom: 0;
			}
			.rfwc-scanner-info li {
				margin-bottom: 5px;
			}
			.rfwc-scanner-section,
			.rfwc-manual-section,
			.rfwc-validation-result-section,
			.rfwc-recent-validations {
				background: #fff;
				padding: 20px;
				margin-bottom: 20px;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
			}
			.rfwc-scanner-video-container {
				position: relative;
				width: 100%;
				max-width: 400px;
				margin: 20px 0;
				background: #000;
				border-radius: 4px;
				overflow: hidden;
			}
			#rfwc-scanner-video {
				width: 100%;
				display: block;
			}
			.rfwc-scanner-overlay {
				position: absolute;
				top: 50%;
				left: 50%;
				width: 200px;
				height: 200px;
				margin: -100px 0 0 -100px;
				border: 2px solid rgba(127, 84, 179, 0.8);
				border-radius: 8px;
				pointer-events: none;
			}
			.rfwc-scanner-controls {
				margin-top: 10px;
			}
			.rfwc-validation-result-card {
				padding: 20px;
				border-radius: 8px;
				margin-bottom: 10px;
			}
			.rfwc-validation-result-card.valid {
				background: #d4edda;
				border: 1px solid #c3e6cb;
			}
			.rfwc-validation-result-card.invalid {
				background: #f8d7da;
				border: 1px solid #f5c6cb;
			}
			.rfwc-validation-result-card.winner {
				background: #fff3cd;
				border: 1px solid #ffeeba;
			}
			.rfwc-validation-result-card.instant-winner {
				background: linear-gradient(135deg, #fffbeb, #fef3c7);
				border: 2px solid #f59e0b;
			}
			.rfwc-ticket-number-large {
				font-size: 24px;
				font-family: monospace;
				color: #7f54b3;
			}
			.rfwc-checkin-btn {
				margin-top: 10px;
			}
			@media screen and (max-width: 960px) {
				.rfwc-scanner-layout {
					flex-direction: column;
				}
				.rfwc-scanner-sidebar {
					width: 100%;
				}
				.rfwc-scanner-info {
					position: static;
				}
			}
		';
	}

	/**
	 * Get scanner page JavaScript.
	 *
	 * @since 1.0.0
	 *
	 * @return string JavaScript content.
	 */
	private function get_scanner_js() {
		$i18n = array(
			'cameraNotSupported' => __( 'Camera not supported on this device.', 'raffle-for-woocommerce' ),
			'cameraAccessError'  => __( 'Could not access camera. Please check permissions.', 'raffle-for-woocommerce' ),
			'validating'         => __( 'Validating...', 'raffle-for-woocommerce' ),
			'validationFailed'   => __( 'Validation failed. Please try again.', 'raffle-for-woocommerce' ),
			'ticketNumber'       => __( 'Ticket Number:', 'raffle-for-woocommerce' ),
			'status'             => __( 'Status:', 'raffle-for-woocommerce' ),
			'raffle'             => __( 'Raffle:', 'raffle-for-woocommerce' ),
			'holder'             => __( 'Holder:', 'raffle-for-woocommerce' ),
			'checkInTicket'      => __( 'Check In Ticket', 'raffle-for-woocommerce' ),
			'checkedIn'          => __( 'Checked In:', 'raffle-for-woocommerce' ),
			'checkingIn'         => __( 'Checking in...', 'raffle-for-woocommerce' ),
			'invalidTicket'      => __( 'Invalid Ticket', 'raffle-for-woocommerce' ),
			'winnerMethod'       => __( 'Won via:', 'raffle-for-woocommerce' ),
			'validateNonce'      => wp_create_nonce( 'rfwc_validate_ticket' ),
			'checkinNonce'       => wp_create_nonce( 'rfwc_checkin_ticket' ),
		);

		return '
		jQuery(function($) {
			var rfwI18n = ' . wp_json_encode( $i18n ) . ';
			var scanner = null;
			var isScanning = false;

			// Start camera scanner.
			$("#rfwc-start-scanner").on("click", function() {
				startScanner();
			});

			// Stop camera scanner.
			$("#rfwc-stop-scanner").on("click", function() {
				stopScanner();
			});

			// Manual validation form.
			$("#rfwc-manual-validation-form").on("submit", function(e) {
				e.preventDefault();
				var ticketNumber = $("#rfwc-ticket-number").val().trim();
				if (ticketNumber) {
					validateTicket(ticketNumber);
				}
			});

			function startScanner() {
				if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
					alert(rfwI18n.cameraNotSupported);
					return;
				}

				navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
					.then(function(stream) {
						var video = document.getElementById("rfwc-scanner-video");
						video.srcObject = stream;
						video.play();
					isScanning = true;
					$("#rfwc-start-scanner").addClass("rfwc-stop-scanner-hidden");
					$("#rfwc-stop-scanner").removeClass("rfwc-stop-scanner-hidden");
						scanFrame(video);
					})
					.catch(function(err) {
						console.error("Camera error:", err);
						alert(rfwI18n.cameraAccessError);
					});
			}

			function stopScanner() {
				var video = document.getElementById("rfwc-scanner-video");
				if (video.srcObject) {
					video.srcObject.getTracks().forEach(function(track) {
						track.stop();
					});
				}
				isScanning = false;
				$("#rfwc-start-scanner").removeClass("rfwc-stop-scanner-hidden");
				$("#rfwc-stop-scanner").addClass("rfwc-stop-scanner-hidden");
			}

			function scanFrame(video) {
				if (!isScanning) return;

				// Use BarcodeDetector API if available.
				if ("BarcodeDetector" in window) {
					var detector = new BarcodeDetector({ formats: ["qr_code"] });
					detector.detect(video)
						.then(function(barcodes) {
							if (barcodes.length > 0) {
								processQRCode(barcodes[0].rawValue);
							} else {
								requestAnimationFrame(function() { scanFrame(video); });
							}
						})
						.catch(function(err) {
							requestAnimationFrame(function() { scanFrame(video); });
						});
				} else {
					// Fallback: Manual entry only.
					console.log("BarcodeDetector not supported. Use manual entry.");
				}
			}

			function processQRCode(data) {
				stopScanner();

				// Parse URL to get ticket number.
				var url = new URL(data);
				var ticketNumber = url.searchParams.get("ticket");
				if (ticketNumber) {
					$("#rfwc-ticket-number").val(ticketNumber);
					validateTicket(ticketNumber);
				}
			}

			function validateTicket(ticketNumber) {
				$(".rfwc-validation-result-section").removeClass("rfwc-validation-result-hidden");
				$("#rfwc-validation-result").html("<p>" + rfwI18n.validating + "</p>");

				$.ajax({
					url: ajaxurl,
					type: "POST",
					data: {
						action: "rfwc_validate_ticket",
						ticket_number: ticketNumber,
						nonce: rfwI18n.validateNonce
					},
					success: function(response) {
						if (response.success) {
							displayResult(response.data);
						} else {
							displayError(response.data.message);
						}
					},
					error: function() {
						displayError(rfwI18n.validationFailed);
					}
				});
			}

			function escHtml(str) {
				var d = document.createElement("div");
				d.appendChild(document.createTextNode(str || ""));
				return d.innerHTML;
			}

			function displayResult(data) {
				var cardClass = "invalid";
				if (data.status === "active") { cardClass = "valid"; }
				else if (data.status === "winner" && data.winner_method === "instant_win") { cardClass = "winner instant-winner"; }
				else if (data.status === "winner") { cardClass = "winner"; }
				var html = "<div class=\"rfwc-validation-result-card " + cardClass + "\">";
				html += "<p><strong>" + escHtml(rfwI18n.ticketNumber) + "</strong><br>";
				html += "<span class=\"rfwc-ticket-number-large\">" + escHtml(data.ticket_number) + "</span></p>";
				html += "<p><strong>" + escHtml(rfwI18n.status) + "</strong> " + escHtml(data.status_label) + "</p>";

				if (data.winner_method_label) {
					var methodIcon = data.winner_method === "instant_win" ? "⚡ " : "🏆 ";
					html += "<p><strong>" + escHtml(rfwI18n.winnerMethod) + "</strong> " + methodIcon + escHtml(data.winner_method_label) + "</p>";
				}

				html += "<p><strong>" + escHtml(rfwI18n.raffle) + "</strong> " + escHtml(data.raffle_name) + "</p>";
				html += "<p><strong>" + escHtml(rfwI18n.holder) + "</strong> " + escHtml(data.holder_name) + "</p>";

				if ((data.status === "active" || data.status === "winner") && !data.checked_in) {
					html += "<button type=\"button\" class=\"button button-primary rfwc-checkin-btn\" data-ticket-id=\"" + escHtml(data.ticket_id) + "\">";
					html += escHtml(rfwI18n.checkInTicket) + "</button>";
				} else if (data.checked_in) {
					html += "<p><strong>" + escHtml(rfwI18n.checkedIn) + "</strong> " + escHtml(data.checkin_time) + "</p>";
				}

				html += "</div>";
				$("#rfwc-validation-result").html(html);
			}

			function displayError(message) {
				var html = "<div class=\"rfwc-validation-result-card invalid\">";
				html += "<p><strong>" + escHtml(rfwI18n.invalidTicket) + "</strong></p>";
				html += "<p>" + escHtml(message) + "</p>";
				html += "</div>";
				$("#rfwc-validation-result").html(html);
			}

			// Check-in button.
			$(document).on("click", ".rfwc-checkin-btn", function() {
				var ticketId = $(this).data("ticket-id");
				var $btn = $(this);
				$btn.prop("disabled", true).text(rfwI18n.checkingIn);

				$.ajax({
					url: ajaxurl,
					type: "POST",
					data: {
						action: "rfwc_checkin_ticket",
						ticket_id: ticketId,
						nonce: rfwI18n.checkinNonce
					},
					success: function(response) {
						if (response.success) {
							$btn.replaceWith("<p><strong>" + escHtml(rfwI18n.checkedIn) + "</strong> " + escHtml(response.data.checkin_time) + "</p>");
							location.reload();
						} else {
							$btn.prop("disabled", false).text(rfwI18n.checkInTicket);
							alert(response.data.message);
						}
					}
				});
			});
		});
		';
	}

	/**
	 * Handle public validation request from QR code scan.
	 *
	 * @since 1.0.0
	 */
	public function handle_validation_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint for QR code validation.
		if ( ! isset( $_GET['rfwc_validate'] ) || ! isset( $_GET['ticket'] ) || ! isset( $_GET['hash'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint secured by hash.
		$ticket_number = sanitize_text_field( wp_unslash( $_GET['ticket'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint secured by hash.
		$hash = sanitize_text_field( wp_unslash( $_GET['hash'] ) );

		// Find the ticket.
		$ticket = $this->find_ticket_by_number( $ticket_number );

		if ( ! $ticket ) {
			$this->display_validation_result( false, __( 'Invalid ticket number.', 'raffle-for-woocommerce' ) );
			return;
		}

		// Verify hash.
		$expected_hash = $this->generate_ticket_hash( $ticket );
		if ( ! hash_equals( $expected_hash, $hash ) ) {
			$this->display_validation_result( false, __( 'Invalid ticket verification code.', 'raffle-for-woocommerce' ) );
			return;
		}

		// Get raffle.
		$raffle = wc_get_product( $ticket->get_raffle_id() );
		if ( ! $raffle ) {
			$this->display_validation_result( false, __( 'Raffle not found.', 'raffle-for-woocommerce' ) );
			return;
		}

		// Log the validation.
		$this->log_validation( $ticket, 'public_scan' );

		// Display result.
		$this->display_validation_result( true, '', $ticket, $raffle );
	}

	/**
	 * Find ticket by number.
	 *
	 * Handles both raw ticket numbers (e.g., "0002") and formatted numbers with prefix (e.g., "RFL-0002").
	 *
	 * @since 1.0.0
	 *
	 * @param string $ticket_number The ticket number (with or without prefix).
	 * @return RFWC_Ticket|null
	 */
	private function find_ticket_by_number( $ticket_number ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );

		// Clean the input.
		$ticket_number = sanitize_text_field( $ticket_number );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// 1. Try exact match on raw ticket_number column.
		$ticket_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE ticket_number = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ticket_number
			)
		);

		// 2. Try removing dash-separated prefixes (e.g., "RFL-0002", "RAFFLE-0002").
		if ( ! $ticket_id && preg_match( '/^[A-Za-z]+-(.+)$/', $ticket_number, $matches ) ) {
			$ticket_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE ticket_number = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					sanitize_text_field( $matches[1] )
				)
			);
		}

		// 3. Try matching by formatted ticket number (prefix + raw number).
		// Handles non-dash prefixes like "RFW" where "RFW0YT6U7E2" = prefix "RFW" + raw "0YT6U7E2".
		if ( ! $ticket_id ) {
			$ticket_id = $this->find_ticket_by_formatted_number( $ticket_number );
		}

		// 4. Fallback: numeric part only (zero-padded exact match for sequential tickets).
		if ( ! $ticket_id ) {
			$numeric_part = preg_replace( '/^0+/', '', preg_replace( '/[^0-9]/', '', $ticket_number ) );
			if ( $numeric_part ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above via esc_sql().
				$ticket_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE ticket_number = %s LIMIT 1",
						str_pad( absint( $numeric_part ), 4, '0', STR_PAD_LEFT )
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $ticket_id ) {
			return null;
		}

		return RFWC_Ticket::get_by_id( $ticket_id );
	}

	/**
	 * Find a ticket by its formatted number (prefix + raw number).
	 *
	 * Collects all known ticket prefixes (global + per-raffle) and tries
	 * stripping each from the input to match the raw ticket_number in the DB.
	 *
	 * @since 1.0.8
	 *
	 * @param string $input The user-entered ticket number (possibly with prefix).
	 * @return int|null Ticket ID or null if not found.
	 */
	private function find_ticket_by_formatted_number( $input ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_tickets' );
		$input = strtoupper( trim( $input ) );

		// Collect all known prefixes (global setting + per-raffle overrides).
		$prefixes = array();

		$global_prefix = get_option( 'rfwc_ticket_prefix', '' );
		if ( '' !== $global_prefix ) {
			$prefixes[] = strtoupper( $global_prefix );
		}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name via $wpdb->postmeta.
		$raffle_prefixes = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rfwc_ticket_prefix' AND meta_value != ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $raffle_prefixes as $rp ) {
			$prefixes[] = strtoupper( $rp );
		}

		$prefixes = array_unique( $prefixes );

		// Sort longest first to avoid partial-prefix false positives.
		usort(
			$prefixes,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $input, $prefix ) && strlen( $input ) > strlen( $prefix ) ) {
				$raw = substr( $input, strlen( $prefix ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above via esc_sql().
				$ticket_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE UPPER(ticket_number) = %s LIMIT 1",
						$raw
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( $ticket_id ) {
					return (int) $ticket_id;
				}
			}
		}

		return null;
	}

	/**
	 * Generate ticket hash for validation.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket.
	 * @return string
	 */
	private function generate_ticket_hash( $ticket ) {
		// Delegate to the canonical implementation to avoid duplicate logic.
		return RFWC_QR_Code::generate_ticket_hash( $ticket );
	}

	/**
	 * Display validation result page.
	 *
	 * @since 1.0.0
	 *
	 * @param bool             $valid   Whether ticket is valid.
	 * @param string           $message Error message if invalid.
	 * @param RFWC_Ticket|null $ticket  The ticket object.
	 * @param WC_Product|null  $raffle  The raffle product.
	 */
	private function display_validation_result( $valid, $message = '', $ticket = null, $raffle = null ) {
		// Get header.
		get_header();

		echo '<div class="rfwc-validation-result" style="max-width: 600px; margin: 50px auto; padding: 30px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">';

		if ( $valid && $ticket && $raffle ) {
			$t_status        = $ticket->get_status();
			$winner_record   = null;
			$is_instant_win  = false;

			if ( 'winner' === $t_status ) {
				$winner_record  = RFWC_Winner::get_by_ticket( $ticket->get_id() );
				$is_instant_win = $winner_record && 'instant_win' === $winner_record->get_selection_method();
			}

			if ( 'active' === $t_status ) {
				$status_color = '#4caf50';
				$status_text  = __( 'VALID', 'raffle-for-woocommerce' );
			} elseif ( 'winner' === $t_status && $is_instant_win ) {
				$status_color = '#f59e0b';
				$status_text  = __( 'INSTANT WINNER', 'raffle-for-woocommerce' );
			} elseif ( 'winner' === $t_status ) {
				$status_color = '#daa520';
				$status_text  = __( 'WINNER', 'raffle-for-woocommerce' );
			} elseif ( 'refunded' === $t_status ) {
				$status_color = '#e91e63';
				$status_text  = __( 'REFUNDED', 'raffle-for-woocommerce' );
			} else {
				$status_color = '#f44336';
				$status_text  = strtoupper( $t_status );
			}

			echo '<div style="background: ' . esc_attr( $status_color ) . '; color: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px;">';
			echo '<h1 style="margin: 0; font-size: 24px;">' . esc_html( $status_text ) . '</h1>';
			echo '</div>';

			echo '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: left;">';

			echo '<p style="margin: 10px 0;"><strong>' . esc_html__( 'Ticket Number:', 'raffle-for-woocommerce' ) . '</strong><br>';
			echo '<span style="font-size: 24px; font-family: monospace; color: #7f54b3;">' . esc_html( $ticket->get_formatted_ticket_number() ) . '</span></p>';

			echo '<p style="margin: 10px 0;"><strong>' . esc_html__( 'Raffle:', 'raffle-for-woocommerce' ) . '</strong><br>';
			echo esc_html( $raffle->get_name() ) . '</p>';

			echo '<p style="margin: 10px 0;"><strong>' . esc_html__( 'Ticket Holder:', 'raffle-for-woocommerce' ) . '</strong><br>';
			echo esc_html( $this->mask_name( $ticket->get_holder_name() ) ) . '</p>';

			if ( 'winner' === $ticket->get_status() ) {
				if ( $is_instant_win ) {
					echo '<p style="margin: 10px 0; background: linear-gradient(135deg, #fbbf24, #f59e0b); padding: 10px; border-radius: 4px; text-align: center; color: #78350f;">';
					echo '<strong>⚡ ' . esc_html__( 'INSTANT WINNER!', 'raffle-for-woocommerce' ) . '</strong></p>';
				} else {
					echo '<p style="margin: 10px 0; background: #ffd700; padding: 10px; border-radius: 4px; text-align: center;">';
					echo '<strong>🏆 ' . esc_html__( 'WINNER!', 'raffle-for-woocommerce' ) . '</strong></p>';
				}

				if ( $winner_record ) {
					echo '<p style="margin: 10px 0; font-size: 12px; color: #666;">';
					printf(
						/* translators: %s: selection method label */
						esc_html__( 'Won via: %s', 'raffle-for-woocommerce' ),
						esc_html( $winner_record->get_selection_method_label() )
					);
					echo '</p>';
				}
			}

			echo '</div>';

			echo '<p style="margin-top: 20px; color: #666; font-size: 12px;">';
			echo esc_html__( 'Verified by', 'raffle-for-woocommerce' ) . ' ' . esc_html( get_bloginfo( 'name' ) );
			echo '</p>';

		} else {
			echo '<div style="background: #f44336; color: #fff; padding: 20px; border-radius: 8px;">';
			echo '<h1 style="margin: 0; font-size: 24px;">' . esc_html__( 'INVALID', 'raffle-for-woocommerce' ) . '</h1>';
			echo '</div>';

			if ( $message ) {
				echo '<p style="margin-top: 20px; color: #666;">' . esc_html( $message ) . '</p>';
			}
		}

		echo '</div>';

		get_footer();
		exit;
	}

	/**
	 * Add scanner admin page.
	 *
	 * @since 1.0.0
	 */
	public function add_scanner_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Ticket Scanner', 'raffle-for-woocommerce' ),
			__( 'Ticket Scanner', 'raffle-for-woocommerce' ),
			'manage_woocommerce',
			'rfwc-ticket-scanner',
			array( $this, 'render_scanner_page' ),
			4
		);
	}

	/**
	 * Render the scanner admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_scanner_page() {
		?>
		<div class="wrap rfwc-scanner-page">
			<h1><?php esc_html_e( 'Raffle Ticket Scanner', 'raffle-for-woocommerce' ); ?></h1>

			<div class="rfwc-scanner-layout">
				<div class="rfwc-scanner-main">
					<div class="rfwc-scanner-section">
						<h2><?php esc_html_e( 'Scan QR Code', 'raffle-for-woocommerce' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Use your device camera to scan a ticket QR code, or enter the ticket number manually.', 'raffle-for-woocommerce' ); ?></p>

						<div class="rfwc-scanner-video-container">
							<video id="rfwc-scanner-video" playsinline></video>
							<div class="rfwc-scanner-overlay"></div>
						</div>

						<div class="rfwc-scanner-controls">
							<button type="button" id="rfwc-start-scanner" class="button button-primary">
								<?php esc_html_e( 'Start Camera', 'raffle-for-woocommerce' ); ?>
							</button>
							<button type="button" id="rfwc-stop-scanner" class="button rfwc-stop-scanner-hidden">
								<?php esc_html_e( 'Stop Camera', 'raffle-for-woocommerce' ); ?>
							</button>
						</div>
					</div>

					<div class="rfwc-manual-section">
						<h2><?php esc_html_e( 'Manual Entry', 'raffle-for-woocommerce' ); ?></h2>

						<form id="rfwc-manual-validation-form">
							<p>
								<label for="rfwc-ticket-number"><?php esc_html_e( 'Ticket Number', 'raffle-for-woocommerce' ); ?></label>
								<input type="text" id="rfwc-ticket-number" name="ticket_number" class="regular-text" placeholder="<?php esc_attr_e( 'Enter ticket number...', 'raffle-for-woocommerce' ); ?>">
							</p>
							<p>
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Validate Ticket', 'raffle-for-woocommerce' ); ?>
								</button>
							</p>
						</form>
					</div>

					<div class="rfwc-validation-result-section rfwc-validation-result-hidden">
						<h2><?php esc_html_e( 'Validation Result', 'raffle-for-woocommerce' ); ?></h2>
						<div id="rfwc-validation-result"></div>
					</div>

					<div class="rfwc-recent-validations">
						<h2><?php esc_html_e( 'Recent Validations', 'raffle-for-woocommerce' ); ?></h2>
						<?php $this->display_recent_validations(); ?>
					</div>
				</div>

				<div class="rfwc-scanner-sidebar">
					<div class="rfwc-scanner-info">
						<h3><?php esc_html_e( 'About This Tool', 'raffle-for-woocommerce' ); ?></h3>
						<p><?php esc_html_e( 'The Ticket Scanner allows you to verify raffle tickets at live events or when participants arrive to claim prizes.', 'raffle-for-woocommerce' ); ?></p>
						<h4><?php esc_html_e( 'Use it to:', 'raffle-for-woocommerce' ); ?></h4>
						<ul>
							<li><?php esc_html_e( 'Verify tickets are valid', 'raffle-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Check-in attendees', 'raffle-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Confirm winner status', 'raffle-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Track validation history', 'raffle-for-woocommerce' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for ticket validation.
	 *
	 * @since 1.0.0
	 */
	public function ajax_validate_ticket() {
		check_ajax_referer( 'rfwc_validate_ticket', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket_number = isset( $_POST['ticket_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_number'] ) ) : '';

		if ( empty( $ticket_number ) ) {
			wp_send_json_error( array( 'message' => __( 'Ticket number is required.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket = $this->find_ticket_by_number( $ticket_number );

		if ( ! $ticket ) {
			// Log failed validation attempt.
			$this->log_failed_validation( $ticket_number, 'admin_scan' );
			wp_send_json_error( array( 'message' => __( 'Ticket not found.', 'raffle-for-woocommerce' ) ) );
		}

		$raffle = wc_get_product( $ticket->get_raffle_id() );

		if ( ! $raffle ) {
			wp_send_json_error( array( 'message' => __( 'Raffle not found.', 'raffle-for-woocommerce' ) ) );
		}

		// Log the validation.
		$this->log_validation( $ticket, 'admin_scan' );

		// Get check-in status.
		$checkin_time = $ticket->get_meta( '_checkin_time' );

		// Status labels.
		$status_labels = array(
			'active'    => __( 'Active', 'raffle-for-woocommerce' ),
			'winner'    => __( 'Winner', 'raffle-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'raffle-for-woocommerce' ),
			'expired'   => __( 'Expired', 'raffle-for-woocommerce' ),
			'refunded'  => __( 'Refunded', 'raffle-for-woocommerce' ),
		);

		// Determine winner method (instant win vs draw) for winner tickets.
		$winner_method       = '';
		$winner_method_label = '';
		if ( 'winner' === $ticket->get_status() ) {
			$winner_record = RFWC_Winner::get_by_ticket( $ticket->get_id() );
			if ( $winner_record ) {
				$winner_method       = $winner_record->get_selection_method();
				$winner_method_label = $winner_record->get_selection_method_label();

				if ( 'instant_win' === $winner_method ) {
					$status_labels['winner'] = __( 'Instant Winner', 'raffle-for-woocommerce' );
				}
			}
		}

		wp_send_json_success(
			array(
				'ticket_id'           => $ticket->get_id(),
				'ticket_number'       => $ticket->get_formatted_ticket_number(),
				'status'              => $ticket->get_status(),
				'status_label'        => isset( $status_labels[ $ticket->get_status() ] ) ? $status_labels[ $ticket->get_status() ] : $ticket->get_status(),
				'raffle_name'         => $raffle->get_name(),
				'holder_name'         => $ticket->get_holder_name(),
				'holder_email'        => $ticket->get_holder_email(),
				'checked_in'          => ! empty( $checkin_time ),
				'checkin_time'        => $checkin_time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $checkin_time ) ) : '',
				'winner_method'       => $winner_method,
				'winner_method_label' => $winner_method_label,
			)
		);
	}

	/**
	 * AJAX handler for ticket check-in.
	 *
	 * @since 1.0.0
	 */
	public function ajax_checkin_ticket() {
		check_ajax_referer( 'rfwc_checkin_ticket', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;

		if ( ! $ticket_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ticket ID.', 'raffle-for-woocommerce' ) ) );
		}

		$ticket = RFWC_Ticket::get_by_id( $ticket_id );

		if ( ! $ticket ) {
			wp_send_json_error( array( 'message' => __( 'Ticket not found.', 'raffle-for-woocommerce' ) ) );
		}

		// Check if already checked in.
		if ( $ticket->get_meta( '_checkin_time' ) ) {
			wp_send_json_error( array( 'message' => __( 'Ticket already checked in.', 'raffle-for-woocommerce' ) ) );
		}

		// Check ticket status.
		if ( 'active' !== $ticket->get_status() && 'winner' !== $ticket->get_status() ) {
			wp_send_json_error( array( 'message' => __( 'Only active or winning tickets can be checked in.', 'raffle-for-woocommerce' ) ) );
		}

		// Perform check-in.
		$checkin_time = current_time( 'mysql' );
		$ticket->update_meta( '_checkin_time', $checkin_time );
		$ticket->update_meta( '_checkin_user', get_current_user_id() );

		// Log the check-in.
		$this->log_checkin( $ticket );

		wp_send_json_success(
			array(
				'message'      => __( 'Ticket checked in successfully.', 'raffle-for-woocommerce' ),
				'checkin_time' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $checkin_time ) ),
			)
		);
	}

	/**
	 * Log ticket validation.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket.
	 * @param string      $source The validation source.
	 */
	private function log_validation( $ticket, $source ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-only logging to custom table.
		$wpdb->insert(
			$table,
			array(
				'raffle_id'  => $ticket->get_raffle_id(),
				'action'     => 'ticket_validated',
				'details'    => wp_json_encode(
					array(
						'ticket_id'     => $ticket->get_id(),
						'ticket_number' => $ticket->get_formatted_ticket_number(),
						'source'        => $source,
						'ip_address'    => $this->get_client_ip(),
					)
				),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Log ticket check-in.
	 *
	 * @since 1.0.0
	 *
	 * @param RFWC_Ticket $ticket The ticket.
	 */
	private function log_checkin( $ticket ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-only logging to custom table.
		$wpdb->insert(
			$table,
			array(
				'raffle_id'  => $ticket->get_raffle_id(),
				'action'     => 'ticket_checkin',
				'details'    => wp_json_encode(
					array(
						'ticket_id'     => $ticket->get_id(),
						'ticket_number' => $ticket->get_formatted_ticket_number(),
					)
				),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Log failed validation attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ticket_number The ticket number that was attempted.
	 * @param string $source        The validation source.
	 */
	private function log_failed_validation( $ticket_number, $source ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-only logging to custom table.
		$wpdb->insert(
			$table,
			array(
				'raffle_id'  => 0,
				'action'     => 'ticket_validation_failed',
				'details'    => wp_json_encode(
					array(
						'ticket_number' => $ticket_number,
						'source'        => $source,
						'ip_address'    => $this->get_client_ip(),
						'reason'        => 'not_found',
					)
				),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}

	/**
	 * Display recent validations.
	 *
	 * @since 1.0.0
	 */
	private function display_recent_validations() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'rfwc_log' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped above via esc_sql().
		$validations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE action IN ('ticket_validated', 'ticket_checkin', 'ticket_validation_failed') ORDER BY created_at DESC LIMIT %d",
				20
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $validations ) ) {
			echo '<p class="description">' . esc_html__( 'No recent validations.', 'raffle-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'raffle-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Ticket', 'raffle-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'raffle-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'raffle-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Method', 'raffle-for-woocommerce' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $validations as $validation ) {
			$details      = json_decode( $validation->details, true );
			$source       = isset( $details['source'] ) ? $details['source'] : '';
			$is_failed    = 'ticket_validation_failed' === $validation->action;
			$action_label = '';
			$row_class    = '';
			$order_link   = '-';

			// Get order information if ticket exists.
			if ( ! $is_failed && ! empty( $details['ticket_id'] ) ) {
				$ticket = RFWC_Ticket::get_by_id( $details['ticket_id'] );
				if ( $ticket && $ticket->get_order_id() ) {
					$order_id   = $ticket->get_order_id();
					$order_link = sprintf(
						'<a href="%s">#%d</a>',
						esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ),
						$order_id
					);
				}
			}

			// Format source label.
			$source_label = $this->get_source_label( $source );

			if ( 'ticket_checkin' === $validation->action ) {
				$action_label = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' . __( 'Check-in', 'raffle-for-woocommerce' );
			} elseif ( $is_failed ) {
				$action_label = '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' . __( 'Failed', 'raffle-for-woocommerce' );
				$row_class    = ' class="rfwc-validation-failed"';
			} else {
				$action_label = '<span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' . __( 'Valid', 'raffle-for-woocommerce' );
			}

			// $row_class is a hardcoded attribute string, safe to output directly.
			echo '<tr' . $row_class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $validation->created_at ) ) ) . '</td>';
			echo '<td><code>' . esc_html( $details['ticket_number'] ?? '-' ) . '</code></td>';
			echo '<td>' . wp_kses_post( $order_link ) . '</td>';
			echo '<td>' . wp_kses_post( $action_label ) . '</td>';
			echo '<td>' . esc_html( $source_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Get user-friendly source label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Source identifier.
	 * @return string User-friendly label.
	 */
	private function get_source_label( $source ) {
		$labels = array(
			'admin_scan'  => __( 'Manual Entry', 'raffle-for-woocommerce' ),
			'qr_scan'     => __( 'QR Scan', 'raffle-for-woocommerce' ),
			'public_page' => __( 'Public Page', 'raffle-for-woocommerce' ),
			'public_scan' => __( 'Public QR Scan', 'raffle-for-woocommerce' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Mask a full name for privacy on public pages.
	 *
	 * Converts "John Doe" to "J*** D**".
	 *
	 * @since 1.0.3
	 *
	 * @param string $name Full name.
	 * @return string Masked name.
	 */
	private function mask_name( $name ) {
		if ( empty( $name ) ) {
			return '';
		}

		$parts  = explode( ' ', trim( $name ) );
		$masked = array();

		foreach ( $parts as $part ) {
			if ( mb_strlen( $part ) <= 1 ) {
				$masked[] = $part;
			} else {
				$masked[] = mb_substr( $part, 0, 1 ) . str_repeat( '*', mb_strlen( $part ) - 1 );
			}
		}

		return implode( ' ', $masked );
	}
}

