/* Advanced Quotes: copies the chosen quantity and variation into the quote request button. */
(function () {
	'use strict';

	document.addEventListener('submit', function (event) {
		var form = event.target.closest && event.target.closest('form.wewp-aq-add');
		if (!form) {
			return;
		}
		var scope = form.closest('.product, .wp-block-woocommerce-single-product, .wp-block-group, main') || document;
		var cart = scope.querySelector('form.cart') || document.querySelector('form.cart');
		var error = form.querySelector('.wewp-aq-add-error');
		var quantity = cart && cart.querySelector('[name="quantity"]');
		if (quantity && parseInt(quantity.value, 10) > 0) {
			form.querySelector('[name="quantity"]').value = quantity.value;
		}
		if (form.dataset.variable === '1') {
			var variation = cart && cart.querySelector('[name="variation_id"]');
			if (!variation || !variation.value || variation.value === '0') {
				event.preventDefault();
				if (error) {
					error.textContent = (window.wewpAqStore && window.wewpAqStore.chooseOptions) || '';
					error.hidden = false;
				}
				return;
			}
			form.querySelector('[name="variation_id"]').value = variation.value;
			// Carry the chosen options, including values the variation leaves open ("any").
			form.querySelectorAll('input[data-wewp-aq-attribute]').forEach(function (input) {
				input.remove();
			});
			cart.querySelectorAll('[name^="attribute_"]').forEach(function (select) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'attributes[' + select.name + ']';
				input.value = select.value;
				input.setAttribute('data-wewp-aq-attribute', '');
				form.appendChild(input);
			});
		}
		if (error) {
			error.hidden = true;
		}
	});
})();
