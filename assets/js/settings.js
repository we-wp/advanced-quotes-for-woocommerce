/* Advanced Quotes: logo picker on WooCommerce → Settings → Quotes. */
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
})(jQuery);
