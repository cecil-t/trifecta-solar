// Small progressive enhancements for forms. No framework.
(function () {
	// <select data-reveal="value" data-target="#el">: show #el when that value is selected
	// <select data-reveal-any="a,b" data-target="#el">: show #el when any listed value is selected
	function wireReveal(sel) {
		const target = document.querySelector(sel.dataset.target);
		if (!target) return;
		const values = (sel.dataset.revealAny || sel.dataset.reveal).split(',');
		const update = () => { target.hidden = !values.includes(sel.value); };
		sel.addEventListener('change', update);
		update();
	}
	document.querySelectorAll('select[data-reveal], select[data-reveal-any]').forEach(wireReveal);

	// <select data-sets-customer="customer_id">: picking an option with data-customer selects that
	// customer in the named select (service ticket: Original project sets Customer)
	document.querySelectorAll('select[data-sets-customer]').forEach((sel) => {
		const target = sel.form && sel.form.elements[sel.dataset.setsCustomer];
		if (!target) return;
		sel.addEventListener('change', () => {
			const id = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.customer : '';
			if (!id || id === '0' || !target.querySelector('option[value="' + CSS.escape(id) + '"]')) return;
			if (target.value === id) return;
			target.value = id;
			target.dispatchEvent(new Event('change'));
		});
	});

	// Opportunity: retail rate from the bill, (total - customer charge x months) / kWh, as it is typed
	document.querySelectorAll('[data-bill-rate]').forEach((box) => {
		const num = (name) => {
			const raw = (box.querySelector('[name=' + name + ']').value || '').replace(/[^0-9.\-]/g, '');
			return raw === '' ? null : parseFloat(raw);
		};
		const out = box.querySelector('.bill-rate-out');
		const update = () => {
			const total = num('bill_total');
			const kwh = num('bill_kwh');
			const charge = num('bill_charge') || 0;
			const months = parseInt(box.querySelector('[name=bill_months]').value, 10) || 1;
			out.textContent = '';
			out.classList.remove('bad');
			if (total === null || kwh === null || !(kwh > 0)) return;
			const cents = (total - charge * months) / kwh * 100;
			out.append('Retail rate: ');
			const b = document.createElement('strong');
			b.textContent = cents.toFixed(2) + ' cents per kWh';
			out.append(b);
			if (cents < 1 || cents > 200) {
				out.classList.add('bad');
				out.append(' (check the numbers)');
			}
		};
		box.addEventListener('input', update);
		box.addEventListener('change', update);
	});

	// <input type=checkbox data-toggle="#el">: show #el while checked
	document.querySelectorAll('input[type=checkbox][data-toggle]').forEach((cb) => {
		const target = document.querySelector(cb.dataset.toggle);
		const update = () => { if (target) target.hidden = !cb.checked; };
		cb.addEventListener('change', update);
		update();
	});

	// Equipment and roof face line editors: add / remove rows, renumbering the [i] in field names
	document.querySelectorAll('.eq').forEach((eq) => {
		const renumber = () => {
			eq.querySelectorAll('.eq-row').forEach((row, i) => {
				row.querySelectorAll('input, select').forEach((inp) => {
					inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
				});
			});
		};
		eq.querySelector('.eq-add').addEventListener('click', () => {
			const rows = eq.querySelectorAll('.eq-row');
			const clone = rows[rows.length - 1].cloneNode(true);
			clone.querySelectorAll('input, select').forEach((i) => { i.value = ''; });
			rows[rows.length - 1].after(clone);
			renumber();
			clone.querySelector('input').focus();
		});
		eq.addEventListener('click', (e) => {
			if (!e.target.classList.contains('eq-del')) return;
			const rows = eq.querySelectorAll('.eq-row');
			const row = e.target.closest('.eq-row');
			if (rows.length > 1) { row.remove(); } else { row.querySelectorAll('input, select').forEach((i) => { i.value = ''; }); }
			renumber();
		});
	});
})();
