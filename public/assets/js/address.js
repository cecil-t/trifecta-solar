/*
 * One-line address entry. A [data-address] box holds a text field (.addr-full), a Look up button
 * (.addr-go) and a note (.addr-note). Looking up splits the address on the server (Google, then the
 * Census and OpenEI; see App\Address), fills the form's [data-addr=street|city|state|zip] fields
 * and, where the form has them, the map point (lat, lon, source) and the utility (a suggestion the
 * user confirms). The filled fields fire "change", so the township lookup (geo.js) runs too.
 * Typing in a split field afterwards clears the map point, so the server finds it again on save.
 *
 * Also the opportunity's utility rate picker: [data-rate-picker] lists OpenEI's rates for the
 * site's point, utility and sector, and keeps the pick in a hidden rate_label field.
 */
(function () {
	'use strict';

	const el = (tag, cls, text) => {
		const n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	};

	// Fields a lookup filled are highlighted until the user changes them (programmatic events are not trusted)
	const mark = (f) => { f.classList.add('is-auto'); f.title = 'Filled in by the lookup: check it'; };
	window.TrifectaMarkAuto = mark;
	['input', 'change'].forEach((type) => document.addEventListener(type, (e) => {
		if (e.isTrusted && e.target.classList && e.target.classList.contains('is-auto')) {
			e.target.classList.remove('is-auto');
			e.target.removeAttribute('title');
		}
	}));

	document.querySelectorAll('[data-address]').forEach((box) => {
		const form = box.closest('form');
		const input = box.querySelector('.addr-full');
		const btn = box.querySelector('.addr-go');
		const note = box.querySelector('.addr-note');
		const field = (k) => form.querySelector('[data-addr="' + k + '"]');
		let filling = false;
		let seq = 0;

		const set = (k, v) => {
			const f = field(k);
			if (!f || v === null || v === undefined || v === '') return;
			if (f.tagName === 'SELECT' && !Array.from(f.options).some((o) => o.value === String(v))) return;
			if (f.value === String(v)) return;
			f.value = String(v);
			f.dispatchEvent(new Event('change', { bubbles: true }));
			mark(f);
		};

		function utilityLine(u) {
			const sel = field('utility');
			if (!sel || !u) return null;
			const line = el('div', 'small');
			if (!u.ours) {
				line.append(el('span', 'muted', u.openei.length
					? 'OpenEI lists ' + u.openei.join(', ') + ' here, none of them in our Utilities list.'
					: 'OpenEI lists no utility at this point.'));
				return line;
			}
			const id = String(u.ours.id);
			if (sel.value === '') {
				set('utility', id);
				line.append(el('span', '', 'Utility set to '), el('strong', '', u.ours.name), el('span', 'muted', ' (OpenEI service territory). Check it against the bill.'));
			} else if (sel.value === id) {
				line.append(el('span', 'muted', 'Utility: '), el('strong', '', u.ours.name), el('span', 'geo-check', ' ✓ matches OpenEI'));
			} else {
				line.append(el('span', 'muted', 'OpenEI says '), el('strong', '', u.ours.name), el('span', 'muted', ' serves this point. '));
				const use = el('button', 'btn btn-secondary btn-small', 'Use ' + u.ours.name);
				use.type = 'button';
				use.addEventListener('click', () => { set('utility', id); line.replaceChildren(el('span', 'muted', 'Utility set to ' + u.ours.name + '.')); });
				line.append(use);
			}
			return line;
		}

		async function go() {
			const q = input.value.trim();
			if (q.length < 5) { note.hidden = false; note.replaceChildren(el('span', 'muted', 'Type or paste the whole address first.')); return; }
			const mine = ++seq;
			note.hidden = false;
			note.replaceChildren(el('span', 'muted', 'Looking up the address...'));
			btn.disabled = true;
			try {
				const res = await fetch(box.dataset.address + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
				const r = await res.json();
				if (mine !== seq) return;
				if (!r.ok) { note.replaceChildren(el('span', 'muted', r.error || 'Lookup is unavailable right now.')); return; }
				const src = { google: 'Google', google_approx: 'Google (town or ZIP only)', census: 'the Census', osm: 'OpenStreetMap', osm_street: 'OpenStreetMap (road only)', parsed: '' }[r.source] || '';
				filling = true;
				['street', 'city', 'state', 'zip'].forEach((k) => set(k, r.parts[k]));
				if (r.lat !== null && field('lat')) {
					field('lat').value = r.lat;
					field('lon').value = r.lon;
					mark(field('lat'));
					mark(field('lon'));
					const sum = form.querySelector('[data-point-summary]');
					if (sum) sum.textContent = Number(r.lat).toFixed(5) + ', ' + Number(r.lon).toFixed(5) + ' (' + (src || 'lookup') + ')';
					if (field('source')) field('source').value = r.source;
				}
				filling = false;
				note.replaceChildren();
				const where = el('div', 'small');
				if (src) {
					where.append(el('span', 'muted', 'Found by ' + src + ': '), el('strong', '', [r.parts.street, r.parts.city, (r.parts.state + ' ' + r.parts.zip).trim()].filter(Boolean).join(', ')));
					if (r.lat !== null) where.append(el('span', 'muted', ' · point ' + Number(r.lat).toFixed(5) + ', ' + Number(r.lon).toFixed(5)));
				} else {
					where.append(el('span', 'muted', 'Split into the fields below; the map lookup found no match. Check them, and type the map point if you have it.'));
				}
				note.append(where);
				if (form.querySelector('.is-auto')) note.append(el('div', 'small muted', 'Fields the lookup filled or changed are highlighted until you edit them.'));
				if (r.note) note.append(el('div', 'small text-orange', r.note));
				if (form.querySelector('#geo-note') && window.TrifectaGeo) {
					window.TrifectaGeo.show(r.geo || { ok: true, status: 'no_match' });
				} else if (r.geo && r.geo.label) {
					note.append(el('div', 'small muted', 'Municipality: ' + r.geo.label));
				}
				const u = utilityLine(r.utility);
				if (u) note.append(u);
				if (window.TrifectaRates) window.TrifectaRates.refresh();
			} catch (e) {
				if (mine === seq) note.replaceChildren(el('span', 'muted', 'Lookup is unavailable right now.'));
			} finally {
				btn.disabled = false;
				filling = false;
			}
		}

		btn.addEventListener('click', go);
		input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
		input.addEventListener('paste', () => setTimeout(go, 30));

		// A split field typed by hand: the old point no longer fits the address
		['street', 'city', 'state', 'zip'].forEach((k) => {
			const f = field(k);
			if (!f) return;
			f.addEventListener('input', () => {
				if (filling || !field('lat')) return;
				field('lat').value = '';
				field('lon').value = '';
				field('lat').classList.remove('is-auto');
				field('lon').classList.remove('is-auto');
				const sum = form.querySelector('[data-point-summary]');
				if (sum) sum.textContent = 'found again when you save';
				if (field('source')) field('source').value = '';
			});
		});
		['lat', 'lon'].forEach((k) => {
			const f = field(k);
			if (f) f.addEventListener('input', () => { if (field('source')) field('source').value = 'manual'; });
		});
	});

	// ------------------------------------------------------------------ utility rate picker
	const picker = document.querySelector('[data-rate-picker]');
	if (!picker) return;
	const form = picker.closest('form');
	const label = picker.querySelector('input[name=rate_label]');
	const list = picker.querySelector('.rate-list');
	const status = picker.querySelector('.rate-status');
	const chosen = picker.querySelector('.rate-chosen');
	const findBtn = picker.querySelector('.rate-find');
	const clearBtn = picker.querySelector('.rate-clear');
	const tariff = form.querySelector('input[name=utility_tariff]');
	const f = (k) => form.querySelector('[data-addr="' + k + '"]');
	const sector = () => {
		const t = form.querySelector('select[name=customer_type]');
		return t && t.value && t.value !== 'residential' ? 'Commercial' : 'Residential';
	};
	let seq = 0;

	function ready() {
		const lat = f('lat') ? f('lat').value.trim() : '';
		const util = f('utility') ? f('utility').value : '';
		if (!lat) return 'Look up the site address first, so the rates are for that point.';
		if (!util) return 'Pick the utility first.';
		return '';
	}

	function showChosen(name, about) {
		chosen.replaceChildren();
		if (!label.value) { chosen.append(el('span', 'muted', 'No rate picked.')); clearBtn.hidden = true; return; }
		chosen.append(el('strong', '', name));
		if (about) chosen.append(el('span', 'muted small', ' ' + about));
		clearBtn.hidden = false;
	}

	async function load() {
		const why = ready();
		list.replaceChildren();
		if (why) { status.textContent = why; return; }
		const mine = ++seq;
		status.textContent = 'Asking OpenEI for ' + sector().toLowerCase() + ' rates...';
		findBtn.disabled = true;
		const q = new URLSearchParams({ lat: f('lat').value.trim(), lon: f('lon').value.trim(), utility_id: f('utility').value, sector: sector() });
		try {
			const res = await fetch(picker.dataset.ratePicker + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			const r = await res.json();
			if (mine !== seq) return;
			if (!r.ok) { status.textContent = r.error; return; }
			if (!r.rates.length) {
				status.textContent = 'OpenEI has no approved ' + r.sector.toLowerCase() + ' rate for ' + (r.utility || 'this utility') + ' at this point'
					+ (r.other_utilities ? ' (it has rates for other utilities here: check the utility).' : '.');
				return;
			}
			status.textContent = r.rates.length + ' ' + r.sector.toLowerCase() + ' rate' + (r.rates.length === 1 ? '' : 's') + ' for ' + r.utility
				+ '. Pick the one on the customer’s bill. Prices are full service (delivery plus the utility’s default generation).';
			r.rates.forEach((rate) => {
				const li = el('li');
				const b = el('button', 'rate-option', '');
				b.type = 'button';
				b.append(el('strong', '', rate.name), el('span', 'muted small', ' ' + rate.about + (rate.start ? ', from ' + rate.start : '')));
				if (rate.status === 'unapproved') b.append(el('span', 'chip chip-orange', 'Not yet approved by OpenEI'));
				if (rate.status === 'ended') b.append(el('span', 'chip chip-orange', 'OpenEI’s latest, ended ' + rate.ended));
				b.addEventListener('click', () => {
					label.value = rate.label;
					if (tariff && (tariff.value === '' || tariff.dataset.fromRate === '1')) { tariff.value = rate.name; tariff.dataset.fromRate = '1'; }
					showChosen(rate.name, rate.about + (rate.status === 'unapproved' ? ' (not yet approved by OpenEI)' : rate.status === 'ended' ? ' (OpenEI’s latest, ended ' + rate.ended + ')' : ''));
					list.replaceChildren();
					status.textContent = 'Picked. Saving the opportunity keeps the full rate as it is today.';
					form.dispatchEvent(new Event('change', { bubbles: true }));
				});
				li.append(b);
				list.append(li);
			});
		} catch (e) {
			if (mine === seq) status.textContent = 'OpenEI is unavailable right now.';
		} finally {
			findBtn.disabled = false;
		}
	}

	findBtn.addEventListener('click', load);
	clearBtn.addEventListener('click', () => { label.value = ''; showChosen('', ''); status.textContent = ''; });
	window.TrifectaRates = { refresh: () => { const why = ready(); findBtn.disabled = Boolean(why); status.textContent = why; } };
	['lat', 'utility'].forEach((k) => { if (f(k)) f(k).addEventListener('change', window.TrifectaRates.refresh); });
	if (f('lat')) f('lat').addEventListener('input', window.TrifectaRates.refresh);
	window.TrifectaRates.refresh();
})();
