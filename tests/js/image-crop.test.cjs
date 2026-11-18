// Recorte de imagem: as contas do quadrado de seleção em cima da imagem (public/js/crop-core.js).
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/js/crop-core.js');

const SIDE = 640;
const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-6, `${actual} ≈ ${expected}`);
const same = (sel, x, y, size) => { near(sel.x, x); near(sel.y, y); near(sel.size, size); };

// Foto deitada 1600×800: ocupa a largura toda da área e fica com 320 de altura, no meio.
const rect = Core.layout(1600, 800, SIDE);
const photo = Core.limits(rect, SIDE, 'cover');
const trophy = Core.limits(rect, SIDE, 'contain');

test('a imagem aparece inteira e centralizada na área', () => {
    assert.deepEqual(rect, { x: 0, y: 160, w: 640, h: 320 });
    assert.deepEqual(Core.layout(500, 1000, SIDE), { x: 160, y: 0, w: 320, h: 640 });
});

test('foto de perfil começa com o maior quadrado dentro da imagem, no meio', () => {
    same(Core.start(photo), 160, 160, 320);
});

test('arte de troféu começa com a área toda (imagem inteira, com sobra transparente)', () => {
    same(Core.start(trophy), 0, 0, 640);
});

test('arrastar a seleção para no limite: dentro da imagem na foto, dentro da área no troféu', () => {
    const start = Core.start(photo);
    same(Core.move(start, 9999, 9999, photo, SIDE), 320, 160, 320);
    same(Core.move(start, -9999, -9999, photo, SIDE), 0, 160, 320);

    const small = Core.resize(Core.start(trophy), 200, trophy, SIDE);
    same(Core.move(small, 9999, -9999, trophy, SIDE), 440, 0, 200);
});

test('zoom muda o tamanho pelo centro, com piso e teto', () => {
    const start = Core.start(photo);
    same(Core.resize(start, 160, photo, SIDE), 240, 240, 160);
    near(Core.resize(start, 1, photo, SIDE).size, SIDE * Core.MIN_PART);
    near(Core.resize(start, 9999, photo, SIDE).size, 320);

    // Crescer perto da borda empurra a seleção para dentro em vez de vazar.
    const corner = Core.move(Core.resize(start, 100, photo, SIDE), 9999, 9999, photo, SIDE);
    same(Core.resize(corner, 300, photo, SIDE), 340, 180, 300);
});

test('desenhar uma seleção nova: quadrado do ponto do clique até o ponteiro, em qualquer direção', () => {
    same(Core.fromAnchor(100, 200, 220, 260, photo, SIDE), 100, 200, 120);
    // Para cima e para a esquerda.
    same(Core.fromAnchor(400, 400, 300, 340, photo, SIDE), 300, 300, 100);
    // Para no limite da imagem: de y=200 só cabem 40 para cima.
    same(Core.fromAnchor(300, 200, 500, 0, photo, SIDE), 300, 160, 40 < SIDE * Core.MIN_PART ? SIDE * Core.MIN_PART : 40);
    // Clique que começa fora da imagem é trazido para a borda dela (y=160) e o quadrado cresce a partir dali.
    same(Core.fromAnchor(100, 20, 300, 400, photo, SIDE), 100, 160, 240);
});

test('puxar um canto mantém fixo o canto oposto', () => {
    const sel = { x: 200, y: 200, size: 100 };
    assert.deepEqual(Core.opposite(sel, 'se'), { x: 200, y: 200 });
    assert.deepEqual(Core.opposite(sel, 'nw'), { x: 300, y: 300 });
    assert.deepEqual(Core.opposite(sel, 'ne'), { x: 200, y: 300 });

    // Puxando o canto de cima à esquerda até (150, 180): o de baixo à direita (300, 300) não sai do lugar.
    const fixed = Core.opposite(sel, 'nw');
    same(Core.fromAnchor(fixed.x, fixed.y, 150, 180, trophy, SIDE), 150, 150, 150);
    // Na foto a seleção para na borda da imagem (y=160): só cabem 140 para cima.
    same(Core.fromAnchor(fixed.x, fixed.y, 150, 180, photo, SIDE), 160, 160, 140);
});

test('o ponteiro pega um canto, o meio ou o lado de fora', () => {
    const sel = { x: 200, y: 200, size: 100 };
    assert.equal(Core.hit(sel, 205, 195, 30), 'nw');
    assert.equal(Core.hit(sel, 310, 290, 30), 'se');
    assert.equal(Core.hit(sel, 250, 250, 30), 'move');
    assert.equal(Core.hit(sel, 400, 250, 30), 'new');
});

test('a saída traz só a seleção, no tamanho pedido', () => {
    // A seleção 320×320 no começo da foto vira 512×512: a imagem é desenhada 1,6× maior.
    const spot = Core.place({ x: 0, y: 160, size: 320 }, rect, 512);
    near(spot.x, 0);
    near(spot.y, 0);
    near(spot.w, 1024);
    near(spot.h, 512);

    // Troféu inteiro: a imagem fica no meio do quadrado, com sobra em cima e embaixo.
    const whole = Core.place(Core.start(trophy), rect, 512);
    near(whole.y, 128);
    near(whole.h, 256);
});
