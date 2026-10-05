// Township / county lookup on the project form. The server asks the Census (see App\Geo).
// New project: looks up as the site address is typed and fills Municipality while the user hasn't
// picked one themselves. Edit: runs the one-time check for a saved address, then flags a mismatch
// with "Use" and "Keep mine" (a muni_keep checkbox posted with the form).
(function () {
	const note = document.getElementById('geo-note');
	if (!note) return;
	const form = note.closest('form');
	const field = (k) => form.querySelector('[data-geo-field="' + k + '"]');
	const addr = { street: field('street'), city: field('city'), state: field('state'), zip: field('zip') };
	const muni = form.querySelector('select[name=municipality_id]');
	const newName = form.querySelector('input[name=new_muni_name]');
	const newCounty = form.querySelector('select[name=new_muni_county_id]');
	const isEdit = Boolean(note.dataset.geoCheck);
	const state = JSON.parse(note.dataset.geo || '{}');
	let result = state.ok ? state : null;
	let confirmedId = state.confirmed_id ? String(state.confirmed_id) : null;
	let autoValue = null; // the Municipality value this script chose, so a user's own choice is never replaced
	let settingMuni = false;
	let timer = null;
	let seq = 0;

	const el = (tag, cls, text) => {
		const n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	};
	const complete = () => {
		const v = (k) => (addr[k] ? addr[k].value.trim() : '');
		return /\d/.test(v('street')) && (v('city') || v('zip')) && (v('state') || v('zip'));
	};
	const selectedText = () => (muni.selectedIndex >= 0 ? muni.options[muni.selectedIndex].text : '');
	// "Penn Township (Lancaster Co., PA)" -> ["Lancaster", "PA"]
	const countyOf = (text) => { const m = /\((.+) Co\., ([A-Z]{2})\)$/.exec(text); return m ? [m[1].toLowerCase(), m[2]] : null; };

	function mismatch() {
		if (!result || result.status !== 'found' || muni.value === '' || muni.value === 'new') return false;
		if (result.municipality_id) return muni.value !== String(result.municipality_id);
		if (result.name) return true; // the lookup's municipality isn't in the list, so whatever is chosen differs
		const c = countyOf(selectedText()); // MD / DE outside a town: only the county can disagree
		const want = /^No municipality, (.+) Co\., ([A-Z]{2})$/.exec(result.label || '');
		return Boolean(c && want && (c[0] !== want[1].toLowerCase() || c[1] !== want[2]));
	}

	function useLookup() {
		if (!result || !result.name) return;
		settingMuni = true;
		if (result.municipality_id) {
			muni.value = String(result.municipality_id);
		} else {
			muni.value = 'new';
			if (newName) newName.value = result.name;
			if (newCounty && result.county_id) newCounty.value = String(result.county_id);
		}
		muni.dispatchEvent(new Event('change', { bubbles: true }));
		settingMuni = false;
		autoValue = muni.value;
		render();
	}

	function render(message) {
		note.replaceChildren();
		note.classList.remove('geo-warn', 'geo-ok');
		if (message) {
			note.append(el('span', 'muted', message));
			note.hidden = false;
			return;
		}
		if (!result) { note.hidden = true; return; }
		note.hidden = false;
		if (result.status !== 'found') {
			note.append(el('span', 'muted', 'Address lookup found no match for this address. Choose the municipality by hand.'));
			return;
		}
		const line = el('div');
		line.append(el('span', 'muted', 'Address lookup: '), el('strong', '', result.label));
		if (result.source === 'osm_street') line.append(el('span', 'muted', ' (approximate: found the road, not the house)'));
		note.append(line);

		const bad = mismatch();
		if (muni.value !== '' && muni.value !== 'new' && !bad) {
			note.classList.add('geo-ok');
			line.append(el('span', 'geo-check', ' ✓ matches'));
			return;
		}
		if (bad && isEdit && confirmedId === muni.value) {
			note.append(el('div', 'muted small', 'Kept ' + selectedText() + ' over the lookup.'));
			return;
		}
		const actions = el('div', 'geo-actions');
		if (bad) {
			note.classList.add('geo-warn');
			note.append(el('div', 'small', isEdit
				? 'This doesn’t match the municipality chosen above.'
				: 'This doesn’t match your choice. Saving keeps your choice.'));
		}
		if (result.name && (bad || muni.value === '')) {
			const btn = el('button', 'btn btn-secondary btn-small', 'Use ' + result.name + (result.municipality_id ? '' : ' (adds it)'));
			btn.type = 'button';
			btn.addEventListener('click', useLookup);
			actions.append(btn);
		}
		if (bad && isEdit) {
			const lab = el('label', 'check');
			const box = el('input');
			box.type = 'checkbox';
			box.name = 'muni_keep';
			box.value = '1';
			lab.append(box, document.createTextNode(' Keep ' + selectedText() + ' (the lookup is wrong)'));
			actions.append(lab);
		}
		if (actions.childElementCount) note.append(actions);
	}

	async function lookup() {
		if (!complete()) return;
		const mine = ++seq;
		render('Looking up the township and county...');
		const q = new URLSearchParams();
		for (const k of Object.keys(addr)) if (addr[k]) q.set(k, addr[k].value.trim());
		try {
			const res = await fetch(note.dataset.geoUrl + '?' + q.toString(), { headers: { Accept: 'application/json' } });
			const data = await res.json();
			if (mine !== seq) return;
			if (!data.ok) { result = null; render(data.reason === 'unavailable' || res.ok ? 'Address lookup is unavailable right now.' : ''); return; }
			result = data;
			confirmedId = null; // a new address needs a new decision
			if (!isEdit && (muni.value === '' || muni.value === autoValue)) useLookup();
			render();
		} catch (e) {
			if (mine === seq) render('Address lookup is unavailable right now.');
		}
	}

	async function firstCheck() {
		render('Checking the township and county for this address...');
		try {
			const res = await fetch(note.dataset.geoCheck, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
				body: new URLSearchParams({ _csrf: note.dataset.csrf }),
			});
			const data = await res.json();
			if (!data.ok) { render(data.reason === 'unavailable' ? 'Address lookup is unavailable right now. It will try again next time.' : ''); return; }
			result = data;
			render();
		} catch (e) {
			render('Address lookup is unavailable right now. It will try again next time.');
		}
	}

	Object.values(addr).forEach((input) => {
		if (!input) return;
		input.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(lookup, 250); });
	});
	muni.addEventListener('change', () => { if (!settingMuni) autoValue = null; render(); });
	if (newName) newName.addEventListener('input', () => { if (!settingMuni) autoValue = null; });

	if (isEdit && state.pending) {
		firstCheck();
	} else {
		render();
	}
})();
