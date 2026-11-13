/* Regras do contador de vida compartilhado (sem DOM): o mesmo arquivo roda no navegador e nos testes (node).
 *
 * A mesa e uma lista de jogadas em ordem. Cada celular aplica as mesmas jogadas na mesma ordem e chega ao
 * mesmo placar: o servidor so da a ordem (seq). Jogadas de vida/veneno/comandante sao variacoes, entao
 * toques ao mesmo tempo nos dois celulares somam. Fim de game, reiniciar e encerrar levam o game em que
 * foram feitas, e desfazer leva o tamanho da pilha: se a mesa ja andou, a jogada nao faz nada (dois
 * celulares confirmando o mesmo fim de game nao dao duas vitorias).
 *
 * Store guarda a copia confirmada pelo servidor (base + seq) e as jogadas deste aparelho que ainda nao
 * foram confirmadas (pending). A tela mostra base + pending: o toque aparece na hora, mesmo sem internet. */
(function (global) {
    'use strict';

    const UNDO_LIMIT = 15;
    const clone = (v) => JSON.parse(JSON.stringify(v));

    function freshPlayers(cfg) {
        return cfg.players.map(() => ({ life: cfg.startingLife, poison: 0, cmd: {}, out: false }));
    }

    function create(cfg) {
        return { game: 1, wins: cfg.players.map(() => 0), winners: [], elim: [], players: freshPlayers(cfg), finished: false, undo: [], roll: null, submitted: null };
    }

    function snapshot(s) {
        s.undo.push(clone({ game: s.game, wins: s.wins, winners: s.winners, elim: s.elim, players: s.players }));
        if (s.undo.length > UNDO_LIMIT) s.undo.shift();
    }

    const alive = (s) => s.players.map((_, i) => i).filter((i) => !s.players[i].out);

    /** Aplica uma jogada ({id, by, op}) no estado. Retorna false quando ela nao muda nada. */
    function apply(s, entry, cfg) {
        const op = entry.op || {};
        const isPod = cfg.mode === 'pod';
        const p = Number.isInteger(op.seat) ? s.players[op.seat] : null;
        switch (op.type) {
            case 'life':
                if (s.finished || !p || p.out) return false;
                p.life += op.d;
                return true;
            case 'poison':
                if (s.finished || !p || p.out) return false;
                p.poison = Math.max(0, p.poison + op.d);
                return true;
            case 'cmd': {
                // Dano de comandante tambem tira vida (como no Lotus).
                if (s.finished || !p || p.out || op.from === op.seat || !s.players[op.from]) return false;
                const cur = p.cmd[op.from] || 0, next = Math.max(0, cur + op.d);
                p.cmd[op.from] = next;
                p.life -= next - cur;
                return next !== cur;
            }
            case 'loss': {
                if (s.finished || !p || p.out || op.game !== s.game) return false;
                snapshot(s);
                if (isPod) {
                    p.out = true;
                    s.elim.push(op.seat);
                    if (alive(s).length <= 1) s.finished = true;
                    return true;
                }
                const w = 1 - op.seat;
                s.wins[w]++;
                s.winners.push(w);
                if (s.wins[w] >= cfg.winsNeeded) s.finished = true;
                else { s.game++; s.players = freshPlayers(cfg); }
                return true;
            }
            case 'undo': {
                if (!s.undo.length || op.depth !== s.undo.length) return false;
                Object.assign(s, s.undo.pop(), { finished: false, submitted: null });
                return true;
            }
            case 'reset_game':
                if (s.finished || op.game !== s.game) return false;
                snapshot(s);
                s.players = s.players.map((pl) => ({ life: cfg.startingLife, poison: 0, cmd: {}, out: pl.out }));
                return true;
            case 'reset_all':
                Object.assign(s, create(cfg));
                return true;
            case 'end_early':
                if (isPod || s.finished || op.game !== s.game || s.wins.reduce((a, b) => a + b, 0) === 0) return false;
                snapshot(s);
                s.finished = true;
                return true;
            case 'roll':
                s.roll = { key: entry.id, rolls: op.rolls };
                return true;
            case 'submitted':
                if (!s.finished) return false;
                s.submitted = { by: entry.by ?? null };
                return true;
            default:
                return false;
        }
    }

    function replay(cfg, entries) {
        const s = create(cfg);
        entries.forEach((e) => apply(s, e, cfg));
        return s;
    }

    let counter = 0;
    function uuid() {
        if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
        counter++;
        return 'x' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10) + '-' + counter;
    }

    class Store {
        constructor(cfg, saved) {
            this.cfg = cfg;
            const ok = saved && saved.v === 2 && saved.base && Array.isArray(saved.base.players) && saved.base.players.length === cfg.players.length;
            this.seq = ok ? saved.seq : 0;
            this.base = ok ? saved.base : create(cfg);
            this.pending = ok && Array.isArray(saved.pending) ? saved.pending : [];
        }

        /** O que a tela mostra: confirmado + o que este aparelho fez e ainda nao chegou ao servidor. */
        view() {
            const s = clone(this.base);
            this.pending.forEach((e) => apply(s, e, this.cfg));
            return s;
        }

        local(op, by) {
            const entry = { id: uuid(), by: by ?? null, op, attempted: false };
            this.pending.push(entry);
            return entry;
        }

        /**
         * Jogadas confirmadas pelo servidor (de qualquer celular), em ordem. Ignora as que ja tem.
         * gap = faltou alguma no meio (aviso perdido): busque de novo a partir de this.seq.
         * remote = as que vieram de outro aparelho (para animar o -3 do amigo).
         */
        receive(entries) {
            const remote = [];
            let gap = false;
            [...entries].sort((a, b) => a.seq - b.seq).forEach((e) => {
                if (gap || e.seq <= this.seq) return;
                if (e.seq !== this.seq + 1) { gap = true; return; }
                apply(this.base, e, this.cfg);
                this.seq = e.seq;
                const mine = this.pending.findIndex((p) => p.id === e.id);
                if (mine >= 0) this.pending.splice(mine, 1); else remote.push(e);
            });
            return { gap, remote };
        }

        /** O servidor esta atras do aparelho (mesa recriada): recomeca do zero, mantendo o que falta enviar. */
        reset() {
            this.seq = 0;
            this.base = create(this.cfg);
        }

        /** Selecionar para envio sela o payload: salvar serialize() antes de iniciar a requisicao.
         * attempted persiste em pending; ausencia (copia antiga) nao prova que nunca foi enviada. */
        outbox(limit) {
            return this.pending.slice(0, limit).map((e) => {
                e.attempted = true;
                return Object.assign({ id: e.id }, e.op);
            });
        }

        drop(ids) {
            this.pending = this.pending.filter((e) => !ids.includes(e.id));
        }

        serialize() {
            return { v: 2, seq: this.seq, base: this.base, pending: this.pending };
        }
    }

    global.LifeCore = { create, apply, replay, clone, uuid, Store, UNDO_LIMIT };
})(typeof window !== 'undefined' ? window : globalThis);
