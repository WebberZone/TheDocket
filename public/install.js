/*
 * Web installer helpers. Served as a static file so it runs under the
 * strict CSP without a nonce. Loaded by every install page; each feature
 * activates only when its marker element is present.
 */

/* Preselect the browser's timezone in the setup form — only while the
   field still shows the UTC default, so a validated-and-returned form
   keeps the zone the user actually chose. */
(function () {
    var select = document.querySelector('select[data-browser-timezone]');
    if (!select || select.value !== 'UTC' || !window.Intl || !Intl.DateTimeFormat) {
        return;
    }

    var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!zone) {
        return;
    }

    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].value === zone) {
            select.value = zone;
            return;
        }
    }
})();

/* Copy buttons (e.g. the cron line on the completion page). */
document.querySelectorAll('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
        var target = document.querySelector(button.getAttribute('data-copy-target'));
        if (!target) {
            return;
        }

        var text = target.textContent.trim();

        var show = function (message) {
            button.textContent = message;
            setTimeout(function () {
                button.textContent = 'Copy';
            }, 3000);
        };

        /* No Clipboard API over plain HTTP, and it can be refused: try the
           legacy copy, else leave the text selected for a manual copy. */
        var fallback = function () {
            var copied = false;
            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }
            area.remove();

            if (!copied && window.getSelection) {
                window.getSelection().selectAllChildren(target);
            }
            show(copied ? 'Copied' : (/Mac|iPhone|iPad/.test(navigator.userAgent) ? 'Press ⌘C' : 'Press Ctrl+C'));
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                show('Copied');
            }, fallback);
        } else {
            fallback();
        }
    });
});
