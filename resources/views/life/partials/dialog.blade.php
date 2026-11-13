{{-- Janela do gráfico da partida, uma por página: os links [data-dialog-src] carregam o gráfico aqui dentro (nav.js). --}}
<dialog class="ui-dialog lchart-dialog" id="life-chart-dialog" aria-labelledby="life-chart-title">
    <header class="ui-dialog-head">
        <h2 id="life-chart-title">Gráfico da partida</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body" data-dialog-content></div>
</dialog>
