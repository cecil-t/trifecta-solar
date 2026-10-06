// Site-wide behavior loaded on every page.
(function () {
	// Floating column headers sit just under the sticky top bar, whatever its height.
	const topbar = document.querySelector('.topbar');
	const setTopbarHeight = () => {
		if (topbar) document.documentElement.style.setProperty('--topbar-h', topbar.offsetHeight + 'px');
	};
	setTopbarHeight();
	window.addEventListener('resize', setTopbarHeight);

	// Record pages (a project): once the page heading scrolls away, a slim bar under the top bar
	// names the record. It hangs below the top bar instead of pushing the page down, and the
	// floating column headers move down to clear it. Clicking it goes back to the top.
	const pin = document.getElementById('topbar-pin');
	const pinFor = document.querySelector('.project-head h1');
	if (pin && pinFor && topbar && 'IntersectionObserver' in window) {
		topbar.appendChild(pin);
		const pinPhase = document.getElementById('pin-phase');
		const badge = document.getElementById('phase-badge');
		const setOffsets = () => {
			const h = topbar.offsetHeight + (pin.hidden ? 0 : pin.offsetHeight);
			document.documentElement.style.setProperty('--topbar-h', h + 'px');
		};
		new IntersectionObserver(([entry]) => {
			const show = !entry.isIntersecting && entry.boundingClientRect.top < topbar.offsetHeight;
			if (show && badge && pinPhase) { // follows phase changes made on this page
				pinPhase.className = badge.className;
				pinPhase.textContent = badge.textContent;
			}
			pin.hidden = !show;
			setOffsets();
		}, { rootMargin: '-' + topbar.offsetHeight + 'px 0px 0px 0px' }).observe(pinFor);
		window.addEventListener('resize', setOffsets);
		pin.querySelector('button').addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
	}

	// Drop-down menus (<details>): close when clicking anywhere else, pressing Escape,
	// or opening another menu.
	const menus = 'details.usermenu, details.navmenu, details.tmenu';
	document.addEventListener('click', (e) => {
		document.querySelectorAll(menus + '[open]').forEach((d) => {
			if (!d.contains(e.target)) d.removeAttribute('open');
		});
	});
	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') document.querySelectorAll(menus + '[open]').forEach((d) => d.removeAttribute('open'));
	});
	document.querySelectorAll(menus).forEach((d) => {
		d.addEventListener('toggle', () => {
			if (d.open) document.querySelectorAll(menus + '[open]').forEach((o) => { if (o !== d) o.removeAttribute('open'); });
		});
	});

	// Coming back to a list from a record (#project-12, #ticket-7, #todo-3), or to a log entry from the
	// dashboard (#log-40): open its folded year group,
	// bring the row into view below the floating header, and flash it.
	const row = /^#[\w-]+$/.test(location.hash) ? document.querySelector('tr' + location.hash + ', li.todo' + location.hash + ', li.activity-item' + location.hash) : null;
	if (row) {
		const body = row.closest('tbody[hidden]');
		if (body) {
			body.hidden = false;
			const toggle = body.previousElementSibling && body.previousElementSibling.querySelector('.year-toggle');
			if (toggle) toggle.setAttribute('aria-expanded', 'true');
		}
		// After load, so the browser's own jump to the #anchor (row at the very top, under the
		// floating headers) has already happened and this one wins.
		const show = () => requestAnimationFrame(() => row.scrollIntoView({ block: 'center' }));
		if (document.readyState === 'complete') show(); else window.addEventListener('load', show);
		row.classList.add('row-flash');
	}

	// Page behaviors wired by data attributes instead of inline handlers, so the Content Security
	// Policy can forbid inline script.
	document.addEventListener('click', (e) => {
		const t = e.target;
		// Folded year groups (Projects, Service): the button opens or closes the tbody after it.
		const yearToggle = t.closest('.year-toggle');
		if (yearToggle) {
			const body = yearToggle.closest('tbody').nextElementSibling;
			body.hidden = !body.hidden;
			yearToggle.setAttribute('aria-expanded', String(!body.hidden));
			return;
		}
		// Dashboard open items: a project row opens or closes its item list (its link still navigates).
		const openHead = t.closest('tr.open-head');
		if (openHead && !t.closest('a')) {
			const body = openHead.closest('tbody').nextElementSibling;
			body.hidden = !body.hidden;
			openHead.classList.toggle('is-open', !body.hidden);
			return;
		}
		// Edit buttons that show the form row under them (service visits).
		const rowToggle = t.closest('[data-toggle-next-row]');
		if (rowToggle) {
			const next = rowToggle.closest('tr').nextElementSibling;
			next.hidden = !next.hidden;
			return;
		}
		if (t.closest('[data-print]')) {
			window.print();
			return;
		}
		// A button with its own confirm (e.g. a formaction delete inside an edit form).
		const confirmBtn = t.closest('button[data-confirm]');
		if (confirmBtn && !window.confirm(confirmBtn.dataset.confirm)) {
			e.preventDefault();
			return;
		}
		// Whole-row links in lists; clicks on links and controls inside the row keep their own job.
		const rowLink = t.closest('tr[data-href]');
		if (rowLink && !t.closest('a, button, input, select, textarea, label')) {
			location.href = rowLink.dataset.href;
		}
	});

	// Forms that ask before submitting (deletes).
	document.addEventListener('submit', (e) => {
		const form = e.target;
		if (form.matches('form[data-confirm]') && !window.confirm(form.dataset.confirm)) e.preventDefault();
	});

	// Filter drop-downs that apply as soon as they change.
	document.addEventListener('change', (e) => {
		if (e.target.matches('select[data-autosubmit]')) e.target.form.submit();
	});

	// Service worker, so the app can be installed to a phone's home screen.
	if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
		navigator.serviceWorker.register('/sw.js');
	}
})();
