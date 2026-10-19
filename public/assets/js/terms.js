/* Proposal terms page: open or close every block at once. */
(function () {
	'use strict';
	document.querySelectorAll('[data-terms-all]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var open = btn.getAttribute('data-terms-all') === 'open';
			document.querySelectorAll('details.terms-block:not(.is-new)').forEach(function (d) {
				d.open = open;
			});
		});
	});
})();
