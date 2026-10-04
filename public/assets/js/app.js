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
})();
