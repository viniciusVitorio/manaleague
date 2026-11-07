(() => {
    'use strict';
    document.querySelectorAll('[data-fullscreen]').forEach(button => button.addEventListener('click', async () => {
        try { if (document.fullscreenElement) await document.exitFullscreen(); else await document.documentElement.requestFullscreen(); }
        catch { button.textContent = 'Tela cheia indisponível'; }
    }));
    document.querySelectorAll('[data-live-url]').forEach(board => {
        let etag = null, delay = 10000;
        const state = board.querySelector('[data-live-status]');
        const statusLabel = {pending: 'em jogo', finished: 'encerrada', bye: 'folga'};
        // Mesma regra do ranking: 🔥 com 3 vitórias seguidas, ⚡ com 6 ou mais.
        const streakMark = n => n >= 6 ? ' ⚡' : (n >= 3 ? ' 🔥' : '');
        const render = (data, serverNow) => {
            board.querySelectorAll('[data-round-timer]').forEach(timer => timer.dispatchEvent(new CustomEvent('round-timer:update', {detail: {endsAt: data.round_ends_at, serverNow}})));
            const rows = data.standings.map(row => { const li = document.createElement('li'); li.textContent = `${row.name}${streakMark(row.streak)} — ${row.points} pts · ${row.wins}V ${row.draws}E ${row.losses}D`; return li; });
            board.querySelector('[data-live-standings]').replaceChildren(...rows);
            const matches = data.matches.map(match => { const p = document.createElement('p'); p.textContent = `R${match.round} · ${match.one} ${match.one_score ?? '–'} × ${match.two_score ?? '–'} ${match.two} · ${statusLabel[match.status] ?? match.status}`; return p; });
            board.querySelector('[data-live-matches]').replaceChildren(...matches);
        };
        // Tempo real: o canal publico da arena avisa quando um placar, a rodada ou o cronometro muda e o painel
        // busca na hora (o mesmo endpoint, com ETag). Com o aviso assinado, a consulta fixa cai para 60s.
        const realtime = window.ManaLeagueRealtime, channel = board.dataset.liveChannel;
        const live = () => !!(realtime && channel && realtime.subscribed(channel));
        let next = null, running = false, again = false;
        const schedule = (ms) => { clearTimeout(next); next = setTimeout(poll, ms); };
        async function poll() {
            if (running) { again = true; return; }
            if (document.hidden) { schedule(10000); return; }
            running = true;
            const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 8000);
            try {
                const response = await fetch(board.dataset.liveUrl, {credentials:'omit',cache:'no-store',headers:etag ? {'If-None-Match':etag,'Accept':'application/json'} : {'Accept':'application/json'},signal:controller.signal});
                if (response.status !== 304) { if (!response.ok) throw new Error('offline'); const data = await response.json(); const date = response.headers.get('Date'); render(data, date ? new Date(date).toISOString() : null); etag = response.headers.get('ETag'); }
                state.textContent = 'Ao vivo · atualizado ' + new Date().toLocaleTimeString('pt-BR'); delay = live() ? 60000 : 10000;
            } catch { state.textContent = 'Conexão interrompida · tentando novamente; exibindo último placar.'; delay = Math.min(delay * 2, 60000); }
            finally { clearTimeout(timeout); running = false; if (again) { again = false; schedule(0); } else schedule(delay); }
        }
        if (realtime && channel) {
            realtime.on(channel, 'changed', () => schedule(200));
            realtime.on(channel, 'subscribed', () => schedule(200));
        }
        poll();
    });
})();
