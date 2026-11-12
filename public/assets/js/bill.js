/*
 * Read a bill on the opportunity form. [data-bill-read] holds a file input (.bill-files), a button
 * (.bill-go) and a note (.bill-note). The files go to the server, which asks Claude and answers with
 * the form's field values (App\BillReader::fields). Blank fields are filled and highlighted (the
 * address.js highlight); fields that already hold something different are listed with a button to
 * use the bill's values. Several bills (separate accounts) show as cards: the salesperson picks the
 * primary one, which fills the fields, and the others go in Notes ("Second utility bill provided by
 * customer: ..."); picking another replaces both. Nothing is saved until the form is.
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

	function setValue(f, v) {
		if (f.tagName === 'SELECT' && !Array.from(f.options).some((o) => o.value === String(v))) return false;
		f.value = String(v);
		f.dispatchEvent(new Event('input', { bubbles: true }));
		f.dispatchEvent(new Event('change', { bubbles: true }));
		mark(f);
		return true;
	}

	// The bill used for the form's fields so far, so picking another primary bill replaces its values
	let applied = null;
	let otherNote = '';

	// The other bills' lines in Notes: replaced when the primary changes, left alone otherwise
	function setOtherNotes(text) {
		const notes = field('notes');
		if (!notes) return;
		let v = notes.value;
		if (otherNote && v.includes(otherNote)) v = v.replace(otherNote, '').replace(/\n{3,}/g, '\n\n').trim();
		if (text) v = v.trim() === '' ? text : v.trim() + '\n\n' + text;
		otherNote = text;
		if (v !== notes.value) {
			notes.value = v;
			notes.dispatchEvent(new Event('input', { bubbles: true }));
			if (text) mark(notes);
		}
	}

	function apply(r) {
		const fields = r.fields;
		const prev = applied ? applied.fields : {};
		const filled = [];
		const differ = [];
		Object.keys(LABELS).forEach((name) => {
			const v = fields[name];
			const f = field(name);
			if (!f || v === '' || v === null || v === undefined) return;
			const have = f.value.trim();
			// Blank, or still holding the previous primary bill's value: take this bill's
			const fromPrev = prev[name] !== undefined && prev[name] !== null && prev[name] !== '' && have === String(prev[name]);
			if (have === '' || fromPrev || (name === 'bill_months' && !field('bill_total').value.trim())) {
				if (setValue(f, v)) filled.push(LABELS[name]);
			} else if (have.replace(/[,$]/g, '') !== String(v)) {
				differ.push([name, f, v]);
			}
		});
		// Twelve months from the usage history, when the bill prints them and none are typed
		const months = Array.from(form.querySelectorAll('input[name^="monthly_usage["]'));
		const prevMonths = (prev.monthly_usage || []).map(String);
		const monthsFromPrev = prevMonths.length === 12 && months.every((m, i) => m.value.trim() === prevMonths[i]);
		if (fields.monthly_usage && fields.monthly_usage.length === 12 && months.length === 12) {
			if (months.every((m) => m.value.trim() === '') || monthsFromPrev) {
				months.forEach((m, i) => setValue(m, fields.monthly_usage[i]));
				const det = months[0].closest('details');
				if (det) det.open = true;
				filled.push('12 months of use');
			}
		} else if (monthsFromPrev) {
			months.forEach((m) => { m.value = ''; m.classList.remove('is-auto'); });
		}
		// New customer from the name on the bill, when no customer is picked yet
		const cust = field('customer_id');
		const nn = field('new_customer_name');
		if (cust && cust.value === '' && fields.bill_name) {
			cust.value = 'new';
			cust.dispatchEvent(new Event('change', { bubbles: true }));
		}
		if (cust && cust.value === 'new' && nn && fields.bill_name && (nn.value.trim() === '' || nn.value.trim() === (prev.bill_name || ''))) {
			if (nn.value !== fields.bill_name) { setValue(nn, fields.bill_name); filled.push('New customer name'); }
		}
		// The service address, when the site has none yet (or has the previous bill's): look it up like a pasted address
		const street = form.querySelector('[data-addr="street"]');
		const full = form.querySelector('.addr-full');
		let addrLine = null;
		const addrFromPrev = Boolean(prev.service_address && full && full.value.trim() === prev.service_address);
		if (fields.service_address && full && ((street && street.value.trim() === '') || addrFromPrev)) {
			if (full.value.trim() !== fields.service_address) {
				full.value = fields.service_address;
				full.closest('[data-address]').dispatchEvent(new Event('trifecta:lookup'));
				filled.push('Site address (looking it up)');
			}
		} else if (fields.service_address) {
			addrLine = el('div', 'small muted', 'Service address on the bill: ' + fields.service_address);
		}
		applied = r;

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
		return out;
	}

	// Several bills: one card each, the salesperson picks the primary one; the rest go in Notes
	function choose(res) {
		const bills = res.bills;
		applied = null;
		if (bills.length === 1) { setOtherNotes(''); say(...apply(bills[0])); return; }
		const list = el('div', 'bill-choices');
		const detail = el('div', 'bill-applied');
		const cards = [];
		bills.forEach((b, i) => {
			const card = el('div', 'bill-choice');
			const head = el('div', 'bill-choice-head');
			const pick = el('button', 'btn btn-secondary btn-small', 'Use as primary');
			pick.type = 'button';
			pick.addEventListener('click', () => {
				detail.replaceChildren(...apply(b));
				setOtherNotes(res.other_notes[i] || '');
				detail.append(el('div', 'small muted', 'The other bill' + (bills.length > 2 ? 's are' : ' is') + ' in Notes (Site).'));
				cards.forEach((c, j) => {
					const cb = c.querySelector('.btn');
					c.classList.toggle('is-primary', j === i);
					cb.textContent = j === i ? 'Primary' : 'Use as primary';
					cb.disabled = j === i;
				});
			});
			head.append(el('strong', '', 'Bill ' + (i + 1)), pick);
			card.append(head, el('div', 'small', b.summary));
			if (b.notes) card.append(el('div', 'small text-orange', b.notes));
			cards.push(card);
		});
		list.append(...cards);
		say(el('div', 'small', bills.length + ' separate bills. Pick the primary one for this opportunity: it fills the fields below, and the others go in Notes.'), list, detail);
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
			choose(r);
		} catch (e) {
			say(el('span', 'text-orange', 'The bill could not be read right now. Try again, or enter the fields by hand.'));
		} finally {
			btn.disabled = false;
		}
	});
})();
