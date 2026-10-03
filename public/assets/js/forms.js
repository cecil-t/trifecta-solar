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

    // <input type=checkbox data-toggle="#el">: show #el while checked
    document.querySelectorAll('input[type=checkbox][data-toggle]').forEach((cb) => {
        const target = document.querySelector(cb.dataset.toggle);
        const update = () => { if (target) target.hidden = !cb.checked; };
        cb.addEventListener('change', update);
        update();
    });

    // Equipment line editors: add / remove rows, renumbering the [i] in input names
    document.querySelectorAll('.eq').forEach((eq) => {
        const renumber = () => {
            eq.querySelectorAll('.eq-row').forEach((row, i) => {
                row.querySelectorAll('input').forEach((inp) => {
                    inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
                });
            });
        };
        eq.querySelector('.eq-add').addEventListener('click', () => {
            const rows = eq.querySelectorAll('.eq-row');
            const clone = rows[rows.length - 1].cloneNode(true);
            clone.querySelectorAll('input').forEach((i) => { i.value = ''; });
            rows[rows.length - 1].after(clone);
            renumber();
            clone.querySelector('input').focus();
        });
        eq.addEventListener('click', (e) => {
            if (!e.target.classList.contains('eq-del')) return;
            const rows = eq.querySelectorAll('.eq-row');
            const row = e.target.closest('.eq-row');
            if (rows.length > 1) { row.remove(); } else { row.querySelectorAll('input').forEach((i) => { i.value = ''; }); }
            renumber();
        });
    });
})();
