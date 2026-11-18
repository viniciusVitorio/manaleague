/* Contas do recorte de imagem (usado por image-crop.js; testado em tests/js/image-crop.test.cjs).
 * A imagem aparece inteira numa área quadrada de `side` px e a pessoa marca nela um quadrado, a "seleção":
 *   rect: onde a imagem fica na área { x, y, w, h }      (layout)
 *   box:  até onde a seleção pode ir { x, y, w, h }      (limits)
 *   sel:  a seleção { x, y, size }, sempre quadrada e dentro de box
 * fit "cover": a seleção fica dentro da imagem (foto de perfil).
 * fit "contain": pode pegar a área toda, com fundo transparente onde a imagem não chega (arte de troféu). */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.CropCore = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Menor seleção, como fração da área: abaixo disso o recorte sairia borrado.
    var MIN_PART = 0.08;

    function between(value, low, high) {
        return Math.min(high, Math.max(low, value));
    }

    // A imagem inteira cabe na área, centralizada.
    function layout(iw, ih, side) {
        var k = side / Math.max(iw, ih), w = iw * k, h = ih * k;
        return { x: (side - w) / 2, y: (side - h) / 2, w: w, h: h };
    }

    function limits(rect, side, fit) {
        return fit === 'contain' ? { x: 0, y: 0, w: side, h: side } : rect;
    }

    function maxSize(box) {
        return Math.min(box.w, box.h);
    }

    function minSize(box, side) {
        return Math.min(maxSize(box), side * MIN_PART);
    }

    // Traz a seleção para dentro dos limites, no tamanho permitido.
    function fitIn(sel, box, side) {
        var size = between(sel.size, minSize(box, side), maxSize(box));
        return { x: between(sel.x, box.x, box.x + box.w - size), y: between(sel.y, box.y, box.y + box.h - size), size: size };
    }

    // Seleção inicial: a maior possível, no meio.
    function start(box) {
        var size = maxSize(box);
        return { x: box.x + (box.w - size) / 2, y: box.y + (box.h - size) / 2, size: size };
    }

    function move(sel, dx, dy, box, side) {
        return fitIn({ x: sel.x + dx, y: sel.y + dy, size: sel.size }, box, side);
    }

    // Muda o tamanho mantendo o centro (zoom, roda do mouse, dois dedos).
    function resize(sel, size, box, side) {
        var next = between(size, minSize(box, side), maxSize(box));
        var cx = sel.x + sel.size / 2, cy = sel.y + sel.size / 2;
        return fitIn({ x: cx - next / 2, y: cy - next / 2, size: next }, box, side);
    }

    // Quadrado que nasce no ponto fixo (ax, ay) e cresce na direção do ponteiro: desenhar uma seleção nova
    // ou puxar um canto (o ponto fixo é o canto oposto). Para no limite da área.
    function fromAnchor(ax, ay, px, py, box, side) {
        ax = between(ax, box.x, box.x + box.w);
        ay = between(ay, box.y, box.y + box.h);
        var right = px >= ax, down = py >= ay;
        var roomX = right ? box.x + box.w - ax : ax - box.x;
        var roomY = down ? box.y + box.h - ay : ay - box.y;
        var size = Math.min(Math.max(Math.abs(px - ax), Math.abs(py - ay)), roomX, roomY);
        return fitIn({ x: right ? ax : ax - size, y: down ? ay : ay - size, size: size }, box, side);
    }

    // Canto oposto ao que a pessoa está puxando.
    function opposite(sel, corner) {
        return { x: corner.charAt(1) === 'w' ? sel.x + sel.size : sel.x, y: corner.charAt(0) === 'n' ? sel.y + sel.size : sel.y };
    }

    // O que o ponteiro pegou: um canto ("nw", "ne", "sw", "se"), o meio ("move") ou fora da seleção ("new").
    function hit(sel, px, py, reach) {
        var corners = { nw: [sel.x, sel.y], ne: [sel.x + sel.size, sel.y], sw: [sel.x, sel.y + sel.size], se: [sel.x + sel.size, sel.y + sel.size] };
        var names = Object.keys(corners);
        for (var i = 0; i < names.length; i++) {
            var corner = corners[names[i]];
            if (Math.abs(px - corner[0]) <= reach && Math.abs(py - corner[1]) <= reach) return names[i];
        }
        return px >= sel.x && px <= sel.x + sel.size && py >= sel.y && py <= sel.y + sel.size ? 'move' : 'new';
    }

    // Onde desenhar a imagem inteira num canvas de saída de `out` px para ficar só a seleção.
    function place(sel, rect, out) {
        var k = out / sel.size;
        return { x: (rect.x - sel.x) * k, y: (rect.y - sel.y) * k, w: rect.w * k, h: rect.h * k };
    }

    return {
        MIN_PART: MIN_PART, layout: layout, limits: limits, maxSize: maxSize, minSize: minSize, fitIn: fitIn, start: start,
        move: move, resize: resize, fromAnchor: fromAnchor, opposite: opposite, hit: hit, place: place,
    };
});
