/*
 * PDF links (a[data-pdf]): making the PDF takes several seconds, so the button turns orange with a
 * spinner and a seconds counter while the file is fetched, then the browser saves it. A problem
 * shows next to the button.
 */
(function () {
	'use strict';
	function save(blob, name) {
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = name;
		document.body.append(a);
		a.click();
		a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
	}
	document.querySelectorAll('a[data-pdf]').forEach(function (link) {
		var label = link.textContent;
		link.addEventListener('click', function (e) {
			if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
				return;   // a new tab or window works the usual way
			}
			e.preventDefault();
			if (link.dataset.running) {
				return;
			}
			link.dataset.running = '1';
			var gone = link.parentNode.querySelector('.pdf-error');
			if (gone) {
				gone.remove();
			}
			link.classList.add('is-running');
			link.setAttribute('aria-busy', 'true');
			var spin = document.createElement('span');
			spin.className = 'btn-spinner';
			spin.setAttribute('aria-hidden', 'true');
			var text = document.createElement('span');
			text.textContent = 'Making PDF...';
			link.replaceChildren(spin, text);
			var started = Date.now();
			var timer = setInterval(function () {
				text.textContent = 'Making PDF... ' + Math.round((Date.now() - started) / 1000) + 's';
			}, 1000);
			fetch(link.href, { credentials: 'same-origin', headers: { 'X-Pdf-Fetch': '1' } })
				.then(function (r) {
					var type = r.headers.get('Content-Type') || '';
					if (!r.ok || type.indexOf('application/pdf') !== 0) {
						return r.text().then(function (t) {
							throw new Error(type.indexOf('text/plain') === 0 && t ? t.slice(0, 400) : 'The PDF could not be made. Try again in a moment.');
						});
					}
					var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
					return r.blob().then(function (b) { save(b, m ? m[1] : 'Proposal.pdf'); });
				})
				.catch(function (err) {
					var msg = document.createElement('span');
					msg.className = 'pdf-error';
					msg.setAttribute('role', 'alert');
					msg.textContent = err.message || 'The PDF could not be made.';
					link.after(msg);
				})
				.finally(function () {
					clearInterval(timer);
					delete link.dataset.running;
					link.classList.remove('is-running');
					link.removeAttribute('aria-busy');
					link.textContent = label;
				});
		});
	});
})();
