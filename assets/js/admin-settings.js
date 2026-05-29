/**
 * Admin Settings JS — wires the Media Library picker for the logo field
 * and initialises the WP color picker on the primary-color field.
 *
 * Enqueued only on WooCommerce → Settings → Factur-X.
 *
 * @package Mathis\FacturX\WooCommerce
 */

(function ($) {
	'use strict';

	var config = window.mathisfxAdminSettings || {};

	$(function () {
		initColorPickers();
		initMediaPickers();
	});

	/**
	 * Turn every text input emitted by WC's `color` field type into a real
	 * WP color picker. WC renders these as <input class="colorpick">.
	 */
	function initColorPickers() {
		if (typeof $.fn.wpColorPicker !== 'function') {
			return;
		}
		$('.colorpick').wpColorPicker();
	}

	/**
	 * Each `.mathisfx-media-picker` is one row of our custom field.
	 * Holds: hidden input for the attachment id, a preview <div>, a
	 * "Choose / Change" button, a "Remove" button.
	 */
	function initMediaPickers() {
		if (typeof wp === 'undefined' || !wp.media) {
			return; // wp_enqueue_media() was not called — nothing to do.
		}

		$('.mathisfx-media-picker').each(function () {
			var $picker    = $(this);
			var $input     = $picker.find('input[type="hidden"]');
			var $preview   = $picker.find('.mathisfx-media-preview');
			var $chooseBtn = $picker.find('.mathisfx-media-choose');
			var $removeBtn = $picker.find('.mathisfx-media-remove');

			var frame;

			$chooseBtn.on('click', function (e) {
				e.preventDefault();

				if (!frame) {
					frame = wp.media({
						title:    config.mediaTitle  || 'Choose image',
						button:   { text: config.mediaButton || 'Use this image' },
						library:  { type: 'image' },
						multiple: false
					});

					frame.on('select', function () {
						var attachment = frame.state().get('selection').first().toJSON();
						$input.val(attachment.id).trigger('change');

						var thumbUrl = attachment.url;
						if (attachment.sizes && attachment.sizes.thumbnail) {
							thumbUrl = attachment.sizes.thumbnail.url;
						}

						$preview.html(
							$('<img>')
								.attr('src', thumbUrl)
								.attr('alt', '')
								.css({
									maxWidth:   '160px',
									maxHeight:  '80px',
									border:     '1px solid #ddd',
									padding:    '4px',
									background: '#fff'
								})
						);

						$removeBtn.show();
					});
				}

				frame.open();
			});

			$removeBtn.on('click', function (e) {
				e.preventDefault();
				$input.val('0').trigger('change');
				$preview.empty();
				$removeBtn.hide();
			});
		});
	}
})(jQuery);
