// The CSP build of Alpine: it evaluates directive expressions with its own
// parser instead of new Function(), so the Content-Security-Policy needs
// no 'unsafe-eval'. Anything with real logic lives in a registered
// component below rather than inline in the markup.
import Alpine from '@alpinejs/csp';
import behaviours from './behaviours';
import bulkSelection from './bulk-selection';
import offline from './offline';
import reader from './reader';

window.Alpine = Alpine;

Alpine.data('bulkSelection', bulkSelection);
Alpine.data('reader', reader);

behaviours(document);
offline(document);

Alpine.start();
