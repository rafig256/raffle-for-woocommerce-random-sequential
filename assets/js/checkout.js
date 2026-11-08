/**
 * Raffle for WooCommerce - Checkout Scripts
 *
 * Handles gift purchase field toggle and per-ticket recipient
 * management on the classic (shortcode) checkout page.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

/* global jQuery, rfwc_checkout_params */

( function( $ ) {
	'use strict';

	var params = ( typeof rfwc_checkout_params !== 'undefined' ) ? rfwc_checkout_params : {};
	var i18n   = params.i18n || {};

	/**
	 * Gift checkout functionality.
	 */
	var RFWCheckout = {

		/**
		 * Whether a cart quantity update is in progress.
		 */
		isUpdatingCart: false,

		/**
		 * Initialize checkout features.
		 */
		init: function() {
			this.$giftSection = $( '#rfwc-gift-checkout' );
			this.$isGiftCheckbox = $( '#rfwc_is_gift' );
			this.$recipientFields = $( '#rfwc-gift-recipient-fields' );
			this.selfClaimEnabled = this.$giftSection.data( 'self-claim' ) === 1
				|| this.$giftSection.data( 'self-claim' ) === '1';

			if ( ! this.$giftSection.length ) {
				return;
			}

			this.bindEvents();
			this.handleInitialState();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			var self = this;

			// Gift checkbox toggle (only exists when not pre-flagged).
			if ( this.$isGiftCheckbox.length && this.$isGiftCheckbox.is( ':checkbox' ) ) {
				this.$isGiftCheckbox.on( 'change', function() {
					self.toggleRecipientFields();
				} );
			}

			// Per-slot "This ticket is for me" toggle.
			if ( this.selfClaimEnabled ) {
				this.$giftSection.on( 'change', '.rfwc-self-claim-toggle', function() {
					self.toggleSlotSelfClaim( $( this ).closest( '.rfwc-gift-recipient-set' ) );
				} );
			}

			// Note: add/remove recipient buttons removed. Quantity is managed in the cart.
		},

		/**
		 * Handle initial state on page load.
		 * If items are pre-flagged, fields are already visible (no checkbox).
		 */
		handleInitialState: function() {
			var self = this;
			var hasPreflagged = this.$giftSection.data( 'has-preflagged' );

			if ( hasPreflagged === 1 || hasPreflagged === '1' ) {
				// Pre-flagged: no checkbox, fields always visible.
				this.$recipientFields.removeClass( 'rfwc-hidden' ).show();
			} else if ( this.$isGiftCheckbox.length && this.$isGiftCheckbox.is( ':checkbox' ) ) {
				this.toggleRecipientFields();
			}

			// Sync each slot's UI to the saved state of its self-claim checkbox.
			if ( this.selfClaimEnabled ) {
				this.$giftSection.find( '.rfwc-gift-recipient-set' ).each( function() {
					self.toggleSlotSelfClaim( $( this ) );
				} );
			}
		},

		/**
		 * Toggle a single recipient slot between gift mode and self-claim mode.
		 *
		 * In self-claim mode the recipient fields are hidden (and their
		 * required attribute removed so HTML5 validation doesn't fight us)
		 * and a small confirmation note is shown instead.
		 *
		 * @param {jQuery} $set The .rfwc-gift-recipient-set element.
		 */
		toggleSlotSelfClaim: function( $set ) {
			if ( ! $set.length ) {
				return;
			}

			var $toggle = $set.find( '.rfwc-self-claim-toggle' );
			if ( ! $toggle.length ) {
				return;
			}

			var isSelf = $toggle.is( ':checked' );
			var $inner = $set.find( '.rfwc-gift-recipient-fields-inner' );
			var $note  = $set.find( '.rfwc-self-claim-note' );

			if ( isSelf ) {
				$inner.addClass( 'rfwc-hidden' );
				$note.removeClass( 'rfwc-hidden' );
				// Drop the required flag from hidden inputs so the browser
				// doesn't block submission.
				$inner.find( 'input, textarea' ).each( function() {
					$( this ).data( 'rfwc-was-required', $( this ).prop( 'required' ) );
					$( this ).prop( 'required', false );
				} );
			} else {
				$inner.removeClass( 'rfwc-hidden' );
				$note.addClass( 'rfwc-hidden' );
				// Restore the required flag for fields that originally had it.
				$inner.find( 'input, textarea' ).each( function() {
					var was = $( this ).data( 'rfwc-was-required' );
					if ( typeof was === 'boolean' && was ) {
						$( this ).prop( 'required', true );
					}
				} );
			}
		},

		/**
		 * Toggle recipient fields visibility based on checkbox state.
		 */
		toggleRecipientFields: function() {
			var isChecked = this.$isGiftCheckbox.is( ':checked' );

			if ( isChecked ) {
				this.$recipientFields.slideDown( 200 ).removeClass( 'rfwc-hidden' );
			} else {
				this.$recipientFields.slideUp( 200 );
			}
		},

		/**
		 * Add a new recipient field set for a cart item.
		 *
		 * @param {jQuery} $button The "Add another recipient" button.
		 */
		addRecipient: function( $button ) {
			var self    = this;
			var cartKey = $button.data( 'cart-key' );
			var $group  = $button.closest( '.rfwc-gift-item-group' );
			var $list   = $group.find( '.rfwc-gift-recipients-list' );
			var currentCount = $list.find( '.rfwc-gift-recipient-set' ).length;
			var newIndex = currentCount;

			// Build new recipient field set HTML.
			var html = this.buildRecipientHTML( cartKey, newIndex );
			$list.append( html );

			// Show remove buttons on all sets if we now have more than 1.
			this.updateRemoveButtons( $group );

			// Focus the first input of the new set.
			$list.find( '.rfwc-gift-recipient-set' ).last().find( 'input' ).first().trigger( 'focus' );

			// Update cart quantity to match new recipient count.
			this.updateCartQuantity( cartKey, currentCount + 1, $group );
		},

		/**
		 * Remove a recipient field set.
		 *
		 * @param {jQuery} $button The "Remove" button.
		 */
		removeRecipient: function( $button ) {
			var self   = this;
			var $group = $button.closest( '.rfwc-gift-item-group' );
			var $list  = $group.find( '.rfwc-gift-recipients-list' );
			var $set   = $button.closest( '.rfwc-gift-recipient-set' );
			var cartKey = $button.data( 'cart-key' );

			// Don't remove if it's the last one.
			if ( $list.find( '.rfwc-gift-recipient-set' ).length <= 1 ) {
				return;
			}

			// Save field values before removal for re-indexing.
			var fieldValues = self.collectFieldValues( $group );

			$set.slideUp( 200, function() {
				$( this ).remove();

				// Re-index remaining sets.
				self.reindexRecipients( $group );

				// Update remove buttons.
				self.updateRemoveButtons( $group );

				// Update cart quantity.
				var newCount = $list.find( '.rfwc-gift-recipient-set' ).length;
				self.updateCartQuantity( cartKey, newCount, $group );
			} );
		},

		/**
		 * Update cart item quantity via AJAX and refresh order review.
		 *
		 * @param {string} cartKey  Cart item key.
		 * @param {number} quantity New quantity.
		 * @param {jQuery} $group   The gift item group.
		 */
		updateCartQuantity: function( cartKey, quantity, $group ) {
			var self = this;

			if ( ! params.ajax_url || ! params.nonce ) {
				return;
			}

			this.isUpdatingCart = true;
			this.setButtonsLoading( true );

			$.ajax( {
				url:  params.ajax_url,
				type: 'POST',
				data: {
					action:   'rfwc_update_cart_quantity',
					nonce:    params.nonce,
					cart_key: cartKey,
					quantity: quantity
				},
				success: function() {
					// Trigger WooCommerce checkout update to refresh order totals.
					$( document.body ).trigger( 'update_checkout' );
				},
				complete: function() {
					self.isUpdatingCart = false;
					self.setButtonsLoading( false );
				}
			} );
		},

		/**
		 * Toggle loading state on add/remove buttons.
		 *
		 * @param {boolean} loading True to disable, false to enable.
		 */
		setButtonsLoading: function( loading ) {
			var $buttons = this.$giftSection.find( '.rfwc-add-recipient, .rfwc-remove-recipient' );

			if ( loading ) {
				$buttons.prop( 'disabled', true ).css( 'opacity', '0.6' );
			} else {
				$buttons.prop( 'disabled', false ).css( 'opacity', '' );
			}
		},

		/**
		 * Collect all field values from a group for persistence.
		 *
		 * @param {jQuery} $group The gift item group.
		 * @return {Array} Array of field value objects.
		 */
		collectFieldValues: function( $group ) {
			var values = [];

			$group.find( '.rfwc-gift-recipient-set' ).each( function() {
				var $set = $( this );
				var data = {};

				$set.find( 'input, textarea' ).each( function() {
					var name = $( this ).attr( 'name' );
					if ( name ) {
						data[ name ] = $( this ).val();
					}
				} );

				values.push( data );
			} );

			return values;
		},

		/**
		 * Re-index recipient field sets after removal.
		 *
		 * @param {jQuery} $group The gift item group.
		 */
		reindexRecipients: function( $group ) {
			var cartKey = $group.data( 'cart-key' );

			$group.find( '.rfwc-gift-recipient-set' ).each( function( index ) {
				var $set = $( this );
				$set.attr( 'data-index', index );

				// Update the recipient number label.
				$set.find( '.rfwc-recipient-number' ).text(
					( i18n.recipient || 'Recipient %d' ).replace( '%d', index + 1 )
				);

				// Update all input/textarea names and IDs.
				$set.find( 'input, textarea' ).each( function() {
					var $input = $( this );
					var name = $input.attr( 'name' );
					var id = $input.attr( 'id' );

					if ( name ) {
						$input.attr( 'name', name.replace(
							/\[(\d+)\]/,
							'[' + index + ']'
						) );
					}
					if ( id ) {
						$input.attr( 'id', id.replace(
							/_(\d+)_/,
							'_' + index + '_'
						) );
					}
				} );

				// Update labels.
				$set.find( 'label' ).each( function() {
					var $label = $( this );
					var forAttr = $label.attr( 'for' );
					if ( forAttr ) {
						$label.attr( 'for', forAttr.replace(
							/_(\d+)_/,
							'_' + index + '_'
						) );
					}
				} );

				// Update remove button data-index.
				$set.find( '.rfwc-remove-recipient' ).attr( 'data-index', index );
			} );
		},

		/**
		 * Build HTML for a new recipient field set.
		 *
		 * @param {string} cartKey Cart item key.
		 * @param {number} index   Recipient index.
		 *
		 * @return {string} HTML string.
		 */
		buildRecipientHTML: function( cartKey, index ) {
			var recipientLabel = ( i18n.recipient || 'Recipient %d' ).replace( '%d', index + 1 );
			var prefix = 'rfwc_recipients[' + cartKey + '][' + index + ']';
			var idPrefix = 'rfwc_recipients_' + cartKey + '_' + index;

			var html = '<div class="rfwc-gift-recipient-set" data-index="' + index + '">';

			html += '<div class="rfwc-gift-recipient-header">';
			html += '<span class="rfwc-recipient-number">' + recipientLabel + '</span>';
			html += '</div>';

			html += '<div class="rfwc-recipient-fields-row">';
			html += '<p class="form-row form-row-first">';
			html += '<label for="' + idPrefix + '_first_name">' + ( i18n.first_name || 'First name' ) + ' <abbr class="required" title="required">*</abbr></label>';
			html += '<input type="text" class="input-text" name="' + prefix + '[first_name]" id="' + idPrefix + '_first_name" placeholder="' + ( i18n.first_name || 'First name' ) + '" />';
			html += '</p>';
			html += '<p class="form-row form-row-last">';
			html += '<label for="' + idPrefix + '_last_name">' + ( i18n.last_name || 'Last name' ) + ' <abbr class="required" title="required">*</abbr></label>';
			html += '<input type="text" class="input-text" name="' + prefix + '[last_name]" id="' + idPrefix + '_last_name" placeholder="' + ( i18n.last_name || 'Last name' ) + '" />';
			html += '</p>';
			html += '</div>';

			html += '<p class="form-row form-row-wide">';
			html += '<label for="' + idPrefix + '_email">' + ( i18n.email || 'Recipient email' ) + ' <abbr class="required" title="required">*</abbr></label>';
			html += '<input type="email" class="input-text" name="' + prefix + '[email]" id="' + idPrefix + '_email" placeholder="' + ( i18n.email_ph || 'email@example.com' ) + '" />';
			html += '</p>';

			html += '<p class="form-row form-row-wide">';
			html += '<label for="' + idPrefix + '_message">' + ( i18n.message || 'Gift message (optional)' ) + '</label>';
			html += '<textarea class="input-text" name="' + prefix + '[message]" id="' + idPrefix + '_message" placeholder="' + ( i18n.message_ph || 'Add a personal message...' ) + '" rows="2"></textarea>';
			html += '</p>';

			html += '</div>';

			return html;
		},

		/**
		 * Show/hide remove buttons based on set count.
		 *
		 * @param {jQuery} $group The gift item group.
		 */
		updateRemoveButtons: function( $group ) {
			var $sets = $group.find( '.rfwc-gift-recipient-set' );

			if ( $sets.length > 1 ) {
				$sets.find( '.rfwc-remove-recipient' ).show();
			} else {
				$sets.find( '.rfwc-remove-recipient' ).hide();
			}
		}
	};

	// Initialize on document ready.
	$( document ).ready( function() {
		RFWCheckout.init();
	} );

	// Re-initialize after checkout update (for AJAX-based checkouts).
	// We need to preserve field values since WooCommerce may re-render.
	$( document.body ).on( 'updated_checkout', function() {
		// Only re-init if our section is gone (full page reload).
		// WooCommerce AJAX update_checkout only refreshes order review,
		// so our fields in the form area stay intact.
		if ( ! $( '#rfwc-gift-checkout' ).length ) {
			RFWCheckout.init();
		}
	} );

} )( jQuery );
