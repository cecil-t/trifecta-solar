// Inline editing for project tasks: each field saves on change and updates status badges.
(function () {
	const head = document.querySelector('[data-project]');
	if (!head) return;
	const projectId = head.dataset.project;
	const csrf = head.dataset.csrf;
	const phaseClasses = ['pre_install', 'installation', 'closeout', 'complete', 'cancelled'];

	async function save(row, field, value) {
		const body = new URLSearchParams({ _csrf: csrf, field, value });
		row.classList.add('is-saving');
		try {
			const res = await fetch('/projects/' + projectId + '/tasks/' + row.dataset.id, {
				method: 'POST', body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
			});
			const data = await res.json().catch(() => ({ ok: false, error: 'Server error' }));
			if (!data.ok) throw new Error(data.error || 'Save failed');
			applyStatus(data);
			flashRow(row, 'saved');
			return data;
		} catch (err) {
			flashRow(row, 'error');
			alert('Not saved: ' + err.message);
			throw err;
		} finally {
			row.classList.remove('is-saving');
		}
	}

	function flashRow(row, cls) {
		row.classList.add('is-' + cls);
		setTimeout(() => row.classList.remove('is-' + cls), 1200);
	}

	function applyStatus(d) {
		Object.entries(d.resolved || {}).forEach(([id, ok]) => {
			const r = document.querySelector('.trow[data-id="' + id + '"]');
			if (r) r.classList.toggle('is-resolved', !!ok);
		});
		// Phase headers: resolved count, and the checkmark once every step is resolved.
		document.querySelectorAll('.phase-block').forEach((block) => {
			const steps = block.querySelectorAll('.trow-task');
			const done = block.querySelectorAll('.trow-task.is-resolved').length;
			const count = block.querySelector('.phase-done');
			if (count) count.textContent = done;
			const check = block.querySelector('.phase-check');
			if (check) check.hidden = !(steps.length && done === steps.length);
		});
		const badge = document.getElementById('phase-badge');
		if (badge && d.phase) {
			phaseClasses.forEach((c) => badge.classList.remove('phase-' + c));
			badge.classList.add('phase-' + d.phase);
			badge.textContent = d.phase_label;
		}
		const clear = document.getElementById('clear-badge');
		if (clear) clear.hidden = !(d.phase === 'pre_install' && d.clear_to_install);
		const days = document.getElementById('days-badge');
		if (days && d.days !== null && d.days !== undefined) {
			days.hidden = false;
			document.getElementById('days-num').textContent = d.days;
		}
	}

	// Target date highlight: past due with no done date (cream), 30+ days past due (red).
	function localToday() {
		const d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function markOverdue(row) {
		const target = row.querySelector('[data-field="target_date"]');
		if (!target) return;
		const done = row.querySelector('[data-field="done_date"]');
		const needed = row.querySelector('[data-field="needed"]') || { value: (row.querySelector('.needed-cell') || {}).dataset?.needed ?? '' };
		let cls = '';
		const today = localToday();
		if (target.value && target.value < today && !(done && done.value)
			&& !(needed && needed.value === '0') && !row.classList.contains('parent-na')) {
			const days = Math.round((Date.parse(today) - Date.parse(target.value)) / 86400000);
			cls = days >= 30 ? 'is-overdue-30' : 'is-overdue';
		}
		if (!cls && target.value) cls = 'has-target';
		target.classList.remove('is-overdue', 'is-overdue-30', 'has-target');
		if (cls) target.classList.add(cls);
		target.title = cls && cls !== 'has-target' ? 'Past target date' + (cls === 'is-overdue-30' ? ' by 30+ days' : '') : '';
	}

	document.querySelectorAll('.trow[data-id]').forEach((row) => {
		row.querySelectorAll('[data-field]').forEach((el) => {
			el.addEventListener('change', async () => {
				const field = el.dataset.field;
				try {
					await save(row, field, el.value);
					if (field === 'needed') {
						row.classList.toggle('is-na', el.value === '0');
						if (row.classList.contains('trow-task')) {
							row.closest('.task').querySelectorAll('.trow-sub').forEach((s) => { s.classList.toggle('parent-na', el.value === '0'); markOverdue(s); });
						}
					}
					if (['needed', 'target_date', 'done_date'].includes(field)) markOverdue(row);
				} catch (e) { /* alert already shown */ }
			});
		});
	});

	// Menu actions
	document.addEventListener('click', async (e) => {
		const btn = e.target.closest('[data-action]');
		if (!btn) return;
		const row = btn.closest('.trow');
		btn.closest('details')?.removeAttribute('open');
		if (btn.dataset.action === 'rename') {
			const name = prompt('Rename to:', btn.dataset.name);
			if (name && name.trim() && name !== btn.dataset.name) {
				await save(row, 'name', name.trim());
				location.reload();
			}
		}
		if (btn.dataset.action === 'set-needed') {
			await save(row, 'needed', btn.dataset.value);
			location.reload();
		}
		if (btn.dataset.action === 'add-sub') {
			const name = prompt('New sub-task name:');
			if (name && name.trim()) {
				const f = document.getElementById('add-sub-form');
				f.elements['parent_id'].value = btn.dataset.parent;
				f.elements['name'].value = name.trim();
				f.submit();
			}
		}
	});


	// Sub-tasks under a "No" task look disabled
	document.querySelectorAll('.task').forEach((t) => {
		const sel = t.querySelector('.trow-task [data-field="needed"]') || { value: (t.querySelector('.trow-task .needed-cell') || {}).dataset?.needed ?? '' };
		if (sel && sel.value === '0') t.querySelectorAll('.trow-sub').forEach((s) => s.classList.add('parent-na'));
	});

	document.querySelectorAll('.trow[data-id]').forEach(markOverdue);

	// Collapsible phases. Phones start with only the current phase open; desktop starts with
	// every phase open. Click a phase bar to open or close it; choices are remembered for this
	// project during the browser session (separately for phone and desktop widths), and a
	// #task-123 link always opens its phase.
	const phone = window.matchMedia('(max-width: 760px)');
	const current = 'phase-' + (document.getElementById('tasks')?.dataset.currentPhase || '');
	const allPhases = [...document.querySelectorAll('.phase-block')].map((b) => b.id);
	const key = () => 'open-phases-' + projectId + (phone.matches ? '-m' : '-d');
	const readOpen = () => {
		try {
			const saved = sessionStorage.getItem(key());
			if (saved !== null) return JSON.parse(saved);
		} catch (e) { /* storage blocked */ }
		return phone.matches ? [current] : allPhases;
	};
	const writeOpen = (ids) => { try { sessionStorage.setItem(key(), JSON.stringify(ids)); } catch (e) { /* private mode */ } };
	function applyPhases() {
		const open = readOpen();
		const target = location.hash ? document.querySelector(location.hash) : null;
		document.querySelectorAll('.phase-block').forEach((block) => {
			const keepOpen = open.includes(block.id) || (target && block.contains(target));
			block.classList.toggle('is-collapsed', !keepOpen);
		});
	}
	document.querySelectorAll('[data-phase-toggle]').forEach((title) => {
		title.addEventListener('click', () => {
			const block = title.closest('.phase-block');
			block.classList.toggle('is-collapsed');
			writeOpen([...document.querySelectorAll('.phase-block:not(.is-collapsed)')].map((b) => b.id));
		});
	});
	applyPhases();
	phone.addEventListener('change', applyPhases);

	// Beside Completed: a green check fills in today while empty; an x clears a set date.
	function syncToday(input) {
		input.closest('.date-wrap')?.classList.toggle('has-date', !!input.value);
	}
	document.querySelectorAll('.date-wrap input').forEach((input) => {
		syncToday(input);
		input.addEventListener('change', () => syncToday(input));
		input.addEventListener('input', () => syncToday(input));
	});
	document.addEventListener('click', (e) => {
		const c = e.target.closest('[data-clear-date]');
		if (c) {
			e.preventDefault();
			const input = c.closest('.date-wrap').querySelector('input');
			if (input.value && confirm('Clear the completed date?')) {
				input.value = '';
				input.dispatchEvent(new Event('change'));
			}
			return;
		}
		const b = e.target.closest('[data-set-today]');
		if (!b) return;
		e.preventDefault();
		const input = b.closest('.date-wrap').querySelector('input');
		input.value = localToday();
		input.dispatchEvent(new Event('change'));
	});
})();
