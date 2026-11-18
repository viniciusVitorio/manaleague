// Recorte de imagem antes do envio: <input type="file" data-crop> abre uma janela com a imagem inteira e um quadrado de
// seleção. A pessoa marca com o mouse (ou o dedo) a parte que quer: arrasta o quadrado, puxa os cantos ou desenha outro.
// Ao confirmar, o arquivo do campo é trocado pelo recorte e o formulário segue como sempre.
//   data-crop="cover|contain"   cover: a seleção fica dentro da imagem (foto de perfil);
//                               contain: pode pegar a área toda, com fundo transparente nas sobras (arte de troféu)
//   data-crop-shape="circle"    mostra na seleção o círculo em que a foto aparece no site
//   data-crop-size="512"        lado do recorte enviado (px)
//   data-crop-preview="#id"     elemento que recebe a prévia do recorte
//   data-crop-title="..."       título da janela
// Avisos vão para o [data-crop-note] do mesmo [data-crop-box] (ou do formulário).
// Sem suporte do navegador nada muda: o arquivo sobe inteiro e o servidor ajusta (ele sempre reencoda e valida).
(function () {
    'use strict';
    var Core = window.CropCore;
    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var MAX_BYTES = 25 * 1024 * 1024; // só para não travar o aparelho: o que sobe é o recorte, bem menor
    var SIDE = 640; // resolução interna da área; o CSS define o tamanho na tela
    var state = null, lastDrag = 0;

    try {
        if (!Core || !window.FileReader || !window.HTMLDialogElement || !HTMLCanvasElement.prototype.toBlob || !window.PointerEvent) return;
        new DataTransfer(); // Safari antigo não deixa criar: sem isso não dá para trocar o arquivo do campo
    } catch (e) { return; }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key]; else node.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { node.appendChild(child); });
        return node;
    }

    // A janela é montada na primeira vez (e de novo se uma atualização da página a tirar do lugar).
    function dialog() {
        var existing = document.getElementById('crop-dialog');
        if (existing) return existing;

        var close = el('button', { type: 'button', class: 'ui-icon-btn', 'data-dialog-close': '', 'aria-label': 'Fechar', text: '×' });
        var canvas = el('canvas', { width: SIDE, height: SIDE, tabindex: '0', role: 'img', 'aria-label': 'Imagem com o quadrado de seleção. Arraste o quadrado ou use as setas para mover; puxe os cantos ou use + e − para mudar o tamanho.' });
        var range = el('input', { type: 'range', id: 'crop-zoom', min: '1', max: '2', step: '0.01', value: '1' });
        var box = el('dialog', { class: 'ui-dialog crop-dialog', id: 'crop-dialog', 'aria-labelledby': 'crop-title', 'data-async-keep': '' }, [
            el('header', { class: 'ui-dialog-head' }, [el('h2', { id: 'crop-title' }), close]),
            el('div', { class: 'ui-dialog-body' }, [
                el('div', { class: 'crop-stage' }, [canvas]),
                el('div', { class: 'crop-zoom' }, [
                    el('label', { for: 'crop-zoom', text: 'Zoom' }), range,
                    el('button', { type: 'button', class: 'ui-btn ui-btn-ghost ui-btn-sm', 'data-crop-reset': '', text: 'Área máxima' }),
                ]),
                el('p', { class: 'crop-hint', text: 'Arraste o quadrado para escolher a parte da imagem e puxe os cantos para mudar o tamanho. Arrastar fora dele desenha uma seleção nova.' }),
            ]),
            el('footer', { class: 'ui-dialog-foot' }, [
                el('button', { type: 'button', class: 'ui-btn ui-btn-ghost', 'data-dialog-close': '', text: 'Cancelar' }),
                el('button', { type: 'button', class: 'ui-btn ui-btn-primary', 'data-crop-apply': '', text: 'Usar este recorte' }),
            ]),
        ]);
        document.body.appendChild(box);
        wire(box, canvas, range);
        return box;
    }

    // Branco com contorno escuro: a seleção precisa aparecer em cima de qualquer foto, clara ou escura, nos dois temas.
    function draw() {
        var context = state.canvas.getContext('2d'), sel = state.sel, rect = state.rect;
        context.clearRect(0, 0, SIDE, SIDE);
        context.imageSmoothingQuality = 'high';
        context.drawImage(state.image, rect.x, rect.y, rect.w, rect.h);

        // Escurece o que fica de fora.
        context.fillStyle = 'rgba(9, 9, 11, .62)';
        context.fillRect(0, 0, SIDE, sel.y);
        context.fillRect(0, sel.y + sel.size, SIDE, SIDE - sel.y - sel.size);
        context.fillRect(0, sel.y, sel.x, sel.size);
        context.fillRect(sel.x + sel.size, sel.y, SIDE - sel.x - sel.size, sel.size);

        context.lineWidth = 4;
        context.strokeStyle = 'rgba(9, 9, 11, .7)';
        context.strokeRect(sel.x, sel.y, sel.size, sel.size);
        context.lineWidth = 2;
        context.strokeStyle = '#fff';
        context.strokeRect(sel.x, sel.y, sel.size, sel.size);

        if (state.circle) {
            context.setLineDash([8, 8]);
            context.beginPath();
            context.arc(sel.x + sel.size / 2, sel.y + sel.size / 2, sel.size / 2 - 1, 0, Math.PI * 2);
            context.stroke();
            context.setLineDash([]);
        }

        // Alças nos cantos.
        var grip = 18;
        [[sel.x, sel.y], [sel.x + sel.size, sel.y], [sel.x, sel.y + sel.size], [sel.x + sel.size, sel.y + sel.size]].forEach(function (corner) {
            var x = Math.min(SIDE - grip, Math.max(0, corner[0] - grip / 2)), y = Math.min(SIDE - grip, Math.max(0, corner[1] - grip / 2));
            context.fillStyle = '#fff';
            context.fillRect(x, y, grip, grip);
            context.lineWidth = 2;
            context.strokeStyle = 'rgba(9, 9, 11, .8)';
            context.strokeRect(x, y, grip, grip);
        });

        state.range.value = String(Core.maxSize(state.box) / sel.size);
    }

    function set(sel) { state.sel = sel; draw(); }
    function moveBy(dx, dy) { set(Core.move(state.sel, dx, dy, state.box, SIDE)); }
    function sizeTo(size) { set(Core.resize(state.sel, size, state.box, SIDE)); }

    function wire(box, canvas, range) {
        var pointers = {}, pinch = null, drag = null;
        // Posição do ponteiro nas medidas internas do canvas (ele é mostrado menor que SIDE).
        function at(event) {
            var rect = canvas.getBoundingClientRect(), k = SIDE / rect.width;
            return { x: (event.clientX - rect.left) * k, y: (event.clientY - rect.top) * k };
        }
        function two() {
            var ids = Object.keys(pointers), a = pointers[ids[0]], b = pointers[ids[1]];
            return Math.hypot(a.x - b.x, a.y - b.y) || 1;
        }
        // No dedo o canto precisa de uma área maior para pegar.
        function reach(event) { return event.pointerType === 'touch' ? 56 : 30; }

        canvas.addEventListener('pointerdown', function (event) {
            if (!state) return;
            canvas.setPointerCapture(event.pointerId);
            var point = at(event);
            pointers[event.pointerId] = point;
            if (Object.keys(pointers).length === 2) {
                pinch = { distance: two(), size: state.sel.size };
                drag = null;
                return;
            }
            var grabbed = Core.hit(state.sel, point.x, point.y, reach(event));
            if (grabbed === 'move') drag = { mode: 'move' };
            else if (grabbed === 'new') drag = { mode: 'new', x: point.x, y: point.y, drawing: false };
            else { var fixed = Core.opposite(state.sel, grabbed); drag = { mode: 'corner', x: fixed.x, y: fixed.y }; }
        });
        canvas.addEventListener('pointermove', function (event) {
            if (!state) return;
            var now = at(event), before = pointers[event.pointerId];
            if (!before) {
                // Só passando o mouse: o cursor mostra o que o clique vai fazer.
                canvas.setAttribute('data-crop-cursor', Core.hit(state.sel, now.x, now.y, reach(event)));
                return;
            }
            pointers[event.pointerId] = now;
            if (pinch && Object.keys(pointers).length === 2) {
                sizeTo(pinch.size * two() / pinch.distance);
            } else if (drag && drag.mode === 'move') {
                moveBy(now.x - before.x, now.y - before.y);
            } else if (drag) {
                // Um clique solto fora da seleção não apaga o que estava marcado: só desenha depois de arrastar um pouco.
                if (drag.mode === 'new' && !drag.drawing && Math.hypot(now.x - drag.x, now.y - drag.y) < 10) return;
                drag.drawing = true;
                set(Core.fromAnchor(drag.x, drag.y, now.x, now.y, state.box, SIDE));
            }
            lastDrag = Date.now();
        });
        ['pointerup', 'pointercancel'].forEach(function (name) {
            canvas.addEventListener(name, function (event) { delete pointers[event.pointerId]; pinch = null; drag = null; });
        });
        canvas.addEventListener('wheel', function (event) {
            if (!state) return;
            event.preventDefault();
            sizeTo(state.sel.size * Math.exp(event.deltaY * 0.0015));
        }, { passive: false });
        canvas.addEventListener('keydown', function (event) {
            if (!state) return;
            var step = 16, moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            if (moves[event.key]) { moveBy(moves[event.key][0], moves[event.key][1]); event.preventDefault(); }
            if (event.key === '+' || event.key === '=') sizeTo(state.sel.size / 1.1);
            if (event.key === '-') sizeTo(state.sel.size * 1.1);
        });
        // Zoom maior = seleção menor (pega menos da imagem, mais de perto).
        range.addEventListener('input', function () { if (state) sizeTo(Core.maxSize(state.box) / parseFloat(range.value)); });

        box.addEventListener('click', function (event) {
            // Soltar o arrasto em cima do fundo conta como clique no fundo, que fecharia a janela (nav.js): ignora logo após arrastar.
            if (event.target === box && Date.now() - lastDrag < 400) event.stopPropagation();
            if (state && event.target.closest('[data-crop-reset]')) set(Core.start(state.box));
            if (event.target.closest('[data-crop-apply]')) apply();
        });
        // Fechou sem confirmar (X, Cancelar, Esc ou fundo): a escolha é desfeita e volta o recorte anterior, se havia um.
        box.addEventListener('close', function () {
            if (state && !state.done) put(state.input, state.input.__cropFile || null);
            state = null;
        });
    }

    // Troca o arquivo do campo (null esvazia).
    function put(input, file) {
        if (!file) { input.value = ''; return; }
        var transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
    }

    function note(input, message, error) {
        var scope = input.closest('[data-crop-box]') || input.form || document;
        var target = scope.querySelector('[data-crop-note]');
        if (!target) return;
        target.textContent = message;
        target.classList.toggle('is-error', !!error);
    }

    function refuse(input, message) {
        put(input, input.__cropFile || null);
        note(input, message, true);
    }

    function open(input, image, name) {
        var box = dialog();
        var fit = input.getAttribute('data-crop') === 'contain' ? 'contain' : 'cover';
        var rect = Core.layout(image.naturalWidth, image.naturalHeight, SIDE);
        state = {
            input: input, image: image, name: name, done: false, rect: rect, box: Core.limits(rect, SIDE, fit),
            circle: input.getAttribute('data-crop-shape') === 'circle',
            out: parseInt(input.getAttribute('data-crop-size'), 10) || 512,
            canvas: box.querySelector('canvas'), range: box.querySelector('input[type=range]'),
        };
        state.range.max = String(Core.maxSize(state.box) / Core.minSize(state.box, SIDE));
        box.querySelector('#crop-title').textContent = input.getAttribute('data-crop-title') || 'Recortar imagem';
        set(Core.start(state.box));
        box.showModal();
        state.canvas.focus();
    }

    function apply() {
        if (!state) return;
        var current = state, input = current.input;
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = current.out;
        var context = canvas.getContext('2d');
        var spot = Core.place(current.sel, current.rect, current.out);
        context.imageSmoothingQuality = 'high';
        context.drawImage(current.image, spot.x, spot.y, spot.w, spot.h);

        // WebP mantém a transparência e fica leve; navegador que não gera WebP devolve PNG.
        canvas.toBlob(function (blob) {
            if (!blob) { refuse(input, 'Não foi possível recortar a imagem. Tente outro arquivo.'); document.getElementById('crop-dialog').close(); return; }
            var base = current.name.replace(/\.[^.]+$/, '') || 'imagem';
            var file = new File([blob], base + (blob.type === 'image/webp' ? '.webp' : '.png'), { type: blob.type });
            put(input, file);
            input.__cropFile = file;

            var preview = input.getAttribute('data-crop-preview') && document.querySelector(input.getAttribute('data-crop-preview'));
            if (preview) {
                var shown = document.createElement('img');
                shown.alt = '';
                shown.src = canvas.toDataURL(blob.type); // data: (e não blob:) é o que a CSP permite em imagens
                preview.textContent = '';
                preview.appendChild(shown);
                preview.classList.add('has-photo');
            }
            note(input, 'Recorte pronto. Falta salvar.', false);

            current.done = true;
            document.getElementById('crop-dialog').close();
            // Avisa os outros scripts do formulário (ex.: prévia da premiação) já com o arquivo recortado.
            input.__cropReady = true;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, 'image/webp', 0.92);
    }

    // Na captura: chega antes dos outros ouvintes de "change", que só veem o arquivo depois do recorte.
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('input[type=file][data-crop]')) return;
        if (input.__cropReady) { input.__cropReady = false; return; }
        var file = input.files && input.files[0];
        if (!file) return;
        event.stopPropagation();

        if (TYPES.indexOf(file.type) === -1) return refuse(input, 'Envie uma imagem JPG, PNG ou WebP.');
        if (file.size > MAX_BYTES) return refuse(input, 'Essa imagem é grande demais para abrir aqui. Escolha uma de até 25 MB.');

        var reader = new FileReader();
        reader.onerror = function () { refuse(input, 'Não foi possível ler a imagem. Tente outro arquivo.'); };
        reader.onload = function () {
            var image = new Image();
            image.onerror = reader.onerror;
            image.onload = function () { open(input, image, file.name); };
            image.src = reader.result;
        };
        reader.readAsDataURL(file);
    }, true);
})();
