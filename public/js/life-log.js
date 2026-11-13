/* Histórico de vida do contador, sem misclick (usado por life-counter.js; testado em tests/js/life-log.test.cjs).
 * Cada game: { at: início em ms, points: [{ t: segundos, s: lugar, l: vida, p: veneno }], winner, out: [{ t, s }] }.
 * Quem chama decide QUANDO gravar (o contador espera a vida assentar por SETTLE_MS); aqui fica O QUE gravar. */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.LifeLog = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var SETTLE_MS = 3000, CANCEL_MS = 15000, MAX_POINTS = 400;

    function freshGame(now) {
        return { at: now, points: [], winner: null, out: [] };
    }

    function seconds(game, now) {
        return Math.max(0, Math.round((now - game.at) / 1000));
    }

    // Último valor gravado do lugar (antes do ponto "upto"); sem ponto, o começo do game.
    function loggedValue(game, seat, startingLife, upto) {
        for (var k = (upto === undefined ? game.points.length : upto) - 1; k >= 0; k--) {
            if (game.points[k].s === seat) return { l: game.points[k].l, p: game.points[k].p };
        }
        return { l: startingLife, p: 0 };
    }

    /**
     * Grava o valor atual do lugar se ele mudou desde o último ponto.
     * Correção atrasada: voltou exatamente ao valor anterior em até CANCEL_MS, sem outro ponto no meio? O ponto anterior
     * era engano e sai. Retorna 'added', 'cancelled' ou null (nada mudou ou passou do limite).
     */
    function commit(game, seat, value, startingLife, now) {
        var prev = loggedValue(game, seat, startingLife);
        if (prev.l === value.l && prev.p === value.p) return null;
        var t = seconds(game, now);
        var last = game.points[game.points.length - 1];
        if (last && last.s === seat && (t - last.t) * 1000 <= CANCEL_MS) {
            var before = loggedValue(game, seat, startingLife, game.points.length - 1);
            if (before.l === value.l && before.p === value.p) {
                game.points.pop();
                return 'cancelled';
            }
        }
        if (game.points.length >= MAX_POINTS) return null;
        game.points.push({ t: t, s: seat, l: value.l, p: value.p });
        return 'added';
    }

    // Corpo do envio: listas curtas ([t, lugar, vida, veneno]); games sem nada ficam de fora.
    function payload(log) {
        return log
            .map(function (g) {
                return {
                    winner: g.winner === undefined ? null : g.winner,
                    points: g.points.map(function (p) { return [p.t, p.s, p.l, p.p]; }),
                    out: (g.out || []).map(function (o) { return [o.t, o.s]; }),
                };
            })
            .filter(function (g) { return g.points.length || g.winner !== null; });
    }

    return { SETTLE_MS: SETTLE_MS, CANCEL_MS: CANCEL_MS, MAX_POINTS: MAX_POINTS, freshGame: freshGame, seconds: seconds, commit: commit, payload: payload };
});
