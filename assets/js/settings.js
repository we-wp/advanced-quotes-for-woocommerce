/* Advanced Quotes: logo picker and request form builder on WooCommerce → Settings → Quotes. */
(function ($) {
	'use strict';

	var frame;
	$(document).on('click', '.wewp-aq-logo-choose', function (event) {
		event.preventDefault();
		var field = $(this).closest('.wewp-aq-logo-field');
		if (!frame) {
			frame = wp.media({ title: $(this).data('title'), library: { type: ['image/png', 'image/jpeg'] }, multiple: false });
		}
		frame.off('select').on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
			field.find('input[type=hidden]').val(attachment.id);
			field.find('.wewp-aq-logo-preview').empty().append($('<img alt="">').attr('src', url));
			field.find('.wewp-aq-logo-remove').prop('hidden', false);
		});
		frame.open();
	});

	$(document).on('click', '.wewp-aq-logo-remove', function (event) {
		event.preventDefault();
		var field = $(this).closest('.wewp-aq-logo-field');
		field.find('input[type=hidden]').val('0');
		field.find('.wewp-aq-logo-preview').empty();
		$(this).prop('hidden', true);
	});

	var builder = $('.wewp-aq-builder');
	if (!builder.length) {
		return;
	}
	var body = builder.find('.wewp-aq-fields-body');
	var max = parseInt(builder.data('max'), 10) || 20;
	var i18n = window.wewpAqSettings || {};
	var choiceTypes = ['select', 'radio', 'checkboxes'];

	function speak(text) {
		if (text && window.wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(text);
		}
	}

	// A change event tells WooCommerce the form has unsaved changes.
	function changed() {
		$('#wewp-aq-fields-present').trigger('change');
	}

	function refresh() {
		var rows = body.children('.wewp-aq-field-row');
		builder.find('.wewp-aq-fields-table').prop('hidden', rows.length === 0);
		builder.find('.wewp-aq-fields-empty').prop('hidden', rows.length > 0);
		builder.find('.wewp-aq-field-add').prop('disabled', rows.length >= max);
		rows.each(function (index) {
			$(this).find('[data-move="up"]').attr('aria-disabled', index === 0 ? 'true' : 'false');
			$(this).find('[data-move="down"]').attr('aria-disabled', index === rows.length - 1 ? 'true' : 'false');
		});
	}

	function newId() {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var bytes = new Uint8Array(8);
		var id = 'f_';
		window.crypto.getRandomValues(bytes);
		bytes.forEach(function (byte) {
			id += chars.charAt(byte % chars.length);
		});
		return id;
	}

	builder.on('click', '.wewp-aq-field-add', function () {
		if (body.children('.wewp-aq-field-row').length >= max) {
			return;
		}
		var row = $($.parseHTML(builder.find('#wewp-aq-field-template').html().replace(/__id__/g, newId()).trim()));
		body.append(row);
		refresh();
		row.find('.wewp-aq-field-label').trigger('focus');
		changed();
	});

	builder.on('click', '.wewp-aq-field-remove', function () {
		var row = $(this).closest('.wewp-aq-field-row');
		var next = row.next('.wewp-aq-field-row').length ? row.next('.wewp-aq-field-row') : row.prev('.wewp-aq-field-row');
		row.remove();
		refresh();
		(next.length ? next.find('.wewp-aq-field-label') : builder.find('.wewp-aq-field-add')).trigger('focus');
		speak(i18n.removed);
		changed();
	});

	builder.on('click', '.wewp-aq-field-move', function () {
		var button = $(this);
		if (button.attr('aria-disabled') === 'true') {
			return;
		}
		var row = button.closest('.wewp-aq-field-row');
		var up = button.data('move') === 'up';
		if (up) {
			row.prev('.wewp-aq-field-row').before(row);
		} else {
			row.next('.wewp-aq-field-row').after(row);
		}
		refresh();
		button.trigger('focus');
		speak(up ? i18n.movedUp : i18n.movedDown);
		changed();
	});

	builder.on('change', '.wewp-aq-field-type', function () {
		$(this).closest('.wewp-aq-field-row').attr('data-choices', choiceTypes.indexOf(this.value) === -1 ? '0' : '1');
	});

	refresh();
})(jQuery);
