/*
 * Read a bill on the opportunity form. [data-bill-read] holds a file input (.bill-files), a button
 * (.bill-go) and a note (.bill-note). The files go to the server, which asks Claude and answers with
 * the form's field values (App\BillReader::fields). Blank fields are filled and highlighted (the
 * address.js highlight); fields that already hold something different are listed with a button to
 * use the bill's values. Nothing is saved until the form is.
 *
 * Also highlights fields a website request's bill filled (form[data-auto-fields]).
 */
(function () {
	'use strict';

	const el = (tag, cls, text) => {
		const n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	};
	const mark = (f) => {
		if (window.TrifectaMarkAuto) { window.TrifectaMarkAuto(f); } else { f.classList.add('is-auto'); }
		f.title = 'Read from the bill: check it';
	};

	const LABELS = {
		bill_name: 'Name on the bill', utility_id: 'Utility', utility_account: 'Account #', utility_meter: 'Meter #',
		utility_tariff: 'Rate name', annual_usage_kwh: 'Yearly use', bill_kwh: 'kWh on the bill', bill_total: 'Bill total',
		bill_charge: 'Customer charge', bill_months: 'Months covered', ptc_cents: 'Price to Compare',
	};

	// Fields filled on the server from a website request's bill
	document.querySelectorAll('form[data-auto-fields]').forEach((form) => {
		form.dataset.autoFields.split(',').forEach((name) => {
			if (name === 'monthly_usage') {
				form.querySelectorAll('input[name^="monthly_usage["]').forEach(mark);
				return;
			}
			const f = form.querySelector('[name="' + name + '"]');
			if (f) mark(f);
		});
	});

	const box = document.querySelector('[data-bill-read]');
	if (!box || !box.querySelector('.bill-go')) return;
	const form = box.closest('form');
	const input = box.querySelector('.bill-files');
	const btn = box.querySelector('.bill-go');
	const note = box.querySelector('.bill-note');
	const field = (name) => form.querySelector('[name="' + name + '"]');
	const say = (...nodes) => { note.hidden = false; note.replaceChildren(...nodes); };

	// "JOHN Q SMITH" reads better as "John Q Smith"; mixed case is left alone
	const tidyName = (s) => (s === s.toUpperCase() ? s.toLowerCase().replace(/\b[a-z]/g, (c) => c.toUpperCase()) : s);

	function setValue(f, v) {
		if (f.tagName === 'SELECT' && !Array.from(f.options).some((o) => o.value === String(v))) return false;
		f.value = String(v);
		f.dispatchEvent(new Event('input', { bubbles: true }));
		f.dispatchEvent(new Event('change', { bubbles: true }));
		mark(f);
		return true;
	}

	function apply(r) {
		const fields = r.fields;
		const filled = [];
		const differ = [];
		if (fields.bill_name) fields.bill_name = tidyName(fields.bill_name);
		Object.keys(LABELS).forEach((name) => {
			const v = fields[name];
			const f = field(name);
			if (!f || v === '' || v === null || v === undefined) return;
			const have = f.value.trim();
			if (have === '' || (name === 'bill_months' && !field('bill_total').value.trim())) {
				if (setValue(f, v)) filled.push(LABELS[name]);
			} else if (have.replace(/[,$]/g, '') !== String(v)) {
				differ.push([name, f, v]);
			}
		});
		// Twelve months from the usage history, when the bill prints them and none are typed
		const months = Array.from(form.querySelectorAll('input[name^="monthly_usage["]'));
		if (fields.monthly_usage && fields.monthly_usage.length === 12 && months.length === 12) {
			if (months.every((m) => m.value.trim() === '')) {
				months.forEach((m, i) => setValue(m, fields.monthly_usage[i]));
				const det = months[0].closest('details');
				if (det) det.open = true;
				filled.push('12 months of use');
			}
		}
		// New customer from the name on the bill, when no customer is picked yet
		const cust = field('customer_id');
		if (cust && cust.value === '' && fields.bill_name) {
			cust.value = 'new';
			cust.dispatchEvent(new Event('change', { bubbles: true }));
			const nn = field('new_customer_name');
			if (nn && nn.value.trim() === '') { setValue(nn, fields.bill_name); filled.push('New customer name'); }
		}
		// The service address, when the site has none yet: look it up like a pasted address
		const street = form.querySelector('[data-addr="street"]');
		const full = form.querySelector('.addr-full');
		let addrLine = null;
		if (fields.service_address && street && street.value.trim() === '' && full) {
			full.value = fields.service_address;
			full.closest('[data-address]').dispatchEvent(new Event('trifecta:lookup'));
			filled.push('Site address (looking it up)');
		} else if (fields.service_address) {
			addrLine = el('div', 'small muted', 'Service address on the bill: ' + fields.service_address);
		}

		const out = [];
		out.push(el('div', 'small', filled.length ? 'Filled from the bill: ' + filled.join(', ') + '. Highlighted until you edit them.' : 'Nothing new to fill: the fields already hold values.'));
		if (r.period) out.push(el('div', 'small muted', 'Billing period ' + r.period + (r.supplier ? '; generation from ' + r.supplier : '') + (r.demand_kw ? '; billed demand ' + r.demand_kw + ' kW' : '') + '.'));
		if (fields.utility_text && !fields.utility_id) out.push(el('div', 'small text-orange', 'The bill is from ' + fields.utility_text + ', which is not in our Utilities list. Pick the utility by hand.'));
		if (addrLine) out.push(addrLine);
		if (r.notes) out.push(el('div', 'small text-orange', r.notes));
		if (r.uncertain && r.uncertain.length) out.push(el('div', 'small text-orange', 'Hard to read on the bill, check: ' + r.uncertain.join(', ').replace(/_/g, ' ') + '.'));
		if (differ.length) {
			const d = el('div', 'small');
			d.append(el('span', '', 'The bill differs from what is entered: ' + differ.map(([n, , v]) => LABELS[n] + ' ' + (n === 'utility_id' ? fields.utility_text : v)).join('; ') + '. '));
			const use = el('button', 'btn btn-secondary btn-small', 'Use the bill’s values');
			use.type = 'button';
			use.addEventListener('click', () => { differ.forEach(([, f, v]) => setValue(f, v)); use.remove(); });
			d.append(use);
			out.push(d);
		}
		if (!fields.annual_usage_kwh) out.push(el('div', 'small muted', 'The bill shows no 12-month total or printed monthly history, so yearly use still needs entering.'));
		say(...out);
	}

	btn.addEventListener('click', async () => {
		const files = Array.from(input.files || []);
		if (!files.length) { say(el('span', 'muted', 'Choose the bill first: a PDF, or photos of its pages.')); return; }
		const total = files.reduce((n, f) => n + f.size, 0);
		if (files.some((f) => f.size > 15 * 1024 * 1024) || total > 24 * 1024 * 1024) { say(el('span', 'text-orange', 'Too large: 15 MB per file and 24 MB in all.')); return; }
		const data = new FormData();
		data.append('_csrf', form.querySelector('input[name=_csrf]').value);
		files.forEach((f) => data.append('bill[]', f, f.name));
		btn.disabled = true;
		say(el('span', 'muted', 'Reading the bill... this takes 10 to 30 seconds.'));
		try {
			const res = await fetch(box.dataset.billRead, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
			const r = await res.json();
			if (!r.ok) { say(el('span', 'text-orange', r.error || 'The bill could not be read.')); return; }
			apply(r);
		} catch (e) {
			say(el('span', 'text-orange', 'The bill could not be read right now. Try again, or enter the fields by hand.'));
		} finally {
			btn.disabled = false;
		}
	});
})();
