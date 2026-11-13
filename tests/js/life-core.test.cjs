// Regras do contador de vida compartilhado (public/js/life-core.js): dois celulares, mesma ordem, mesmo placar.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { console };
vm.createContext(context);
vm.runInContext(fs.readFileSync('public/js/life-core.js', 'utf8'), context);
const { LifeCore } = context;

const duel = { mode: 'duel', startingLife: 20, winsNeeded: 2, players: [{}, {}] };
const pod = { mode: 'pod', startingLife: 40, winsNeeded: 1, players: [{}, {}, {}] };
let seq = 0;
const confirmed = (id, op, by = 1) => ({ seq: ++seq, id, by, op });
const plain = (v) => JSON.parse(JSON.stringify(v));

// Toques ao mesmo tempo nos dois celulares somam (variacoes, nao "a vida agora e X").
{
    const s = LifeCore.create(duel);
    LifeCore.apply(s, { id: 'a', op: { type: 'life', seat: 1, d: -3 } }, duel);
    LifeCore.apply(s, { id: 'b', op: { type: 'life', seat: 1, d: 2 } }, duel);
    assert.equal(s.players[1].life, 19);
}

// Dois celulares confirmando o mesmo fim de game: uma vitoria so.
{
    const s = LifeCore.create(duel);
    LifeCore.apply(s, { id: 'a', op: { type: 'life', seat: 1, d: -20 } }, duel);
    assert.equal(LifeCore.apply(s, { id: 'x', op: { type: 'loss', seat: 1, game: 1 } }, duel), true);
    assert.equal(LifeCore.apply(s, { id: 'y', op: { type: 'loss', seat: 1, game: 1 } }, duel), false);
    assert.deepEqual(plain(s.wins), [1, 0]);
    assert.equal(s.game, 2);
    assert.equal(s.players[1].life, 20, 'game novo comeca com a vida inicial');
}

// Serie termina com a segunda vitoria; desafazer volta o ultimo game e so vale para a pilha esperada.
{
    const s = LifeCore.create(duel);
    LifeCore.apply(s, { id: '1', op: { type: 'loss', seat: 1, game: 1 } }, duel);
    LifeCore.apply(s, { id: '2', op: { type: 'loss', seat: 1, game: 2 } }, duel);
    assert.equal(s.finished, true);
    assert.equal(s.undo.length, 2);
    assert.equal(LifeCore.apply(s, { id: 'u1', op: { type: 'undo', depth: 2 } }, duel), true);
    assert.equal(LifeCore.apply(s, { id: 'u2', op: { type: 'undo', depth: 2 } }, duel), false, 'o outro celular desfez ao mesmo tempo: nao desfaz duas vezes');
    assert.equal(s.finished, false);
    assert.deepEqual(plain(s.wins), [1, 0]);
    // "Enviei o resultado" so vale com a serie encerrada.
    assert.equal(LifeCore.apply(s, { id: 's', by: 7, op: { type: 'submitted' } }, duel), false);
}

// Dano de comandante tira vida; veneno nao fica negativo; jogada em quem saiu nao conta.
{
    const s = LifeCore.create(pod);
    LifeCore.apply(s, { id: 'c', op: { type: 'cmd', seat: 0, from: 2, d: 7 } }, pod);
    LifeCore.apply(s, { id: 'c2', op: { type: 'cmd', seat: 0, from: 2, d: -10 } }, pod);
    assert.equal(s.players[0].life, 40);
    assert.equal(s.players[0].cmd[2], 0);
    LifeCore.apply(s, { id: 'p', op: { type: 'poison', seat: 1, d: -3 } }, pod);
    assert.equal(s.players[1].poison, 0);
    LifeCore.apply(s, { id: 'l1', op: { type: 'loss', seat: 1, game: 1 } }, pod);
    assert.equal(LifeCore.apply(s, { id: 'z', op: { type: 'life', seat: 1, d: 5 } }, pod), false);
    LifeCore.apply(s, { id: 'l2', op: { type: 'loss', seat: 2, game: 1 } }, pod);
    assert.equal(s.finished, true, 'sobrou um na mesa');
    assert.deepEqual(plain(s.elim), [1, 2]);
}

// Store: o toque aparece na hora (pending), o servidor confirma e a jogada do outro celular entra na ordem certa.
{
    seq = 0;
    const store = new LifeCore.Store(duel, null);
    const mine = store.local({ type: 'life', seat: 1, d: -5 }, 1);
    assert.equal(store.view().players[1].life, 15);
    assert.equal(store.base.players[1].life, 20);

    // Chegam fora de ordem (aviso do socket + resposta do envio): o Store aplica pela ordem do servidor.
    const theirs = confirmed('theirs', { type: 'life', seat: 0, d: -2 }, 2);
    const result = store.receive([confirmed(mine.id, mine.op, 1), theirs].reverse());
    assert.equal(result.gap, false);
    assert.deepEqual(plain(result.remote.map((e) => e.id)), ['theirs']);
    assert.equal(store.pending.length, 0);
    assert.equal(store.seq, 2);
    assert.deepEqual([store.view().players[0].life, store.view().players[1].life], [18, 15]);

    // Repetida: ignorada. Buraco na sequencia: avisa para buscar de novo.
    assert.deepEqual(plain(store.receive([theirs]).remote), []);
    assert.equal(store.receive([{ seq: 5, id: 'later', by: 2, op: { type: 'life', seat: 0, d: -1 } }]).gap, true);
    assert.equal(store.seq, 2);

    // Recarregar a pagina: a copia salva continua de onde parou.
    const again = new LifeCore.Store(duel, JSON.parse(JSON.stringify(store.serialize())));
    assert.equal(again.seq, 2);
    assert.equal(again.view().players[0].life, 18);
    // Copia de outra mesa (numero de lugares diferente) e descartada.
    assert.equal(new LifeCore.Store(pod, store.serialize()).seq, 0);
}

// Sorteio: os mesmos numeros em todos os celulares; zerar tudo limpa a mesa.
{
    const s = LifeCore.create(duel);
    LifeCore.apply(s, { id: 'r1', op: { type: 'roll', rolls: { 0: 12, 1: 7 } } }, duel);
    assert.deepEqual(plain(s.roll), { key: 'r1', rolls: { 0: 12, 1: 7 } });
    LifeCore.apply(s, { id: 'r', op: { type: 'reset_all' } }, duel);
    assert.equal(s.roll, null);
    assert.deepEqual(plain(s.wins), [0, 0]);
}

// Executa o frontend real com DOM/rede minimos; timers ficam sob controle do teste.
function counterBrowser(saved, post) {
    const nodes = [], timers = new Map();
    let nextTimer = 0, persisted = saved;
    const others = new Map();
    const cfg = { ...duel, storageKey: 'counter', sync: { me: 1, opsUrl: '/ops', url: '/table' } };
    const node = () => {
        const listeners = {};
        const element = {
            hidden: false, style: { setProperty() {} },
            classList: { add() {}, remove() {}, toggle() {} },
            append() {}, setAttribute() {}, addEventListener(type, fn) { listeners[type] = fn; },
            querySelectorAll() { return []; },
            fire(type, event) { listeners[type]?.(event); },
        };
        nodes.push(element);
        return element;
    };
    const root = node();
    root.dataset = { config: JSON.stringify(cfg) };
    const browser = {
        console, navigator: {}, innerWidth: 390, innerHeight: 844,
        addEventListener() {},
        document: {
            getElementById: () => root, querySelector: () => null,
            createElement: node, addEventListener() {},
        },
        // Só a mesa interessa a estes testes; o histórico de vida (outra chave) fica em memória à parte.
        localStorage: {
            getItem: (key) => (key === cfg.storageKey ? persisted || null : others.get(key) ?? null),
            setItem: (key, value) => { if (key === cfg.storageKey) persisted = value; else others.set(key, value); },
        },
        setTimeout(fn, ms) { const id = ++nextTimer; timers.set(id, { fn, ms }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            return options.method === 'POST'
                ? post(JSON.parse(options.body).ops, () => persisted)
                : Promise.resolve({ ok: true, json: async () => ({ seq: 0, ops: [] }) });
        },
    };
    browser.window = browser;
    vm.createContext(browser);
    vm.runInContext(fs.readFileSync('public/js/life-core.js', 'utf8'), browser);
    vm.runInContext(fs.readFileSync('public/js/life-log.js', 'utf8'), browser);
    vm.runInContext(fs.readFileSync('public/js/life-counter.js', 'utf8'), browser);
    return {
        tap() { nodes.find((e) => e.className === 'life-zone minus').fire('keydown', { key: 'Enter', preventDefault() {} }); },
        flush() {
            const timer = [...timers.entries()].find(([, value]) => value.ms === 150);
            assert.ok(timer, 'toque agenda o envio real');
            timers.delete(timer[0]);
            return timer[1].fn();
        },
        saved: () => persisted,
        pending: () => JSON.parse(persisted).pending,
        // Histórico de vida deste aparelho: dispara a espera de assentar (LifeLog.SETTLE_MS) e lê o que ficou gravado.
        settle() { [...timers.entries()].filter(([, v]) => v.ms === 3000).forEach(([id, v]) => { timers.delete(id); v.fn(); }); },
        log: () => JSON.parse(others.get(cfg.storageKey + ':log') || 'null'),
    };
}

require('node:test')('resposta perdida: novos toques nao alteram client_id tentado, inclusive apos reload', async () => {
    const accepted = new Map();
    let rejectFirst, firstPayload, attemptedAtFetch;
    const post = (ops, saved) => {
        for (const op of ops) if (!accepted.has(op.id)) accepted.set(op.id, op);
        if (!firstPayload) {
            firstPayload = ops[0];
            // O marcador precisa estar no disco ANTES de fetch, nao so no catch/finally.
            const persisted = JSON.parse(saved()).pending.find((e) => e.id === firstPayload.id);
            attemptedAtFetch = persisted.attempted;
            return new Promise((_, reject) => { rejectFirst = reject; });
        }
        return Promise.resolve({ ok: true, json: async () => ({
            seq: accepted.size,
            ops: [...accepted.values()].map(({ id, ...op }, i) => ({ id, op, by: 1, seq: i + 1 })),
        }) });
    };
    const browser = counterBrowser(null, post);
    await new Promise(setImmediate); // primeira copia vazia da mesa
    browser.tap();
    const sending = browser.flush();
    rejectFirst(new Error('resposta perdida depois de persistir no servidor'));
    await sending;
    browser.tap(); // antes do retry; finally ja limpou inflight
    assert.equal(browser.pending().length, 2);
    assert.equal(browser.pending()[0].op.d, -1);
    assert.notEqual(browser.pending()[1].id, firstPayload.id);
    assert.equal(attemptedAtFetch, true, 'primeira tentativa salva antes de fetch');

    // Reload sem a segunda jogada: a primeira tentativa continua imutavel mesmo sem inflight.
    const onlyAttempted = JSON.parse(browser.saved());
    onlyAttempted.pending.pop();
    const reloaded = counterBrowser(JSON.stringify(onlyAttempted), post);
    await new Promise(setImmediate);
    reloaded.tap();
    assert.equal(reloaded.pending().length, 2);
    assert.equal(reloaded.pending()[0].op.d, -1);
    assert.notEqual(reloaded.pending()[1].id, firstPayload.id);
    await reloaded.flush();
    assert.equal(reloaded.pending().length, 0);
    assert.equal(JSON.parse(reloaded.saved()).base.players[0].life, 18, 'deduplicacao preserva os dois toques');
});

require('node:test')('toques nunca enviados ainda agregam, inclusive apos reload', async () => {
    const browser = counterBrowser(null, () => { throw new Error('nao deve enviar'); });
    await new Promise(setImmediate);
    browser.tap();
    browser.tap();
    assert.equal(browser.pending().length, 1);
    assert.equal(browser.pending()[0].op.d, -2);
    const reloaded = counterBrowser(browser.saved(), () => { throw new Error('nao deve enviar'); });
    await new Promise(setImmediate);
    reloaded.tap();
    assert.equal(reloaded.pending().length, 1);
    assert.equal(reloaded.pending()[0].op.d, -3);
});

require('node:test')('a mesa compartilhada alimenta o histórico de vida: toques seguidos viram um ponto ao assentar', async () => {
    const browser = counterBrowser(null, () => new Promise(() => {}));
    await new Promise(setImmediate);
    browser.tap();
    browser.tap();
    assert.deepEqual(browser.log().games[0].points, [], 'ainda assentando');
    browser.settle();
    const points = browser.log().games[0].points;
    assert.equal(points.length, 1);
    assert.deepEqual([points[0].s, points[0].l, points[0].p], [0, 18, 0]);
});

console.log('life-core: ok');
