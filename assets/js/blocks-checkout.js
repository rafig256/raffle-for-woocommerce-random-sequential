/**
 * Raffle for WooCommerce - Block Checkout Integration
 *
 * Adds per-ticket gift recipient fields to the WooCommerce Block checkout.
 * Supports pre-flagged items (gift checked on product page) and manual
 * opt-in via checkbox. Syncs data and cart quantity to the Store API.
 *
 * @package RaffleForWooCommerce
 * @since   1.0.0
 * @since   1.1.0 Rewritten for per-ticket recipient fields.
 */

( function() {
	'use strict';

	var config    = window.rfwcBlocksCheckout || {};
	var i18n      = config.i18n || {};
	var cartItems = config.cartItems || [];
	var hasPreflaggedGifts = !! config.hasPreflaggedGifts;

	var isInitialized = false;
	var isGift        = hasPreflaggedGifts;
	var isUpdatingCart = false;

	// Per-item recipient data: { cartKey: [ { firstName, lastName, email, message }, ... ] }
	var recipientsData = {};

	/**
	 * Initialize recipient data structure from cart items.
	 */
	function initRecipientsData() {
		cartItems.forEach( function( item ) {
			recipientsData[ item.cartKey ] = [];
			for ( var i = 0; i < item.quantity; i++ ) {
				recipientsData[ item.cartKey ].push( {
					firstName: '',
					lastName: '',
					email: '',
					message: '',
				} );
			}
		} );
	}

	/**
	 * Escape HTML to prevent XSS.
	 */
	function escapeHTML( str ) {
		if ( ! str ) return '';
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Build the recipient number label.
	 */
	function recipientLabel( index ) {
		return ( i18n.recipientLabel || 'Recipient %d' ).replace( '%d', index + 1 );
	}

	/**
	 * Build HTML for a single recipient field set.
	 */
	function buildRecipientSetHTML( cartKey, index, totalInGroup ) {
		var idPrefix = 'rfwc-block-' + cartKey + '-' + index;
		var showRemove = totalInGroup > 1;

		var html = '<div class="rfwc-gift-recipient-set" data-cart-key="' + escapeHTML( cartKey ) + '" data-index="' + index + '">';

		// Header with recipient number and remove button.
		html += '<div class="rfwc-gift-recipient-header">';
		html += '<span class="rfwc-recipient-number">' + escapeHTML( recipientLabel( index ) ) + '</span>';
		html += '</div>';

		// Name row (first + last name).
		html += '<div class="rfwc-gift-name-row">';
		html += '<div class="rfwc-field rfwc-field-first-name">';
		html += '<label for="' + idPrefix + '-first-name">' + escapeHTML( i18n.firstNameLabel || 'First name' ) + ' <abbr class="required" title="required">*</abbr></label>';
		html += '<input type="text" id="' + idPrefix + '-first-name" class="rfwc-input" data-field="firstName" placeholder="' + escapeHTML( i18n.firstNamePlaceholder || 'First name' ) + '">';
		html += '<span class="rfwc-field-error"></span>';
		html += '</div>';
		html += '<div class="rfwc-field rfwc-field-last-name">';
		html += '<label for="' + idPrefix + '-last-name">' + escapeHTML( i18n.lastNameLabel || 'Last name' ) + ' <abbr class="required" title="required">*</abbr></label>';
		html += '<input type="text" id="' + idPrefix + '-last-name" class="rfwc-input" data-field="lastName" placeholder="' + escapeHTML( i18n.lastNamePlaceholder || 'Last name' ) + '">';
		html += '<span class="rfwc-field-error"></span>';
		html += '</div>';
		html += '</div>';

		// Email.
		html += '<div class="rfwc-field rfwc-field-email">';
		html += '<label for="' + idPrefix + '-email">' + escapeHTML( i18n.emailLabel || 'Recipient email' ) + ' <abbr class="required" title="required">*</abbr></label>';
		html += '<input type="email" id="' + idPrefix + '-email" class="rfwc-input" data-field="email" placeholder="' + escapeHTML( i18n.emailPlaceholder || 'email@example.com' ) + '">';
		html += '<span class="rfwc-field-help">' + escapeHTML( i18n.emailDescription || '' ) + '</span>';
		html += '<span class="rfwc-field-error"></span>';
		html += '</div>';

		// Gift message.
		html += '<div class="rfwc-field rfwc-field-message">';
		html += '<label for="' + idPrefix + '-message">' + escapeHTML( i18n.messageLabel || 'Gift message (optional)' ) + '</label>';
		html += '<textarea id="' + idPrefix + '-message" class="rfwc-input" data-field="message" rows="2" placeholder="' + escapeHTML( i18n.messagePlaceholder || 'Add a personal message...' ) + '"></textarea>';
		html += '</div>';

		html += '</div>';
		return html;
	}

	/**
	 * Build HTML for an item group (recipient sets + add button).
	 * Only shows a product name label when there are multiple raffle items.
	 */
	function buildItemGroupHTML( item ) {
		var count = recipientsData[ item.cartKey ] ? recipientsData[ item.cartKey ].length : item.quantity;
		var showLabel = cartItems.length > 1;

		var html = '<div class="rfwc-gift-item-group" data-cart-key="' + escapeHTML( item.cartKey ) + '">';

		// Only show product label when multiple raffle items in cart.
		if ( showLabel ) {
			html += '<div class="rfwc-gift-item-label">' + escapeHTML( item.productName ) + '</div>';
		}

		html += '<div class="rfwc-gift-recipients-list">';
		for ( var i = 0; i < count; i++ ) {
			html += buildRecipientSetHTML( item.cartKey, i, count );
		}
		html += '</div>';

		html += '</div>';
		return html;
	}

	/**
	 * Create the full gift purchase block HTML.
	 */
	function createGiftBlockHTML() {
		var html = '<div id="rfwc-gift-checkout-block" class="rfwc-gift-checkout-block">';
		html += '<h3 class="rfwc-gift-title">' + escapeHTML( i18n.title || 'Gift Purchase' ) + '</h3>';

		if ( hasPreflaggedGifts ) {
			// Pre-flagged: show description and fields directly, no checkbox.
			html += '<p class="rfwc-gift-description">' + escapeHTML( i18n.descriptionPreflag || i18n.description || '' ) + '</p>';
			html += '<div id="rfwc-block-recipient-fields" class="rfwc-block-recipient-fields">';
			cartItems.forEach( function( item ) {
				html += buildItemGroupHTML( item );
			} );
			html += '</div>';
		} else {
			// Not pre-flagged: show checkbox with toggle.
			html += '<p class="rfwc-gift-description">' + escapeHTML( i18n.description || '' ) + '</p>';
			html += '<div class="rfwc-is-gift-field">';
			html += '<label class="rfwc-checkbox-label">';
			html += '<input type="checkbox" id="rfwc-is-gift-block" class="rfwc-is-gift-checkbox">';
			html += '<span>' + escapeHTML( i18n.isGiftLabel || 'This is a gift for someone else' ) + '</span>';
			html += '</label>';
			html += '</div>';
			html += '<div id="rfwc-block-recipient-fields" class="rfwc-block-recipient-fields rfwc-hidden">';
			cartItems.forEach( function( item ) {
				html += buildItemGroupHTML( item );
			} );
			html += '</div>';
		}

		html += '</div>';
		return html;
	}

	/**
	 * Initialize the gift checkout block.
	 */
	function initGiftCheckout() {
		if ( isInitialized ) {
			// Check if our element was removed by a re-render.
			if ( ! document.getElementById( 'rfwc-gift-checkout-block' ) ) {
				isInitialized = false;
			} else {
				return;
			}
		}
		if ( ! cartItems.length ) return;

		var checkoutForm = document.querySelector( '.wc-block-checkout__form' );
		if ( ! checkoutForm ) {
			setTimeout( initGiftCheckout, 500 );
			return;
		}

		// Don't re-add if already present.
		if ( document.getElementById( 'rfwc-gift-checkout-block' ) ) {
			isInitialized = true;
			return;
		}

		// Initialize data (only if not already initialized from a previous render).
		if ( ! Object.keys( recipientsData ).length ) {
			initRecipientsData();
		}

		// Build and insert the block.
		var container = document.createElement( 'div' );
		container.innerHTML = createGiftBlockHTML();
		var giftBlock = container.firstElementChild;

		// Insert after order notes or at end of form.
		var orderNotesSection = checkoutForm.querySelector( '.wc-block-checkout__add-note' );
		var billingSection = checkoutForm.querySelector( '.wp-block-woocommerce-checkout-billing-address-block' );

		if ( orderNotesSection ) {
			orderNotesSection.parentNode.insertBefore( giftBlock, orderNotesSection.nextSibling );
		} else if ( billingSection ) {
			billingSection.parentNode.insertBefore( giftBlock, billingSection.nextSibling );
		} else {
			checkoutForm.appendChild( giftBlock );
		}

		// Restore field values if re-rendering.
		restoreFieldValues();

		// Bind events.
		bindEvents();
		isInitialized = true;

		// If pre-flagged, sync initial state immediately.
		if ( hasPreflaggedGifts ) {
			updateServerData();
		}
	}

	/**
	 * Restore field values after a re-render (e.g. after cart quantity update).
	 */
	function restoreFieldValues() {
		Object.keys( recipientsData ).forEach( function( cartKey ) {
			recipientsData[ cartKey ].forEach( function( recipient, index ) {
				var prefix = 'rfwc-block-' + cartKey + '-' + index;
				var fields = {
					'first-name': 'firstName',
					'last-name': 'lastName',
					'email': 'email',
					'message': 'message',
				};
				Object.keys( fields ).forEach( function( suffix ) {
					var el = document.getElementById( prefix + '-' + suffix );
					if ( el && recipient[ fields[ suffix ] ] ) {
						el.value = recipient[ fields[ suffix ] ];
					}
				} );
			} );
		} );
	}

	/**
	 * Bind all event handlers using delegation.
	 */
	function bindEvents() {
		var block = document.getElementById( 'rfwc-gift-checkout-block' );
		if ( ! block ) return;

		// Checkbox toggle (only present when not pre-flagged).
		var checkbox = document.getElementById( 'rfwc-is-gift-block' );
		if ( checkbox ) {
			checkbox.addEventListener( 'change', function() {
				isGift = this.checked;
				var fields = document.getElementById( 'rfwc-block-recipient-fields' );
				if ( fields ) {
					fields.classList.toggle( 'rfwc-hidden', ! this.checked );
				}
				if ( ! this.checked ) {
					clearAllErrors();
				}
				updateServerData();
			} );
		}

		// Delegated events on the block container.
		block.addEventListener( 'click', function( e ) {
			var target = e.target;

			// (Add/remove recipient buttons removed — quantity is managed in the cart.)
		} );

		// Input changes (delegated).
		block.addEventListener( 'input', function( e ) {
			var input = e.target;
			if ( ! input.classList.contains( 'rfwc-input' ) ) return;

			var set = input.closest( '.rfwc-gift-recipient-set' );
			if ( ! set ) return;

			var cartKey = set.getAttribute( 'data-cart-key' );
			var index = parseInt( set.getAttribute( 'data-index' ), 10 );
			var field = input.getAttribute( 'data-field' );

			if ( recipientsData[ cartKey ] && recipientsData[ cartKey ][ index ] && field ) {
				recipientsData[ cartKey ][ index ][ field ] = input.value;
			}

			debouncedUpdate();
		} );

		// Blur validation (delegated).
		block.addEventListener( 'focusout', function( e ) {
			var input = e.target;
			if ( ! input.classList.contains( 'rfwc-input' ) ) return;
			if ( ! isGift ) return;

			var field = input.getAttribute( 'data-field' );
			if ( field ) {
				validateField( input, field, input.value );
			}
		} );
	}

	// Note: add/remove recipient buttons were removed. Ticket quantity is
	// managed entirely in the cart. Checkout only shows recipient fields for
	// the number of tickets already in the cart.

	/**
	 * Validate a single field.
	 */
	function validateField( input, field, value ) {
		var errorEl = input.parentNode.querySelector( '.rfwc-field-error' );
		var error = '';

		if ( field === 'firstName' && ! value.trim() ) {
			error = i18n.firstNameRequired || 'Required';
		} else if ( field === 'lastName' && ! value.trim() ) {
			error = i18n.lastNameRequired || 'Required';
		} else if ( field === 'email' ) {
			if ( ! value.trim() ) {
				error = i18n.emailRequired || 'Required';
			} else if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
				error = i18n.emailInvalid || 'Invalid email';
			}
		}

		if ( errorEl ) {
			errorEl.textContent = error;
		}
		input.classList.toggle( 'has-error', !! error );

		return ! error;
	}

	/**
	 * Clear all error messages.
	 */
	function clearAllErrors() {
		var block = document.getElementById( 'rfwc-gift-checkout-block' );
		if ( ! block ) return;
		block.querySelectorAll( '.rfwc-field-error' ).forEach( function( el ) { el.textContent = ''; } );
		block.querySelectorAll( '.rfwc-input.has-error' ).forEach( function( el ) { el.classList.remove( 'has-error' ); } );
	}

	/**
	 * Debounced server update.
	 */
	var updateTimeout;
	function debouncedUpdate() {
		clearTimeout( updateTimeout );
		updateTimeout = setTimeout( updateServerData, 200 );
	}

	/**
	 * Collect current field values from the DOM into recipientsData.
	 * Ensures data model is always in sync with what the user has typed,
	 * even if an input event was missed.
	 */
	function collectRecipientsFromDOM() {
		var block = document.getElementById( 'rfwc-gift-checkout-block' );
		if ( ! block ) return;

		var groups = block.querySelectorAll( '.rfwc-gift-item-group' );
		groups.forEach( function( group ) {
			var cartKey = group.getAttribute( 'data-cart-key' );
			if ( ! cartKey ) return;

			var sets = group.querySelectorAll( '.rfwc-gift-recipient-set' );

			// Ensure recipientsData has enough entries.
			if ( ! recipientsData[ cartKey ] ) {
				recipientsData[ cartKey ] = [];
			}
			while ( recipientsData[ cartKey ].length < sets.length ) {
				recipientsData[ cartKey ].push( { firstName: '', lastName: '', email: '', message: '' } );
			}

			sets.forEach( function( set, i ) {
				var inputs = set.querySelectorAll( '.rfwc-input' );
				inputs.forEach( function( input ) {
					var field = input.getAttribute( 'data-field' );
					if ( field && recipientsData[ cartKey ][ i ] ) {
						recipientsData[ cartKey ][ i ][ field ] = input.value;
					}
				} );
			} );
		} );
	}

	/**
	 * Store gift data in WooCommerce Blocks' checkout extension data store.
	 *
	 * This makes the gift data travel WITH the checkout POST request body
	 * (under extensions['raffle-for-woocommerce']), providing a reliable
	 * data path that does not depend on WC session timing.
	 */
	function setCheckoutExtensionData() {
		if ( ! window.wp || ! window.wp.data ) return;

		var dispatch = window.wp.data.dispatch( 'wc/store/checkout' );
		if ( ! dispatch ) return;

		var payload = {
			isGift: isGift,
			recipients: isGift ? recipientsData : {},
		};

		if ( typeof dispatch.setExtensionData === 'function' ) {
			dispatch.setExtensionData( 'raffle-for-woocommerce', payload );
		} else if ( typeof dispatch.__internalSetExtensionData === 'function' ) {
			dispatch.__internalSetExtensionData( 'raffle-for-woocommerce', payload );
		}
	}

	/**
	 * Send gift data to the Store API session AND to the checkout extension data store.
	 *
	 * Two data paths are used for reliability:
	 * 1. extensionCartUpdate -> WC session (real-time sync, may be stale at checkout)
	 * 2. setExtensionData -> checkout POST body (travels with the request, always fresh)
	 *
	 * Returns a Promise that resolves when the session update completes.
	 *
	 * @return {Promise} Resolves when data is synced to server session.
	 */
	function updateServerData() {
		// Always collect fresh data from the DOM first.
		collectRecipientsFromDOM();

		// Always update checkout extension data (belt-and-suspenders).
		setCheckoutExtensionData();

		var payload = {
			isGift: isGift,
			recipients: isGift ? recipientsData : {},
		};

		if ( ! window.wc || ! window.wc.blocksCheckout || ! window.wc.blocksCheckout.extensionCartUpdate ) {
			// Fallback to session storage.
			sessionStorage.setItem( 'rfwc_gift_data', JSON.stringify( payload ) );
			return Promise.resolve();
		}

		return window.wc.blocksCheckout.extensionCartUpdate( {
			namespace: 'raffle-for-woocommerce',
			data: payload,
		} ).catch( function() {
			sessionStorage.setItem( 'rfwc_gift_data', JSON.stringify( payload ) );
		} );
	}

	/**
	 * Verify that the WooCommerce cart item quantities match the recipient
	 * counts in recipientsData. If they don't, force an update and wait.
	 *
	 * This is the JS-level safety net for the async changeCartItemQuantity
	 * issue: the cart quantity may not have been updated when a recipient was
	 * added/removed, so we check and correct right before Place Order.
	 *
	 * @return {Promise} Resolves when all cart quantities are correct.
	 */
	function verifyCartQuantities() {
		if ( ! window.wp || ! window.wp.data ) {
			return Promise.resolve();
		}

		var select   = window.wp.data.select( 'wc/store/cart' );
		var dispatch = window.wp.data.dispatch( 'wc/store/cart' );
		if ( ! select || ! dispatch || typeof dispatch.changeCartItemQuantity !== 'function' ) {
			return Promise.resolve();
		}

		var cartItems = select.getCartItems ? select.getCartItems() : [];
		var promises  = [];

		cartItems.forEach( function( item ) {
			var cartKey = item.key;
			if ( recipientsData[ cartKey ] && Array.isArray( recipientsData[ cartKey ] ) ) {
				var expectedQty = recipientsData[ cartKey ].length;
				if ( expectedQty > 0 && item.quantity !== expectedQty ) {
					var result = dispatch.changeCartItemQuantity( cartKey, expectedQty );
					if ( result && typeof result.then === 'function' ) {
						promises.push( result );
					}
				}
			}
		} );

		if ( promises.length === 0 ) {
			return Promise.resolve();
		}

		return Promise.all( promises );
	}

	/**
	 * Intercept the Place Order button to ensure gift data is fully synced
	 * to the server session BEFORE the checkout request fires.
	 *
	 * Without this, a race condition occurs: the async extensionCartUpdate
	 * request and the checkout POST fire simultaneously, and the checkout
	 * may read stale session data that is missing recipient details.
	 *
	 * Solution: capture-phase listener prevents the click from propagating,
	 * awaits the extensionCartUpdate promise, then re-triggers the click
	 * with a bypass flag so it proceeds normally.
	 */
	var syncInProgress = false;
	var skipSync       = false;

	function bindPlaceOrderSync() {
		document.addEventListener( 'click', function( e ) {
			var button = e.target.closest( '.wc-block-components-checkout-place-order-button' );
			if ( ! button ) return;
			if ( ! isGift ) return;
			if ( skipSync || syncInProgress ) return;

			// Block the click from reaching WooCommerce's handler.
			e.preventDefault();
			e.stopImmediatePropagation();

			syncInProgress = true;

			// Visual feedback: disable the button while syncing.
			button.disabled = true;
			button.style.opacity = '0.7';

			// Ensure data model is current, then set both data paths.
			collectRecipientsFromDOM();
			setCheckoutExtensionData(); // Ensure extension data is set right before checkout.

			// Build a chain: sync server data, then verify cart quantities match
			// recipient counts. If a cart item quantity is wrong, force an update
			// and wait for it before proceeding.
			updateServerData().then( function() {
				return verifyCartQuantities();
			} ).then( function() {
				syncInProgress = false;
				button.disabled = false;
				button.style.opacity = '';

				// Re-click with bypass flag so this handler passes through.
				skipSync = true;
				button.click();
				skipSync = false;
			} ).catch( function() {
				syncInProgress = false;
				button.disabled = false;
				button.style.opacity = '';

				// Proceed anyway — server-side patch will correct the quantities.
				skipSync = true;
				button.click();
				skipSync = false;
			} );
		}, true ); // Use capture phase to run BEFORE the button's handler.
	}

	/**
	 * Boot the integration.
	 */
	function init() {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', initGiftCheckout );
		} else {
			initGiftCheckout();
		}

		// Sync data before Place Order, regardless of debounce state.
		bindPlaceOrderSync();

		// Watch for dynamic content in block checkout.
		var observer = new MutationObserver( function() {
			if ( ! isInitialized && document.querySelector( '.wc-block-checkout__form' ) ) {
				initGiftCheckout();
			}
			// Re-check if our element was removed by a re-render.
			if ( isInitialized && ! document.getElementById( 'rfwc-gift-checkout-block' ) ) {
				isInitialized = false;
				initGiftCheckout();
			}
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	init();

} )();
