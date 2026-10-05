// Project page Notes: Save is enabled only when the text changed, and leaving the page
// with unsaved notes asks first. A form marked data-dirty came back from a save conflict.
(function () {
	const form = document.querySelector('form[data-notes]');
	if (!form) return;
	const box = form.querySelector('textarea');
	const save = form.querySelector('[data-notes-save]');
	const status = form.querySelector('[data-notes-status]');
	const saved = form.hasAttribute('data-dirty') ? null : box.value;
	let submitting = false;

	const dirty = () => saved === null || box.value !== saved;
	const update = () => {
		save.disabled = !dirty();
		status.textContent = dirty() ? 'Unsaved changes' : '';
	};
	box.addEventListener('input', update);
	form.addEventListener('submit', () => { submitting = true; });
	// Ctrl+Enter or Cmd+Enter saves
	box.addEventListener('keydown', (e) => {
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && dirty()) {
			e.preventDefault();
			form.requestSubmit();
		}
	});
	window.addEventListener('beforeunload', (e) => {
		if (!submitting && dirty()) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
	update();
})();
