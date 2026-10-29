// Cronômetro da rodada: conta no navegador usando o horário do servidor (corrige relógio adiantado/atrasado do aparelho).
// O placar ao vivo dispara "round-timer:update" quando o organizador inicia, reinicia ou para o tempo.
(function () {
    'use strict';
    var ENDING_SECONDS = 5 * 60;

    function setup(timer) {
        var clock = timer.querySelector('[data-round-clock]');
        var endsAt = null;
        var offset = 0;

        function configure(endsAtIso, serverNow) {
            endsAt = endsAtIso ? Date.parse(endsAtIso) : null;
            var server = serverNow ? Date.parse(serverNow) : NaN;
            offset = isNaN(server) ? 0 : server - Date.now();
            timer.hidden = endsAt === null;
            tick();
        }

        function tick() {
            if (endsAt === null) return;
            var left = Math.max(0, Math.round((endsAt - (Date.now() + offset)) / 1000));
            var over = left <= 0;
            clock.textContent = over ? 'Tempo esgotado' : pad(Math.floor(left / 60)) + ':' + pad(left % 60);
            timer.classList.toggle('is-over', over);
            timer.classList.toggle('is-ending', !over && left <= ENDING_SECONDS);
        }

        configure(timer.getAttribute('data-ends-at'), timer.getAttribute('data-server-now'));
        setInterval(tick, 1000);
        timer.addEventListener('round-timer:update', function (event) {
            configure(event.detail.endsAt, event.detail.serverNow);
        });
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    document.querySelectorAll('[data-round-timer]').forEach(setup);
})();
