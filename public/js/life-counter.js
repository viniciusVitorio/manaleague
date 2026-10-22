/* Contador de vida (estilo Lotus) vinculado a uma partida do ManaLeague.
 * Estado salvo no aparelho (localStorage) por partida; o resultado final vai para o
 * endpoint canonico da partida (mesmas validacoes do formulario manual). */
(function () {
    'use strict';

    const root = document.getElementById('life-counter');
    if (!root) return;
    const cfg = JSON.parse(root.dataset.config);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const n = cfg.players.length;
    const isPod = cfg.mode === 'pod';
    const COLORS = ['#7c3aed', '#0891b2', '#d97706', '#059669', '#dc2626', '#db2777'];

    // ---------- estado ----------
    const freshPlayers = () => cfg.players.map(() => ({ life: cfg.startingLife, poison: 0, cmd: {}, out: false, ack: false }));
    const freshState = () => ({ v: 1, game: 1, wins: cfg.players.map(() => 0), winners: [], elim: [], players: freshPlayers(), finished: false, undo: [], key: cfg.idempotencyKey });

    let state;
    try { state = JSON.parse(localStorage.getItem(cfg.storageKey) || 'null'); } catch (e) { state = null; }
    if (!state || state.v !== 1 || !Array.isArray(state.players) || state.players.length !== n) state = freshState();
    state.key = state.key || cfg.idempotencyKey;

    const save = () => { try { localStorage.setItem(cfg.storageKey, JSON.stringify(state)); } catch (e) { /* aba anonima cheia: segue em memoria */ } };
    const clone = (v) => JSON.parse(JSON.stringify(v));
    const name = (i) => cfg.players[i].name || ('Jogador ' + (i + 1));

    // ---------- helpers de DOM ----------
    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = String(text);
        return e;
    }
    function button(cls, text, label) {
        const b = el('button', cls, text);
        b.type = 'button';
        if (label) b.setAttribute('aria-label', label);
        return b;
    }

    // Toque = 1, segurar = 10 (repete enquanto segura).
    function bindHold(node, fn) {
        let timer = null, repeat = null, fired = false, down = false;
        const stop = () => { clearTimeout(timer); clearInterval(repeat); timer = repeat = null; };
        node.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            down = true; fired = false;
            timer = setTimeout(() => { fired = true; fn(10); repeat = setInterval(() => fn(10), 450); }, 450);
        });
        node.addEventListener('pointerup', () => { if (down && !fired) fn(1); down = false; stop(); });
        node.addEventListener('pointerleave', () => { down = false; stop(); });
        node.addEventListener('pointercancel', () => { down = false; stop(); });
        node.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(e.shiftKey ? 10 : 1); } });
    }

    // ---------- layout ----------
    // Posicoes calculadas pela orientacao da tela (celular deitado na mesa):
    // Retrato: dupla = adversario em cima girado; mesa = colunas esquerda/direita viradas
    //   para quem senta de cada lado (impar: o primeiro ocupa o topo).
    // Paisagem: fileira de cima girada 180, fileira de baixo normal.
    // Grade de 6 colunas: divide igualmente 1, 2 ou 3 lugares por fileira.
    function seatLayout() {
        const landscape = window.innerWidth > window.innerHeight;
        if (!isPod) {
            const me = cfg.meIndex || 0;
            return { rows: 2, seats: [{ i: 1 - me, rot: 180, row: 1, col: 1, span: 6 }, { i: me, rot: 0, row: 2, col: 1, span: 6 }] };
        }
        const seats = [];
        if (landscape) {
            const topCount = Math.floor(n / 2);
            const rows = [cfg.players.map((_, k) => k).slice(0, topCount), cfg.players.map((_, k) => k).slice(topCount)];
            rows.forEach((list, r) => list.forEach((i, k) => {
                const span = 6 / list.length;
                seats.push({ i, rot: r === 0 ? 180 : 0, row: r + 1, col: k * span + 1, span });
            }));
            return { rows: 2, seats };
        }
        let start = 0, row = 1;
        if (n % 2 === 1) { seats.push({ i: 0, rot: 180, row: 1, col: 1, span: 6 }); start = 1; row = 2; }
        for (let k = start; k < n; k++) {
            const left = (k - start) % 2 === 0;
            seats.push({ i: k, rot: left ? 90 : -90, row: row + Math.floor((k - start) / 2), col: left ? 1 : 4, span: 3 });
        }
        return { rows: Math.ceil(n / 2), seats };
    }

    const board = el('div', 'life-board');
    const panels = [];
    const deltas = cfg.players.map(() => ({ sum: 0, timer: null }));

    cfg.players.forEach((_, i) => {
        const seat = el('section', 'life-seat');
        seat.style.setProperty('--c', COLORS[i % COLORS.length]);
        const panel = el('div', 'life-panel');
        const head = el('div', 'life-head');
        const nm = el('strong', 'life-name', name(i));
        const pips = el('span', 'life-pips');
        head.append(nm, pips);
        const total = el('div', 'life-total');
        total.setAttribute('aria-live', 'polite');
        const delta = el('div', 'life-delta');
        const minus = button('life-zone minus', '−', 'Tirar vida de ' + name(i));
        const plus = button('life-zone plus', '+', 'Dar vida para ' + name(i));
        bindHold(minus, (s) => changeLife(i, -s));
        bindHold(plus, (s) => changeLife(i, s));
        const tools = el('div', 'life-tools');
        const poison = button('life-chip', '', 'Veneno e dano de comandante de ' + name(i));
        poison.addEventListener('click', () => openTray(i));
        tools.append(poison);
        const outBadge = el('div', 'life-out');
        const roll = el('div', 'life-roll');
        const tray = el('div', 'life-tray');
        tray.hidden = true;
        panel.append(minus, plus, head, total, delta, tools, outBadge, roll, tray);
        seat.append(panel);
        board.append(seat);
        panels[i] = { seat, panel, pips, total, delta, poison, outBadge, roll, tray };
    });

    function layout() {
        const { rows, seats } = seatLayout();
        board.style.setProperty('--rows', String(rows));
        seats.forEach(({ i, rot, row, col, span }) => {
            const { seat, panel } = panels[i];
            seat.style.gridRow = String(row);
            seat.style.gridColumn = col + ' / span ' + span;
            panel.className = 'life-panel rot' + rot;
        });
    }
    layout();
    window.addEventListener('resize', layout);

    const hub = button('life-hub', '', 'Menu da partida');
    hub.addEventListener('click', openMenu);
    const toastEl = el('div', 'life-toast');
    toastEl.setAttribute('role', 'status');
    const modal = el('div', 'life-modal');
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    root.append(board, hub, toastEl, modal);

    // ---------- render ----------
    function placeOf(i) {
        if (!isPod) return null;
        const pos = state.elim.indexOf(i);
        if (pos >= 0) return n - pos;
        return state.finished ? 1 : null;
    }

    function render() {
        cfg.players.forEach((_, i) => {
            const p = state.players[i], ui = panels[i];
            ui.total.textContent = String(p.life);
            ui.seat.classList.toggle('is-out', !!p.out);
            ui.seat.classList.toggle('is-low', !p.out && p.life <= 5);
            ui.pips.textContent = '';
            if (!isPod) {
                for (let k = 0; k < cfg.winsNeeded; k++) ui.pips.append(el('i', k < state.wins[i] ? 'on' : ''));
                ui.pips.setAttribute('aria-label', state.wins[i] + ' game(s) vencido(s)');
            }
            const cmdMax = Math.max(0, ...Object.values(p.cmd));
            const bits = ['☠ ' + p.poison];
            if (cfg.commanderDamage) bits.push('⚔ ' + cmdMax);
            ui.poison.textContent = bits.join('   ');
            const place = placeOf(i);
            ui.outBadge.textContent = p.out ? 'Eliminado · ' + place + 'º' : (place === 1 ? 'Vencedor · 1º' : '');
            ui.outBadge.hidden = !ui.outBadge.textContent;
        });
        hub.textContent = isPod ? '☰' : 'G' + Math.min(state.game, cfg.bestOf);
        hub.classList.toggle('done', state.finished);
    }

    function showDelta(i, d) {
        const box = deltas[i], ui = panels[i];
        box.sum += d;
        ui.delta.textContent = (box.sum > 0 ? '+' : box.sum < 0 ? '−' : '±') + Math.abs(box.sum);
        ui.delta.classList.toggle('neg', box.sum < 0);
        ui.delta.classList.add('show');
        clearTimeout(box.timer);
        box.timer = setTimeout(() => { box.sum = 0; ui.delta.classList.remove('show'); }, 1600);
    }

    let toastTimer = null;
    function toast(text) {
        toastEl.textContent = text;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2600);
    }

    // ---------- regras ----------
    function lossReason(i) {
        const p = state.players[i];
        if (p.life <= 0) return name(i) + ' chegou a ' + p.life + ' de vida.';
        if (p.poison >= cfg.poisonLimit) return name(i) + ' tem ' + p.poison + ' marcadores de veneno.';
        for (const [from, dmg] of Object.entries(p.cmd)) {
            if (dmg >= cfg.commanderLimit) return name(i) + ' levou ' + dmg + ' de dano de comandante de ' + name(+from) + '.';
        }
        return null;
    }

    function check(i) {
        const p = state.players[i];
        const reason = lossReason(i);
        if (!reason) { p.ack = false; return; }
        if (p.out || p.ack || state.finished || !modal.hidden) return;
        askLoss(i, reason);
    }
    const checkAll = () => cfg.players.forEach((_, i) => check(i));

    function changeLife(i, d) {
        const p = state.players[i];
        if (state.finished || p.out) return;
        p.life += d;
        showDelta(i, d);
        save(); render(); check(i);
        if (navigator.vibrate) navigator.vibrate(8);
    }

    function askLoss(i, reason) {
        const winner = isPod ? null : 1 - i;
        const last = isPod && cfg.players.filter((_, k) => !state.players[k].out && k !== i).length === 1;
        const text = isPod
            ? reason + (last ? ' A mesa termina aqui.' : ' Confirmar eliminação?')
            : reason + ' Confirmar vitória de ' + name(winner) + ' no game ' + state.game + '?';
        showModal(isPod ? 'Eliminar ' + name(i) + '?' : 'Fim do game ' + state.game, text, [
            { label: isPod ? 'Confirmar eliminação' : 'Confirmar', cls: 'primary', fn: () => confirmLoss(i) },
            { label: 'Ainda não (corrigir)', fn: () => { state.players[i].ack = true; save(); } },
        ]);
    }

    function snapshot() {
        state.undo.push(clone({ game: state.game, wins: state.wins, winners: state.winners, elim: state.elim, players: state.players }));
        if (state.undo.length > 15) state.undo.shift();
    }

    function confirmLoss(i) {
        snapshot();
        if (isPod) {
            state.players[i].out = true;
            state.elim.push(i);
            const alive = cfg.players.map((_, k) => k).filter((k) => !state.players[k].out);
            if (alive.length <= 1) return finish();
            save(); render();
            toast(name(i) + ' fica em ' + (n - state.elim.length + 1) + 'º');
            return checkAll();
        }
        const w = 1 - i;
        state.wins[w]++;
        state.winners.push(w);
        if (state.wins[w] >= cfg.winsNeeded) return finish();
        state.game++;
        state.players = freshPlayers();
        save(); render();
        toast('Game ' + (state.game - 1) + ' para ' + name(w) + ' · ' + scoreText() + ' · Game ' + state.game + ' valendo!');
    }

    function undo() {
        const snap = state.undo.pop();
        if (!snap) return toast('Nada para desfazer.');
        Object.assign(state, snap, { finished: false });
        // Quem estava zerado nao dispara o aviso de novo na hora: corrija com o "+".
        state.players.forEach((p, i) => { if (!p.out && lossReason(i)) p.ack = true; });
        save(); render(); closeModal();
        toast('Desfeito.');
    }

    const scoreText = () => state.wins[0] + ' × ' + state.wins[1];

    function finish() {
        state.finished = true;
        save(); render();
        showResult();
    }

    // ---------- resultado ----------
    function resultBody() {
        const box = el('div', 'life-result');
        if (isPod) {
            const ol = el('ol', 'life-places');
            cfg.players.map((_, i) => i).sort((a, b) => placeOf(a) - placeOf(b)).forEach((i) => {
                ol.append(el('li', '', placeOf(i) + 'º  ' + name(i)));
            });
            box.append(ol);
            return box;
        }
        const line = el('p', 'life-score');
        line.append(el('span', '', name(0)), el('b', '', scoreText()), el('span', '', name(1)));
        box.append(line);
        if (state.winners.length) box.append(el('p', 'life-muted', 'Games: ' + state.winners.map((w, k) => (k + 1) + 'º ' + name(w)).join(' · ')));
        return box;
    }

    function showResult(error) {
        const actions = [];
        let title, text;
        if (isPod) {
            title = 'Mesa encerrada';
            text = cfg.submit ? 'Confira as posições e lance o resultado.' : 'Só o organizador lança as posições. Mostre esta tela para ele.';
        } else {
            const [a, b] = state.wins;
            title = 'Fim da partida';
            text = a === b ? 'Empate na série.' : name(a > b ? 0 : 1) + ' venceu a série.';
            if (cfg.submit && cfg.submit.kind !== 'tournament_admin') text += ' O adversário confirma o placar depois.';
        }
        if (cfg.submit) actions.push({ label: isPod ? 'Lançar posições' : 'Enviar resultado', cls: 'primary', keep: true, fn: submit });
        actions.push({ label: isPod ? 'Desfazer última eliminação' : 'Corrigir último game', fn: undo, keep: true });
        actions.push({ label: cfg.submit ? 'Sair sem enviar' : 'Voltar para a partida', href: cfg.resultUrl });
        showModal(title, text, actions, resultBody(), error);
    }

    let sending = false;
    async function submit() {
        if (sending) return;
        const [a, b] = state.wins;
        const kind = cfg.submit.kind;
        let body;
        if (kind === 'pod') {
            const placements = {};
            cfg.players.forEach((p, i) => { placements[p.id] = placeOf(i); });
            body = { placements };
        } else if (kind === 'tournament') body = { player_one_games: a, player_two_games: b, idempotency_key: state.key };
        else if (kind === 'tournament_admin') body = { player_one_games: a, player_two_games: b };
        else if (kind === 'ranked') body = { score_one: a, score_two: b, idempotency_key: state.key };
        else body = { challenger_games: a, opponent_games: b, game_order: state.winners.map((w) => w + 1).join(',') };

        sending = true;
        setBusy(true);
        try {
            const res = await fetch(cfg.submit.url, {
                method: cfg.submit.method,
                credentials: 'same-origin',
                redirect: 'manual',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            // Endpoints JSON respondem 200/201; os de formulario redirecionam (opaqueredirect).
            if (res.ok || res.type === 'opaqueredirect') {
                try { localStorage.removeItem(cfg.storageKey); } catch (e) { /* ok */ }
                window.location.assign(cfg.resultUrl);
                return;
            }
            let msg = 'Não foi possível enviar (' + res.status + ').';
            try {
                const data = await res.json();
                const first = data.errors ? Object.values(data.errors).flat()[0] : null;
                msg = first || data.message || msg;
            } catch (e) { /* resposta sem json */ }
            if (res.status === 419) msg = 'Sua sessão expirou. Recarregue a página e envie de novo (o placar fica salvo).';
            showResult(msg);
        } catch (e) {
            showResult('Sem conexão. O placar fica salvo neste aparelho; tente de novo.');
        } finally {
            sending = false;
            setBusy(false);
        }
    }
    const setBusy = (on) => modal.querySelectorAll('button').forEach((b) => { b.disabled = on; });

    // ---------- modal / menus ----------
    function showModal(title, text, actions, extra, error) {
        modal.textContent = '';
        const card = el('div', 'life-modal-card');
        card.append(el('h2', '', title));
        if (text) card.append(el('p', '', text));
        if (extra) card.append(extra);
        if (error) card.append(el('p', 'life-error', error));
        const row = el('div', 'life-actions');
        actions.forEach((a) => {
            let node;
            if (a.href) { node = el('a', 'life-btn', a.label); node.href = a.href; }
            else {
                node = button('life-btn ' + (a.cls || ''), a.label);
                node.addEventListener('click', () => { if (!a.keep) closeModal(); a.fn(); if (!a.keep) render(); });
            }
            row.append(node);
        });
        card.append(row);
        modal.append(card);
        modal.hidden = false;
        card.querySelector('.primary, button, a')?.focus();
    }
    function closeModal() {
        modal.hidden = true;
        modal.textContent = '';
        setTimeout(checkAll, 0);
    }
    modal.addEventListener('click', (e) => { if (e.target === modal && !state.finished) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden && !state.finished) closeModal(); });

    function openMenu() {
        if (state.finished) return showResult();
        const info = el('div', 'life-menu-info');
        info.append(el('p', 'life-muted', cfg.title + ' · ' + cfg.subtitle));
        if (!isPod) info.append(el('p', 'life-score-small', name(0) + ' ' + scoreText() + ' ' + name(1) + ' · Game ' + state.game));
        const actions = [
            { label: 'Continuar', cls: 'primary', fn: () => {} },
            { label: 'Sortear quem começa (D20)', fn: highRoll },
            { label: 'Reiniciar vidas deste game', fn: () => confirmThen('Reiniciar as vidas deste game?', () => { snapshot(); state.players = state.players.map((p) => ({ life: cfg.startingLife, poison: 0, cmd: {}, out: p.out, ack: false })); }) },
        ];
        if (state.undo.length) actions.push({ label: 'Desfazer última ação', fn: undo });
        if (!isPod && cfg.earlyEnd) actions.push({ label: 'Encerrar série agora (' + scoreText() + ')', fn: endEarly });
        actions.push({ label: 'Zerar tudo', fn: () => confirmThen('Apagar o placar e as vidas desta partida?', () => { state = freshState(); }) });
        actions.push({ label: 'Sair (o placar fica salvo)', href: cfg.resultUrl });
        showModal(isPod ? 'Mesa' : 'Partida', '', actions, info);
    }

    function confirmThen(question, fn) {
        setTimeout(() => showModal('Tem certeza?', question, [
            { label: 'Sim', cls: 'primary', fn: () => { fn(); save(); render(); } },
            { label: 'Cancelar', fn: () => {} },
        ]), 0);
    }

    function endEarly() {
        if (state.wins[0] + state.wins[1] === 0) return toast('Nenhum game decidido ainda.');
        confirmThen('Encerrar a série em ' + scoreText() + ' (por tempo)? O servidor confere se esse placar vale para a partida.', () => { snapshot(); finish(); });
    }

    function highRoll() {
        const alive = cfg.players.map((_, i) => i).filter((i) => !state.players[i].out);
        const rolls = {};
        alive.forEach((i) => { rolls[i] = 1 + Math.floor(Math.random() * 20); });
        const top = Math.max(...Object.values(rolls));
        const winners = alive.filter((i) => rolls[i] === top);
        alive.forEach((i) => {
            const r = panels[i].roll;
            r.textContent = 'D20: ' + rolls[i];
            r.classList.toggle('best', winners.length === 1 && rolls[i] === top);
            r.classList.add('show');
            setTimeout(() => r.classList.remove('show'), 3200);
        });
        toast(winners.length === 1 ? name(winners[0]) + ' começa (' + top + ')' : 'Empate no ' + top + ', role de novo.');
    }

    // Bandeja por jogador (dentro do painel, ja virada para ele): veneno + dano de comandante.
    function openTray(i) {
        if (state.finished || state.players[i].out) return;
        const tray = panels[i].tray;
        tray.textContent = '';
        const close = button('life-tray-close', '✕', 'Fechar');
        close.addEventListener('click', () => { tray.hidden = true; });
        tray.append(close, el('h3', '', 'Contadores de ' + name(i)));
        const row = (label, get, set, limit) => {
            const line = el('div', 'life-tray-row');
            const val = el('b', '', get());
            const minus = button('life-mini', '−', label + ' menos');
            const plus = button('life-mini', '+', label + ' mais');
            const upd = (d) => { set(d); val.textContent = String(get()); save(); render(); check(i); };
            minus.addEventListener('click', () => upd(-1));
            plus.addEventListener('click', () => upd(1));
            line.append(el('span', '', label + ' (' + limit + ')'), minus, val, plus);
            tray.append(line);
        };
        const p = state.players[i];
        row('☠ Veneno', () => p.poison, (d) => { p.poison = Math.max(0, p.poison + d); }, cfg.poisonLimit);
        if (cfg.commanderDamage) {
            cfg.players.forEach((_, from) => {
                if (from === i) return;
                // Dano de comandante tambem tira vida (como no Lotus).
                row('⚔ de ' + name(from), () => p.cmd[from] || 0, (d) => {
                    const cur = p.cmd[from] || 0, next = Math.max(0, cur + d);
                    p.cmd[from] = next;
                    p.life -= next - cur;
                    if (next !== cur) showDelta(i, -(next - cur));
                }, cfg.commanderLimit);
            });
        }
        tray.hidden = false;
    }

    // Tela acesa durante a partida, quando o navegador permite.
    let lock = null;
    async function keepAwake() {
        try { if ('wakeLock' in navigator && document.visibilityState === 'visible') lock = await navigator.wakeLock.request('screen'); } catch (e) { lock = null; }
    }
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && (!lock || lock.released)) keepAwake(); });

    render();
    keepAwake();
    if (state.finished) showResult(); else checkAll();
})();
