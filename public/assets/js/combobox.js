// <select data-searchable>: type to search a long dropdown. The select stays in the form (hidden)
// and is still what gets posted; picking a match sets its value and fires "change", so anything
// listening to the select (data-sets-customer, data-reveal) works as before. Every word typed must
// appear somewhere in the option's text, in any order. No framework, no inline script or style.
(function () {
	let uid = 0;
	const words = (s) => s.toLowerCase().split(/\s+/).filter(Boolean);

	function wire(sel) {
		const id = 'combo-' + (++uid);
		const opts = Array.from(sel.options).map((o) => ({
			value: o.value, text: o.textContent.trim(), hay: o.textContent.toLowerCase(), always: o.hasAttribute('data-always'),
			group: o.parentElement && o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : '',
		}));
		const blank = opts.find((o) => o.value === '');

		const box = document.createElement('div');
		box.className = 'combo';
		const input = document.createElement('input');
		input.type = 'text';
		input.className = 'combo-input';
		input.autocomplete = 'off';
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', id);
		input.placeholder = sel.dataset.searchable || 'Type to search';
		const list = document.createElement('ul');
		list.className = 'combo-list';
		list.id = id;
		list.setAttribute('role', 'listbox');
		list.hidden = true;
		box.append(input, list);
		sel.hidden = true;
		sel.after(box);

		let shown = [];
		let active = -1;
		const current = () => opts.find((o) => o.value === sel.value);
		const sync = () => {
			const c = current();
			input.value = c && c.value !== '' ? c.text : '';
		};

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			active = -1;
		}
		function highlight(i) {
			active = i;
			list.querySelectorAll('li').forEach((li, n) => li.classList.toggle('is-active', n === i));
			const li = list.children[i];
			if (li) {
				input.setAttribute('aria-activedescendant', li.id);
				li.scrollIntoView({ block: 'nearest' });
			}
		}
		// all: list every choice (on focus) instead of filtering by what's in the box
		function render(all) {
			const terms = all ? [] : words(input.value);
			shown = terms.length
				? [...opts.filter((o) => !o.always && o.value !== '' && terms.every((t) => o.hay.includes(t))), ...opts.filter((o) => o.always)]
				: opts.slice();
			shown = shown.slice(0, terms.length ? 60 : 400);
			list.replaceChildren();
			if (!shown.some((o) => !o.always)) {
				const li = document.createElement('li');
				li.className = 'combo-empty';
				li.textContent = 'No matches';
				list.append(li);
			}
			shown.forEach((o, n) => {
				const li = document.createElement('li');
				li.id = id + '-' + n;
				li.setAttribute('role', 'option');
				li.textContent = o.text;
				if (o.value === '') li.classList.add('combo-none');
				if (o.group) {
					const g = document.createElement('span');
					g.className = 'combo-group';
					g.textContent = o.group;
					li.append(' ', g);
				}
				li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(o); });
				list.append(li);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			const at = all ? shown.findIndex((o) => o.value === sel.value && o.value !== '') : -1;
			highlight(at >= 0 ? at : (shown.findIndex((o) => !o.always) >= 0 ? shown.findIndex((o) => !o.always) : (shown.length ? 0 : -1)));
		}
		function pick(o) {
			close();
			if (sel.value !== o.value) {
				sel.value = o.value;
				sel.dispatchEvent(new Event('change', { bubbles: true }));
			}
			sync();
		}

		input.addEventListener('focus', () => { input.select(); render(true); });
		input.addEventListener('input', () => render(false));
		input.addEventListener('keydown', (e) => {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (list.hidden) { render(true); return; }
				if (!shown.length) return;
				highlight((active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length);
			} else if (e.key === 'Enter') {
				if (!list.hidden && shown[active]) {
					e.preventDefault();
					pick(shown[active]);
				}
			} else if (e.key === 'Escape') {
				if (!list.hidden) {
					e.preventDefault();
					close();
					sync();
				}
			}
		});
		// Leaving the box: an emptied box means the blank choice; anything else unpicked goes back
		input.addEventListener('blur', () => {
			close();
			if (input.value.trim() === '' && blank) {
				pick(blank);
			} else {
				sync();
			}
		});
		// Something else changed the select (e.g. picking a project sets the customer)
		sel.addEventListener('change', sync);
		sync();
	}

	document.querySelectorAll('select[data-searchable]').forEach(wire);
})();
