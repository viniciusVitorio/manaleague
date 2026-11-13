{{-- Janela do resumo da partida, uma por página: as linhas [data-dialog-src] do histórico carregam o resumo aqui (nav.js). --}}
<dialog class="ui-dialog mdetail-dialog" id="match-dialog" aria-labelledby="match-dialog-title">
    <header class="ui-dialog-head">
        <h2 id="match-dialog-title">Partida</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body" data-dialog-content></div>
</dialog>
