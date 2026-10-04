// Site-wide behavior loaded on every page.
(function () {
	// Floating column headers sit just under the sticky top bar, whatever its height.
	const topbar = document.querySelector('.topbar');
	const setTopbarHeight = () => {
		if (topbar) document.documentElement.style.setProperty('--topbar-h', topbar.offsetHeight + 'px');
	};
	setTopbarHeight();
	window.addEventListener('resize', setTopbarHeight);

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

	// Coming back to a list from a record (#project-12): open its folded year group,
	// bring the row into view below the floating header, and flash it.
	const row = /^#[\w-]+$/.test(location.hash) ? document.querySelector('tr' + location.hash) : null;
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
})();
