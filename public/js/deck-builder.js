// Construtor de deck: busca de cartas com autocomplete.
// Seguranca: todo conteudo vindo da API e inserido com textContent/atributos (nunca innerHTML),
// e o formulario envia apenas o ID da carta — o servidor busca os dados oficiais no Scryfall.
(function () {
    'use strict';

    function init() {
        var input = document.getElementById('card-search');
        if (!input) return;

        var results = document.getElementById('card-results');
        var status = document.getElementById('card-search-status');
        var form = document.getElementById('add-card-form');
        var foilBox = document.getElementById('add-foil');
        var isCommander = input.dataset.commander === '1';
        var timer = null;
        var controller = null;

        function setStatus(text) { status.textContent = text; }

        function button(label, board, card, primary) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-small ' + (primary ? 'btn-primary' : 'btn-ghost');
            b.textContent = label;
            b.addEventListener('click', function () {
                form.elements.scryfall_id.value = card.scryfall_id;
                form.elements.board.value = board;
                // Da coleção, o resultado já diz se é foil; no Scryfall vale a caixa "Adicionar como foil".
                var foil = typeof card.foil === 'boolean' ? card.foil : !!(foilBox && foilBox.checked);
                if (form.elements.foil) form.elements.foil.value = foil ? '1' : '0';
                b.disabled = true;
                b.textContent = 'Adicionando…';
                // A lista de resultados fica na tela (data-async-keep): da para adicionar varias cartas seguidas.
                document.addEventListener('manaleague:updated', function () { b.disabled = false; b.textContent = label; }, { once: true });
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            });
            return b;
        }

        function render(cards) {
            results.replaceChildren();
            if (!cards.length) { setStatus('Nenhuma carta encontrada.'); return; }
            setStatus(cards.length + (cards.length === 1 ? ' carta encontrada.' : ' cartas encontradas.'));

            cards.forEach(function (card) {
                var row = document.createElement('div');
                row.className = 'card-result';
                row.setAttribute('role', 'listitem');

                if (card.image_url) {
                    var img = document.createElement('img');
                    img.src = card.image_url;
                    img.alt = '';
                    img.loading = 'lazy';
                    img.width = 61; img.height = 85;
                    row.appendChild(img);
                }

                var info = document.createElement('div');
                info.className = 'card-result-info';
                var name = document.createElement('strong');
                name.textContent = card.printed_name && card.printed_name !== card.name ? card.printed_name + ' (' + card.name + ')' : card.name;
                var meta = document.createElement('small');
                meta.textContent = [card.type_line, card.mana_cost, card.set_code, card.foil ? 'Foil' : null, card.owned ? 'Você tem ' + card.owned : null].filter(Boolean).join(' · ');
                info.appendChild(name);
                info.appendChild(meta);

                var actions = document.createElement('div');
                actions.className = 'card-result-actions';
                actions.appendChild(button('+ Deck', 'main', card, true));
                actions.appendChild(button('+ Side', 'side', card, false));
                if (isCommander && /Legendary/.test(card.type_line || '') && /(Creature|Planeswalker|Background)/.test(card.type_line || '')) {
                    actions.appendChild(button('Comandante', 'commander', card, false));
                }
                info.appendChild(actions);
                row.appendChild(info);
                results.appendChild(row);
            });
        }

        function search(term) {
            if (controller) controller.abort();
            controller = new AbortController();
            setStatus('Buscando…');

            fetch(input.dataset.endpoint + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal
            }).then(function (response) {
                if (response.status === 429) throw new Error('Muitas buscas seguidas. Aguarde alguns segundos.');
                if (!response.ok) throw new Error('Não foi possível buscar agora.');
                return response.json();
            }).then(function (payload) {
                render(payload.data || []);
            }).catch(function (error) {
                if (error.name !== 'AbortError') setStatus(error.message);
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var term = input.value.trim();
            if (term.length < 2) { results.replaceChildren(); setStatus('Digite ao menos 2 letras.'); return; }
            timer = setTimeout(function () { search(term); }, 350);
        });

        // Onde buscar: Scryfall ou a coleção da pessoa (os botões só aparecem para quem tem coleção).
        var sources = document.querySelectorAll('[data-search-source]');
        sources.forEach(function (source) {
            source.addEventListener('click', function () {
                if (source.getAttribute('aria-pressed') === 'true') return;
                sources.forEach(function (other) {
                    var on = other === source;
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                    other.classList.toggle('btn-secondary', on);
                    other.classList.toggle('btn-ghost', !on);
                });
                input.dataset.endpoint = source.dataset.searchSource;
                if (foilBox) foilBox.closest('label').hidden = source.dataset.source === 'collection';
                input.placeholder = source.dataset.placeholder;
                clearTimeout(timer);
                var term = input.value.trim();
                if (term.length >= 2) search(term);
                else { results.replaceChildren(); setStatus(source.dataset.hint); }
                input.focus();
            });
        });
    }

    // Arrastar cartas entre deck e sideboard. Com mais de uma cópia, um mini menu pergunta quantas mover.
    // Os botões "↓ side" / "↑ deck" continuam como alternativa (teclado e celular, onde arrastar não funciona).
    function initBoardDrag() {
        if (!document.querySelector('[data-drop-board]')) return;
        function zones() { return document.querySelectorAll('[data-drop-board]'); }

        var token = document.querySelector('meta[name="csrf-token"]');
        var dragged = null;
        var chooser = null;

        function closeChooser() {
            if (chooser) { chooser.remove(); chooser = null; }
        }

        function move(line, board, copies) {
            closeChooser();
            line.classList.add('is-moving');
            var body = new URLSearchParams({ _method: 'PATCH', board: board, move: String(copies) });
            fetch(line.dataset.moveUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token ? token.content : '' },
                credentials: 'same-origin',
                body: body
            }).then(function (response) {
                if (!response.ok) throw new Error();
                if (window.ManaLeague && window.ManaLeague.refresh) return window.ManaLeague.refresh();
                window.location.reload();
            }).then(function () {
                line.classList.remove('is-moving');
            }).catch(function () {
                line.classList.remove('is-moving');
                window.alert('Não foi possível mover a carta. Recarregue a página e tente de novo.');
            });
        }

        function choose(line, board, x, y) {
            closeChooser();
            var qty = parseInt(line.dataset.qty, 10) || 1;
            chooser = document.createElement('div');
            chooser.className = 'move-chooser';
            chooser.setAttribute('role', 'dialog');
            chooser.setAttribute('aria-label', 'Quantas cópias mover');

            var label = document.createElement('span');
            label.textContent = 'Mover quantas?';
            chooser.appendChild(label);

            var options = [];
            for (var n = 1; n < qty && n <= 4; n++) options.push(n);
            options.push(qty);
            options.forEach(function (copies) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'qty-btn' + (copies === qty ? ' wide' : '');
                b.textContent = copies === qty ? 'Todas (' + qty + ')' : String(copies);
                b.addEventListener('click', function () { move(line, board, copies); });
                chooser.appendChild(b);
            });

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'qty-btn';
            cancel.textContent = '✕';
            cancel.setAttribute('aria-label', 'Cancelar');
            cancel.addEventListener('click', closeChooser);
            chooser.appendChild(cancel);

            document.body.appendChild(chooser);
            // Posiciona perto de onde a carta foi solta, sem sair da tela.
            var rect = chooser.getBoundingClientRect();
            chooser.style.left = Math.max(8, Math.min(x - rect.width / 2, window.innerWidth - rect.width - 8)) + 'px';
            chooser.style.top = Math.max(8, Math.min(y + 12, window.innerHeight - rect.height - 8)) + 'px';
            chooser.querySelector('button').focus();
        }

        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeChooser(); });
        document.addEventListener('mousedown', function (event) { if (chooser && !chooser.contains(event.target)) closeChooser(); });
        window.addEventListener('scroll', closeChooser, { passive: true });

        // Delegado no document: cartas e zonas que chegam numa atualizacao sem recarregar continuam arrastaveis.
        function lineOf(event) { return event.target.closest && event.target.closest('[data-move-url]'); }
        function zoneOf(event) { return event.target.closest && event.target.closest('[data-drop-board]'); }
        function accepts(zone) { return zone && dragged && dragged.dataset.board !== zone.dataset.dropBoard; }

        document.addEventListener('dragstart', function (event) {
            var line = lineOf(event);
            if (!line) return;
            closeChooser();
            dragged = line;
            line.classList.add('is-dragging');
            document.body.classList.add('is-dragging-card');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', line.dataset.name || '');
        });
        document.addEventListener('dragend', function (event) {
            var line = lineOf(event);
            if (line) line.classList.remove('is-dragging');
            document.body.classList.remove('is-dragging-card');
            zones().forEach(function (zone) { zone.classList.remove('is-drop-target'); });
            dragged = null;
        });
        document.addEventListener('dragover', function (event) {
            var zone = zoneOf(event);
            if (!accepts(zone)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            zone.classList.add('is-drop-target');
        });
        document.addEventListener('dragleave', function (event) {
            var zone = zoneOf(event);
            if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove('is-drop-target');
        });
        document.addEventListener('drop', function (event) {
            var zone = zoneOf(event);
            if (!accepts(zone)) return;
            event.preventDefault();
            zone.classList.remove('is-drop-target');
            var line = dragged;
            var board = zone.dataset.dropBoard;
            if ((parseInt(line.dataset.qty, 10) || 1) > 1) choose(line, board, event.clientX, event.clientY);
            else move(line, board, 1);
        });
    }

    function boot() { init(); initBoardDrag(); }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
