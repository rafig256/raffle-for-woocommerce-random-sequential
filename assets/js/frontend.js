/**
 * Raffle for WooCommerce - Frontend Scripts
 *
 * Handles countdown timer functionality for raffle products.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 */

/* global jQuery, rfwc_params */

( function( $ ) {
	'use strict';

	/**
	 * Raffle frontend functionality.
	 */
	var RFWFrontend = {

		/**
		 * Initialize frontend features.
		 */
		init: function() {
			this.initCountdowns();
			this.initComplianceTabs();
			this.initBulkPricing();
			this.initRangeSlider();
			this.initSkillQuestionTimer();
		},

		/**
		 * Initialize compliance tab toggle (Paid Entry / Free Entry).
		 */
		initComplianceTabs: function() {
			if ( ! $( '.rfwc-compliance-tabs' ).length ) {
				return;
			}

			$( document ).on( 'click', '.rfwc-compliance-tab', function( e ) {
				e.preventDefault();

				var $tab = $( this );
				var target = $tab.data( 'tab' );
				var $container = $tab.closest( '.rfwc-compliance-tabs' );

				if ( ! target || ! $container.length ) {
					return;
				}

				// Update active tab.
				$tab.siblings().removeClass( 'rfwc-compliance-tab--active' );
				$tab.addClass( 'rfwc-compliance-tab--active' );

				// Show the target panel, hide others.
				$container.siblings( '.rfwc-compliance-panel' )
					.removeClass( 'rfwc-compliance-panel--active' );

				$container.siblings( '.rfwc-compliance-panel--' + target )
					.addClass( 'rfwc-compliance-panel--active' );
			} );
		},

		/**
		 * Initialize all countdown timers on the page.
		 */
		initCountdowns: function() {
			var self = this;

			$( '.rfwc-countdown' ).each( function() {
				var $countdown = $( this );
				var endTime = parseInt( $countdown.data( 'end' ), 10 ) * 1000;

				// Update immediately.
				self.updateCountdown( $countdown, endTime );

				// Update every second.
				var interval = setInterval( function() {
					var shouldStop = self.updateCountdown( $countdown, endTime );
					if ( shouldStop ) {
						clearInterval( interval );
					}
				}, 1000 );
			} );
		},

		/**
		 * Update countdown display.
		 *
		 * @param {jQuery} $countdown The countdown element.
		 * @param {number} endTime    End timestamp in milliseconds.
		 * @return {boolean} True if countdown should stop.
		 */
		updateCountdown: function( $countdown, endTime ) {
			var now = Date.now();
			var diff = endTime - now;

			if ( diff <= 0 ) {
				var endedText = ( typeof rfwc_params !== 'undefined' && rfwc_params.i18n ) ? rfwc_params.i18n.ended : 'Ended';
				$countdown.html( '<div class="rfwc-countdown-ended">' + endedText + '</div>' );
				return true;
			}

			var days = Math.floor( diff / ( 1000 * 60 * 60 * 24 ) );
			var hours = Math.floor( ( diff % ( 1000 * 60 * 60 * 24 ) ) / ( 1000 * 60 * 60 ) );
			var minutes = Math.floor( ( diff % ( 1000 * 60 * 60 ) ) / ( 1000 * 60 ) );
			var seconds = Math.floor( ( diff % ( 1000 * 60 ) ) / 1000 );

			$countdown.find( '.rfwc-countdown-days' ).text( this.pad( days ) );
			$countdown.find( '.rfwc-countdown-hours' ).text( this.pad( hours ) );
			$countdown.find( '.rfwc-countdown-minutes' ).text( this.pad( minutes ) );
			$countdown.find( '.rfwc-countdown-seconds' ).text( this.pad( seconds ) );

			return false;
		},

		/**
		 * Initialize bulk pricing dynamic preview.
		 */
		initBulkPricing: function() {
			var $tiers = $( '.rfwc-bulk-tiers' );

			if ( ! $tiers.length ) {
				return;
			}

			var tiers     = $tiers.data( 'tiers' );
			var basePrice = parseFloat( $tiers.data( 'base-price' ) );
			var decimals  = parseInt( $tiers.data( 'decimals' ), 10 ) || 2;
			var $preview  = $tiers.find( '.rfwc-bulk-price-preview' );
			var $items    = $tiers.find( '.rfwc-bulk-tier-item' );
			var currency  = ( typeof rfwc_params !== 'undefined' && rfwc_params.currency_symbol ) ? rfwc_params.currency_symbol : '';

			if ( ! tiers || ! tiers.length || ! basePrice ) {
				return;
			}

			function formatPrice( amount ) {
				return currency + amount.toFixed( decimals );
			}

			function update( qty ) {
				var discount  = 0;
				var activeIdx = -1;

				$items.removeClass( 'rfwc-bulk-tier-active' );

				for ( var i = 0; i < tiers.length; i++ ) {
					if ( qty >= tiers[ i ].min_qty ) {
						discount  = tiers[ i ].discount_pct;
						activeIdx = i;
					}
				}

				if ( activeIdx >= 0 ) {
					$items.eq( activeIdx ).addClass( 'rfwc-bulk-tier-active' );
				}

				if ( discount > 0 && qty > 1 ) {
					var unitPrice = Math.round( basePrice * ( 1 - discount / 100 ) * Math.pow( 10, decimals ) ) / Math.pow( 10, decimals );
					var total     = unitPrice * qty;
					var savings   = ( basePrice * qty ) - total;

					$preview.html(
						'<strong>' + qty + ' &times; ' + formatPrice( unitPrice ) + ' = ' + formatPrice( total ) + '</strong>' +
						' <span class="rfwc-bulk-savings">' +
						( ( typeof rfwc_params !== 'undefined' && rfwc_params.i18n && rfwc_params.i18n.you_save ) ? rfwc_params.i18n.you_save : 'You save' ) +
						' ' + formatPrice( savings ) +
						'</span>'
					).removeClass( 'rfwc-hidden' );
				} else {
					$preview.addClass( 'rfwc-hidden' );
				}
			}

			$( document ).on( 'change input', '.cart .qty, .rfwc-compliance-panel .qty', function() {
				update( parseInt( $( this ).val(), 10 ) || 1 );
			} );

			// Run once on load in case qty is pre-set.
			var $qty = $( '.cart .qty, .rfwc-compliance-panel .qty' ).first();
			if ( $qty.length ) {
				update( parseInt( $qty.val(), 10 ) || 1 );
			}
		},

		/**
		 * Initialize skill question countdown timer when a time limit is set.
		 */
		initSkillQuestionTimer: function() {
			var $timer = $( '.rfwc-skill-timer' );
			if ( ! $timer.length ) {
				return;
			}

			var seconds = parseInt( $timer.data( 'seconds' ), 10 );
			if ( ! seconds || seconds <= 0 ) {
				return;
			}

			var remaining = seconds;
			$timer.text( '(' + remaining + 's)' );

			var interval = setInterval( function() {
				remaining--;
				if ( remaining <= 0 ) {
					clearInterval( interval );
					$timer.text( '(' + rfwc_params.i18n.ended + ')' ).addClass( 'rfwc-timer-expired' );
					$( '.rfwc-skill-question' ).find( 'input, select, button[type="submit"]' ).prop( 'disabled', true );
					$( '.single_add_to_cart_button' ).prop( 'disabled', true );
					$( '.rfwc-skill-question' ).after(
						'<p class="rfwc-skill-timer-expired-msg">' +
						( rfwc_params.i18n.time_expired || 'Time expired. Please refresh the page to try again.' ) +
						'</p>'
					);
				} else {
					$timer.text( '(' + remaining + 's)' );
					if ( remaining <= 10 ) {
						$timer.addClass( 'rfwc-timer-warning' );
					}
				}
			}, 1000 );
		},

		/**
		 * Initialize range slider for quantity input when enabled.
		 */
		initRangeSlider: function() {
			if ( typeof rfwc_params === 'undefined' || 'yes' !== rfwc_params.use_range_slider ) {
				return;
			}

			var $wrapper = $( '.rfwc-quantity-wrapper' );

			if ( ! $wrapper.length ) {
				return;
			}

			var $qty = $wrapper.find( '.qty' );

			if ( ! $qty.length ) {
				return;
			}

			var min = parseInt( $qty.attr( 'min' ), 10 ) || 1;
			var maxAttr = $qty.attr( 'max' );
			var max = maxAttr && '' !== maxAttr ? parseInt( maxAttr, 10 ) : 10000;
			var val = parseInt( $qty.val(), 10 ) || min;

			$qty.attr( 'type', 'hidden' );

			// Remove WooCommerce +/- buttons if present.
			$wrapper.find( '.quantity' ).find( 'button, .plus, .minus' ).remove();

			var sliderHtml =
				'<div class="rfwc-range-slider">' +
					'<div class="rfwc-range-slider-track">' +
						'<input type="range" class="rfwc-range-input" min="' + min + '" max="' + max + '" value="' + val + '" step="1" />' +
					'</div>' +
					'<div class="rfwc-range-slider-info">' +
						'<span class="rfwc-range-value">' + val + '</span>' +
						'<span class="rfwc-range-bounds">' + min + ' – ' + max + '</span>' +
					'</div>' +
				'</div>';

			$wrapper.find( '.quantity' ).after( sliderHtml );

			var $slider = $wrapper.find( '.rfwc-range-input' );
			var $display = $wrapper.find( '.rfwc-range-value' );

			$slider.on( 'input change', function() {
				var newVal = parseInt( $( this ).val(), 10 );
				$qty.val( newVal ).trigger( 'change' );
				$display.text( newVal );
			} );
		},

		/**
		 * Pad single digit numbers with leading zero.
		 *
		 * @param {number} num Number to pad.
		 * @return {string} Padded number string.
		 */
		pad: function( num ) {
			return num < 10 ? '0' + num : num.toString();
		}
	};

	// Initialize on document ready.
	$( document ).ready( function() {
		RFWFrontend.init();
	} );

} )( jQuery );
