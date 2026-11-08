/**
 * Raffle for WooCommerce - Admin Tickets Page Scripts
 *
 * Handles winner management (mark claimed, bulk claim, resend notification)
 * on the Raffle Tickets > Winners / Claimed tabs.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.4
 */

/* global jQuery, rfwcTickets */

( function( $ ) {
	'use strict';

	var RFWCAdminTickets = {

		refundOrderIds: [],
		refundRaffleId: 0,
		selectiveOrderIds: [],
		selectiveTicketIds: [],

		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Single claim.
			$( document ).on( 'click', '.rfwc-claim-btn', this.handleClaim );

			// Bulk claim.
			$( document ).on( 'click', '.rfwc-bulk-claim-btn', this.handleBulkClaim );

			// Resend notification.
			$( document ).on( 'click', '.rfwc-resend-btn', this.handleResend );

			// Select all checkbox (winners tab).
			$( '#rfwc-select-all-winners' ).on( 'change', this.handleSelectAll );

			// Individual checkboxes toggle bulk button (winners tab).
			$( document ).on( 'change', '.rfwc-winner-cb', this.toggleBulkButton );

			// Mass refund.
			$( '#rfwc-mass-refund-raffle' ).on( 'change', this.handleRefundSelect );
			$( '#rfwc-mass-refund-btn' ).on( 'click', this.handleRefundClick.bind( this ) );
			$( '#rfwc-refund-confirm' ).on( 'click', this.handleRefundConfirm.bind( this ) );
			$( '#rfwc-refund-cancel, .rfwc-modal-close, .rfwc-modal-backdrop' ).on( 'click', this.closeRefundModal );

			// Ticket list: select all.
			$( '#rfwc-select-all-tickets' ).on( 'change', function() {
				$( '.rfwc-ticket-cb' ).prop( 'checked', $( this ).is( ':checked' ) );
			} );

			// Ticket list: bulk action apply.
			$( '#rfwc-ticket-bulk-apply' ).on( 'click', this.handleTicketBulkApply.bind( this ) );

			// Ticket list: single refund row action.
			$( document ).on( 'click', '.rfwc-single-refund', this.handleSingleRefund.bind( this ) );

			// Selective refund modal.
			$( '#rfwc-selective-refund-confirm' ).on( 'click', this.handleSelectiveRefundConfirm.bind( this ) );
			$( '.rfwc-selective-modal-close' ).on( 'click', this.closeSelectiveModal );

			// Copy manual refund list.
			$( document ).on( 'click', '.rfwc-copy-manual-list', function() {
				RFWCAdminTickets.copyManualRefundList( $( this ).data( 'table' ) );
			} );

			// Mark manual refund as done.
			$( document ).on( 'click', '.rfwc-mark-refund-done', function() {
				RFWCAdminTickets.handleMarkRefundDone( $( this ) );
			} );
		},

		handleClaim: function( e ) {
			e.preventDefault();
			var $btn = $( this );
			var winnerId = $btn.data( 'winner-id' );

			if ( ! confirm( rfwcTickets.confirmClaim ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( '...' );

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_tickets_mark_claimed',
				nonce: rfwcTickets.nonce,
				winner_id: winnerId
			}, function( response ) {
				if ( response.success ) {
					location.reload();
				} else {
					alert( response.data.message );
					$btn.prop( 'disabled', false ).text( 'Mark Claimed' );
				}
			} );
		},

		handleBulkClaim: function( e ) {
			e.preventDefault();
			var ids = [];

			$( '.rfwc-winner-cb:checked' ).each( function() {
				ids.push( $( this ).val() );
			} );

			if ( ids.length === 0 ) {
				alert( rfwcTickets.selectAtLeastOne );
				return;
			}

			if ( ! confirm( rfwcTickets.confirmBulkClaim ) ) {
				return;
			}

			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( '...' );

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_tickets_bulk_claim',
				nonce: rfwcTickets.nonce,
				winner_ids: ids
			}, function( response ) {
				if ( response.success ) {
					location.reload();
				} else {
					alert( response.data.message );
					$btn.prop( 'disabled', false );
				}
			} );
		},

		handleResend: function( e ) {
			e.preventDefault();
			var $btn = $( this );
			var winnerId = $btn.data( 'winner-id' );
			var originalText = $btn.text();

			$btn.prop( 'disabled', true ).text( rfwcTickets.sending );

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_tickets_resend_notification',
				nonce: rfwcTickets.nonce,
				winner_id: winnerId
			}, function( response ) {
				if ( response.success ) {
					$btn.text( rfwcTickets.sent );
					setTimeout( function() {
						$btn.prop( 'disabled', false ).text( rfwcTickets.resend );
					}, 2000 );
				} else {
					alert( response.data.message );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			} );
		},

		handleSelectAll: function() {
			var checked = $( this ).is( ':checked' );
			$( '.rfwc-winner-cb' ).prop( 'checked', checked );
			RFWCAdminTickets.toggleBulkButton();
		},

		toggleBulkButton: function() {
			var anyChecked = $( '.rfwc-winner-cb:checked' ).length > 0;
			$( '.rfwc-bulk-claim-btn' ).prop( 'disabled', ! anyChecked );
		},

		handleRefundSelect: function() {
			$( '#rfwc-mass-refund-btn' ).prop( 'disabled', ! $( this ).val() );
		},

		handleRefundClick: function() {
			var raffleId = $( '#rfwc-mass-refund-raffle' ).val();
			if ( ! raffleId ) {
				return;
			}

			this.refundRaffleId = parseInt( raffleId, 10 );
			this.refundOrderIds = [];

			// Show modal, load info.
			$( '#rfwc-refund-modal' ).show();
			$( '#rfwc-refund-summary' ).show().html( '<p>' + rfwcTickets.refundLoading + '</p>' );
			$( '#rfwc-refund-mode' ).hide();
			$( 'input[name="rfwc-refund-method"][value="standard"]' ).prop( 'checked', true );
			$( '#rfwc-refund-progress' ).hide();
			$( '#rfwc-refund-result' ).hide();
			$( '#rfwc-refund-confirm' ).prop( 'disabled', true ).show();
			$( '#rfwc-refund-cancel' ).show();

			var self = this;
			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_mass_refund_info',
				nonce: rfwcTickets.nonce,
				raffle_id: raffleId
			}, function( response ) {
				if ( ! response.success ) {
					$( '#rfwc-refund-summary' ).html( '<p class="rfwc-refund-error">' + response.data.message + '</p>' );
					return;
				}

				var d = response.data;
				self.refundOrderIds = d.order_ids;

				var html = '<table class="widefat striped"><tbody>';
				html += '<tr><td><strong>Raffle</strong></td><td>' + d.raffle_name + '</td></tr>';
				html += '<tr><td><strong>Orders to refund</strong></td><td>' + d.order_count + '</td></tr>';
				html += '<tr><td><strong>Tickets affected</strong></td><td>' + d.ticket_count + '</td></tr>';
				html += '<tr><td><strong>Total refund amount</strong></td><td>' + d.total_amount + '</td></tr>';
				html += '</tbody></table>';
				html += '<p class="description" style="margin-top:12px;color:#d63638;"><strong>&#9888; This action cannot be undone.</strong></p>';

				$( '#rfwc-refund-summary' ).html( html );
				$( '#rfwc-refund-mode' ).show();
				$( '#rfwc-refund-confirm' ).prop( 'disabled', false );
			} );
		},

		handleRefundConfirm: function() {
			if ( ! this.refundOrderIds.length || ! this.refundRaffleId ) {
				return;
			}

			var selectedMethod = $( 'input[name="rfwc-refund-method"]:checked' ).val() || 'standard';
			this.tryGateway      = 'gateway' === selectedMethod;
			this.refundToWallet  = 'wallet' === selectedMethod;

			$( '#rfwc-refund-confirm' ).prop( 'disabled', true ).hide();
			$( '#rfwc-refund-cancel' ).hide();
			$( '#rfwc-refund-summary' ).hide();
			$( '#rfwc-refund-mode' ).hide();
			$( '#rfwc-refund-progress' ).show();

			this.processRefundBatch( 0, [], [] );
		},

		processRefundBatch: function( index, successes, failures ) {
			var self = this;
			var total = this.refundOrderIds.length;

			if ( index >= total ) {
				self.finalizeMassRefund( successes, failures );
				return;
			}

			var orderId = this.refundOrderIds[ index ];
			var pct = Math.round( ( ( index + 1 ) / total ) * 100 );

			$( '#rfwc-refund-progress-text' ).text(
				rfwcTickets.refundProcessing + ' ' + ( index + 1 ) + ' ' + rfwcTickets.refundOf + ' ' + total + ' ' + rfwcTickets.refundOrders
			);
			$( '#rfwc-refund-progress-bar' ).css( 'width', pct + '%' );

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_mass_refund_process',
				nonce: rfwcTickets.nonce,
				raffle_id: self.refundRaffleId,
				order_id: orderId,
				try_gateway: self.tryGateway ? 1 : 0,
				refund_to_wallet: self.refundToWallet ? 1 : 0
			}, function( response ) {
				if ( response.success ) {
					successes.push( response.data );
				} else {
					failures.push( response.data );
				}
				self.processRefundBatch( index + 1, successes, failures );
			} ).fail( function() {
				failures.push( { order_id: orderId, message: 'Request failed for order #' + orderId } );
				self.processRefundBatch( index + 1, successes, failures );
			} );
		},

		finalizeMassRefund: function( successes, failures ) {
			var self = this;
			var totalAmount = 0;
			var failureMessages = [];

			for ( var i = 0; i < successes.length; i++ ) {
				if ( successes[ i ].amount_raw ) {
					totalAmount += parseFloat( successes[ i ].amount_raw );
				}
			}
			for ( var j = 0; j < failures.length; j++ ) {
				failureMessages.push( failures[ j ].message || '' );
			}

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_mass_refund_complete',
				nonce: rfwcTickets.nonce,
				raffle_id: self.refundRaffleId,
				orders_refunded: successes.length,
				orders_failed: failures.length,
				total_amount: totalAmount,
				failures: failureMessages
			} ).always( function() {
				self.showRefundResult( successes, failures );
			} );
		},

		showRefundResult: function( successes, failures ) {
			$( '#rfwc-refund-progress' ).hide();

			var html = '<div class="notice notice-success inline" style="margin:0 0 12px;"><p><strong>' + rfwcTickets.refundComplete + '</strong></p></div>';

			var gatewayCount = 0, walletCount = 0, manualCount = 0, manualOrders = [];
			for ( var s = 0; s < successes.length; s++ ) {
				if ( successes[ s ].refund_method === 'gateway' ) {
					gatewayCount++;
				} else if ( successes[ s ].refund_method === 'wallet' ) {
					walletCount++;
				} else {
					manualCount++;
					manualOrders.push( successes[ s ] );
				}
			}

			html += '<p>' + successes.length + ' orders refunded successfully.';
			if ( failures.length > 0 ) {
				html += ' ' + failures.length + ' failed.';
			}
			html += '</p>';

			if ( successes.length > 0 && ( gatewayCount > 0 || walletCount > 0 || manualCount > 0 ) ) {
				html += '<p style="margin:4px 0; font-size:13px; color:#555;">';
				var parts = [];
				if ( gatewayCount > 0 ) {
					parts.push( '<span style="color:#00a32a;">&#10003; ' + gatewayCount + ' via payment gateway</span>' );
				}
				if ( walletCount > 0 ) {
					parts.push( '<span style="color:#283593;">&#10003; ' + walletCount + ' refunded to wallet</span>' );
				}
				if ( manualCount > 0 ) {
					parts.push( '<span style="color:#2271b1;">&#9432; ' + manualCount + ' refund records only</span>' );
				}
				html += parts.join( ' &nbsp;&middot;&nbsp; ' );
				html += '</p>';
			}

			if ( this.refundToWallet && manualOrders.length > 0 ) {
				html += '<div class="notice notice-info inline" style="margin:8px 0; padding:8px 12px;"><p style="margin:0;"><span class="dashicons dashicons-info" style="margin-right:4px;"></span>' + rfwcTickets.walletGuestFallback + '</p></div>';
			}

			if ( manualOrders.length > 0 ) {
				html += this.buildManualRefundTable( manualOrders, 'rfwc-mass-manual-list' );
			}

			if ( failures.length > 0 ) {
				html += '<div class="notice notice-error inline" style="margin:8px 0;"><ul style="margin:0.5em 0 0.5em 1.5em;">';
				for ( var i = 0; i < failures.length; i++ ) {
					html += '<li>' + failures[ i ].message + '</li>';
				}
				html += '</ul></div>';
			}

			html += '<p><button type="button" class="button button-primary" onclick="location.reload();">OK</button></p>';

			$( '#rfwc-refund-result' ).html( html ).show();
			$( '#rfwc-modal-footer' ).hide();
		},

		closeRefundModal: function() {
			$( '#rfwc-refund-modal' ).hide();
			$( '#rfwc-modal-footer' ).show();
		},

		handleTicketBulkApply: function() {
			var action = $( '#rfwc-ticket-bulk-action' ).val();
			if ( ! action ) {
				return;
			}

			if ( 'refund' === action ) {
				var ids = [];
				$( '.rfwc-ticket-cb:checked' ).each( function() {
					ids.push( parseInt( $( this ).val(), 10 ) );
				} );

				if ( ids.length === 0 ) {
					alert( rfwcTickets.selectAtLeastOneTicket );
					return;
				}

				this.openSelectiveRefundModal( ids );
			}
		},

		handleSingleRefund: function( e ) {
			e.preventDefault();
			var ticketId = parseInt( $( e.currentTarget ).data( 'ticket-id' ), 10 );
			this.openSelectiveRefundModal( [ ticketId ] );
		},

		openSelectiveRefundModal: function( ticketIds ) {
			var self = this;
			self.selectiveTicketIds = ticketIds;
			self.selectiveOrderIds = [];

			$( '#rfwc-selective-refund-modal' ).show();
			$( '#rfwc-selective-refund-summary' ).show().html( '<p>' + rfwcTickets.refundLoading + '</p>' );
			$( '#rfwc-selective-refund-mode' ).hide();
			$( 'input[name="rfwc-selective-refund-method"][value="standard"]' ).prop( 'checked', true );
			$( '#rfwc-selective-refund-progress' ).hide();
			$( '#rfwc-selective-refund-result' ).hide();
			$( '#rfwc-selective-refund-confirm' ).prop( 'disabled', true ).show();
			$( '#rfwc-selective-modal-footer' ).show();

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_selective_refund_info',
				nonce: rfwcTickets.nonce,
				ticket_ids: ticketIds
			}, function( response ) {
				if ( ! response.success ) {
					$( '#rfwc-selective-refund-summary' ).html( '<p class="rfwc-refund-error">' + response.data.message + '</p>' );
					return;
				}

				var d = response.data;
				self.selectiveOrderIds = d.orders;
				self.selectiveTicketIds = d.ticket_ids;

				var html = '<table class="widefat striped"><tbody>';
				html += '<tr><td><strong>Tickets to refund</strong></td><td>' + d.ticket_count + '</td></tr>';
				html += '<tr><td><strong>Orders affected</strong></td><td>' + d.order_count + '</td></tr>';
				html += '<tr><td><strong>Total refund amount</strong></td><td>' + d.total_amount + '</td></tr>';
				html += '</tbody></table>';
				html += '<p class="description" style="margin-top:12px;color:#d63638;"><strong>&#9888; This action cannot be undone.</strong></p>';

				$( '#rfwc-selective-refund-summary' ).html( html );
				$( '#rfwc-selective-refund-mode' ).show();
				$( '#rfwc-selective-refund-confirm' ).prop( 'disabled', false );
			} );
		},

		handleSelectiveRefundConfirm: function() {
			if ( ! this.selectiveOrderIds.length ) {
				return;
			}

			var selectedMethod = $( 'input[name="rfwc-selective-refund-method"]:checked' ).val() || 'standard';
			this.selectiveTryGateway     = 'gateway' === selectedMethod;
			this.selectiveRefundToWallet = 'wallet' === selectedMethod;

			$( '#rfwc-selective-refund-confirm' ).prop( 'disabled', true ).hide();
			$( '#rfwc-selective-modal-footer .rfwc-selective-modal-close' ).hide();
			$( '#rfwc-selective-refund-summary' ).hide();
			$( '#rfwc-selective-refund-mode' ).hide();
			$( '#rfwc-selective-refund-progress' ).show();

			this.processSelectiveRefundBatch( 0, [], [] );
		},

		processSelectiveRefundBatch: function( index, successes, failures ) {
			var self  = this;
			var total = this.selectiveOrderIds.length;

			if ( index >= total ) {
				self.showSelectiveRefundResult( successes, failures );
				return;
			}

			var orderId      = this.selectiveOrderIds[ index ];
			var pct          = Math.round( ( ( index + 1 ) / total ) * 100 );
			var ticketsForOrder = this.selectiveTicketIds;

			$( '#rfwc-selective-progress-text' ).text(
				rfwcTickets.refundProcessing + ' ' + ( index + 1 ) + ' ' + rfwcTickets.refundOf + ' ' + total + ' ' + rfwcTickets.refundOrders
			);
			$( '#rfwc-selective-progress-bar' ).css( 'width', pct + '%' );

			$.post( rfwcTickets.ajaxUrl, {
				action: 'rfwc_selective_refund_process',
				nonce: rfwcTickets.nonce,
				order_id: orderId,
				ticket_ids: ticketsForOrder,
				try_gateway: self.selectiveTryGateway ? 1 : 0,
				refund_to_wallet: self.selectiveRefundToWallet ? 1 : 0
			}, function( response ) {
				if ( response.success ) {
					successes.push( response.data );
				} else {
					failures.push( response.data );
				}
				self.processSelectiveRefundBatch( index + 1, successes, failures );
			} ).fail( function() {
				failures.push( { order_id: orderId, message: 'Request failed for order #' + orderId } );
				self.processSelectiveRefundBatch( index + 1, successes, failures );
			} );
		},

		showSelectiveRefundResult: function( successes, failures ) {
			$( '#rfwc-selective-refund-progress' ).hide();

			var html = '<div class="notice notice-success inline" style="margin:0 0 12px;"><p><strong>' + rfwcTickets.refundComplete + '</strong></p></div>';

			var gatewayCount = 0, walletCount = 0, manualCount = 0, manualOrders = [];
			for ( var s = 0; s < successes.length; s++ ) {
				if ( successes[ s ].refund_method === 'gateway' ) {
					gatewayCount++;
				} else if ( successes[ s ].refund_method === 'wallet' ) {
					walletCount++;
				} else {
					manualCount++;
					manualOrders.push( successes[ s ] );
				}
			}

			html += '<p>' + successes.length + ' orders refunded successfully.';
			if ( failures.length > 0 ) {
				html += ' ' + failures.length + ' failed.';
			}
			html += '</p>';

			if ( successes.length > 0 && ( gatewayCount > 0 || walletCount > 0 || manualCount > 0 ) ) {
				html += '<p style="margin:4px 0; font-size:13px; color:#555;">';
				var parts = [];
				if ( gatewayCount > 0 ) {
					parts.push( '<span style="color:#00a32a;">&#10003; ' + gatewayCount + ' via payment gateway</span>' );
				}
				if ( walletCount > 0 ) {
					parts.push( '<span style="color:#283593;">&#10003; ' + walletCount + ' refunded to wallet</span>' );
				}
				if ( manualCount > 0 ) {
					parts.push( '<span style="color:#2271b1;">&#9432; ' + manualCount + ' refund records only</span>' );
				}
				html += parts.join( ' &nbsp;&middot;&nbsp; ' );
				html += '</p>';
			}

			if ( this.selectiveRefundToWallet && manualOrders.length > 0 ) {
				html += '<div class="notice notice-info inline" style="margin:8px 0; padding:8px 12px;"><p style="margin:0;"><span class="dashicons dashicons-info" style="margin-right:4px;"></span>' + rfwcTickets.walletGuestFallback + '</p></div>';
			}

			if ( manualOrders.length > 0 ) {
				html += this.buildManualRefundTable( manualOrders, 'rfwc-selective-manual-list' );
			}

			if ( failures.length > 0 ) {
				html += '<div class="notice notice-error inline" style="margin:8px 0;"><ul style="margin:0.5em 0 0.5em 1.5em;">';
				for ( var i = 0; i < failures.length; i++ ) {
					html += '<li>' + failures[ i ].message + '</li>';
				}
				html += '</ul></div>';
			}

			html += '<p><button type="button" class="button button-primary" onclick="location.reload();">OK</button></p>';

			$( '#rfwc-selective-refund-result' ).html( html ).show();
			$( '#rfwc-selective-modal-footer' ).hide();
		},

		handleMarkRefundDone: function( $btn ) {
			var orderId = $btn.data( 'order-id' );
			if ( ! confirm( rfwcTickets.confirmMarkDone ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( rfwcTickets.processing );

			$.ajax( {
				url: rfwcTickets.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_mark_refund_done',
					nonce: rfwcTickets.nonce,
					order_id: orderId
				},
				success: function( response ) {
					if ( response.success ) {
						var $td = $btn.closest( 'td' );
						$td.html( '<span class="rfwc-refund-badge rfwc-refund-manual-done">' + rfwcTickets.manualDoneLabel + '</span>' );
					} else {
						alert( response.data && response.data.message ? response.data.message : 'Error' );
						$btn.prop( 'disabled', false ).text( rfwcTickets.markDoneLabel );
					}
				},
				error: function() {
					alert( 'Request failed.' );
					$btn.prop( 'disabled', false ).text( rfwcTickets.markDoneLabel );
				}
			} );
		},

		buildManualRefundTable: function( manualOrders, tableId ) {
			var html = '<div class="notice notice-warning inline" style="margin:8px 0; padding:8px 12px;">';
			html += '<p style="margin:0 0 8px;"><strong>&#9888; ' + rfwcTickets.manualRefundNotice + '</strong></p>';
			html += '<table class="widefat striped" style="margin:0 0 8px;" id="' + tableId + '">';
			html += '<thead><tr>';
			html += '<th style="padding:6px 8px;">#</th>';
			html += '<th style="padding:6px 8px;">Order</th>';
			html += '<th style="padding:6px 8px;">Amount</th>';
			html += '<th style="padding:6px 8px;">Payment Method</th>';
			html += '</tr></thead><tbody>';

			for ( var i = 0; i < manualOrders.length; i++ ) {
				var o = manualOrders[ i ];
				var orderUrl = rfwcTickets.orderEditUrl + o.order_id;
				var paymentTitle = o.payment_method_title || '-';
				html += '<tr>';
				html += '<td style="padding:6px 8px;">' + ( i + 1 ) + '</td>';
				html += '<td style="padding:6px 8px;"><a href="' + orderUrl + '" target="_blank">#' + o.order_id + '</a></td>';
				html += '<td style="padding:6px 8px;">' + o.amount + '</td>';
				html += '<td style="padding:6px 8px;">' + paymentTitle + '</td>';
				html += '</tr>';
			}

			html += '</tbody></table>';
			html += '<button type="button" class="button button-secondary rfwc-copy-manual-list" data-table="' + tableId + '">' + rfwcTickets.copyManualRefunds + '</button>';
			html += '</div>';

			return html;
		},

		copyManualRefundList: function( tableId ) {
			var rows = $( '#' + tableId + ' tbody tr' );
			var lines = [ 'Order\tAmount\tPayment Method' ];

			rows.each( function() {
				var cols = $( this ).find( 'td' );
				var orderId = cols.eq( 1 ).text().trim();
				var amount  = cols.eq( 2 ).text().trim();
				var method  = cols.eq( 3 ).text().trim();
				lines.push( orderId + '\t' + amount + '\t' + method );
			} );

			var text = lines.join( '\n' );
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function() {
					RFWCAdminTickets.showCopyFeedback( tableId );
				} );
			} else {
				var textarea = document.createElement( 'textarea' );
				textarea.value = text;
				textarea.style.position = 'fixed';
				textarea.style.opacity = '0';
				document.body.appendChild( textarea );
				textarea.select();
				document.execCommand( 'copy' );
				document.body.removeChild( textarea );
				RFWCAdminTickets.showCopyFeedback( tableId );
			}
		},

		showCopyFeedback: function( tableId ) {
			var $btn = $( '.rfwc-copy-manual-list[data-table="' + tableId + '"]' );
			var original = $btn.text();
			$btn.text( rfwcTickets.copiedToClipboard );
			setTimeout( function() {
				$btn.text( original );
			}, 2000 );
		},

		closeSelectiveModal: function() {
			$( '#rfwc-selective-refund-modal' ).hide();
			$( '#rfwc-selective-modal-footer' ).show();
		}
	};

	$( document ).ready( function() {
		RFWCAdminTickets.init();
	} );

} )( jQuery );
