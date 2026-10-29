// Formularios assincronos: todo <form method="POST"> das telas com o layout principal e enviado com fetch(),
// sem recarregar a pagina. O servidor responde como sempre (redirect + mensagem flash); o middleware
// AsyncFormRedirects transforma o redirect em {"redirect": url}. Se a URL final e a mesma pagina, ela e
// buscada de novo e o <body> e atualizado no lugar (Idiomorph): rolagem, foco e <details> abertos ficam como
// estavam. Se e outra pagina (criar torneio, login, excluir...), o navegador navega normalmente.
//
// Fica de fora: GET (busca/filtros), target="_blank", outro dominio e quem marcar data-async="off"
// (no <form> ou no botao). Elementos com data-async-keep nao sao tocados pela atualizacao.
// Depois de cada atualizacao o documento recebe o evento "manaleague:updated".
(function () {
    'use strict';

    var HEADER = 'X-ManaLeague-Async';
    var pending = new WeakSet();

    if (!window.fetch || !window.FormData || !window.DOMParser || !window.Idiomorph) return;

    function samePage(url) {
        var u = new URL(url, location.href);
        return u.origin === location.origin && u.pathname === location.pathname && u.search === location.search;
    }

    function attr(form, submitter, name, formName) {
        return (submitter && submitter.getAttribute(formName)) || form.getAttribute(name);
    }

    function eligible(form, submitter) {
        if (!(form instanceof HTMLFormElement)) return false;
        if (form.dataset.async === 'off' || (submitter && submitter.dataset && submitter.dataset.async === 'off')) return false;
        if ((attr(form, submitter, 'method', 'formmethod') || 'get').toLowerCase() !== 'post') return false;
        var target = attr(form, submitter, 'target', 'formtarget');
        if (target && target !== '_self') return false;
        var action = new URL(attr(form, submitter, 'action', 'formaction') || location.href, location.href);
        return action.origin === location.origin;
    }

    function formData(form, submitter) {
        try {
            return new FormData(form, submitter || undefined);
        } catch (e) {
            var data = new FormData(form);
            if (submitter && submitter.name) data.append(submitter.name, submitter.value);
            return data;
        }
    }

    // ---------- avisos (toast) ----------
    function toastRegion() {
        var region = document.getElementById('async-toasts');
        if (!region) {
            region = document.createElement('div');
            region.id = 'async-toasts';
            region.className = 'async-toasts';
            region.setAttribute('data-async-keep', '');
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        return region;
    }

    function toast(text, tone) {
        text = (text || '').replace(/\s+/g, ' ').trim();
        if (!text) return;
        var item = document.createElement('div');
        item.className = 'async-toast async-toast-' + (tone || 'success');
        item.textContent = text;
        toastRegion().appendChild(item);
        setTimeout(function () { item.classList.add('is-leaving'); }, 4200);
        setTimeout(function () { item.remove(); }, 4600);
    }

    function inViewport(el) {
        var r = el.getBoundingClientRect();
        return r.bottom > 0 && r.top < window.innerHeight;
    }

    // Mensagens do topo da pagina (status/erros) que ficaram fora da tela tambem aparecem como aviso.
    function announce() {
        document.querySelectorAll('main > .alert').forEach(function (alert) {
            if (inViewport(alert)) return;
            toast(alert.textContent, alert.classList.contains('alert-danger') ? 'danger' : 'success');
        });
    }

    // ---------- atualizacao da pagina ----------
    function isKept(node) {
        return node.nodeType === 1 && node.hasAttribute('data-async-keep');
    }

    // <details id="..."> abertos/fechados pela pessoa continuam assim depois da atualizacao.
    function detailsState() {
        var state = {};
        document.querySelectorAll('details[id]').forEach(function (d) { state[d.id] = d.open; });
        return state;
    }

    function restoreDetails(state) {
        Object.keys(state).forEach(function (id) {
            var d = document.getElementById(id);
            if (d && d.tagName === 'DETAILS' && d.open !== state[id]) d.open = state[id];
        });
    }

    function morph(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var opened = detailsState();
        if (!doc.body) { location.reload(); return; }
        if (doc.title) document.title = doc.title;
        ['csrf-token', 'theme-color'].forEach(function (name) {
            var fresh = doc.querySelector('meta[name="' + name + '"]');
            var current = document.querySelector('meta[name="' + name + '"]');
            if (fresh && current) current.setAttribute('content', fresh.getAttribute('content'));
        });
        // Atributos do <html> (ex.: data-theme depois de trocar o tema nas preferencias).
        Array.prototype.forEach.call(doc.documentElement.attributes, function (a) {
            if (document.documentElement.getAttribute(a.name) !== a.value) document.documentElement.setAttribute(a.name, a.value);
        });
        // Um <dialog> aberto (ex.: escolher deck da vitrine) fecha pelo jeito certo antes da atualizacao.
        document.querySelectorAll('dialog[open]').forEach(function (d) { if (typeof d.close === 'function') d.close(); });

        // Passa o HTML interno (string): um <body> de outro documento seria tratado como um filho novo
        // e a pagina inteira seria trocada, perdendo foco, ouvintes e o estado dos <details>.
        window.Idiomorph.morph(document.body, doc.body.innerHTML, {
            morphStyle: 'innerHTML',
            ignoreActiveValue: true,
            restoreFocus: true,
            callbacks: {
                // Quem abriu/fechou uma rodada continua vendo do mesmo jeito.
                beforeAttributeUpdated: function (name, node) {
                    return !(name === 'open' && node.tagName === 'DETAILS');
                },
                beforeNodeMorphed: function (oldNode) { return !isKept(oldNode); },
                beforeNodeRemoved: function (node) { return !isKept(node); }
            }
        });
        restoreDetails(opened);
        announce();
        document.dispatchEvent(new CustomEvent('manaleague:updated'));
    }

    function handle(response, depth) {
        var type = response.headers.get('Content-Type') || '';

        if (response.ok && type.indexOf('application/json') !== -1) {
            return response.json().then(function (json) {
                if (!json || !json.redirect) { location.reload(); return; }
                if (samePage(json.redirect) && depth < 3) return load(json.redirect, depth + 1);
                location.assign(json.redirect);
            });
        }
        if (response.redirected && !samePage(response.url)) {
            location.assign(response.url);
            return;
        }
        if (response.ok && type.indexOf('text/html') !== -1) {
            return response.text().then(morph);
        }
        if (response.status === 419) {
            toast('Sua sessão expirou. Recarregue a página e tente de novo.', 'danger');
        } else if (response.status === 403 && response.headers.get('X-WAF-Block')) {
            toast('O firewall bloqueou esta requisição. Tente de novo sem caracteres especiais.', 'danger');
        } else if (response.status === 429) {
            toast('Muitas tentativas seguidas. Aguarde um minuto e tente de novo.', 'danger');
        } else if (response.status === 403) {
            toast('Você não tem permissão para fazer isso.', 'danger');
        } else {
            toast('Não foi possível concluir (erro ' + response.status + '). Tente de novo.', 'danger');
        }
    }

    function load(url, depth) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html', 'X-ManaLeague-Async': '1' }
        }).then(function (response) { return handle(response, depth || 0); });
    }

    // O botao fica desabilitado durante o envio. Se a pagina foi atualizada, o servidor ja definiu o estado
    // certo do botao (a atualizacao remove data-async-busy) e nada e restaurado aqui.
    function setBusy(form, submitter, busy) {
        form.classList.toggle('is-submitting', busy);
        if (busy) form.setAttribute('aria-busy', 'true'); else form.removeAttribute('aria-busy');
        if (!submitter || !('disabled' in submitter)) return;
        if (busy) {
            if (submitter.disabled) return;
            submitter.setAttribute('data-async-busy', '');
            submitter.disabled = true;
        } else if (submitter.hasAttribute('data-async-busy')) {
            submitter.removeAttribute('data-async-busy');
            submitter.disabled = false;
        }
    }

    function send(form, submitter) {
        var body = formData(form, submitter);
        var action = attr(form, submitter, 'action', 'formaction') || location.href;
        pending.add(form);
        setBusy(form, submitter, true);

        var headers = { 'Accept': 'text/html, application/json;q=0.9' };
        headers[HEADER] = '1';

        return fetch(action, { method: 'POST', body: body, credentials: 'same-origin', headers: headers })
            .then(function (response) { return handle(response, 0); })
            .catch(function () { toast('Sem conexão com o servidor. Confira a internet e tente de novo.', 'danger'); })
            .then(function () {
                pending.delete(form);
                setBusy(form, submitter, false);
            });
    }

    // Ouvinte na window (fase de bolha): roda depois dos confirm() e dos scripts de cada tela,
    // que continuam podendo cancelar o envio com preventDefault().
    window.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        var form = event.target;
        var submitter = event.submitter || null;
        if (!eligible(form, submitter)) return;
        event.preventDefault();
        if (pending.has(form)) return;
        send(form, submitter);
    });

    window.ManaLeague = window.ManaLeague || {};
    // Atualiza a pagina atual sem recarregar (ex.: depois de um fetch proprio de um modulo).
    window.ManaLeague.refresh = function () { return load(location.href, 0); };
    window.ManaLeague.toast = toast;
})();
