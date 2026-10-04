// Report page behaviors (loaded by reports/_filters.php on every report). Each block checks for its
// own elements, so it only runs on the report that has them.

// Picking a preset applies it right away; typing a date switches the preset to Custom.
(function () {
	const form = document.getElementById('report-filters');
	const preset = document.getElementById('rf-preset');
	if (!form) return;
	form.querySelectorAll('select').forEach((s) => s.addEventListener('change', () => form.submit()));
	['rf-from', 'rf-to'].forEach((id) => {
		const el = document.getElementById(id);
		if (el && preset) el.addEventListener('change', () => { preset.value = 'custom'; });
	});
})();

// Growth-adjusted projection, recalculated as the percentage changes.
(function () {
	const g = document.getElementById('growth');
	if (!g) return;
	const fmt = {
		count: (n) => n.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
		money: (n) => '$' + Math.round(n).toLocaleString('en-US'),
		kw: (n) => n.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
	};
	function calc() {
		const k = 1 + (parseFloat(g.value) || 0) / 100;
		document.querySelectorAll('[data-show="growth"]').forEach((el) => { el.textContent = parseFloat(g.value) || 0; });
		document.querySelectorAll('#proj-table [data-base]').forEach((td) => {
			td.querySelector('[data-v]').textContent = fmt[td.dataset.kind](parseFloat(td.dataset.base) * k);
		});
	}
	g.addEventListener('input', calc);
	calc();
})();

// Live average: unchecked projects drop out of the completed-time stats (and print struck through).
(function () {
	const table = document.getElementById('time-table');
	if (!table) return;
	const set = (k, v) => { const el = document.querySelector('#time-stats [data-stat="' + k + '"]'); if (el) el.textContent = v; };
	table.addEventListener('change', () => {
		const days = [];
		table.querySelectorAll('tbody tr[data-days]').forEach((tr) => {
			const on = tr.querySelector('input').checked;
			tr.classList.toggle('is-excluded', !on);
			if (on) days.push(+tr.dataset.days);
		});
		days.sort((a, b) => a - b);
		const n = days.length;
		set('count', n);
		set('avg', n ? Math.round(days.reduce((a, b) => a + b, 0) / n) : '-');
		set('median', n ? Math.round(n % 2 ? days[(n - 1) / 2] : (days[n / 2 - 1] + days[n / 2]) / 2) : '-');
	});
})();

// Warranty cost estimate, priced live from the two rate boxes.
(function () {
	const trip = document.getElementById('rate-trip');
	const hour = document.getElementById('rate-hour');
	if (!trip || !hour) return;
	const fmt = (n, round) => '$' + n.toLocaleString('en-US', { minimumFractionDigits: round ? 0 : 2, maximumFractionDigits: round ? 0 : 2 });
	function calc() {
		const t = parseFloat(trip.value) || 0;
		const h = parseFloat(hour.value) || 0;
		document.querySelectorAll('[data-show="trip"]').forEach((el) => { el.textContent = t; });
		document.querySelectorAll('[data-show="hour"]').forEach((el) => { el.textContent = h; });
		document.querySelectorAll('[data-wtrips]').forEach((row) => {
			const cost = (+row.dataset.wtrips) * t + (+row.dataset.whours) * h;
			row.querySelectorAll('[data-est]').forEach((el) => {
				el.textContent = cost || el.hasAttribute('data-round') ? fmt(cost, el.hasAttribute('data-round')) : '';
			});
		});
	}
	trip.addEventListener('input', calc);
	hour.addEventListener('input', calc);
	calc();
})();
