/**
 * Small page behaviours that used to be inline onclick/onsubmit/onchange
 * attributes, which the Content-Security-Policy blocks. Each is opted into
 * with a data attribute and handled by one delegated listener.
 *
 *   <form data-confirm="Delete this?">       ask before submitting
 *   <select data-autosubmit>                 submit the form on change
 *   <input data-select-on-click>             select the text when clicked
 *   <a data-bookmarklet-hint="Drag me…">     explain instead of navigating
 *   <button data-copy-target="#id">          copy that element's value/text to the clipboard
 */
export default function behaviours(root) {
    root.addEventListener('submit', (event) => {
        const form = event.target;

        if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    root.addEventListener('change', (event) => {
        const field = event.target;

        if (field instanceof HTMLSelectElement && field.hasAttribute('data-autosubmit') && field.form) {
            field.form.requestSubmit();
        }
    });

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        const selectable = target?.closest('[data-select-on-click]');
        if (selectable instanceof HTMLInputElement || selectable instanceof HTMLTextAreaElement) {
            selectable.select();
        }

        const bookmarklet = target?.closest('[data-bookmarklet-hint]');
        if (bookmarklet instanceof HTMLElement) {
            event.preventDefault();
            window.alert(bookmarklet.dataset.bookmarkletHint);
        }

        const copyButton = target?.closest('[data-copy-target]');
        if (copyButton instanceof HTMLElement) {
            const source = document.querySelector(copyButton.dataset.copyTarget);
            const text = source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement
                ? source.value
                : source?.textContent?.trim();
            if (text) {
                const label = copyButton.textContent;
                navigator.clipboard?.writeText(text).then(() => {
                    copyButton.textContent = 'Copied';
                    setTimeout(() => { copyButton.textContent = label; }, 2000);
                });
            }
        }
    });
}
