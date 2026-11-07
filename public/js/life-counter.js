/* Contador de vida (estilo Lotus) vinculado a uma partida do ManaLeague.
 * A mesa e compartilhada: cada toque vira uma jogada (life-core.js) que vai para o servidor e chega aos outros
 * celulares pelo Reverb (realtime.js). Sem internet, as jogadas ficam salvas no aparelho (localStorage) e sao
 * enviadas quando a conexao volta; sem tempo real, o contador pergunta ao servidor de poucos em poucos segundos.
 * O resultado final vai para o endpoint canonico da partida (mesmas validacoes do formulario manual). */
(function () {
    'use strict';

    const root = document.getElementById('life-counter');
    if (!root || !window.LifeCore) return;
    const core = window.LifeCore;
    const cfg = JSON.parse(root.dataset.config);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const n = cfg.players.length;
    const isPod = cfg.mode === 'pod';
    const sync = cfg.sync || null;
    const me = sync ? sync.me : null;
    const realtime = window.ManaLeagueRealtime || null;
    const COLORS = ['#7c3aed', '#0891b2', '#d97706', '#059669', '#dc2626', '#db2777'];
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- estado ----------
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(cfg.storageKey) || 'null'); } catch (e) { saved = null; }
    const store = new core.Store(cfg, saved);
    // Chave de idempotencia do envio do resultado: a mesma em todas as tentativas deste aparelho.
    const submitKey = (saved && saved.key) || cfg.idempotencyKey;
    let state = store.view();
    // Aparelho sem copia da mesa (abriu agora, ou e o segundo celular): a primeira leva de jogadas so monta o placar.
    let firstCopy = !(saved && saved.v === 2);
    // "Ainda nao (corrigir)": vale so neste aparelho, ate a vida sair da zona de derrota.
    const acks = cfg.players.map(() => false);

    const save = () => {
        try { localStorage.setItem(cfg.storageKey, JSON.stringify(Object.assign(store.serialize(), { key: submitKey }))); } catch (e) { /* aba anonima cheia: segue em memoria */ }
    };

    // Cor do fundo: a que cada jogador escolheu nas preferências do perfil. Sem escolha (ou repetida na mesa),
    // pega a primeira cor padrão que ninguém está usando, para os lados nunca ficarem iguais.
    let seatColors = [];
    function computeColors() {
        const taken = new Set(), out = [];
        cfg.players.forEach((p, i) => { if (p.color && !taken.has(p.color)) { out[i] = p.color; taken.add(p.color); } });
        cfg.players.forEach((_, i) => {
            if (out[i]) return;
            out[i] = COLORS.find((c) => !taken.has(c)) || COLORS[i % COLORS.length];
            taken.add(out[i]);
        });
        seatColors = out;
    }
    computeColors();
    const colorOf = (i) => seatColors[i];
    // Fundo claro (ex.: branco) pede texto escuro; a conta segue a luminância relativa do WCAG.
    function isLight(hex) {
        const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) return false;
        const [r, g, b] = [0, 2, 4].map((k) => {
            const c = parseInt(m[1].slice(k, k + 2), 16) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.5;
    }

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
            const mine = cfg.meIndex || 0;
            return { rows: 2, seats: [{ i: 1 - mine, rot: 180, row: 1, col: 1, span: 6 }, { i: mine, rot: 0, row: 2, col: 1, span: 6 }] };
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
        const panel = el('div', 'life-panel');
        const head = el('div', 'life-head');
        const nm = el('strong', 'life-name');
        const pips = el('span', 'life-pips');
        head.append(nm, pips);
        const total = el('div', 'life-total');
        total.setAttribute('aria-live', 'polite');
        const delta = el('div', 'life-delta');
        const minus = button('life-zone minus', '−');
        const plus = button('life-zone plus', '+');
        bindHold(minus, (s) => changeLife(i, -s));
        bindHold(plus, (s) => changeLife(i, s));
        const tools = el('div', 'life-tools');
        const poison = button('life-chip', '');
        poison.addEventListener('click', () => openTray(i));
        tools.append(poison);
        const outBadge = el('div', 'life-out');
        const roll = el('div', 'life-roll');
        const tray = el('div', 'life-tray');
        tray.hidden = true;
        panel.append(minus, plus, head, total, delta, tools, outBadge, roll, tray);
        seat.append(panel);
        board.append(seat);
        panels[i] = { seat, panel, nm, minus, plus, pips, total, delta, poison, outBadge, roll, tray };
    });

    // Nome, cor e rotulos dos jogadores da partida.
    function paintSeats() {
        cfg.players.forEach((_, i) => {
            const ui = panels[i];
            ui.seat.style.setProperty('--c', colorOf(i));
            ui.seat.classList.toggle('is-light', isLight(colorOf(i)));
            ui.nm.textContent = name(i);
            ui.minus.setAttribute('aria-label', 'Tirar vida de ' + name(i));
            ui.plus.setAttribute('aria-label', 'Dar vida para ' + name(i));
            ui.poison.setAttribute('aria-label', 'Veneno e dano de comandante de ' + name(i));
        });
    }
    paintSeats();

    function layout() {
        const { rows, seats } = seatLayout();
        board.style.setProperty('--rows', String(rows));
        seats.forEach(({ i, rot, row, col, span }) => {
            const { seat, panel } = panels[i];
            seat.style.gridRow = String(row);
            seat.style.gridColumn = col + ' / span ' + span;
            panel.className = 'life-panel rot' + rot;
            panels[i].rot = rot;
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
    let modalInfo = null; // o que o modal aberto mostra: { kind: 'loss', seat, game } | { kind: 'result' } | ...

    // ---------- render ----------
    function placeOf(i) {
        if (!isPod) return null;
        const pos = state.elim.indexOf(i);
        if (pos >= 0) return n - pos;
        return state.finished ? 1 : null;
    }

    // Quem levou a série (ou ficou de pé na mesa). null enquanto não acabou ou em empate.
    function seriesWinner() {
        if (!state.finished) return null;
        if (isPod) return cfg.players.map((_, i) => i).find((i) => placeOf(i) === 1) ?? null;
        return state.wins[0] === state.wins[1] ? null : (state.wins[0] > state.wins[1] ? 0 : 1);
    }

    function render() {
        const champion = seriesWinner();
        cfg.players.forEach((_, i) => {
            const p = state.players[i], ui = panels[i];
            ui.seat.classList.toggle('is-winner', champion === i);
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
            ui.outBadge.textContent = p.out ? 'Eliminado · ' + place + 'º' : (place === 1 ? '🏆 Vencedor · 1º' : (champion === i ? '🏆 Vencedor' : ''));
            ui.outBadge.hidden = !ui.outBadge.textContent;
            if (!ui.tray.hidden && ui.tray.refresh) ui.tray.refresh();
        });
        hub.textContent = isPod ? '☰' : 'G' + Math.min(state.game, cfg.bestOf);
        hub.classList.toggle('done', state.finished);
    }

    function showDelta(i, d) {
        if (!d) return;
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

    // ---------- jogadas ----------
    // Toda mudança passa por aqui: vira jogada, aparece na hora e vai para o servidor logo depois.
    function act(op) {
        const prev = state;
        const last = store.pending[store.pending.length - 1];
        // Só agrega se comprovadamente nunca enviada; falha/reload nao reabre o mesmo client_id.
        if (op.type === 'life' && last && last.op.type === 'life' && last.op.seat === op.seat && last.attempted === false
            && Math.abs(last.op.d + op.d) <= 999 && last.op.d + op.d !== 0) {
            last.op.d += op.d;
        } else {
            store.local(op, me);
        }
        state = store.view();
        save();
        afterChange(prev, []);
        flushSoon();
    }

    function changeLife(i, d) {
        const p = state.players[i];
        if (state.finished || p.out) return;
        act({ type: 'life', seat: i, d });
        showDelta(i, d);
        if (navigator.vibrate) navigator.vibrate(8);
    }

    // Depois de qualquer mudança (deste ou de outro celular): tela, animações e avisos que dependem dela.
    // quiet = primeira cópia da mesa num aparelho novo: mostra o placar sem festejar games antigos.
    function afterChange(prev, remote, quiet) {
        render();
        if (quiet) {
            closeModal();
            if (state.finished) showResult(); else checkAll();
            return;
        }
        // Desfeito no outro celular: quem voltou para a zona de derrota não pergunta de novo na hora.
        if (remote.some((e) => e.op.type === 'undo')) state.players.forEach((p, i) => { if (!p.out && lossReason(i)) acks[i] = true; });
        remote.forEach((e) => {
            if (e.op.type === 'life') showDelta(e.op.seat, e.op.d);
            if (e.op.type === 'cmd') showDelta(e.op.seat, -e.op.d);
        });
        const fromOther = remote.length > 0;
        if (state.game > prev.game && !state.finished) {
            const w = state.winners[state.winners.length - 1];
            acks.fill(false);
            celebrate(w, false);
            toast('Game ' + (state.game - 1) + ' para ' + name(w) + ' · ' + scoreText() + ' · Game ' + state.game + ' valendo!');
        } else if (isPod && state.elim.length > prev.elim.length && !state.finished) {
            const out = state.elim[state.elim.length - 1];
            toast(name(out) + ' fica em ' + (n - state.elim.length + 1) + 'º');
        }
        if (state.roll && state.roll.key !== (prev.roll && prev.roll.key)) showRolls(state.roll.rolls);
        if (!prev.finished && state.finished) finishUI();
        else if (prev.finished && !state.finished) {
            closeModal();
            if (fromOther) toast('Placar corrigido no outro celular.');
        }
        if (state.finished && state.submitted && !(prev.submitted) && state.submitted.by !== me) showResult();
        // Modal de fim de game aberto, mas a mesa já andou (o outro celular confirmou ou corrigiu): fecha.
        if (modalInfo && modalInfo.kind === 'loss') {
            const p = state.players[modalInfo.seat];
            if (state.finished || state.game !== modalInfo.game || !p || p.out || !lossReason(modalInfo.seat)) closeModal();
        }
        if (!state.finished) checkAll();
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
        if (!reason) { acks[i] = false; return; }
        if (p.out || acks[i] || state.finished || !modal.hidden) return;
        askLoss(i, reason);
    }
    const checkAll = () => cfg.players.forEach((_, i) => check(i));

    function askLoss(i, reason) {
        const winner = isPod ? null : 1 - i;
        const last = isPod && cfg.players.filter((_, k) => !state.players[k].out && k !== i).length === 1;
        const text = isPod
            ? reason + (last ? ' A mesa termina aqui.' : ' Confirmar eliminação?')
            : reason + ' Confirmar vitória de ' + name(winner) + ' no game ' + state.game + '?';
        const game = state.game;
        showModal(isPod ? 'Eliminar ' + name(i) + '?' : 'Fim do game ' + game, text, [
            { label: isPod ? 'Confirmar eliminação' : 'Confirmar', cls: 'primary', fn: () => act({ type: 'loss', seat: i, game }) },
            { label: 'Ainda não (corrigir)', fn: () => { acks[i] = true; } },
        ], null, null, { kind: 'loss', seat: i, game });
    }

    function undo() {
        if (!state.undo.length) return toast('Nada para desfazer.');
        // Quem estava zerado nao dispara o aviso de novo na hora: corrija com o "+".
        act({ type: 'undo', depth: state.undo.length });
        state.players.forEach((p, i) => { if (!p.out && lossReason(i)) acks[i] = true; });
        closeModal();
        toast('Desfeito.');
    }

    const scoreText = () => state.wins[0] + ' × ' + state.wins[1];

    function finishUI() {
        const champion = seriesWinner();
        if (champion === null || reduceMotion) return showResult();
        // Primeiro a festa no lado de quem ganhou; o resumo aparece logo depois, por cima.
        celebrate(champion, true);
        setTimeout(() => { if (state.finished && modal.hidden) showResult(); }, 3500);
    }

    // ---------- comemoração ----------
    // Confete, fogos e balões num canvas por cima de tudo, desenhados de frente para quem ganhou
    // (o "para cima" segue a rotação do painel). Some sozinho; não bloqueia toques.
    const UP = { 0: [0, -1], 180: [0, 1], 90: [1, 0], '-90': [-1, 0] };
    let party = null;
    function celebrate(i, big) {
        if (reduceMotion || i === undefined || i === null || !panels[i]) return;
        const rect = panels[i].seat.getBoundingClientRect();
        const [ux, uy] = UP[panels[i].rot] || UP[0];
        const rx = -uy, ry = ux; // "direita" de quem está sentado ali
        const sideways = ux !== 0;
        const w = sideways ? rect.height : rect.width, h = sideways ? rect.width : rect.height;
        const reach = Math.max(window.innerWidth, window.innerHeight);
        // Cores claras e vivas: aparecem sobre qualquer fundo escolhido.
        const tint = ['#facc15', '#fde68a', '#ffffff', '#f472b6', '#38bdf8', '#4ade80', '#a78bfa', '#fb923c'];
        const pick = (list) => list[Math.floor(Math.random() * list.length)];
        const rand = (a, b) => a + Math.random() * (b - a);

        if (!party) {
            const canvas = el('canvas', 'life-party');
            canvas.setAttribute('aria-hidden', 'true');
            root.append(canvas);
            party = { canvas, ctx: canvas.getContext('2d'), bits: [], pending: 0, frame: null };
        }
        const p = party;
        const later = (fn, ms) => { p.pending++; setTimeout(() => { p.pending--; fn(); }, ms); };
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        p.canvas.width = window.innerWidth * dpr;
        p.canvas.height = window.innerHeight * dpr;
        // Coordenadas locais: origem no centro do lado do vencedor, x para a direita dele, y para baixo dele.
        p.frameOf = { a: rx * dpr, b: ry * dpr, c: -ux * dpr, d: -uy * dpr, e: (rect.left + rect.width / 2) * dpr, f: (rect.top + rect.height / 2) * dpr };
        p.limit = reach * 1.2;

        const confetti = (count, spread) => {
            for (let k = 0; k < count; k++) {
                p.bits.push({ kind: 'confetti', x: rand(-spread, spread), y: -h / 2 - rand(0, h * .6), vx: rand(-1.2, 1.2), vy: rand(1.5, 4),
                    g: .06, rot: rand(0, Math.PI), vr: rand(-.2, .2), wob: rand(0, 6), size: rand(5, 10), color: pick(tint), life: 1 });
            }
        };
        // Dois canhões nos cantos de baixo de quem ganhou, atirando para o alto e para o meio.
        const cannons = (count) => {
            [-1, 1].forEach((side) => {
                for (let k = 0; k < count; k++) {
                    p.bits.push({ kind: 'confetti', x: side * w * .48, y: h / 2 - 8, vx: -side * rand(1, 5.5), vy: -rand(7, 13.5),
                        g: .16, rot: rand(0, Math.PI), vr: rand(-.25, .25), wob: rand(0, 6), size: rand(7, 12), color: pick(tint), life: 1 });
                }
            });
        };
        const firework = () => {
            const x = rand(-w * .35, w * .35), y = rand(-h * .4, h * .05), color = pick(tint), sparks = 46;
            for (let k = 0; k < sparks; k++) {
                const angle = (k / sparks) * Math.PI * 2, speed = rand(2, 5.5);
                p.bits.push({ kind: 'spark', x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, g: .05, drag: .965, color, life: 1, fade: rand(.012, .02) });
            }
            p.bits.push({ kind: 'flash', x, y, r: 6, color, life: 1, fade: .08 });
        };
        const balloon = () => {
            p.bits.push({ kind: 'balloon', x: rand(-w * .45, w * .45), y: h / 2 + rand(30, 140), vy: -rand(.9, 1.7), sway: rand(0, 6), r: rand(16, 24),
                color: pick(tint.filter((c) => c !== '#ffffff')), life: 1 });
        };

        if (big) {
            // Uns 7 segundos de festa: ondas de confete, fogos espaçados e balões subindo devagar.
            [0, 900, 2200, 3600].forEach((ms, k) => later(() => cannons(k ? 45 : 70), ms));
            [0, 1300, 2800, 4300].forEach((ms) => later(() => confetti(110, Math.max(w, reach * .5)), ms));
            for (let k = 0; k < 11; k++) later(firework, 150 + k * 450);
            for (let k = 0; k < 18; k++) later(balloon, k * 260);
        } else {
            confetti(70, w / 2);
            later(() => confetti(50, w / 2), 700);
        }
        if (!p.frame) p.frame = requestAnimationFrame(tick);
    }

    function tick() {
        const p = party, { ctx, canvas } = p, f = p.frameOf;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.setTransform(f.a, f.b, f.c, f.d, f.e, f.f);
        p.bits = p.bits.filter((b) => b.life > 0 && Math.abs(b.x) < p.limit && Math.abs(b.y) < p.limit);
        for (const b of p.bits) {
            ctx.globalAlpha = Math.max(0, Math.min(1, b.life));
            if (b.kind === 'confetti') {
                b.vy = Math.min(b.vy + b.g, 4.5); b.vx *= .985; b.x += b.vx + Math.sin(b.wob += .1) * .6; b.y += b.vy; b.rot += b.vr;
                ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.rot); ctx.scale(1, Math.cos(b.wob));
                ctx.fillStyle = b.color; ctx.fillRect(-b.size / 2, -b.size / 4, b.size, b.size / 2); ctx.restore();
            } else if (b.kind === 'spark') {
                b.vx *= b.drag; b.vy = b.vy * b.drag + b.g; b.x += b.vx; b.y += b.vy; b.life -= b.fade;
                ctx.fillStyle = b.color; ctx.beginPath(); ctx.arc(b.x, b.y, 2.4, 0, Math.PI * 2); ctx.fill();
            } else if (b.kind === 'flash') {
                b.r += 5; b.life -= b.fade;
                ctx.fillStyle = '#fff'; ctx.globalAlpha = Math.max(0, b.life) * .5; ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2); ctx.fill();
            } else if (b.kind === 'balloon') {
                b.y += b.vy; b.x += Math.sin(b.sway += .03) * .7;
                ctx.strokeStyle = 'rgba(255,255,255,.6)'; ctx.lineWidth = 1.2;
                ctx.beginPath(); ctx.moveTo(b.x, b.y + b.r * 1.2); ctx.quadraticCurveTo(b.x + 6, b.y + b.r * 2, b.x, b.y + b.r * 2.9); ctx.stroke();
                ctx.fillStyle = b.color; ctx.beginPath(); ctx.ellipse(b.x, b.y, b.r, b.r * 1.2, 0, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.moveTo(b.x - 4, b.y + b.r * 1.3); ctx.lineTo(b.x + 4, b.y + b.r * 1.3); ctx.lineTo(b.x, b.y + b.r * 1.15); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.beginPath(); ctx.ellipse(b.x - b.r * .35, b.y - b.r * .45, b.r * .22, b.r * .34, -.5, 0, Math.PI * 2); ctx.fill();
            }
        }
        ctx.globalAlpha = 1;
        if (p.bits.length || p.pending) { p.frame = requestAnimationFrame(tick); return; }
        // Acabou a festa: tira o canvas.
        p.canvas.remove();
        party = null;
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

    // Quem mandou o resultado pelo outro celular (pela conta de quem está sentado).
    function submitterName() {
        const by = state.submitted && state.submitted.by;
        const seat = cfg.players.findIndex((p) => p.user && p.user === by);
        return seat >= 0 ? name(seat) : 'Seu adversário';
    }

    function showResult(error) {
        const actions = [];
        let title, text;
        const sentByOther = state.submitted && state.submitted.by !== me;
        if (isPod) {
            title = 'Mesa encerrada';
            text = cfg.submit ? 'Confira as posições e lance o resultado.' : (cfg.type === 'quick' ? 'Boa partida!' : 'Só o organizador lança as posições. Mostre esta tela para ele.');
        } else {
            const [a, b] = state.wins;
            title = 'Fim da partida';
            text = a === b ? 'Empate na série.' : name(a > b ? 0 : 1) + ' venceu a série.';
            if (cfg.submit && cfg.submit.kind !== 'tournament_admin' && !sentByOther) text += ' O adversário confirma o placar depois.';
        }
        if (sentByOther) {
            // O outro celular já enviou: aqui só falta conferir e confirmar na página da partida.
            text += ' ' + submitterName() + ' enviou o resultado. Confira e confirme na partida.';
            actions.push({ label: 'Ver e confirmar', cls: 'primary', href: cfg.resultUrl });
        } else if (cfg.submit) {
            actions.push({ label: isPod ? 'Lançar posições' : 'Enviar resultado', cls: 'primary', keep: true, fn: submit });
        }
        if (cfg.type === 'quick') actions.push({ label: 'Jogar de novo', cls: cfg.submit ? '' : 'primary', fn: () => act({ type: 'reset_all' }) });
        if (!sentByOther) actions.push({ label: isPod ? 'Desfazer última eliminação' : 'Corrigir último game', fn: undo, keep: true });
        if (!sentByOther) actions.push({ label: cfg.submit ? 'Sair sem enviar' : 'Voltar', href: cfg.resultUrl });
        showModal(title, text, actions, resultBody(), error, { kind: 'result' });
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
        } else if (kind === 'tournament') body = { player_one_games: a, player_two_games: b, idempotency_key: submitKey };
        else if (kind === 'tournament_admin') body = { player_one_games: a, player_two_games: b };
        else if (kind === 'ranked') body = { score_one: a, score_two: b, idempotency_key: submitKey };
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
                // Avisa os outros celulares da mesa ("Fulano enviou o resultado") antes de sair.
                act({ type: 'submitted' });
                await Promise.race([flush(), new Promise((r) => setTimeout(r, 2500))]);
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
    function showModal(title, text, actions, extra, error, info) {
        modal.textContent = '';
        modalInfo = info || { kind: 'menu' };
        const card = el('div', 'life-modal-card');
        card.append(el('h2', '', title));
        if (text) card.append(el('p', '', text));
        if (extra) card.append(extra);
        if (error) card.append(el('p', 'life-error', error));
        const row = el('div', 'life-actions');
        actions.forEach((a) => {
            let node;
            if (a.href) { node = el('a', 'life-btn ' + (a.cls || ''), a.label); node.href = a.href; }
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
        modalInfo = null;
        setTimeout(() => { if (!state.finished) checkAll(); }, 0);
    }
    modal.addEventListener('click', (e) => { if (e.target === modal && !state.finished) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden && !state.finished) closeModal(); });

    function openMenu() {
        if (state.finished) return showResult();
        const info = el('div', 'life-menu-info');
        info.append(el('p', 'life-muted', cfg.title + ' · ' + cfg.subtitle));
        if (!isPod) info.append(el('p', 'life-score-small', name(0) + ' ' + scoreText() + ' ' + name(1) + ' · Game ' + state.game));
        if (sync) info.append(el('p', 'life-muted life-sync-line', syncText()));
        const actions = [
            { label: 'Continuar', cls: 'primary', fn: () => {} },
        ];
        actions.push({ label: 'Sortear quem começa (D20)', fn: highRoll });
        actions.push({ label: 'Reiniciar vidas deste game', fn: () => confirmThen('Reiniciar as vidas deste game?', () => act({ type: 'reset_game', game: state.game })) });
        if (state.undo.length) actions.push({ label: 'Desfazer última ação', fn: undo });
        if (!isPod && cfg.earlyEnd) actions.push({ label: 'Encerrar série agora (' + scoreText() + ')', fn: endEarly });
        actions.push({ label: 'Zerar tudo', fn: () => confirmThen('Apagar o placar e as vidas desta partida' + (sync ? ' (em todos os celulares)' : '') + '?', () => act({ type: 'reset_all' })) });
        actions.push({ label: 'Sair (o placar fica salvo)', href: cfg.resultUrl });
        showModal(isPod ? 'Mesa' : 'Partida', '', actions, info);
    }

    function confirmThen(question, fn) {
        setTimeout(() => showModal('Tem certeza?', question, [
            { label: 'Sim', cls: 'primary', fn: () => { fn(); } },
            { label: 'Cancelar', fn: () => {} },
        ]), 0);
    }

    function endEarly() {
        if (state.wins[0] + state.wins[1] === 0) return toast('Nenhum game decidido ainda.');
        confirmThen('Encerrar a série em ' + scoreText() + ' (por tempo)? O servidor confere se esse placar vale para a partida.', () => act({ type: 'end_early', game: state.game }));
    }

    // O sorteio é feito aqui e vai como jogada: todos os celulares mostram os mesmos números.
    function highRoll() {
        const rolls = {};
        cfg.players.forEach((_, i) => { if (!state.players[i].out) rolls[i] = 1 + Math.floor(Math.random() * 20); });
        act({ type: 'roll', rolls });
    }

    function showRolls(rolls) {
        const entries = Object.entries(rolls || {}).map(([i, v]) => [+i, v]).filter(([i]) => panels[i]);
        if (!entries.length) return;
        const top = Math.max(...entries.map(([, v]) => v));
        const winners = entries.filter(([, v]) => v === top).map(([i]) => i);
        entries.forEach(([i, v]) => {
            const r = panels[i].roll;
            r.textContent = 'D20: ' + v;
            r.classList.toggle('best', winners.length === 1 && v === top);
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
        close.addEventListener('click', () => { tray.hidden = true; tray.refresh = null; });
        tray.append(close, el('h3', '', 'Contadores de ' + name(i)));
        const values = [];
        const row = (label, get, op, limit) => {
            const line = el('div', 'life-tray-row');
            const val = el('b', '', get());
            const minus = button('life-mini', '−', label + ' menos');
            const plus = button('life-mini', '+', label + ' mais');
            const upd = (d) => {
                const before = state.players[i].life;
                act(op(d));
                showDelta(i, state.players[i].life - before);
            };
            minus.addEventListener('click', () => upd(-1));
            plus.addEventListener('click', () => upd(1));
            line.append(el('span', '', label + ' (' + limit + ')'), minus, val, plus);
            tray.append(line);
            values.push(() => { val.textContent = String(get()); });
        };
        row('☠ Veneno', () => state.players[i].poison, (d) => ({ type: 'poison', seat: i, d }), cfg.poisonLimit);
        if (cfg.commanderDamage) {
            cfg.players.forEach((_, from) => {
                if (from === i) return;
                row('⚔ de ' + name(from), () => state.players[i].cmd[from] || 0, (d) => ({ type: 'cmd', seat: i, from, d }), cfg.commanderLimit);
            });
        }
        // Atualiza os números quando a jogada vem do outro celular com a bandeja aberta.
        tray.refresh = () => values.forEach((fn) => fn());
        tray.hidden = false;
    }

    // ---------- sincronia com a mesa ----------
    // Envio: junta os toques por um instante e manda em lote. Resposta = tudo que entrou na mesa depois
    // do que este aparelho tinha (inclusive do outro celular). Falhou: tenta de novo com espera crescente.
    const net = { sending: null, timer: null, backoff: 0, ok: true, pulling: false, poll: null, stopped: false };

    function flushSoon(ms) {
        if (!sync || net.stopped) return;
        clearTimeout(net.timer);
        net.timer = setTimeout(flush, ms === undefined ? 150 : ms);
    }

    function headers() {
        return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
    }

    function flush() {
        if (!sync || net.stopped || !store.pending.length) return Promise.resolve();
        if (net.sending) return net.sending;
        const batch = store.outbox(50);
        save(); // Persiste a primeira tentativa ANTES de fetch, mesmo se a resposta nunca chegar.
        net.sending = fetch(sync.opsUrl, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify({ after: store.seq, ops: batch }) })
            .then(async (res) => {
                if (res.status === 422) {
                    // Jogada recusada (ex.: mesa no limite): sai da fila para não travar as próximas.
                    const data = await res.json().catch(() => ({}));
                    const prev = state;
                    store.drop(batch.map((o) => o.id));
                    state = store.view();
                    save();
                    afterChange(prev, []);
                    toast((data.errors && Object.values(data.errors).flat()[0]) || 'Jogada recusada pelo servidor.');
                    return;
                }
                if (res.status === 419 || res.status === 401) { net.stopped = true; setNet(false, 'Sua sessão expirou. Recarregue a página: as jogadas ficam salvas.'); return; }
                if (res.status === 404 || res.status === 403) { net.stopped = true; setNet(false, 'Esta mesa não está mais disponível.'); return; }
                if (!res.ok) throw new Error('http ' + res.status);
                ingest(await res.json());
                net.backoff = 0;
                setNet(true);
            })
            .catch(() => {
                setNet(false);
                net.backoff = Math.min(30000, (net.backoff || 1000) * 2);
                flushSoon(net.backoff);
            })
            .finally(() => {
                net.sending = null;
                if (store.pending.length && net.ok) flushSoon(30);
            });
        return net.sending;
    }

    function ingest(data) {
        if (!data) return;
        if (typeof data.seq === 'number' && data.seq < store.seq) {
            // A mesa no servidor está atrás deste aparelho (foi recriada): recomeça da cópia do servidor.
            store.reset();
            pull();
            return;
        }
        const prev = state;
        const quiet = firstCopy && store.seq === 0;
        const result = store.receive(Array.isArray(data.ops) ? data.ops : []);
        if (result.remote.length || store.seq > 0) firstCopy = false;
        state = store.view();
        save();
        afterChange(prev, result.remote, quiet && result.remote.length > 0);
        if (result.gap) pull();
    }

    function pull() {
        if (!sync || net.pulling || net.stopped) return;
        net.pulling = true;
        fetch(sync.url + '?after=' + store.seq, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then((res) => {
                if (res.status === 404 || res.status === 403) { net.stopped = true; setNet(false, 'Esta mesa não está mais disponível.'); return null; }
                if (!res.ok) throw new Error('http ' + res.status);
                return res.json();
            })
            .then((data) => { if (data) { ingest(data); setNet(true); } })
            .catch(() => setNet(false))
            .finally(() => { net.pulling = false; });
    }

    // Plano B sem socket: pergunta a cada 3s (aba visível). Com o canal assinado, só uma conferência a cada 20s.
    function pollLoop() {
        clearTimeout(net.poll);
        const live = realtime && realtime.subscribed(sync.channel);
        net.poll = setTimeout(() => { if (!document.hidden) pull(); pollLoop(); }, live ? 20000 : 3000);
    }

    let netMessage = null;
    function setNet(ok, message) {
        net.ok = ok;
        netMessage = message || null;
        const live = ok && realtime && realtime.subscribed(sync.channel);
        hub.classList.toggle('is-offline', !ok);
        hub.classList.toggle('is-live', !!live);
        hub.title = syncText();
        if (message) toast(message);
    }

    function syncText() {
        if (netMessage) return netMessage;
        const waiting = store.pending.length;
        if (!net.ok) return 'Sem conexão: ' + (waiting ? waiting + (waiting === 1 ? ' jogada esperando' : ' jogadas esperando') + ' para enviar. ' : '') + 'O placar fica salvo neste aparelho.';
        return realtime && realtime.subscribed(sync.channel) ? 'Ao vivo: o placar aparece igual nos outros celulares.' : 'Sincronizando com os outros celulares a cada poucos segundos.';
    }

    if (sync) {
        if (realtime) {
            realtime.on(sync.channel, 'ops', (data) => ingest({ ops: data && data.ops }));
            realtime.on(sync.channel, 'subscribed', () => { pull(); setNet(net.ok); pollLoop(); });
            realtime.onState(() => setNet(net.ok));
        }
        window.addEventListener('online', () => { if (!net.stopped) { net.backoff = 0; flushSoon(0); pull(); } });
        document.addEventListener('visibilitychange', () => { if (!document.hidden && !net.stopped) { pull(); flushSoon(0); } });
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
    if (sync) {
        hub.classList.add('has-sync');
        setNet(true);
        pull();
        flushSoon(0);
        pollLoop();
    }
})();
