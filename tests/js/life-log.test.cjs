// Histórico de vida do contador: o que entra no gráfico e o que é tratado como misclick (public/js/life-log.js).
const test = require('node:test');
const assert = require('node:assert/strict');
const LifeLog = require('../../public/js/life-log.js');

const START = 20;
const at = (game, seconds) => game.at + seconds * 1000;
const commit = (game, seat, life, seconds, poison = 0) => LifeLog.commit(game, seat, { l: life, p: poison }, START, at(game, seconds));

test('a vida que não mudou desde o último ponto não grava nada (−1 e +1 antes de assentar)', () => {
    const game = LifeLog.freshGame(0);
    assert.equal(commit(game, 0, 20, 5), null);
    assert.deepEqual(game.points, []);
});

test('cada mudança assentada vira um ponto com o tempo do game', () => {
    const game = LifeLog.freshGame(1000);
    assert.equal(commit(game, 0, 17, 40), 'added');
    assert.equal(commit(game, 1, 15, 95), 'added');
    assert.deepEqual(game.points, [{ t: 40, s: 0, l: 17, p: 0 }, { t: 95, s: 1, l: 15, p: 0 }]);
});

test('correção atrasada: voltar ao valor anterior em até 15 s apaga o ponto errado', () => {
    const game = LifeLog.freshGame(0);
    commit(game, 0, 17, 30);
    commit(game, 0, 14, 60);
    assert.equal(commit(game, 0, 17, 70), 'cancelled');
    assert.deepEqual(game.points, [{ t: 30, s: 0, l: 17, p: 0 }]);
});

test('correção atrasada não vale depois de 15 s nem com outro jogador no meio', () => {
    const late = LifeLog.freshGame(0);
    commit(late, 0, 17, 30);
    assert.equal(commit(late, 0, 20, 50), 'added');
    assert.equal(late.points.length, 2);

    const between = LifeLog.freshGame(0);
    commit(between, 0, 17, 30);
    commit(between, 1, 18, 33);
    assert.equal(commit(between, 0, 20, 36), 'added');
    assert.equal(between.points.length, 3);
});

test('veneno conta como mudança, mesmo sem perder vida', () => {
    const game = LifeLog.freshGame(0);
    assert.equal(commit(game, 1, 20, 12, 3), 'added');
    assert.deepEqual(game.points[0], { t: 12, s: 1, l: 20, p: 3 });
});

test('passou do limite de pontos: para de gravar em vez de crescer sem fim', () => {
    const game = LifeLog.freshGame(0);
    for (let k = 0; k < LifeLog.MAX_POINTS + 10; k++) commit(game, k % 2, 100 + k, k * 20);
    assert.equal(game.points.length, LifeLog.MAX_POINTS);
});

test('envio: listas curtas e sem games vazios', () => {
    const first = LifeLog.freshGame(0);
    commit(first, 0, 12, 60);
    first.winner = 1;
    const empty = LifeLog.freshGame(0);
    assert.deepEqual(LifeLog.payload([first, empty]), [{ winner: 1, points: [[60, 0, 12, 0]], out: [] }]);
});
