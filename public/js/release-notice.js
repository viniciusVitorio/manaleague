// A new shared UI release is offered, never forced over an unfinished form.
(function () {
    'use strict';
    var notice = document.getElementById('release-notice');
    if (!notice) return;
    var busy = false;
    var stopped = false;
    var interval;

    async function check() {
        if (busy || stopped || document.hidden) return;
        busy = true;
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
            var response = await fetch(notice.dataset.checkUrl, {
                method: 'HEAD', cache: 'no-store', credentials: 'same-origin',
                signal: controller.signal
            });
            var version = response.headers.get('X-ManaLeague-Assets');
            if (response.ok && version && version !== notice.dataset.release) {
                notice.hidden = false;
                stopped = true;
                clearInterval(interval);
            }
        } catch (_) {
            // Offline or unavailable: leave the current page and entered data untouched.
        } finally {
            clearTimeout(timeout);
            busy = false;
        }
    }

    notice.querySelector('[data-release-reload]').addEventListener('click', function () {
        window.location.reload();
    });
    notice.querySelector('[data-release-dismiss]').addEventListener('click', function () {
        notice.hidden = true;
        stopped = true;
        clearInterval(interval);
    });
    interval = setInterval(check, 120000);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) check();
    });
})();
