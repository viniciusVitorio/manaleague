// Escanear carta pela camera (construtor de deck e contador de vida).
//
// Como funciona: a camera fica no navegador (nada e transmitido ou gravado); ao tocar na carta,
// um unico frame vai para POST /cards/scan junto com a posicao do toque. O servidor responde com
// os dados oficiais da carta (que ele busca no Scryfall pelo ID devolvido pelo reconhecedor).
//
// Seguranca: todo texto vindo da resposta entra por textContent (nunca innerHTML) e as imagens
// usam a URL que o servidor validou (so cards.scryfall.io, igual a CSP). O frame capturado e
// descartado depois do envio.
(function () {
    'use strict';

    var MODE_DECK = 'deck';

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function button(cls, text, label) {
        var b = el('button', cls, text);
        b.type = 'button';
        if (label) b.setAttribute('aria-label', label);
        return b;
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    // ---------- a janela da camera ----------
    function Scanner(trigger) {
        this.endpoint = trigger.dataset.endpoint;
        this.mode = trigger.dataset.mode || MODE_DECK;
        this.allowCommander = trigger.dataset.commander === '1';
        this.stream = null;
        this.busy = false;
        this.build();
    }

    Scanner.prototype.build = function () {
        var self = this;

        // data-async-keep: a janela sobrevive a atualizacao da pagina sem recarregar (async-forms),
        // entao da para escanear varias cartas seguidas sem reabrir a camera.
        this.root = el('div', 'scan-overlay');
        this.root.setAttribute('data-async-keep', '');
        this.root.setAttribute('role', 'dialog');
        this.root.setAttribute('aria-modal', 'true');
        this.root.setAttribute('aria-label', 'Escanear carta pela camera');
        this.root.hidden = true;

        this.video = el('video', 'scan-video');
        this.video.autoplay = true;
        this.video.muted = true;
        this.video.playsInline = true;
        this.video.setAttribute('playsinline', '');

        this.outline = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.outline.setAttribute('class', 'scan-outline');
        this.outline.setAttribute('viewBox', '0 0 100 100');
        this.outline.setAttribute('preserveAspectRatio', 'none');
        this.polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        this.outline.appendChild(this.polygon);

        this.stage = el('div', 'scan-stage');
        this.stage.append(this.video, this.outline);
        this.stage.addEventListener('click', function (event) { self.scan(event); });

        this.status = el('p', 'scan-status', 'Toque na carta para identificar.');
        this.status.setAttribute('role', 'status');
        this.result = el('div', 'scan-result');
        this.result.hidden = true;

        var close = button('btn btn-ghost btn-small scan-close', '✕ Fechar', 'Fechar a camera');
        close.addEventListener('click', function () { self.close(); });

        var bar = el('div', 'scan-bar');
        bar.append(el('strong', null, 'Escanear carta'), close);

        var panel = el('div', 'scan-panel');
        panel.append(bar, this.stage, this.status, this.result);
        this.root.append(panel);
        document.body.appendChild(this.root);

        this.onKey = function (event) { if (event.key === 'Escape') self.close(); };
    };

    Scanner.prototype.setStatus = function (text, kind) {
        this.status.textContent = text;
        this.status.className = 'scan-status' + (kind ? ' is-' + kind : '');
    };

    Scanner.prototype.open = function () {
        var self = this;
        this.root.hidden = false;
        document.body.classList.add('scan-open');
        document.addEventListener('keydown', this.onKey);
        this.result.hidden = true;
        this.result.replaceChildren();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.setStatus('Este navegador não dá acesso à câmera. Use o celular ou busque a carta pelo nome.', 'error');
            return;
        }

        this.setStatus('Pedindo acesso à câmera…');
        navigator.mediaDevices.getUserMedia({
            // facingMode "environment" = camera de tras no celular, que e a que aponta para a mesa.
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
            audio: false
        }).then(function (stream) {
            self.stream = stream;
            self.video.srcObject = stream;
            // iOS nem sempre inicia sozinho, mesmo com autoplay + playsinline.
            var playing = self.video.play();
            if (playing && playing.catch) playing.catch(function () { /* o autoplay cobre o caso normal */ });
            self.setStatus('Toque na carta para identificar.');
        }).catch(function (error) {
            var message = error && error.name === 'NotAllowedError'
                ? 'Acesso à câmera negado. Libere a câmera para este site nas permissões do navegador.'
                : (window.isSecureContext === false
                    ? 'A câmera só funciona em HTTPS (ou em localhost).'
                    : 'Não foi possível abrir a câmera. Verifique se outro aplicativo está usando.');
            self.setStatus(message, 'error');
        });
    };

    Scanner.prototype.close = function () {
        this.root.hidden = true;
        document.body.classList.remove('scan-open');
        document.removeEventListener('keydown', this.onKey);
        this.polygon.removeAttribute('points');
        if (this.stream) {
            this.stream.getTracks().forEach(function (track) { track.stop(); });
            this.stream = null;
        }
        this.video.srcObject = null;
    };

    // Onde a pessoa tocou, em fracoes 0-1 da imagem da camera. O video usa object-fit: contain,
    // entao as bordas vazias (letterbox) precisam sair da conta.
    Scanner.prototype.point = function (event) {
        var rect = this.video.getBoundingClientRect();
        var vw = this.video.videoWidth, vh = this.video.videoHeight;
        if (!vw || !vh || !rect.width || !rect.height) return null;

        var scale = Math.min(rect.width / vw, rect.height / vh);
        var shownW = vw * scale, shownH = vh * scale;
        var x = (event.clientX - rect.left - (rect.width - shownW) / 2) / shownW;
        var y = (event.clientY - rect.top - (rect.height - shownH) / 2) / shownH;
        if (x < 0 || x > 1 || y < 0 || y > 1) return null;

        return { x: Math.round(x * 10000) / 10000, y: Math.round(y * 10000) / 10000 };
    };

    Scanner.prototype.frame = function () {
        var canvas = document.createElement('canvas');
        canvas.width = this.video.videoWidth;
        canvas.height = this.video.videoHeight;
        canvas.getContext('2d').drawImage(this.video, 0, 0);

        return new Promise(function (resolve) {
            canvas.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', 0.85);
        });
    };

    Scanner.prototype.scan = function (event) {
        var self = this;
        if (this.busy || !this.stream) return;
        var point = this.point(event);
        if (!point) return;

        this.busy = true;
        this.root.classList.add('is-busy');
        this.polygon.removeAttribute('points');
        this.setStatus('Identificando…');

        this.frame().then(function (blob) {
            if (!blob) throw new Error('Não foi possível capturar a imagem da câmera.');
            var body = new FormData();
            body.append('image', blob, 'frame.jpg');
            body.append('x', String(point.x));
            body.append('y', String(point.y));

            return fetch(self.endpoint, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
                body: body
            });
        }).then(function (response) {
            if (response.status === 429) throw new Error('Muitos escaneamentos seguidos. Espere alguns segundos.');
            if (response.status === 413) throw new Error('A imagem da câmera ficou grande demais. Tente em uma resolução menor.');
            if (response.status === 419) throw new Error('Sua sessão expirou. Recarregue a página.');
            return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; });
        }).then(function (data) {
            self.show(data.payload, data.ok);
        }).catch(function (error) {
            self.setStatus(error.message || 'Falha de rede ao enviar a imagem.', 'error');
        }).then(function () {
            self.busy = false;
            self.root.classList.remove('is-busy');
        });
    };

    Scanner.prototype.show = function (payload, ok) {
        this.result.replaceChildren();
        this.result.hidden = true;

        if (!ok || payload.status === 'unavailable' || payload.status === 'no_match') {
            this.setStatus(payload.message || 'Não reconheci a carta.', payload.status === 'no_match' ? 'warn' : 'error');
            return;
        }

        if (payload.status === 'unsure') {
            this.setStatus(payload.message || 'Não tenho certeza. É uma destas?', 'warn');
            this.drawOutline(payload.corners);
            var row = el('div', 'scan-suggestions');
            var self = this;
            (payload.suggestions || []).forEach(function (card) {
                var pick = button('scan-pick', '');
                if (card.image_url) {
                    var thumb = el('img');
                    thumb.src = card.image_url;
                    thumb.alt = '';
                    thumb.width = 86;
                    thumb.height = 120;
                    pick.appendChild(thumb);
                }
                pick.appendChild(el('span', null, card.name));
                pick.addEventListener('click', function () { self.accept(card); });
                row.appendChild(pick);
            });
            this.result.appendChild(row);
            this.result.hidden = false;
            return;
        }

        this.drawOutline(payload.corners);
        this.accept(payload.card);
    };

    Scanner.prototype.drawOutline = function (corners) {
        if (!corners || corners.length !== 4) return;
        this.polygon.setAttribute('points', corners.map(function (p) {
            return (p[0] * 100).toFixed(2) + ',' + (p[1] * 100).toFixed(2);
        }).join(' '));
    };

    // Carta confirmada: no construtor de deck da para adiciona-la; no contador de vida e consulta.
    Scanner.prototype.accept = function (card) {
        this.setStatus(card.name, 'ok');
        this.result.replaceChildren();

        var box = el('div', 'scan-card');
        if (card.image_url) {
            var img = el('img');
            img.src = card.image_url;
            img.alt = card.name;
            img.width = 146;
            img.height = 204;
            box.appendChild(img);
        }

        var info = el('div', 'scan-card-info');
        info.appendChild(el('strong', null, card.printed_name && card.printed_name !== card.name
            ? card.printed_name + ' (' + card.name + ')' : card.name));
        info.appendChild(el('small', null, [card.type_line, card.mana_cost, card.set_code].filter(Boolean).join(' · ')));

        if (this.mode === MODE_DECK) {
            info.appendChild(this.deckActions(card));
        } else {
            info.appendChild(el('small', 'muted', 'Dados oficiais do Scryfall.'));
        }

        box.appendChild(info);
        this.result.appendChild(box);
        this.result.hidden = false;
    };

    // Reaproveita o formulario escondido do construtor de deck (#add-card-form): mesma rota,
    // mesmo CSRF e mesmas validacoes do botao "+ Deck" da busca por nome.
    Scanner.prototype.deckActions = function (card) {
        var form = document.getElementById('add-card-form');
        var actions = el('div', 'scan-actions');
        if (!form) return actions;

        var self = this;
        var add = function (label, board, primary) {
            var b = button('btn btn-small ' + (primary ? 'btn-primary' : 'btn-ghost'), label);
            b.addEventListener('click', function () {
                form.elements.scryfall_id.value = card.scryfall_id;
                form.elements.board.value = board;
                b.disabled = true;
                b.textContent = 'Adicionando…';
                document.addEventListener('manaleague:updated', function () {
                    b.disabled = false;
                    b.textContent = label;
                    self.setStatus(card.name + ' adicionada. Toque na próxima carta.', 'ok');
                }, { once: true });
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            });
            return b;
        };

        actions.appendChild(add('+ Deck', 'main', true));
        actions.appendChild(add('+ Side', 'side', false));
        var type = card.type_line || '';
        if (this.allowCommander && /Legendary/.test(type) && /(Creature|Planeswalker|Background)/.test(type)) {
            actions.appendChild(add('Comandante', 'commander', false));
        }

        return actions;
    };

    // ---------- ligacao com a pagina ----------
    // Uma unica janela por pagina: o botao pode ser recriado por uma atualizacao sem recarregar,
    // e criar outra janela a cada clique deixaria cameras penduradas no DOM.
    var scanner = null;

    function handle(event) {
        var trigger = event.target.closest ? event.target.closest('[data-card-scanner]') : null;
        if (!trigger) return;
        event.preventDefault();

        if (!scanner) {
            scanner = new Scanner(trigger);
        } else {
            scanner.endpoint = trigger.dataset.endpoint;
            scanner.mode = trigger.dataset.mode || MODE_DECK;
            scanner.allowCommander = trigger.dataset.commander === '1';
        }
        scanner.open();
    }

    // Delegado no document: o botao pode voltar numa atualizacao sem recarregar a pagina.
    document.addEventListener('click', handle);
})();
