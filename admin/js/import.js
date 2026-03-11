/* global jQuery, vikingbadImport */
(function ($) {
	'use strict';

	var totals = { created: 0, updated: 0, failed: 0, skipped: 0 };

	function updateStats(stats) {
		totals.created += stats.created || 0;
		totals.updated += stats.updated || 0;
		totals.failed  += stats.failed  || 0;
		totals.skipped += stats.skipped || 0;

		$('#vikingbad-count-created').text(totals.created);
		$('#vikingbad-count-updated').text(totals.updated);
		$('#vikingbad-count-failed').text(totals.failed);
		$('#vikingbad-count-skipped').text(totals.skipped);

		if (stats.errors && stats.errors.length) {
			var $list = $('#vikingbad-error-list');
			$('#vikingbad-errors').show();
			stats.errors.forEach(function (err) {
				$list.append($('<li>').text(err));
			});
		}

		if (stats.skipped_products && stats.skipped_products.length) {
			var $table = $('#vikingbad-skipped-list');
			$('#vikingbad-skipped').show();
			$('#vikingbad-skipped-count').text(totals.skipped);
			stats.skipped_products.forEach(function (p) {
				$table.append(
					$('<tr>')
						.append($('<td>').text(p.sku))
						.append($('<td>').text(p.name))
						.append($('<td>').text(p.description))
						.append($('<td>').text(p.reason))
				);
			});
		}
	}

	function setProgress(current, total) {
		var pct = Math.round((current / total) * 100);
		$('#vikingbad-progress-fill').css('width', pct + '%');
		$('#vikingbad-progress-text').text(
			vikingbadImport.i18n.importing
				.replace('%1$d', current)
				.replace('%2$d', total)
		);
	}

	function importPage(page, totalPages) {
		return $.post(vikingbadImport.ajaxUrl, {
			action: 'vikingbad_import_page',
			nonce:  vikingbadImport.nonce,
			page:   page,
		}).then(function (response) {
			if (!response.success) {
				throw new Error(response.data.message || 'Unknown error');
			}

			updateStats(response.data.stats);
			setProgress(page, totalPages);

			if (page < totalPages) {
				return importPage(page + 1, totalPages);
			}
		});
	}

	$(function () {
		$('#vikingbad-start-import').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);

			// Reset state.
			totals = { created: 0, updated: 0, failed: 0, skipped: 0 };
			$('#vikingbad-count-created, #vikingbad-count-updated, #vikingbad-count-failed, #vikingbad-count-skipped').text('0');
			$('#vikingbad-error-list').empty();
			$('#vikingbad-skipped-list').empty();
			$('#vikingbad-errors, #vikingbad-skipped').hide();
			$('#vikingbad-progress-wrap, #vikingbad-stats').show();
			$('#vikingbad-progress-fill').css('width', '0%');
			$('#vikingbad-progress-text').text('Starter import...');

			$.post(vikingbadImport.ajaxUrl, {
				action: 'vikingbad_start_import',
				nonce:  vikingbadImport.nonce,
			})
			.then(function (response) {
				if (!response.success) {
					throw new Error(response.data.message || 'Unknown error');
				}

				var totalPages = response.data.total_pages;

				updateStats(response.data.stats);
				setProgress(1, totalPages);

				if (totalPages > 1) {
					return importPage(2, totalPages);
				}
			})
			.then(function () {
				$('#vikingbad-progress-text').text(vikingbadImport.i18n.complete);
				$('#vikingbad-progress-fill').css('width', '100%');
			})
			.fail(function (jqXHR, textStatus, errorThrown) {
				var msg = errorThrown || textStatus || 'Request failed';
				$('#vikingbad-progress-text')
					.text(vikingbadImport.i18n.error.replace('%s', msg))
					.addClass('vikingbad-error-text');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
