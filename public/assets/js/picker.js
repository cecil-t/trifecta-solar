/*
 * Searchable pickers: a text box that searches the directory as you type and keeps the picked
 * record's id in a hidden field; typing over a pick clears the id, so a plain typed name is kept
 * as text. Markup: .picker[data-picker-url] > input.picker-input + input.picker-id + ul.picker-list.
 * Also shows and hides the opportunity form's lead fields (data-lead-show) by lead source.
 */
(function () {
	'use strict';

	document.querySelectorAll('.picker').forEach((box) => {
		const input = box.querySelector('.picker-input');
		const idField = box.querySelector('.picker-id');
		const list = box.querySelector('.picker-list');
		const url = box.dataset.pickerUrl;
		let timer = null;
		let seq = 0;
		let items = [];
		let active = -1;

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			active = -1;
		}
		function highlight(i) {
			active = i;
			list.querySelectorAll('li').forEach((li, k) => li.classList.toggle('active', k === i));
		}
		function pick(item) {
			input.value = item.name;
			idField.value = String(item.id);
			close();
		}
		function render() {
			list.textContent = '';
			items.forEach((item, i) => {
				const li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.id = list.id + '-' + i;
				const name = document.createElement('span');
				name.textContent = item.name;
				const meta = document.createElement('small');
				meta.textContent = item.kind + (item.city ? ', ' + item.city : '');
				li.appendChild(name);
				li.appendChild(meta);
				li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(item); });
				list.appendChild(li);
			});
			list.hidden = items.length === 0;
			input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
			active = -1;
		}
		function search() {
			const q = input.value.trim();
			if (q.length < 2) { items = []; render(); return; }
			const my = ++seq;
			fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then((r) => (r.ok ? r.json() : []))
				.then((res) => { if (my === seq) { items = Array.isArray(res) ? res : []; render(); } })
				.catch(() => {});
		}

		input.addEventListener('input', () => {
			idField.value = '';
			clearTimeout(timer);
			timer = setTimeout(search, 200);
		});
		input.addEventListener('keydown', (e) => {
			if (list.hidden) return;
			if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(items.length - 1, active + 1)); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(0, active - 1)); }
			else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(items[active]); }
			else if (e.key === 'Escape') { close(); }
		});
		input.addEventListener('blur', () => setTimeout(close, 100));
	});

	// Lead fields that only apply to one lead source
	const source = document.getElementById('lead-source');
	if (source) {
		const sync = () => {
			document.querySelectorAll('[data-lead-show]').forEach((el) => {
				el.hidden = el.dataset.leadShow !== source.value;
			});
		};
		source.addEventListener('change', sync);
		sync();
	}
})();
