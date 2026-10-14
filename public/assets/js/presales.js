// Build sheet editor (Pre-Sales): line rows, arrays, live totals, copy buttons.
// The math lives on the server (App\Pricing); this file posts the form to .../calc as it
// changes and writes the returned figures into the page with textContent only.
(function () {
	const form = document.getElementById('design-form');

	// Copy buttons: data-copy-from="id" copies that field's value (works on any page)
	document.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-copy-from]');
		if (!btn) return;
		const src = document.getElementById(btn.dataset.copyFrom);
		if (!src) return;
		const text = src.value !== undefined ? src.value : src.textContent;
		const done = () => {
			btn.classList.add('is-copied');
			const was = btn.getAttribute('title');
			btn.setAttribute('title', 'Copied');
			setTimeout(() => { btn.classList.remove('is-copied'); if (was) btn.setAttribute('title', was); }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, () => { fallbackCopy(src, text); done(); });
		} else {
			fallbackCopy(src, text);
			done();
		}
	});
	function fallbackCopy(src, text) {
		const t = document.createElement('textarea');
		t.value = text;
		t.setAttribute('readonly', '');
		t.className = 'visually-hidden';
		document.body.appendChild(t);
		t.select();
		try { document.execCommand('copy'); } catch (err) { /* nothing more to try */ }
		t.remove();
	}

	// ---- Rows (build sheet, Rates page): add, remove, renumber the first [n] in each field name.
	// Radio buttons that pick a default row carry the row number as their value.
	const renumber = (box) => {
		box.querySelectorAll(':scope > .eq-row, :scope > .pl-row').forEach((row, i) => {
			row.querySelectorAll('input, select, textarea').forEach((inp) => {
				if (inp.name) inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
				if (inp.type === 'radio' && inp.dataset.rowValue !== undefined) inp.value = String(i);
			});
		});
	};
	let newKey = 0;
	document.addEventListener('click', (e) => {
		const add = e.target.closest('.row-add');
		if (add) {
			const box = add.closest('[data-rows]');
			const rows = box.querySelectorAll(':scope > .eq-row, :scope > .pl-row');
			const last = rows[rows.length - 1];
			const clone = last.cloneNode(true);
			clone.querySelectorAll('input, select, textarea').forEach((i) => {
				if (i.type === 'checkbox' || i.type === 'radio') i.checked = false;
				else if (i.tagName === 'SELECT') i.selectedIndex = i.classList.contains('array-mount') ? last.querySelector('.array-mount').selectedIndex : 0;
				else i.value = '';
				if (i.dataset.defaultChecked) i.checked = true;
			});
			clone.classList.remove('has-flag', 'is-steep');
			clone.querySelectorAll('[data-l]').forEach((s) => { s.textContent = ''; });
			clone.querySelectorAll('.pl-flags').forEach((s) => { s.textContent = ''; });
			const key = clone.querySelector('.pl-key');
			if (key) {
				newKey += 1;
				key.value = 'new' + newKey;
				clone.dataset.key = key.value;
			}
			last.after(clone);
			renumber(box);
			if (form && clone.classList.contains('pl-row')) syncLine(clone);
			if (form && clone.classList.contains('array-row')) syncArray(clone);
			const first = clone.querySelector('select, input[type=text]');
			if (first) first.focus();
			if (form) schedule();
			return;
		}
		const del = e.target.closest('.row-del');
		if (del) {
			const box = del.closest('[data-rows]');
			const row = del.closest('.eq-row, .pl-row');
			const rows = box.querySelectorAll(':scope > .eq-row, :scope > .pl-row');
			if (rows.length > 1) {
				row.remove();
			} else {
				row.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((i) => {
					if (i.type === 'checkbox' || i.type === 'radio') i.checked = false; else if (i.tagName === 'SELECT') i.selectedIndex = 0; else i.value = '';
				});
				row.querySelectorAll('input[type=hidden]:not(.pl-key)').forEach((i) => { i.value = ''; });
				row.querySelectorAll('[data-l]').forEach((s) => { s.textContent = ''; });
				if (form && row.classList.contains('pl-row')) syncLine(row);
			}
			renumber(box);
			if (form) schedule();
		}
	});

	if (!form) return;

	// ---- A line's controls follow the picked item: quantity box or automatic, custom fields, extended warranty, notes
	function syncLine(row) {
		const sel = row.querySelector('.pl-select');
		if (!sel) return;
		const opt = sel.options[sel.selectedIndex];
		const rule = (opt && opt.dataset.rule) || 'manual';
		const custom = sel.value === 'custom';
		row.querySelector('.pl-custom').hidden = !custom;
		row.querySelector('.pl-qty-input').hidden = rule !== 'manual';
		const auto = row.querySelector('.pl-qty-auto');
		auto.hidden = rule === 'manual' || sel.value === '';
		const ext = row.querySelector('.pl-ext');
		const extText = opt && opt.dataset.ext;
		ext.hidden = !extText;
		if (!extText) ext.querySelector('input').checked = false;
		ext.querySelector('.pl-ext-label').textContent = extText ? '(+' + extText + ')' : '';
		const notes = row.querySelector('.pl-notes');
		const text = opt && opt.dataset.notes !== undefined ? opt.dataset.notes : null;
		if (text !== null) {
			notes.hidden = text === '';
			notes.querySelector('.pl-notes-text').textContent = text;
		}
		if (rule === 'manual' && !custom && sel.value !== '' && row.querySelector('.pl-qty-input').value === '') {
			row.querySelector('.pl-qty-input').value = '1';
		}
	}

	// ---- Arrays: tilt drawing (red when steep), tracker and roof-only fields
	const steep = parseFloat(form.dataset.steep || '30');
	const NS = 'http://www.w3.org/2000/svg';
	function parseTilt(raw) {
		raw = (raw || '').replace('°', '').replace('deg', '').trim();
		if (raw === '') return null;
		const m = raw.match(/^(\d+(?:\.\d+)?)\s*[/:]\s*12$/);
		if (m) return Math.atan(parseFloat(m[1]) / 12) * 180 / Math.PI;
		const n = parseFloat(raw);
		return isNaN(n) ? null : n;
	}
	function syncArray(row) {
		const mount = row.querySelector('.array-mount').value;
		row.dataset.mount = mount;
		row.querySelectorAll('.array-fixed-only').forEach((i) => { i.disabled = mount === 'tracker'; });
		row.querySelectorAll('.array-roof-only').forEach((i) => { i.disabled = mount !== 'roof'; });
		const tilt = mount === 'tracker' ? null : parseTilt(row.querySelector('.array-tilt').value);
		const t = tilt === null ? 0 : Math.max(0, Math.min(90, tilt));
		const wrap = row.querySelector('.array-pitch');
		let svg = wrap.querySelector('svg');
		if (!svg) {
			svg = document.createElementNS(NS, 'svg');
			svg.setAttribute('viewBox', '0 0 44 30');
			svg.setAttribute('width', '44');
			svg.setAttribute('height', '30');
			svg.setAttribute('class', 'pitch');
			['pitch-base', 'pitch-roof'].forEach((c) => { const l = document.createElementNS(NS, 'line'); l.setAttribute('class', c); svg.appendChild(l); });
			wrap.prepend(svg);
		}
		const [base, roof] = svg.querySelectorAll('line');
		base.setAttribute('x1', '2'); base.setAttribute('y1', '26'); base.setAttribute('x2', '42'); base.setAttribute('y2', '26');
		roof.setAttribute('x1', '4'); roof.setAttribute('y1', '26');
		roof.setAttribute('x2', (4 + 36 * Math.cos(t * Math.PI / 180)).toFixed(2));
		roof.setAttribute('y2', Math.max(1, 26 - 36 * Math.sin(t * Math.PI / 180)).toFixed(2));
		const isSteep = tilt !== null && tilt > steep;
		svg.setAttribute('class', 'pitch' + (isSteep ? ' is-steep' : ''));
		row.classList.toggle('is-steep', isSteep);
		const label = wrap.querySelector('.pitch-label');
		if (tilt === null) {
			label.textContent = mount === 'tracker' ? 'tracks' : '';
		} else {
			const r = (n) => (Math.round(n * 10) / 10).toString();
			label.textContent = r(tilt) + '° (' + r(12 * Math.tan(Math.min(tilt, 89) * Math.PI / 180)) + '/12)';
		}
	}

	// Folded categories open from the "Add a line for" buttons
	form.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-show-cat]');
		if (!btn) return;
		const block = form.querySelector('.pl-block[data-cat="' + btn.dataset.showCat + '"]');
		if (block) {
			block.hidden = false;
			btn.remove();
			const more = form.querySelector('.pl-more');
			if (more && !more.querySelector('[data-show-cat]')) more.remove();
			const sel = block.querySelector('select');
			if (sel) sel.focus();
		}
	});

	form.querySelectorAll('.pl-row').forEach(syncLine);
	form.querySelectorAll('.array-row').forEach(syncArray);
	form.addEventListener('change', (e) => {
		const row = e.target.closest('.pl-row');
		if (row && e.target.classList.contains('pl-select')) syncLine(row);
		const arr = e.target.closest('.array-row');
		if (arr) syncArray(arr);
	});
	form.addEventListener('input', (e) => {
		const arr = e.target.closest('.array-row');
		if (arr && e.target.classList.contains('array-tilt')) syncArray(arr);
	});

	// ---- Live totals
	let timer = null;
	let seq = 0;
	const status = document.getElementById('calc-status');
	const errBox = document.getElementById('calc-error');
	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(recalc, 350);
	}
	form.addEventListener('input', (e) => { if (!e.target.closest('.warranty-summary')) schedule(); });
	form.addEventListener('change', schedule);

	function recalc() {
		const my = ++seq;
		const data = new FormData(form);
		fetch(form.dataset.calcUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then((r) => (r.ok && (r.headers.get('content-type') || '').includes('json') ? r.json() : Promise.reject(new Error('The totals could not be updated (signed out?). Save to recalculate.'))))
			.then((res) => { if (my === seq) apply(res); })
			.catch((err) => { if (my === seq) showError(err.message); });
	}
	function showError(msg) {
		errBox.textContent = msg;
		errBox.hidden = !msg;
	}
	const setText = (el, v) => { if (el.textContent !== v) el.textContent = v; };

	function apply(res) {
		if (res.error) { showError(res.error); return; }
		showError('');
		Object.keys(res.v).forEach((k) => {
			form.querySelectorAll('[data-v="' + k + '"]').forEach((el) => setText(el, res.v[k]));
			form.querySelectorAll('[data-v-value="' + k + '"]').forEach((el) => { el.value = res.v[k]; });
		});
		const st = res.state;
		toggle('cont-capped', st.capped);
		toggle('cont-floor', st.floor);
		toggle('cont-overridden', st.overridden);
		toggle('panels-mismatch', st.mismatch);
		const count = document.getElementById('arrays-count');
		if (count) count.textContent = st.arrays_panels + ' panels on arrays, ' + res.v.panels + ' modules';

		// Lines, matched by their row key
		form.querySelectorAll('.pl-row').forEach((row) => row.classList.add('pl-unpriced'));
		res.lines.forEach((l) => {
			const row = form.querySelector('.pl-row[data-key="' + CSS.escape(l.key) + '"]');
			if (!row) return;
			row.classList.remove('pl-unpriced');
			['qty', 'unit', 'cost', 'tax', 'retail', 'rpw'].forEach((k) => {
				const el = row.querySelector('[data-l="' + k + '"]');
				if (el) setText(el, l[k]);
			});
			const flags = row.querySelector('.pl-flags');
			flags.textContent = '';
			if (l.retired) flags.appendChild(chip('Retired from the catalog', 'chip-red'));
			else if (l.changed) flags.appendChild(chip('Catalog price changed', 'chip-orange'));
			if (l.stale && !l.retired) flags.appendChild(chip('Old price', 'chip-red'));
			if (l.min) flags.appendChild(chip('Minimum applies', 'chip-blue'));
			row.classList.toggle('has-flag', l.retired || l.changed || l.stale);
		});
		form.querySelectorAll('.pl-row.pl-unpriced').forEach((row) => {
			row.querySelectorAll('[data-l]').forEach((el) => setText(el, ''));
			row.querySelector('.pl-flags').textContent = '';
			row.classList.remove('pl-unpriced', 'has-flag');
		});

		// Contingency rules
		res.rules.forEach((r) => {
			const tr = form.querySelector('tr[data-rule="' + r.id + '"]');
			if (!tr) return;
			const cb = tr.querySelector('input[type=checkbox]');
			if (r.auto) {
				if (!cb.disabled) cb.dataset.was = cb.checked ? '1' : '';
				cb.checked = true;
				cb.disabled = true;
			} else if (cb.dataset.manualOk) {
				if (cb.disabled) { cb.disabled = false; cb.checked = cb.dataset.was === '1'; }
			} else {
				cb.checked = r.on;
			}
			setText(tr.querySelector('.rule-why'), r.why || (cb.dataset.manualOk ? 'Check if it applies' : ''));
		});

		// Payments
		const body = document.getElementById('payments-body');
		body.textContent = '';
		res.payments.forEach((p) => {
			const tr = document.createElement('tr');
			[p.label, p.amount, p.pct_balance, p.pct_total].forEach((v, i) => {
				const td = document.createElement('td');
				if (i > 0) td.className = 'num';
				td.textContent = v;
				tr.appendChild(td);
			});
			body.appendChild(tr);
		});

		// Deposit choices follow the system size
		const dep = document.getElementById('deposit-select');
		if (dep && res.deposit_options.length) {
			const want = res.deposit_options.map((o) => o.value).join('|');
			if (dep.dataset.opts !== want) {
				const cur = dep.value;
				dep.textContent = '';
				res.deposit_options.forEach((o) => {
					const opt = document.createElement('option');
					opt.value = o.value;
					opt.textContent = o.label;
					dep.appendChild(opt);
				});
				dep.value = res.deposit_options.some((o) => o.value === cur) ? cur : res.deposit_options[0].value;
				dep.dataset.opts = want;
				if (dep.value !== cur) schedule();
			}
		}

		const ws = document.getElementById('warranty-summary');
		if (ws && ws.value !== res.warranty_summary) ws.value = res.warranty_summary;
		if (status) status.textContent = 'Totals updated. Save to keep changes.';
	}
	function toggle(id, on) {
		const el = document.getElementById(id);
		if (el) el.hidden = !on;
	}
	function chip(text, cls) {
		const s = document.createElement('span');
		s.className = 'chip ' + cls;
		s.textContent = text;
		return s;
	}

	// Leaving with unsaved changes asks first
	let dirty = false;
	form.addEventListener('input', () => { dirty = true; });
	form.addEventListener('change', () => { dirty = true; });
	form.addEventListener('submit', () => { dirty = false; });
	window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
