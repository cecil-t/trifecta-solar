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
			clone.classList.remove('has-flag', 'has-changed', 'is-steep');
			clone.querySelectorAll('details').forEach((d) => { d.open = false; });
			clone.querySelectorAll('.pl-ovr-pop').forEach((p) => { p.hidden = true; });
			clone.querySelectorAll('.is-overridden').forEach((u) => u.classList.remove('is-overridden'));
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
			const srcSel = last.querySelector('.pl-select');
			if (form && srcSel && masters.has(srcSel)) masters.set(clone.querySelector('.pl-select'), masters.get(srcSel));
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

	// Decimal fields: ".22" becomes "0.22" when the field is left
	document.addEventListener('change', (e) => {
		const i = e.target;
		if (i.matches && i.matches('input[inputmode=decimal]') && /^-?\./.test(i.value.trim())) {
			i.value = i.value.trim().replace(/^(-?)\./, '$10.');
		}
		// Fixed precision where a field asks for it (per-watt prices: 0.2200)
		if (i.dataset && i.dataset.dec !== undefined && i.value.trim() !== '') {
			const v = parseFloat(i.value.replace(/[$,\s]/g, ''));
			if (!isNaN(v)) i.value = v.toFixed(parseInt(i.dataset.dec, 10));
		}
	}, true);

	if (!form) return;

	// ---- Sections fold from their blue header: the +/- button, or a click on the header's title.
	// "Collapse all" / "Expand all" above the sections do every section at once.
	function setFold(btn, folded) {
		const sec = document.getElementById(btn.getAttribute('aria-controls'));
		if (!sec) return;
		sec.classList.toggle('is-folded', folded);
		btn.setAttribute('aria-expanded', folded ? 'false' : 'true');
		btn.firstElementChild.textContent = folded ? '+' : '\u2212';
		const label = btn.title.replace(/^(Show|Hide) /, '');
		btn.title = (folded ? 'Show ' : 'Hide ') + label;
		btn.lastElementChild.textContent = btn.title;
		if (!folded && sec.id === 'trench-builder') trDraw();   // the drawing needs the section's width
	}
	document.addEventListener('click', (e) => {
		const all = e.target.closest('[data-fold-all]');
		if (!all) return;
		const fold = all.dataset.foldAll === 'collapse';
		form.querySelectorAll('.band .fold-btn').forEach((b) => setFold(b, fold));
	});
	form.addEventListener('click', (e) => {
		let btn = e.target.closest('.fold-btn');
		if (!btn) {
			const head = e.target.closest('.band > h2:first-child, .band > .band-head:first-child h2');
			btn = head && head.querySelector('.fold-btn');
		}
		if (!btn) return;
		const sec = document.getElementById(btn.getAttribute('aria-controls'));
		if (sec) setFold(btn, !sec.classList.contains('is-folded'));
	});

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
		const pencil = row.querySelector('.pl-pencil');
		if (pencil) pencil.hidden = custom || sel.value === '';
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
	const COMPASS = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
	const r1 = (n) => (Math.round(n * 10) / 10).toString();
	function svgEl(tag, attrs) {
		const el = document.createElementNS(NS, tag);
		Object.keys(attrs).forEach((k) => el.setAttribute(k, attrs[k]));
		return el;
	}
	// Compass (azimuth, north up) beside the tilt drawn as a roof line; red past the steep threshold
	function syncArray(row) {
		const mount = row.querySelector('.array-mount').value;
		row.dataset.mount = mount;
		row.querySelectorAll('.array-fixed-only').forEach((i) => { i.disabled = mount === 'tracker'; });
		row.querySelectorAll('.array-roof-only').forEach((i) => { i.disabled = mount !== 'roof'; });
		const tilt = mount === 'tracker' ? null : parseTilt(row.querySelector('.array-tilt').value);
		const azRaw = row.querySelector('.array-az').value.trim();
		const az = mount === 'tracker' || azRaw === '' || isNaN(parseFloat(azRaw)) ? null : ((parseFloat(azRaw) % 360) + 360) % 360;
		const t = tilt === null ? 0 : Math.max(0, Math.min(90, tilt));
		const wrap = row.querySelector('.array-pitch');
		wrap.textContent = '';
		const compass = svgEl('svg', { viewBox: '0 0 30 30', width: '30', height: '30', class: 'compass', 'aria-hidden': 'true' });
		compass.appendChild(svgEl('circle', { cx: '15', cy: '15', r: '13' }));
		const n = svgEl('text', { x: '15', y: '8.5' });
		n.textContent = 'N';
		compass.appendChild(n);
		if (az !== null) {
			compass.appendChild(svgEl('line', { class: 'compass-needle', x1: '15', y1: '15',
				x2: (15 + 11 * Math.sin(az * Math.PI / 180)).toFixed(2), y2: (15 - 11 * Math.cos(az * Math.PI / 180)).toFixed(2) }));
		}
		const isSteep = tilt !== null && tilt > steep;
		const pitch = svgEl('svg', { viewBox: '0 0 44 30', width: '40', height: '27', class: 'pitch' + (isSteep ? ' is-steep' : ''), 'aria-hidden': 'true' });
		pitch.appendChild(svgEl('line', { class: 'pitch-base', x1: '2', y1: '26', x2: '42', y2: '26' }));
		pitch.appendChild(svgEl('line', { class: 'pitch-roof', x1: '4', y1: '26',
			x2: (4 + 36 * Math.cos(t * Math.PI / 180)).toFixed(2), y2: Math.max(1, 26 - 36 * Math.sin(t * Math.PI / 180)).toFixed(2) }));
		row.classList.toggle('is-steep', isSteep);
		const label = document.createElement('span');
		label.className = 'pitch-label';
		const parts = [];
		if (mount === 'tracker') parts.push('tracks');
		if (az !== null) parts.push(Math.round(az) + '° ' + COMPASS[Math.round(az / 22.5) % 16]);
		if (tilt !== null) parts.push(r1(tilt) + '° (' + r1(12 * Math.tan(Math.min(tilt, 89) * Math.PI / 180)) + '/12)');
		label.textContent = parts.join(' · ');
		wrap.append(compass, pitch, label);
	}

	// ---- Install type limits racking and foundations; other options stay. Options are rebuilt
	// from a saved copy because a hidden <option> still shows on iPhones.
	const installSel = document.getElementById('install-type');
	const masters = new Map();
	function filterOptions(sel) {
		if (!masters.has(sel)) masters.set(sel, Array.from(sel.options).map((o) => o.cloneNode(true)));
		const type = installSel ? installSel.value : '';
		const cur = sel.value;
		const keep = masters.get(sel).filter((o) => !type || !o.dataset.mounts || o.dataset.mounts.split(',').includes(type) || o.value === cur);
		if (keep.length === sel.options.length) return;
		sel.textContent = '';
		keep.forEach((o) => sel.appendChild(o.cloneNode(true)));
		sel.value = cur;
	}
	const filterAll = () => form.querySelectorAll('.pl-block[data-cat="racking"] .pl-select, .pl-block[data-cat="foundation"] .pl-select').forEach(filterOptions);
	if (installSel) installSel.addEventListener('change', filterAll);
	filterAll();

	// ---- Tax exempt: the rate is locked (BOS still uses it) and the note clears
	const exempt = document.getElementById('tax-exempt');
	if (exempt) {
		exempt.addEventListener('change', () => {
			document.getElementById('tax-rate').readOnly = exempt.checked;
			if (exempt.checked) document.getElementById('tax-note').value = '';
		});
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
			// bring the opened category into view where it sits in the list, and flash it
			block.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
			block.classList.remove('row-flash');
			void block.offsetWidth; // restart the animation if it is opened again
			block.classList.add('row-flash');
			if (sel) sel.focus({ preventScroll: true });
		}
	});

	form.querySelectorAll('.pl-row').forEach(syncLine);
	form.querySelectorAll('.array-row').forEach(syncArray);
	form.addEventListener('change', (e) => {
		const row = e.target.closest('.pl-row');
		if (row && e.target.classList.contains('pl-select')) {
			// a different item starts from its catalog price
			const pop = row.querySelector('.pl-ovr-pop');
			if (pop) {
				pop.querySelectorAll('input').forEach((i) => { i.value = ''; });
				closePop(pop);
			}
			syncLine(row);
		}
		const arr = e.target.closest('.array-row');
		if (arr) syncArray(arr);
	});
	form.addEventListener('input', (e) => {
		const arr = e.target.closest('.array-row');
		if (arr && (e.target.classList.contains('array-tilt') || e.target.classList.contains('array-az'))) syncArray(arr);
	});

	// ---- Labor price: four decimals per watt, cents for a total
	const laborValue = document.getElementById('labor-value');
	form.querySelectorAll('input[name=labor_mode]').forEach((r) => r.addEventListener('change', () => {
		if (!laborValue || !r.checked) return;
		const dec = r.value === 'total' ? 2 : 4;
		laborValue.dataset.dec = String(dec);
		laborValue.placeholder = dec === 2 ? '0.00' : '0.0000';
		const v = parseFloat(laborValue.value.replace(/[$,\s]/g, ''));
		if (!isNaN(v)) laborValue.value = v.toFixed(dec);
	}));

	// ---- Markup stepper: 0.5% steps, never below the floor, always one decimal
	function fmtTenths(input) {
		const v = parseFloat(input.value.replace(/[%,\s]/g, ''));
		if (!isNaN(v)) input.value = v.toFixed(1);
		const min = parseFloat(input.dataset.min);
		const hint = document.getElementById(input.getAttribute('aria-describedby'));
		if (hint) hint.classList.toggle('text-red', !isNaN(v) && !isNaN(min) && v < min - 1e-9);
	}
	form.querySelectorAll('.stepper input').forEach((input) => {
		input.addEventListener('blur', () => fmtTenths(input));
		input.addEventListener('input', () => {
			const v = parseFloat(input.value);
			const min = parseFloat(input.dataset.min);
			const hint = document.getElementById(input.getAttribute('aria-describedby'));
			if (hint) hint.classList.toggle('text-red', !isNaN(v) && !isNaN(min) && v < min - 1e-9);
		});
	});
	form.addEventListener('click', (e) => {
		const btn = e.target.closest('.stepper [data-step-dir]');
		if (!btn) return;
		const input = btn.closest('.stepper').querySelector('input');
		const step = parseFloat(input.dataset.step || '0.5');
		const min = parseFloat(input.dataset.min);
		let v = parseFloat(input.value.replace(/[%,\s]/g, ''));
		if (isNaN(v)) v = isNaN(min) ? 0 : min;
		const dir = parseInt(btn.dataset.stepDir, 10);
		// snap to the step grid first, so 27.3 goes to 27.5 or 27.0
		v = dir > 0 ? Math.floor(v / step + 1e-9) * step + step : Math.ceil(v / step - 1e-9) * step - step;
		if (!isNaN(min) && v < min) v = min;
		input.value = v.toFixed(1);
		fmtTenths(input);
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	});

	// ---- Price override: the pencil opens a small box over the unit price
	function closePop(pop) {
		pop.hidden = true;
		pop.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
	}
	function closeAllPops(except) {
		form.querySelectorAll('.pl-ovr-pop:not([hidden])').forEach((p) => { if (p !== except) closePop(p); });
	}
	form.addEventListener('click', (e) => {
		const pencil = e.target.closest('.pl-pencil');
		if (pencil) {
			const pop = pencil.closest('.pl-unit').querySelector('.pl-ovr-pop');
			closeAllPops(pop);
			pop.hidden = !pop.hidden;
			if (!pop.hidden) pop.querySelector('.pl-ovr-price').focus();
			return;
		}
		const done = e.target.closest('.pl-ovr-done');
		if (done) {
			const pop = done.closest('.pl-ovr-pop');
			const price = pop.querySelector('.pl-ovr-price');
			const reason = pop.querySelector('.pl-ovr-reason');
			if (price.value.trim() !== '' && reason.value.trim() === '') {
				reason.classList.add('is-invalid');
				reason.focus();
				return;
			}
			closePop(pop);
			return;
		}
		const clear = e.target.closest('.pl-ovr-clear');
		if (clear) {
			const pop = clear.closest('.pl-ovr-pop');
			pop.querySelectorAll('input').forEach((i) => { i.value = ''; });
			closePop(pop);
			schedule();
			return;
		}
		if (!e.target.closest('.pl-ovr-pop')) closeAllPops(null);
	});
	form.addEventListener('keydown', (e) => {
		const pop = e.target.closest && e.target.closest('.pl-ovr-pop');
		if (!pop) return;
		if (e.key === 'Escape') { closePop(pop); pop.closest('.pl-unit').querySelector('.pl-pencil').focus(); }
		if (e.key === 'Enter') { e.preventDefault(); pop.querySelector('.pl-ovr-done').click(); }
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
			form.querySelectorAll('[data-v="' + k + '"]').forEach((el) => {
				setText(el, res.v[k]);
				if (el.hasAttribute('data-hide-empty')) el.hidden = res.v[k] === '';
			});
			form.querySelectorAll('[data-v-value="' + k + '"]').forEach((el) => { el.value = res.v[k]; });
		});
		const st = res.state;
		toggle('cont-capped', st.capped);
		toggle('cont-floor', st.floor);
		toggle('cont-overridden', st.overridden);
		toggle('panels-mismatch', st.mismatch);
		// Production estimate: what is still missing, and whether the saved estimate is out of date
		if (res.production) {
			const box = document.getElementById('prod-ready');
			const ul = document.getElementById('prod-missing');
			const run = document.getElementById('prod-run');
			const stale = document.getElementById('prod-stale');
			if (ul) {
				ul.replaceChildren(...res.production.missing.map((t) => { const li = document.createElement('li'); li.textContent = t; return li; }));
				box.hidden = res.production.missing.length === 0;
			}
			if (run) run.disabled = res.production.missing.length > 0;
			if (stale) stale.hidden = !res.production.stale;
		}
		const count = document.getElementById('arrays-count');
		if (count) count.textContent = st.arrays_panels + ' panels on arrays, ' + res.v.panels + ' modules';

		// Lines, matched by their row key
		form.querySelectorAll('.pl-row').forEach((row) => row.classList.add('pl-unpriced'));
		res.lines.forEach((l) => {
			const row = form.querySelector('.pl-row[data-key="' + CSS.escape(l.key) + '"]');
			if (!row) return;
			row.classList.remove('pl-unpriced');
			['qty', 'unit', 'cost', 'tax', 'retail', 'rpw', 'why'].forEach((k) => {
				const el = row.querySelector('[data-l="' + k + '"]');
				if (el) setText(el, l[k]);
			});
			const unitBox = row.querySelector('.pl-unit');
			if (unitBox) unitBox.classList.toggle('is-overridden', !!l.overridden);
			const unitEl = row.querySelector('[data-l="unit"]');
			if (unitEl) { if (l.overridden) unitEl.title = 'Catalog ' + l.catalog_unit; else unitEl.removeAttribute('title'); }
			const flags = row.querySelector('.pl-flags');
			flags.textContent = '';
			if (l.pass) flags.appendChild(chip('Pass-through', 'chip-blue'));
			if (l.pays) flags.appendChild(chip('Pays the salesperson', 'chip-green'));
			if (l.override_pending) flags.appendChild(chip('Type a reason to use the override', 'chip-red'));
			if (l.retired) flags.appendChild(chip('Retired from the catalog', 'chip-red'));
			else if (l.changed) flags.appendChild(chip('Catalog price changed', 'chip-red'));
			if (l.stale && !l.retired) flags.appendChild(chip('Check price: ' + (l.price_date || 'never dated'), 'chip-orange'));
			if (l.min) flags.appendChild(chip('Minimum applies', 'chip-blue'));
			row.classList.toggle('has-changed', l.retired || l.changed);
			row.classList.toggle('has-flag', !(l.retired || l.changed) && l.stale);
		});
		form.querySelectorAll('.pl-row.pl-unpriced').forEach((row) => {
			row.querySelectorAll('[data-l]').forEach((el) => setText(el, ''));
			row.querySelector('.pl-flags').textContent = '';
			row.querySelectorAll('.is-overridden').forEach((u) => u.classList.remove('is-overridden'));
			row.classList.remove('pl-unpriced', 'has-flag', 'has-changed');
		});

		// Category totals
		form.querySelectorAll('[data-cat-total]').forEach((row) => {
			const t = res.cats[row.dataset.catTotal] || { cost: '', tax: '', retail: '' };
			row.hidden = !res.cats[row.dataset.catTotal];
			['cost', 'tax', 'retail'].forEach((k) => setText(row.querySelector('[data-t="' + k + '"]'), t[k]));
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

		const checks = document.getElementById('design-checks');
		if (checks) {
			const ul = checks.querySelector('ul');
			ul.textContent = '';
			(res.checks || []).forEach((msg) => { const li = document.createElement('li'); li.textContent = msg; ul.appendChild(li); });
			checks.hidden = !(res.checks || []).length;
		}
		const ws = document.getElementById('warranty-summary');
		if (ws && ws.value !== res.warranty_summary) ws.value = res.warranty_summary;
		applyTrench(res.trench);
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

	// ---- Trench builder: segment keys, the "Runs through" checkboxes, fields by circuit type, and
	// a drawing per segment (length to scale; brown open trench, gray bore, tan customer trench;
	// red AC, blue DC, green comms). Sizes and prices come back from the server with the totals.
	const trBox = document.getElementById('trench-builder');
	const METHOD_LABEL = { open: 'Open trench', bore: 'Linear bore', customer: 'Customer trench' };
	const trRows = () => (trBox ? Array.from(trBox.querySelectorAll('#trench-rows > .tr-row')) : []);
	const circRows = () => (trBox ? Array.from(trBox.querySelectorAll('#circuit-rows > .tr-circ')) : []);
	let trSeq = Date.now() % 46656;
	const trKey = (p) => p + (++trSeq).toString(36);
	const num = (v) => { const n = parseFloat(String(v || '').replace(/[,\s]/g, '')); return isNaN(n) ? 0 : n; };

	function trEnsureKeys() {
		const seen = new Set();
		trRows().forEach((r) => {
			const k = r.querySelector('.tr-key');
			if (!k.value || seen.has(k.value)) {
				k.value = trKey('t');
				r.querySelector('[data-seg-out]').textContent = '';
			}
			seen.add(k.value);
		});
		const cseen = new Set();
		circRows().forEach((r) => {
			const k = r.querySelector('.tr-ckey');
			if (!k.value || cseen.has(k.value)) {
				k.value = trKey('c');
				r.querySelectorAll('[data-cr]').forEach((el) => { el.textContent = ''; });
				r.querySelector('.tr-cwarn').textContent = '';
			}
			cseen.add(k.value);
		});
	}
	function trSegments() {
		return trRows().map((r, i) => ({
			key: r.querySelector('.tr-key').value,
			name: r.querySelector('.tr-name').value.trim() || 'Segment ' + (i + 1),
			method: r.querySelector('.tr-method').value,
			len: num(r.querySelector('.tr-len').value),
		}));
	}
	// Rebuild every circuit's "Runs through" boxes from the segments as they stand, keeping what was checked
	function trSyncSegs() {
		const segs = trSegments();
		circRows().forEach((row) => {
			const box = row.querySelector('.tr-segs');
			const checked = new Set(Array.from(box.querySelectorAll('input:checked')).map((i) => i.value));
			const base = row.querySelector('.tr-ckey').name.replace(/\[key\]$/, '[segs][]');
			box.querySelectorAll('label').forEach((l) => l.remove());
			segs.forEach((s) => {
				const label = document.createElement('label');
				label.className = 'boxwrap';
				const cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.name = base;
				cb.value = s.key;
				cb.checked = checked.has(s.key);
				label.append(cb, ' ' + s.name);
				box.appendChild(label);
			});
		});
	}
	function trSyncKind(row) {
		const kind = row.querySelector('.tr-kind').value;
		row.dataset.kind = kind;
		row.querySelectorAll('.tr-ac-only input, select.tr-ac-only').forEach((i) => { i.disabled = kind !== 'ac'; });
		row.querySelectorAll('.tr-dc-only input').forEach((i) => { i.disabled = kind !== 'dc'; });
		row.querySelectorAll('.tr-wire-only select, .tr-wire-only input').forEach((i) => { i.disabled = kind === 'comms'; });
	}
	function trCircuits() {
		return circRows().map((r) => {
			const kind = r.querySelector('.tr-kind').value;
			const kwIn = r.querySelector('.tr-kw');
			return {
				kind,
				name: r.querySelector('.tr-cname').value.trim(),
				kw: num(kwIn.value),
				strings: Math.round(num(r.querySelector('.tr-strings').value)),
				segs: Array.from(r.querySelectorAll('.tr-segs input:checked')).map((i) => i.value),
			};
		});
	}
	const fmtNum = (n) => (Math.round(n * 10) / 10).toLocaleString('en-US');
	function trDraw() {
		const wrap = document.getElementById('trench-graphs');
		if (!wrap) return;
		wrap.textContent = '';
		const segs = trSegments().filter((s) => s.len > 0);
		if (!segs.length) return;
		const circs = trCircuits();
		const W = Math.max(300, Math.round(wrap.clientWidth || 640));
		const narrow = W < 520;
		const x0 = narrow ? 104 : 170;
		const maxW = W - x0 - (narrow ? 58 : 70);
		const maxLen = Math.max(...segs.map((s) => s.len));
		segs.forEach((s) => {
			const through = circs.filter((c) => c.segs.includes(s.key));
			const barH = Math.max(18, 8 + 14 * through.length);
			const top = 24;
			const H = top + barH + 6;
			const w = Math.max(22, maxW * s.len / maxLen);
			const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, width: String(W), height: String(H), class: 'tr-graph' });
			const title = svgEl('text', { x: '0', y: '15', class: 'tr-g-title' });
			const t1 = svgEl('tspan', { class: 'tr-g-name' });
			t1.textContent = s.name;
			const t2 = svgEl('tspan', { class: 'tr-g-meta', dx: '8' });
			t2.textContent = METHOD_LABEL[s.method] + ' · ' + fmtNum(s.len) + ' ft';
			title.append(t1, t2);
			svg.appendChild(title);
			svg.appendChild(svgEl('rect', { x: String(x0), y: String(top), width: w.toFixed(1), height: String(barH), rx: '3', class: 'tr-dig tr-dig-' + s.method }));
			const len = svgEl('text', { x: (x0 + w + 6).toFixed(1), y: String(top + barH / 2 + 4), class: 'tr-g-len' });
			len.textContent = fmtNum(s.len) + ' ft';
			svg.appendChild(len);
			if (!through.length) {
				const none = svgEl('text', { x: String(x0 - 8), y: String(top + barH / 2 + 4), class: 'tr-lbl tr-lbl-none', 'text-anchor': 'end' });
				none.textContent = 'No circuits yet';
				svg.appendChild(none);
			}
			through.forEach((c, i) => {
				const y = (top + 11 + 14 * i).toFixed(1);
				svg.appendChild(svgEl('line', { x1: (x0 + 4).toFixed(1), x2: (x0 + w - 4).toFixed(1), y1: y, y2: y, class: 'tr-w-under' }));
				svg.appendChild(svgEl('line', { x1: (x0 + 4).toFixed(1), x2: (x0 + w - 4).toFixed(1), y1: y, y2: y, class: 'tr-w tr-w-' + c.kind }));
				const lbl = svgEl('text', { x: String(x0 - 8), y: String(parseFloat(y) + 4), class: 'tr-lbl tr-lbl-' + c.kind, 'text-anchor': 'end' });
				lbl.textContent = c.kind === 'ac' ? (c.kw > 0 ? 'AC ' + fmtNum(c.kw) + ' kW' : 'AC, kW needed') : (c.kind === 'dc' ? 'DC ' + c.strings + ' string' + (c.strings === 1 ? '' : 's') : 'Comms');
				const tip = svgEl('title', {});
				tip.textContent = c.name || lbl.textContent;
				lbl.appendChild(tip);
				svg.appendChild(lbl);
			});
			wrap.appendChild(svg);
		});
	}
	function trSync() {
		if (!trBox) return;
		trEnsureKeys();
		trSyncSegs();
		circRows().forEach(trSyncKind);
		trDraw();
	}
	// Results from the live calculation: per circuit, per segment, the parts table and warnings
	function applyTrench(t) {
		if (!trBox || !t) return;
		setText(trBox.querySelector('[data-tr="cost"]'), t.cost);
		setText(trBox.querySelector('[data-tr="retail"]'), t.retail);
		circRows().forEach((r) => {
			const res = t.circuits[r.querySelector('.tr-ckey').value] || { text: '', conduit: '', drop: '', length: '', warn: [] };
			['text', 'conduit', 'drop', 'length'].forEach((k) => setText(r.querySelector('[data-cr="' + k + '"]'), res[k] || ''));
			const ul = r.querySelector('.tr-cwarn');
			ul.textContent = '';
			res.warn.forEach((w) => { const li = document.createElement('li'); li.textContent = w; ul.appendChild(li); });
		});
		trRows().forEach((r) => setText(r.querySelector('[data-seg-out]'), t.segments[r.querySelector('.tr-key').value] || ''));
		const body = document.getElementById('trench-parts');
		body.textContent = '';
		if (!t.parts.length) {
			const tr = document.createElement('tr');
			const td = document.createElement('td');
			td.colSpan = 6;
			td.className = 'muted';
			td.textContent = 'Nothing priced yet.';
			tr.appendChild(td);
			body.appendChild(tr);
		}
		t.parts.forEach((p) => {
			const tr = document.createElement('tr');
			if (p.missing) tr.className = 'row-warn';
			[p.name, p.qty, p.unit, p.tax, p.cost, p.retail].forEach((v, i) => {
				const td = document.createElement('td');
				if (i > 0) td.className = 'num';
				td.textContent = v;
				tr.appendChild(td);
			});
			body.appendChild(tr);
		});
		const warn = document.getElementById('trench-warnings');
		const ul = warn.querySelector('ul');
		ul.textContent = '';
		t.warnings.forEach((w) => { const li = document.createElement('li'); li.textContent = w; ul.appendChild(li); });
		warn.hidden = !t.warnings.length;
		trDraw();
	}
	if (trBox) {
		trSync();
		trBox.addEventListener('input', (e) => {
			if (e.target.matches('.tr-name')) { trSyncSegs(); trDraw(); }
			if (e.target.matches('.tr-len, .tr-kw, .tr-strings')) trDraw();
		});
		trBox.addEventListener('change', (e) => {
			const row = e.target.closest('.tr-circ');
			if (row && e.target.matches('.tr-kind')) trSyncKind(row);
			trDraw();
		});
		// rows added or removed (the shared + / x buttons)
		const watch = new MutationObserver(() => { trSync(); schedule(); });
		['trench-rows', 'circuit-rows'].forEach((id) => watch.observe(document.getElementById(id), { childList: true }));
		let resizeTimer = null;
		window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(trDraw, 150); });
	}

	// Leaving with unsaved changes asks first
	let dirty = false;
	form.addEventListener('input', () => { dirty = true; });
	form.addEventListener('change', () => { dirty = true; });
	form.addEventListener('submit', () => { dirty = false; });

	// "Save and run production" takes about 10 seconds: the button turns yellow with a spinner and
	// a counter, and a second click does nothing until the page comes back
	const prodRun = document.getElementById('prod-run');
	if (prodRun) {
		const label = prodRun.textContent;
		let timer = null;
		form.addEventListener('submit', (e) => {
			if (form.dataset.running) {
				e.preventDefault();
				return;
			}
			if (e.submitter !== prodRun) return;
			form.dataset.running = '1';
			prodRun.classList.add('is-running');
			prodRun.setAttribute('aria-busy', 'true');
			const spin = document.createElement('span');
			spin.className = 'btn-spinner';
			spin.setAttribute('aria-hidden', 'true');
			const text = document.createElement('span');
			text.textContent = 'Running production...';
			prodRun.replaceChildren(spin, text);
			const note = document.getElementById('prod-running');
			const started = Date.now();
			if (note) {
				note.hidden = false;
				timer = setInterval(() => {
					note.textContent = 'Saving and running SAM and PVWatts: ' + Math.round((Date.now() - started) / 1000) + ' seconds so far (usually about 10; a site\'s first run also downloads its weather).';
				}, 1000);
			}
		});
		// Coming back to the page from the browser's history shows the button as it was
		window.addEventListener('pageshow', (e) => {
			if (!e.persisted) return;
			delete form.dataset.running;
			clearInterval(timer);
			prodRun.classList.remove('is-running');
			prodRun.removeAttribute('aria-busy');
			prodRun.textContent = label;
			const note = document.getElementById('prod-running');
			if (note) note.hidden = true;
		});
	}
	window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
