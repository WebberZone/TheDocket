// Lists the articles the service worker (sw.js) has saved on this device,
// on the page shown when Docket is opened without a connection. A plain
// file rather than an inline script, so it runs under any
// Content-Security-Policy.
(function () {
    var list = document.getElementById('articles');
    var summary = document.getElementById('summary');
    var synced = document.getElementById('synced');

    if (!('caches' in window)) {
        return;
    }

    caches.open('docket-articles')
        .then(function (cache) { return cache.match('/__docket/offline-index'); })
        .then(function (response) { return response ? response.json() : { items: [] }; })
        .then(function (index) {
            if (!index.items.length) {
                return;
            }

            summary.textContent = 'These unread articles are saved on this device. Everything else in Docket needs a connection.';

            index.items.forEach(function (item) {
                var link = document.createElement('a');
                var title = document.createElement('strong');
                var meta = document.createElement('span');

                link.href = item.url;
                title.textContent = item.title;
                meta.textContent = item.site + (item.minutes > 0 ? ' · ' + item.minutes + ' min read' : '');

                link.appendChild(title);
                link.appendChild(meta);

                var row = document.createElement('li');
                row.appendChild(link);
                list.appendChild(row);
            });

            if (index.syncedAt) {
                synced.textContent = 'Last updated ' + new Date(index.syncedAt).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) + '.';
            }
        })
        .catch(function () {});
})();
