/* Advanced Quotes: quote generator interactions. Server-side code validates and prices everything. */
(function ($) {
	'use strict';

	var form = document.getElementById('wewp-aq-form');
	if (!form || !window.wewpAq) {
		return;
	}
	var cfg = window.wewpAq;
	var lines = document.getElementById('wewp-aq-lines');
	var template = document.getElementById('wewp-aq-line-template');
	var emptyNote = form.querySelector('.wewp-aq-empty-lines');
	var totals = form.querySelector('.wewp-aq-totals');
	var errorBox = form.querySelector('.wewp-aq-calc-error');
	var dirty = false;
	var timer = null;
	var request = null;

	function post(action, data) {
		data.append('action', action);
		data.append('nonce', cfg.nonce);
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
			return response.json();
		});
	}

	function refreshEmpty() {
		if (emptyNote) {
			emptyNote.hidden = lines.querySelectorAll('tr.wewp-aq-line').length > 0;
		}
	}

	function markDirty() {
		dirty = true;
		schedule();
	}

	function schedule() {
		window.clearTimeout(timer);
		timer = window.setTimeout(calculate, 350);
	}

	function setTotals(rows) {
		var body = document.createElement('tbody');
		rows.forEach(function (row) {
			var tr = document.createElement('tr');
			tr.className = 'is-' + row.key + (row.included ? ' is-included' : '');
			var th = document.createElement('th');
			th.scope = 'row';
			th.textContent = row.label;
			var td = document.createElement('td');
			td.textContent = row.amount;
			tr.appendChild(th);
			tr.appendChild(td);
			body.appendChild(tr);
		});
		totals.replaceChildren(body);
	}

	function calculate() {
		if (request) {
			request.abort = true;
		}
		var current = { abort: false };
		request = current;
		totals.setAttribute('aria-busy', 'true');
		var data = new FormData(form);
		data.delete('action');
		post('wewp_aq_calculate', data).then(function (json) {
			if (current.abort) {
				return;
			}
			totals.setAttribute('aria-busy', 'false');
			if (!json.success) {
				errorBox.textContent = (json.data && json.data.message) || '';
				errorBox.hidden = !errorBox.textContent;
				return;
			}
			errorBox.hidden = true;
			errorBox.textContent = '';
			var amounts = {};
			(json.data.lines || []).forEach(function (line) {
				amounts[line.key] = line.amount;
			});
			lines.querySelectorAll('tr.wewp-aq-line').forEach(function (row) {
				var out = row.querySelector('.wewp-aq-amount');
				out.textContent = amounts[row.dataset.key] || '—';
			});
			setTotals(json.data.rows || []);
		}).catch(function () {
			totals.setAttribute('aria-busy', 'false');
		});
	}

	function addRow(html) {
		var holder = document.createElement('tbody');
		holder.innerHTML = html.trim();
		var row = holder.firstElementChild;
		lines.appendChild(row);
		refreshEmpty();
		return row;
	}

	function uniqueKey() {
		return 'l' + Math.random().toString(36).slice(2, 10);
	}

	document.getElementById('wewp-aq-add-custom').addEventListener('click', function () {
		var key = uniqueKey();
		var row = addRow(template.innerHTML.split('__key__').join(key));
		var name = row.querySelector('.wewp-aq-custom-name');
		if (name) {
			name.focus();
		}
		markDirty();
	});

	var adding = false;
	$('#wewp-aq-product').on('select2:select', function (event) {
		var id = event.params && event.params.data ? event.params.data.id : $(this).val();
		if (!id || adding) {
			return;
		}
		adding = true;
		var data = new FormData();
		data.append('product', id);
		post('wewp_aq_product', data).then(function (json) {
			adding = false;
			$('#wewp-aq-product').val(null).trigger('change.select2');
			if (!json.success) {
				errorBox.textContent = (json.data && json.data.message) || cfg.i18n.chooseVariation;
				errorBox.hidden = false;
				return;
			}
			var row = addRow(json.data.html);
			var qty = row.querySelector('.col-qty input');
			if (qty) {
				qty.focus();
				qty.select();
			}
			markDirty();
		}).catch(function () {
			adding = false;
		});
	});

	$('#wewp-aq-customer-id').on('change', function () {
		var id = $(this).val();
		if (!id) {
			markDirty();
			return;
		}
		var data = new FormData();
		data.append('customer', id);
		post('wewp_aq_customer', data).then(function (json) {
			if (!json.success) {
				return;
			}
			Object.keys(json.data).forEach(function (key) {
				var input = form.querySelector('[name="customer[' + key + ']"]');
				if (!input || !json.data[key]) {
					return;
				}
				input.value = json.data[key];
				if (input.tagName === 'SELECT') {
					$(input).trigger('change.select2');
				}
			});
			markDirty();
		});
	});

	lines.addEventListener('click', function (event) {
		var button = event.target.closest('.wewp-aq-remove');
		if (!button) {
			return;
		}
		var row = button.closest('tr');
		var next = row.nextElementSibling || row.previousElementSibling;
		row.remove();
		refreshEmpty();
		if (next) {
			var focusable = next.querySelector('.wewp-aq-remove');
			if (focusable) {
				focusable.focus();
			}
		} else {
			document.getElementById('wewp-aq-add-custom').focus();
		}
		markDirty();
	});

	form.addEventListener('input', function (event) {
		if (event.target.closest('.wewp-aq-fields, .wewp-aq-side')) {
			markDirty();
		}
	});
	form.addEventListener('change', function (event) {
		if (event.target.name === 'customer[country]' || event.target.name === 'display' || event.target.name === 'template') {
			markDirty();
		}
	});
	$(form).on('change', 'select.wc-enhanced-select', markDirty);

	form.querySelectorAll('.wewp-aq-copy-btn').forEach(function (button) {
		button.addEventListener('click', function () {
			var input = document.getElementById(button.dataset.copy);
			var done = function () {
				var label = button.textContent;
				button.textContent = cfg.i18n.copied;
				window.setTimeout(function () {
					button.textContent = label;
				}, 1600);
			};
			if (navigator.clipboard) {
				navigator.clipboard.writeText(input.value).then(done);
			} else {
				input.select();
				document.execCommand('copy');
				done();
			}
		});
	});

	form.addEventListener('submit', function (event) {
		var submitter = event.submitter;
		var op = submitter ? submitter.value : 'save';
		var messages = { accept: cfg.i18n.confirmAccept, cancel: cfg.i18n.confirmCancel, reset: cfg.i18n.confirmReset };
		if (submitter && submitter.dataset.confirm && !window.confirm(messages[submitter.dataset.confirm])) {
			event.preventDefault();
			return;
		}
		if (op === 'send') {
			var email = form.querySelector('[name="customer[email]"]').value || '';
			if (!window.confirm(cfg.i18n.confirmSend.replace('%s', email))) {
				event.preventDefault();
				return;
			}
		}
		if (op !== 'preview') {
			dirty = false;
		}
	});

	window.addEventListener('beforeunload', function (event) {
		if (dirty) {
			event.preventDefault();
			event.returnValue = cfg.i18n.unsaved;
		}
	});

	refreshEmpty();
	if (lines.querySelectorAll('tr.wewp-aq-line').length) {
		calculate();
	}
})(jQuery);
