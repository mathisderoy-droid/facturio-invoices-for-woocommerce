/**
 * Toggle visibility of the B2B section on the classic WooCommerce checkout.
 *
 * Pure vanilla JS, no jQuery. WC loads jQuery on the checkout page anyway,
 * but using vanilla keeps this script lightweight and independent of
 * upstream WC changes to its jQuery API.
 *
 * @package Mathis\FacturX\WooCommerce
 */

(function () {
	'use strict';

	function init() {
		var checkbox = document.getElementById('mathisfx_is_b2b');
		var section  = document.getElementById('mathisfx_b2b_fields');

		if (!checkbox || !section) {
			return;
		}

		function applyVisibility() {
			if (checkbox.checked) {
				section.style.display    = 'block';
				section.setAttribute('aria-hidden', 'false');
			} else {
				section.style.display    = 'none';
				section.setAttribute('aria-hidden', 'true');
			}
		}

		checkbox.addEventListener('change', applyVisibility);

		// Initial render — covers the case where the form is re-submitted
		// with errors and the checkbox was previously checked.
		applyVisibility();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
