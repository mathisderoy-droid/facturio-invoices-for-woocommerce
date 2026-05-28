/**
 * Checkout B2B section — toggle visibility + live INSEE/VIES validation.
 *
 * Pure vanilla JS, no jQuery. WC loads jQuery on the checkout page anyway,
 * but using vanilla keeps this script lightweight and independent of
 * upstream WC changes to its jQuery API.
 *
 * @package Mathis\FacturX\WooCommerce
 */

(function () {
	'use strict';

	var config  = window.mathisfxCheckout || {};
	var strings = config.strings || {};

	function sprintf(template, value) {
		return String(template).replace('%s', value);
	}

	/**
	 * Create or update the small feedback line shown right below an input.
	 *
	 * State is one of: 'checking', 'valid', 'invalid', 'warning', 'idle'.
	 */
	function setFeedback(input, state, message) {
		if (!input) {
			return;
		}

		var feedbackId = input.id + '_feedback';
		var feedback   = document.getElementById(feedbackId);

		if (!feedback) {
			feedback        = document.createElement('div');
			feedback.id     = feedbackId;
			feedback.className = 'mathisfx-feedback';
			input.parentNode.appendChild(feedback);
		}

		input.classList.remove('mathisfx-valid', 'mathisfx-invalid', 'mathisfx-warning', 'mathisfx-checking');
		feedback.classList.remove('is-valid', 'is-invalid', 'is-warning', 'is-checking');

		if (state === 'idle' || !state) {
			feedback.textContent = '';
			return;
		}

		input.classList.add('mathisfx-' + state);
		feedback.classList.add('is-' + state);
		feedback.textContent = message || '';
	}

	/**
	 * POST to admin-ajax.php with a URL-encoded body.
	 */
	function postAjax(action, payload, onResult) {
		if (!config.ajaxUrl || !config.nonce) {
			return; // Plugin not properly enqueued — silent fail.
		}

		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce',  config.nonce);
		Object.keys(payload).forEach(function (key) {
			body.set(key, payload[key]);
		});

		fetch(config.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString()
		})
			.then(function (resp) { return resp.json(); })
			.then(onResult)
			.catch(function () { onResult({ valid: false, error: strings.unknownError }); });
	}

	/**
	 * Section toggle (carry-over from Etape 4A).
	 */
	function initToggle() {
		var checkbox = document.getElementById('mathisfx_is_b2b');
		var section  = document.getElementById('mathisfx_b2b_fields');

		if (!checkbox || !section) {
			return;
		}

		function applyVisibility() {
			if (checkbox.checked) {
				section.style.display = 'block';
				section.setAttribute('aria-hidden', 'false');
			} else {
				section.style.display = 'none';
				section.setAttribute('aria-hidden', 'true');
			}
		}

		checkbox.addEventListener('change', applyVisibility);
		applyVisibility();
	}

	/**
	 * SIRET field: on blur, call INSEE Sirene via our AJAX endpoint.
	 *
	 * On success: show the company name, auto-fill the raison sociale
	 * input if it's empty.
	 * On failure: show the error inline. Server-side checkout validation
	 * will block submission anyway, but the live feedback saves the user
	 * from filling everything else for nothing.
	 */
	function initSiretValidation() {
		var input        = document.getElementById('mathisfx_siret');
		var companyInput = document.getElementById('mathisfx_company_name');

		if (!input) {
			return;
		}

		input.addEventListener('blur', function () {
			var value = (input.value || '').replace(/\D+/g, '');
			if (value.length === 0) {
				setFeedback(input, 'idle', '');
				return;
			}
			if (value.length !== 14) {
				setFeedback(input, 'invalid', '14 chiffres attendus.');
				return;
			}

			setFeedback(input, 'checking', strings.checking || '');

			postAjax('mathisfx_validate_siret', { siret: value }, function (data) {
				if (!data) {
					setFeedback(input, 'invalid', strings.unknownError);
					return;
				}
				if (data.valid) {
					var msg = sprintf(strings.siretValid || '%s', data.company_name || '');
					if (data.is_active === false) {
						setFeedback(input, 'warning', msg + ' (' + (strings.siretInactive || 'inactif') + ')');
					} else {
						setFeedback(input, 'valid', msg);
					}
					// Auto-fill the raison sociale if empty.
					if (companyInput && companyInput.value.trim() === '' && data.company_name) {
						companyInput.value = data.company_name;
					}
				} else {
					setFeedback(input, 'invalid', data.error || strings.unknownError);
				}
			});
		});
	}

	/**
	 * VAT field: on blur, call VIES.
	 *
	 * Lighter than SIRET — VIES sometimes returns trader info, sometimes
	 * not (depends on the member state). We only set valid/invalid; we
	 * don't auto-fill anything from VIES because the names returned for
	 * French entities are often missing or in a non-friendly format.
	 */
	function initVatValidation() {
		var input = document.getElementById('mathisfx_vat');

		if (!input) {
			return;
		}

		input.addEventListener('blur', function () {
			var value = (input.value || '').replace(/\s+/g, '').toUpperCase();
			// Reflect normalized form back into the input.
			input.value = value;

			if (value === '') {
				setFeedback(input, 'idle', '');
				return;
			}

			setFeedback(input, 'checking', strings.checking || '');

			postAjax('mathisfx_validate_vat', { vat: value }, function (data) {
				if (!data) {
					setFeedback(input, 'invalid', strings.unknownError);
					return;
				}
				if (data.unavailable) {
					setFeedback(input, 'warning', strings.unavailable);
					return;
				}
				if (data.valid) {
					if (data.company_name) {
						setFeedback(input, 'valid', sprintf(strings.vatValidWithName || '%s', data.company_name));
					} else {
						setFeedback(input, 'valid', strings.vatValid);
					}
				} else {
					setFeedback(input, 'invalid', data.error || strings.unknownError);
				}
			});
		});
	}

	function init() {
		initToggle();
		initSiretValidation();
		initVatValidation();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
