/* Tempo real: cliente pequeno do protocolo do Pusher, falado pelo Laravel Reverb (sem biblioteca externa;
 * a CSP so libera 'self'). Abre um WebSocket na mesma origem (/app/{key}, o Caddy encaminha para o Reverb),
 * assina canais e entrega os eventos. Canal "private-*" pede autorizacao em /broadcasting/auth (sessao + CSRF).
 *
 * Uso: ManaLeagueRealtime.on('private-user.5', 'changed', fn); o evento 'subscribed' avisa quando a assinatura
 * (re)comeca, hora de buscar o que mudou enquanto estava fora. onState(fn) recebe 'connected' | 'connecting' | 'offline'. Caiu a conexao: tenta de novo sozinho (espera crescente) e assina
 * tudo outra vez. Quem usa sempre tem plano B (polling): o socket so deixa a tela mais rapida.
 * Sem <meta name="realtime"> (Reverb desligado), ManaLeagueRealtime fica null. */
(function (global) {
    'use strict';

    var meta = document.querySelector('meta[name="realtime"]');
    if (!meta || !global.WebSocket || !global.fetch) { global.ManaLeagueRealtime = null; return; }

    var cfg;
    try { cfg = JSON.parse(meta.getAttribute('content')); } catch (e) { global.ManaLeagueRealtime = null; return; }

    var ws = null;
    var socketId = null;
    var state = 'offline';
    var attempts = 0;
    var retryTimer = null;
    var idleTimer = null;
    var pongTimer = null;
    var stopped = false;
    var channels = {}; // nome -> { events: { evento: [fn] }, subscribed: bool }
    var stateListeners = [];

    function csrf() {
        var tag = document.querySelector('meta[name="csrf-token"]');
        return tag ? tag.getAttribute('content') : '';
    }

    function endpoint() {
        var base = cfg.url || ((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host);
        return base.replace(/\/+$/, '') + '/app/' + encodeURIComponent(cfg.key) + '?protocol=7&client=js&version=8.4.0&flash=false';
    }

    function setState(next) {
        if (state === next) return;
        state = next;
        stateListeners.slice().forEach(function (fn) { try { fn(next); } catch (e) { /* ouvinte com erro nao derruba os outros */ } });
    }

    function send(event, data) {
        if (ws && ws.readyState === 1) ws.send(JSON.stringify({ event: event, data: data }));
    }

    // Sem nenhuma mensagem por um tempo: pergunta se o servidor ainda esta la; sem resposta, reconecta.
    function touchIdle() {
        clearTimeout(idleTimer);
        clearTimeout(pongTimer);
        idleTimer = setTimeout(function () {
            send('pusher:ping', {});
            pongTimer = setTimeout(function () { if (ws) ws.close(); }, 10000);
        }, 45000);
    }

    function subscribe(name) {
        var channel = channels[name];
        if (!channel || channel.subscribed || channel.pending || !socketId) return;
        if (name.indexOf('private-') !== 0) { send('pusher:subscribe', { channel: name }); return; }
        channel.pending = true;
        var body = 'socket_id=' + encodeURIComponent(socketId) + '&channel_name=' + encodeURIComponent(name);
        var forSocket = socketId;
        fetch(cfg.auth, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        }).then(function (response) {
            if (!response.ok) throw new Error('auth ' + response.status);
            return response.json();
        }).then(function (data) {
            channel.pending = false;
            // A conexao pode ter caido e voltado com outro socket enquanto a autorizacao respondia.
            if (forSocket === socketId && data && data.auth) send('pusher:subscribe', { channel: name, auth: data.auth });
        }).catch(function () {
            // Sem permissao (ou sessao expirada): fica sem tempo real neste canal; o polling continua.
            channel.pending = false;
            channel.denied = true;
        });
    }

    function dispatch(name, event, data) {
        var channel = channels[name];
        if (!channel || !channel.events[event]) return;
        channel.events[event].slice().forEach(function (fn) { try { fn(data); } catch (e) { /* idem */ } });
    }

    function connect() {
        if (stopped || ws) return;
        setState('connecting');
        try { ws = new WebSocket(endpoint()); } catch (e) { ws = null; retry(); return; }

        ws.onmessage = function (message) {
            touchIdle();
            var frame;
            try { frame = JSON.parse(message.data); } catch (e) { return; }
            var data = frame.data;
            if (typeof data === 'string') { try { data = JSON.parse(data); } catch (e) { /* texto puro */ } }

            switch (frame.event) {
                case 'pusher:connection_established':
                    socketId = data && data.socket_id;
                    attempts = 0;
                    setState('connected');
                    Object.keys(channels).forEach(subscribe);
                    return;
                case 'pusher:ping':
                    send('pusher:pong', {});
                    return;
                case 'pusher:pong':
                    clearTimeout(pongTimer);
                    return;
                case 'pusher:error':
                    // 4000-4099: configuracao/origem recusada; nao adianta insistir.
                    if (data && data.code >= 4000 && data.code < 4100) stopped = true;
                    return;
                case 'pusher_internal:subscription_succeeded':
                    if (channels[frame.channel]) channels[frame.channel].subscribed = true;
                    dispatch(frame.channel, 'subscribed', null);
                    return;
                default:
                    if (frame.channel) dispatch(frame.channel, frame.event, data);
            }
        };

        ws.onclose = function () {
            ws = null;
            socketId = null;
            clearTimeout(idleTimer);
            clearTimeout(pongTimer);
            Object.keys(channels).forEach(function (name) { channels[name].subscribed = false; channels[name].pending = false; });
            setState('offline');
            retry();
        };
        ws.onerror = function () { /* o onclose vem logo depois */ };
    }

    function retry() {
        if (stopped) return;
        clearTimeout(retryTimer);
        attempts++;
        var wait = Math.min(30000, 1000 * Math.pow(2, Math.min(attempts, 5))) * (0.75 + Math.random() * 0.5);
        retryTimer = setTimeout(connect, wait);
    }

    // Celular acordou ou a rede voltou: tenta na hora em vez de esperar o proximo intervalo.
    function wake() {
        if (!ws && !stopped && Object.keys(channels).length) { attempts = 0; clearTimeout(retryTimer); connect(); }
    }
    global.addEventListener('online', wake);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) wake(); });

    global.ManaLeagueRealtime = {
        on: function (name, event, fn) {
            if (!name) return;
            var channel = channels[name] || (channels[name] = { events: {}, subscribed: false });
            (channel.events[event] || (channel.events[event] = [])).push(fn);
            if (!ws) connect(); else subscribe(name);
        },
        onState: function (fn) { stateListeners.push(fn); },
        state: function () { return state; },
        connected: function () { return state === 'connected'; },
        // Assinatura confirmada: so entao a tela pode confiar no aviso e esperar mais entre as consultas.
        subscribed: function (name) { return state === 'connected' && !!(channels[name] && channels[name].subscribed); },
    };
})(window);
