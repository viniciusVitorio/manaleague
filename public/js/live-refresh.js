// Telas ao vivo: a pagina declara o que mostra (@section('live-scope')) e o layout grava a versao com que
// ela foi montada em #live-refresh. A cada poucos segundos este script pergunta a versao atual; quando muda
// (o amigo aceitou o desafio, o adversario mandou o placar, a rodada virou...), a pagina e atualizada no lugar
// com ManaLeague.refresh(), sem recarregar. Se a pessoa estiver digitando num formulario, aparece um aviso
// com o botao "Atualizar" em vez de mexer na tela. Aba escondida nao pergunta nada.
(function () {
    'use strict';

    var INTERVAL = 5000;
    var MAX_DELAY = 60000;
    var timer = null;
    var delay = INTERVAL;
    var dirty = false;
    var busy = false;

    if (!window.fetch) return;

    function source() { return document.getElementById('live-refresh'); }

    function schedule(ms) {
        clearTimeout(timer);
        timer = setTimeout(poll, ms);
    }

    // Digitando, com um <dialog> aberto ou no meio de um envio: nao troca a tela por baixo da pessoa.
    function editing() {
        var active = document.activeElement;
        if (active && active.closest && active.closest('form') && active.matches('textarea, select, input:not([type=hidden]):not([type=radio]):not([type=checkbox]):not([type=submit]):not([type=button])')) return true;
        return dirty || !!document.querySelector('dialog[open], form[aria-busy="true"]');
    }

    function notice() {
        var box = document.getElementById('live-notice');
        if (box) return;
        box = document.createElement('div');
        box.id = 'live-notice';
        box.className = 'live-notice';
        box.setAttribute('data-async-keep', '');
        box.setAttribute('role', 'status');
        box.innerHTML = '<span>Tem novidade nesta página.</span> <button type="button" class="ui-btn ui-btn-primary ui-btn-sm">Atualizar</button>';
        box.querySelector('button').addEventListener('click', refresh);
        document.body.appendChild(box);
    }

    function clearNotice() {
        var box = document.getElementById('live-notice');
        if (box) box.remove();
    }

    function refresh() {
        if (!window.ManaLeague || !window.ManaLeague.refresh) { location.reload(); return; }
        clearNotice();
        dirty = false;
        busy = true;
        window.ManaLeague.refresh().then(function () {
            if (window.ManaLeague.toast) window.ManaLeague.toast('Novidades: a página foi atualizada.');
        }).catch(function () {}).then(function () { busy = false; });
    }

    function poll() {
        var el = source();
        if (!el || !el.dataset.url || !el.dataset.version) return; // a tela nao e ao vivo (ou deixou de ser)
        if (document.hidden) return; // volta quando a aba aparecer
        if (busy) { schedule(INTERVAL); return; }

        fetch(el.dataset.url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                if ([401, 403, 404].indexOf(response.status) !== -1) return null; // sessao saiu ou perdeu acesso: para
                if (!response.ok) throw new Error('offline');
                return response.json();
            })
            .then(function (data) {
                if (!data) return;
                delay = INTERVAL;
                var current = source();
                if (current && data.v && data.v !== current.dataset.version) {
                    current.dataset.version = data.v; // a nova pagina traz a mesma versao (ou uma mais nova)
                    if (editing()) notice(); else refresh();
                }
                schedule(delay);
            })
            .catch(function () {
                delay = Math.min(delay * 2, MAX_DELAY);
                schedule(delay);
            });
    }

    document.addEventListener('input', function (event) {
        if (event.target && event.target.closest && event.target.closest('form')) dirty = true;
    }, true);
    // Depois de um envio (ou de uma atualizacao) a tela e nova: o que estava digitado foi enviado ou descartado.
    document.addEventListener('manaleague:updated', function () { dirty = false; clearNotice(); schedule(INTERVAL); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(300); });

    schedule(INTERVAL);
})();
