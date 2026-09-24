/* POKBON Checkout — progressive enhancement (form works without JS).
   Live updates the shipping line + grand total + Place Order button label
   when the customer changes delivery type or region. */
(function () {
	'use strict';

	if (typeof window.PokbonCheckout !== 'object') return;

	var rates         = window.PokbonCheckout.rates || {};
	var freeThreshold = parseFloat(window.PokbonCheckout.freeThreshold) || 0;
	var currency      = window.PokbonCheckout.currency || '';

	var form        = document.getElementById('pokbon-checkout-form');
	var root        = document.querySelector('.pokbon-checkout');
	if (!form || !root) return;

	var subtotal       = parseFloat(root.getAttribute('data-subtotal')) || 0;
	var shippingLineEl = document.getElementById('pokbon-shipping-line');
	var grandTotalEl   = document.getElementById('pokbon-grand-total');
	var btnTotalEl     = document.getElementById('pokbon-button-total');
	var pickupBlock    = document.getElementById('pokbon-pickup-info');
	var addressBlock   = document.getElementById('pokbon-shipping-address');
	var deliveryRadios = form.querySelectorAll('input[name="delivery_type"]');
	var stateSelect    = form.querySelector('select[name="shipping_state"]');
	// Present only when POKBON Delivery has priced areas in this region.
	var zoneSelect     = form.querySelector('select[name="delivery_zone"]');
	var placeBtn       = document.getElementById('pokbon-place-order');
	var paymentRadios  = form.querySelectorAll('input[name="payment_method"]');

	// USSD instructions popup — mirrors the mobile app's USSD confirmation screen.
	var ussdModal         = document.getElementById('pokbon-ussd-modal');
	var ussdViewLink      = document.getElementById('pokbon-ussd-view-link');
	var ussdModalCloseX   = document.getElementById('pokbon-ussd-modal-close');
	var ussdModalCloseBtn = document.getElementById('pokbon-ussd-modal-close-btn');
	var ussdModalGotIt    = document.getElementById('pokbon-ussd-modal-gotit');
	var ussdLastFocused   = null;

	function format(amount) {
		// Match WC's locale-light formatting. Two decimals, comma thousands.
		var n = Math.round((amount + Number.EPSILON) * 100) / 100;
		var parts = n.toFixed(2).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		return currency + ' ' + parts.join('.');
	}

	function currentDeliveryType() {
		var checked = form.querySelector('input[name="delivery_type"]:checked');
		return checked ? checked.value : 'home';
	}

	/*
	 * True when the coverage gate is turned on and this region has no priced
	 * area. Set by syncZones(), read here, because a refusal has to reach the
	 * money as well as the message — see the shipping line below.
	 */
	var refusing = false;

	function compute() {
		var type = currentDeliveryType();
		var shipping = 0;
		var label    = '';

		/*
		 * Do not quote a price for a delivery we have just refused.
		 *
		 * The gate said "we do not deliver to this area yet" while the line
		 * underneath it read GH¢100.00 and the button read "· GH¢101.00". Two
		 * answers to the same question, one of them a number, and a number is
		 * the one people believe — so a buyer reads the refusal as a glitch and
		 * tries again, or worse, remembers the figure and expects it later.
		 *
		 * The flat regional rate is real, but it is the price of delivering
		 * somewhere we have no rider for. Saying no means saying no to the
		 * price too. Pickup is unaffected: it is always available.
		 */
		if (refusing && type !== 'pickup') {
			if (shippingLineEl) shippingLineEl.textContent = 'Not available';
			if (grandTotalEl)   grandTotalEl.textContent   = '—';
			if (btnTotalEl)     btnTotalEl.textContent     = '';
			if (pickupBlock)    pickupBlock.hidden         = true;
			return;
		}

		if (type === 'pickup') {
			shipping = 0;
			label = 'Free';
		} else {
			var code = stateSelect ? stateSelect.value : '';
			var rate = rates[code];

			/*
			 * An area's own price beats the regional rate.
			 *
			 * The price is carried on each option so the total updates as the
			 * buyer chooses, without a round trip. The server recalculates it
			 * from the matrix anyway — this is display, and is never what is
			 * charged.
			 */
			if (zoneSelect && zoneSelect.value) {
				var opt = zoneSelect.options[zoneSelect.selectedIndex];
				var zoned = opt ? parseFloat(opt.getAttribute('data-amount')) : NaN;
				if (!isNaN(zoned)) rate = zoned;
			}

			if (typeof rate === 'number' && !isNaN(rate)) {
				if (freeThreshold > 0 && subtotal >= freeThreshold) {
					shipping = 0;
					label = 'Free (over threshold)';
				} else {
					shipping = rate;
					label = format(rate);
				}
			} else {
				label = '—';
			}
		}

		if (shippingLineEl) shippingLineEl.textContent = label;
		if (grandTotalEl)   grandTotalEl.textContent   = format(subtotal + shipping);
		if (btnTotalEl)     btnTotalEl.textContent     = '· ' + format(subtotal + shipping);

		if (pickupBlock)  pickupBlock.hidden  = (type !== 'pickup');
		if (addressBlock) {
			// Address is always required (we deliver OR you pickup, but admin still wants to know who).
			// We just hide region irrelevance when pickup is selected? No — keep address visible.
			addressBlock.hidden = false;
		}
	}

	if (zoneSelect) {
		zoneSelect.addEventListener('change', function () {
			// Clear the "choose an area" nudge the moment they do.
			if (noCoverageEl && zoneSelect.value) {
				noCoverageEl.hidden = true;
				noCoverageEl.textContent = '';
			}
			compute();
		});
	}

	function openUssdModal() {
		if (!ussdModal) return;
		ussdLastFocused = document.activeElement;
		ussdModal.hidden = false;
		document.body.classList.add('pokbon-modal-open');
		document.addEventListener('keydown', onUssdModalKeydown);
		// Focus a control inside the dialog so keyboard/screen-reader users land inside it.
		if (ussdModalGotIt) ussdModalGotIt.focus();
	}

	function closeUssdModal() {
		if (!ussdModal || ussdModal.hidden) return;
		ussdModal.hidden = true;
		document.removeEventListener('keydown', onUssdModalKeydown);
		if (ussdLastFocused && typeof ussdLastFocused.focus === 'function') {
			ussdLastFocused.focus();
		}
		ussdLastFocused = null;
	}

	function onUssdModalKeydown(e) {
		var key = e.key || '';
		if (key === 'Escape' || key === 'Esc' || e.keyCode === 27) {
			closeUssdModal();
		}
	}

	if (ussdModal) {
		// Click on the overlay itself (not the dialog card) closes the popup.
		ussdModal.addEventListener('click', function (e) {
			if (e.target === ussdModal) closeUssdModal();
		});
	}
	[ussdModalCloseX, ussdModalCloseBtn, ussdModalGotIt].forEach(function (btn) {
		if (btn) btn.addEventListener('click', closeUssdModal);
	});
	if (ussdViewLink) {
		ussdViewLink.addEventListener('click', function (e) {
			e.preventDefault();
			openUssdModal();
		});
	}

	/*
	 * The area list follows the region.
	 *
	 * It used to be rendered once, server-side, for whatever region the page
	 * loaded with — so a buyer who changed to Volta was still offered Accra's
	 * environs at Accra's prices, on a form that looked completely normal. The
	 * options are rebuilt here instead of hidden, because hiding an <option>
	 * is not reliable across browsers and a "hidden" one can still be selected
	 * by keyboard.
	 */
	var zoneData     = null;
	var zoneField    = form.querySelector('[data-pokbon-zone-field]');
	var noCoverageEl = form.querySelector('[data-pokbon-no-coverage]');
	try {
		var raw = document.getElementById('pokbon-delivery-areas');
		if (raw) zoneData = JSON.parse(raw.textContent || '{}');
	} catch (e) {
		// Malformed payload: fall back to regional pricing rather than block
		// the form. Delivery still works; only the area picker is missing.
		zoneData = null;
	}

	function syncZones() {
		if (!zoneData || !zoneSelect || !zoneField) return;

		var region = stateSelect ? stateSelect.value : '';
		var areas  = (zoneData.areas && zoneData.areas[region]) || [];
		var wanted = zoneSelect.value || zoneData.chosen || '';

		// Rebuild, keeping the placeholder.
		zoneSelect.options.length = 1;
		areas.forEach(function (a) {
			var opt = document.createElement('option');
			opt.value = a.code;
			opt.textContent = a.label;
			opt.setAttribute('data-amount', String(a.amount));
			if (wanted && String(wanted).toUpperCase() === String(a.code).toUpperCase()) {
				opt.selected = true;
			}
			zoneSelect.appendChild(opt);
		});

		var served = areas.length > 0;
		zoneField.hidden = !served;
		// Required only when there is something to require. A required select
		// that is hidden blocks submission with a message nobody can see.
		if (served) {
			zoneSelect.setAttribute('required', 'required');
		} else {
			zoneSelect.removeAttribute('required');
			zoneSelect.value = '';
		}

		// Off, an unserved region is priced at the flat regional rate exactly as
		// before. On, it is refused — the one setting here that can cost a sale,
		// which is why the shop has to choose it.
		//
		// Computed whether or not the notice element is on the page: the gate
		// used to live entirely inside `if (noCoverageEl)`, so a template
		// missing that one <p> would have taken the order at the flat rate with
		// nothing to show for it.
		refusing = !served && !!zoneData.gate && currentDeliveryType() !== 'pickup' && region !== '';

		if (noCoverageEl) {
			noCoverageEl.hidden = !refusing;
			noCoverageEl.textContent = refusing ? (zoneData.noCover || '') : '';
		}

		if (placeBtn) {
			if (refusing) {
				placeBtn.setAttribute('disabled', 'disabled');
			} else if (placeBtn.getAttribute('disabled') && !form.dataset.submitting) {
				placeBtn.removeAttribute('disabled');
			}
		}

		compute();
	}

	deliveryRadios.forEach(function (r) { r.addEventListener('change', function () { syncZones(); }); });
	if (stateSelect) stateSelect.addEventListener('change', function () { syncZones(); });
	paymentRadios.forEach(function (r) {
		r.addEventListener('change', function () {
			if (r.checked && r.value === 'ussd') openUssdModal();
		});
	});

	// Prevent double-submit.
	form.addEventListener('submit', function (e) {
		/*
		 * Ask for the area before the server has to refuse it.
		 *
		 * This form carries `novalidate`, deliberately — the plugin renders its
		 * own errors rather than the browser's — so the `required` set on the
		 * select above does nothing whatsoever. The server now insists on an
		 * area, which is where the rule belongs; this exists so the buyer is
		 * told before a round trip loses their place on a long form.
		 */
		if (zoneField && !zoneField.hidden && zoneSelect && !zoneSelect.value) {
			e.preventDefault();
			if (noCoverageEl) {
				noCoverageEl.hidden = false;
				noCoverageEl.textContent = 'Please choose the delivery area closest to you.';
			}
			zoneSelect.focus();
			if (zoneSelect.scrollIntoView) zoneSelect.scrollIntoView({ block: 'center', behavior: 'smooth' });
			return;
		}

		form.dataset.submitting = '1';
		if (placeBtn) {
			placeBtn.setAttribute('disabled', 'disabled');
			placeBtn.dataset.originalText = placeBtn.textContent;
			placeBtn.textContent = 'Processing…';
		}
		// Re-enable after 30s as a safety net in case the redirect fails so the user isn't stuck.
		setTimeout(function () {
			if (placeBtn) {
				placeBtn.removeAttribute('disabled');
				if (placeBtn.dataset.originalText) placeBtn.textContent = placeBtn.dataset.originalText;
			}
		}, 30000);
	});

	syncZones();
	compute();
})();
