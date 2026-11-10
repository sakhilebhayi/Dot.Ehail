// Small page behaviours that used to be inline event handlers (onclick, onsubmit, onchange). The enforced
// Content-Security-Policy runs no inline handler (R-46 security S-29), so markup declares intent with data
// attributes and this one delegated listener carries it out. Progressive: without JavaScript the links,
// forms and buttons still work as plain HTML (a form simply submits without its confirmation).
//
//   data-eh-href="/url"           a clickable row: click or Enter goes to the URL
//   data-eh-confirm="Question?"   a form: asks before submitting
//   data-eh-print                 a button: prints the page
//   data-eh-autosubmit            a select: submits its form when changed
//   data-eh-dismiss=".selector"   a button: removes its closest ancestor matching the selector

export function initInlineActions(doc = document) {
    if (doc.documentElement.dataset.ehInlineActions === 'on') return;
    doc.documentElement.dataset.ehInlineActions = 'on';

    const go = (row) => { const href = row.getAttribute('data-eh-href'); if (href) window.location.assign(href); };

    doc.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const print = target.closest('[data-eh-print]');
        if (print) { event.preventDefault(); window.print(); return; }

        const dismiss = target.closest('[data-eh-dismiss]');
        if (dismiss) { dismiss.closest(dismiss.getAttribute('data-eh-dismiss') || '*')?.remove(); return; }

        // A real link or button inside the row keeps its own behaviour.
        if (target.closest('a, button, input, select, textarea, label')) return;
        const row = target.closest('[data-eh-href]');
        if (row) go(row);
    });

    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || !(event.target instanceof Element)) return;
        const row = event.target.closest('[data-eh-href]');
        if (row && row === event.target) go(row);
    });

    doc.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        const question = form?.getAttribute('data-eh-confirm');
        if (question && !window.confirm(question)) event.preventDefault();
    }, true);

    doc.addEventListener('change', (event) => {
        const select = event.target instanceof Element ? event.target.closest('[data-eh-autosubmit]') : null;
        if (select && select.form) select.form.requestSubmit ? select.form.requestSubmit() : select.form.submit();
    });
}
