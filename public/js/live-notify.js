// Notificacao nova em qualquer pagina: o sino ganha +1 e aparece um aviso clicavel no canto, sem recarregar.
// O servidor so manda titulo e link de notificacoes que a pessoa pode ver (NotificationService::canRead),
// e so no canal privado dela. Sem tempo real (Reverb desligado ou fora do ar), o sino atualiza na proxima pagina.
(function () {
    'use strict';

    var source = document.getElementById('live-user');
    var realtime = window.ManaLeagueRealtime;
    if (!source || !realtime) return;

    function bumpBell() {
        var bell = document.querySelector('[data-bell]');
        if (!bell) return;
        var count = bell.querySelector('.ui-count');
        if (!count) {
            count = document.createElement('span');
            count.className = 'ui-count';
            count.setAttribute('aria-hidden', 'true');
            count.textContent = '0';
            bell.appendChild(count);
        }
        var current = parseInt(count.textContent, 10) || 0;
        count.textContent = current >= 99 ? '99+' : String(current + 1);
        bell.setAttribute('aria-label', 'Notificações, novas não lidas');
    }

    function region() {
        var box = document.getElementById('async-toasts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'async-toasts';
            box.className = 'async-toasts';
            box.setAttribute('data-async-keep', '');
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            document.body.appendChild(box);
        }
        return box;
    }

    function show(title, url) {
        var item = document.createElement(url ? 'a' : 'div');
        item.className = 'async-toast async-toast-success live-toast';
        if (url) item.href = url;
        item.textContent = '🔔 ' + title;
        region().appendChild(item);
        setTimeout(function () { item.classList.add('is-leaving'); }, 7000);
        setTimeout(function () { item.remove(); }, 7400);
    }

    realtime.on(source.dataset.channel, 'notification', function (data) {
        if (!data || typeof data.title !== 'string' || !data.title.trim()) return;
        // So link interno (o servidor ja garante; aqui e defesa em profundidade).
        var url = typeof data.url === 'string' && /^\/(?!\/)/.test(data.url) && data.url.indexOf('\\') === -1 ? data.url : null;
        bumpBell();
        show(data.title.trim().slice(0, 160), url);
    });
})();
