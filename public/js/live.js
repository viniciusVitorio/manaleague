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
        const render = data => {
            const rows = data.standings.map(row => { const li = document.createElement('li'); li.textContent = `${row.name}${streakMark(row.streak)} — ${row.points} pts · ${row.wins}V ${row.draws}E ${row.losses}D`; return li; });
            board.querySelector('[data-live-standings]').replaceChildren(...rows);
            const matches = data.matches.map(match => { const p = document.createElement('p'); p.textContent = `R${match.round} · ${match.one} ${match.one_score ?? '–'} × ${match.two_score ?? '–'} ${match.two} · ${statusLabel[match.status] ?? match.status}`; return p; });
            board.querySelector('[data-live-matches]').replaceChildren(...matches);
        };
        async function poll() {
            if (document.hidden) { setTimeout(poll, 10000); return; }
            const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 8000);
            try {
                const response = await fetch(board.dataset.liveUrl, {credentials:'omit',headers:etag ? {'If-None-Match':etag,'Accept':'application/json'} : {'Accept':'application/json'},signal:controller.signal});
                if (response.status !== 304) { if (!response.ok) throw new Error('offline'); const data = await response.json(); render(data); etag = response.headers.get('ETag'); }
                state.textContent = 'Ao vivo · atualizado ' + new Date().toLocaleTimeString('pt-BR'); delay = 10000;
            } catch { state.textContent = 'Conexão interrompida · tentando novamente; exibindo último placar.'; delay = Math.min(delay * 2, 60000); }
            finally { clearTimeout(timeout); setTimeout(poll,delay); }
        }
        poll();
    });
})();
