/**
 * Admin Winner Selection JavaScript.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

/* global jQuery, rfwcWinner */

(function($) {
	'use strict';

	var RFWWinner = {
		/**
		 * Initialize.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind events.
		 */
		bindEvents: function() {
			$(document).on('click', '.rfwc-draw-winners', this.drawWinners.bind(this));
			$(document).on('click', '.rfwc-select-manual-winner', this.selectManualWinner.bind(this));
			$(document).on('click', '.rfwc-clear-winners', this.clearWinners.bind(this));
			$(document).on('click', '.rfwc-resend-notification', this.resendNotification.bind(this));
			$(document).on('click', '.rfwc-mark-claimed', this.markClaimed.bind(this));
			$(document).on('click', '.rfwc-mark-raffle-failed', this.markRaffleFailed.bind(this));
			$(document).on('click', '.rfwc-cancel-raffle', this.cancelRaffle.bind(this));
			$(document).on('click', '.rfwc-extend-raffle', this.extendRaffle.bind(this));
			$(document).on('click', '.rfwc-relist-raffle', this.relistRaffle.bind(this));
		},

		/**
		 * Draw winners.
		 *
		 * @param {Event} e Click event.
		 */
		drawWinners: function(e) {
			e.preventDefault();

			if (!confirm(rfwcWinner.confirmDraw)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');
			var numWinners = $('#rfwc_num_winners').val();
			var method = $('#rfwc_selection_method').val();
			var sendNotifications = $('#rfwc_send_notifications').is(':checked');

			this.showMessage(rfwcWinner.drawing, 'info');
			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_draw_winners',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId,
					num_winners: numWinners,
					method: method,
					send_notifications: sendNotifications
				},
				success: function(response) {
					if (response && response.success && response.data) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						var msg = (response && response.data && response.data.message) ? response.data.message : 'Invalid response.';
						this.showMessage(rfwcWinner.error + ' ' + msg, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Select manual winner.
		 *
		 * @param {Event} e Click event.
		 */
		selectManualWinner: function(e) {
			e.preventDefault();

			var ticketId = $('#rfwc_manual_ticket').val();

			if (!ticketId) {
				alert(rfwcWinner.selectTicket);
				return;
			}

			if (!confirm(rfwcWinner.confirmManual)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');
			var prizeTier = $('#rfwc_manual_tier').val();

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_select_manual_winner',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId,
					ticket_id: ticketId,
					prize_tier: prizeTier
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Clear winners.
		 *
		 * @param {Event} e Click event.
		 */
		clearWinners: function(e) {
			e.preventDefault();

			if (!confirm(rfwcWinner.confirmClear)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_clear_winners',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Resend notification.
		 *
		 * @param {Event} e Click event.
		 */
		resendNotification: function(e) {
			e.preventDefault();

			var $button = $(e.currentTarget);
			var winnerId = $button.data('winner-id');

			$button.prop('disabled', true).text(rfwcWinner.sending || 'Sending...');

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_resend_winner_notification',
					nonce: rfwcWinner.nonce,
					winner_id: winnerId
				},
				success: function(response) {
					if (response.success) {
						$button.text(rfwcWinner.sent || 'Sent!');
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false).text(rfwcWinner.resend || 'Resend');
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false).text(rfwcWinner.resend || 'Resend');
				}.bind(this)
			});
		},

		/**
		 * Mark prize as claimed.
		 *
		 * @param {Event} e Click event.
		 */
		markClaimed: function(e) {
			e.preventDefault();

			var $button = $(e.currentTarget);
			var winnerId = $button.data('winner-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_mark_prize_claimed',
					nonce: rfwcWinner.nonce,
					winner_id: winnerId
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1000);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Mark raffle as failed.
		 *
		 * @param {Event} e Click event.
		 */
		markRaffleFailed: function(e) {
			e.preventDefault();

			if (!confirm(rfwcWinner.confirmFailed)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_mark_raffle_failed',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Cancel raffle.
		 *
		 * @param {Event} e Click event.
		 */
		cancelRaffle: function(e) {
			e.preventDefault();

			if (!confirm(rfwcWinner.confirmCancel)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_cancel_raffle',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Extend raffle with new end date.
		 *
		 * @param {Event} e Click event.
		 */
		extendRaffle: function(e) {
			e.preventDefault();

			var newEndDate = $('#rfwc_new_end_date').val();
			var newEndTime = $('#rfwc_new_end_time').val() || '23:59';

			if (!newEndDate) {
				this.showMessage(rfwcWinner.noEndDate, 'error');
				return;
			}

			if (!confirm(rfwcWinner.confirmExtend)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_extend_raffle',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId,
					new_end_date: newEndDate,
					new_end_time: newEndTime
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() { location.reload(); }, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Relist raffle with new end date, clearing winners.
		 *
		 * @param {Event} e Click event.
		 */
		relistRaffle: function(e) {
			e.preventDefault();

			var newEndDate = $('#rfwc_new_end_date').val();
			var newEndTime = $('#rfwc_new_end_time').val() || '23:59';

			if (!newEndDate) {
				this.showMessage(rfwcWinner.noEndDate, 'error');
				return;
			}

			if (!confirm(rfwcWinner.confirmRelist)) {
				return;
			}

			var $button = $(e.currentTarget);
			var raffleId = $button.data('raffle-id');

			$button.prop('disabled', true);

			$.ajax({
				url: rfwcWinner.ajaxUrl,
				type: 'POST',
				data: {
					action: 'rfwc_relist_raffle',
					nonce: rfwcWinner.nonce,
					raffle_id: raffleId,
					new_end_date: newEndDate,
					new_end_time: newEndTime
				},
				success: function(response) {
					if (response.success) {
						this.showMessage(response.data.message, 'success');
						if (response.data.reload) {
							setTimeout(function() { location.reload(); }, 1500);
						}
					} else {
						this.showMessage(rfwcWinner.error + ' ' + response.data.message, 'error');
						$button.prop('disabled', false);
					}
				}.bind(this),
				error: function() {
					this.showMessage(rfwcWinner.error + ' Request failed.', 'error');
					$button.prop('disabled', false);
				}.bind(this)
			});
		},

		/**
		 * Show message.
		 *
		 * @param {string} message Message text.
		 * @param {string} type    Message type (success, error, info).
		 */
		showMessage: function(message, type) {
			var $messageBox = $('.rfwc-ajax-message');
			$messageBox.removeClass('rfwc-message-success rfwc-message-error rfwc-message-info rfwc-hidden')
				.addClass('rfwc-message-' + type)
				.html(message)
				.slideDown();

			if (type === 'success') {
				setTimeout(function() {
					$messageBox.slideUp(function() {
						$messageBox.addClass('rfwc-hidden');
					});
				}, 5000);
			}
		}
	};

	$(document).ready(function() {
		RFWWinner.init();
	});

})(jQuery);

