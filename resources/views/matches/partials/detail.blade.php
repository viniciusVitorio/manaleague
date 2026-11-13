{{-- Resumo da partida (MatchDetailService): quem jogou, decks, placar, vencedor e, no Ranked, o ELO de cada um; embaixo o
     gráfico de vida, que sempre abre aberto e dá para recolher. Partida antiga ou jogada sem o contador fica sem gráfico. --}}
<div class="mdetail">
    <p class="mdetail-context">
        <span @class(['mm-kind', 'is-ranked' => $detail['ranked']])>{{ $detail['kind'] }}</span>
        @foreach($detail['context'] as $part) · {{ $part }}@endforeach
        @if($detail['date']) · {{ $detail['date']->timezone(config('app.timezone'))->format('d/m/Y') }}@endif
    </p>

    @if($detail['pod'])
        <ol class="mdetail-pod">
            @foreach($detail['players'] as $p)
                <li><span class="mdetail-place">{{ $p['place'] ? $p['place'].'º' : '–' }}</span>@include('matches.partials.detail-player', ['p' => $p, 'withDecks' => $detail['type'] === 'tournament'])</li>
            @endforeach
        </ol>
    @else
        <div class="mdetail-board">
            @include('matches.partials.detail-player', ['p' => $detail['players'][0], 'withDecks' => $detail['type'] === 'tournament'])
            <div class="mdetail-score" @if($detail['result']) aria-label="Placar {{ $detail['players'][0]['games'] }} a {{ $detail['players'][1]['games'] }}" @endif>
                @if($detail['players'][0]['games'] !== null && $detail['players'][1]['games'] !== null)
                    <b>{{ $detail['players'][0]['games'] }}</b><span aria-hidden="true">–</span><b>{{ $detail['players'][1]['games'] }}</b>
                @else
                    <span class="mdetail-vs">×</span>
                @endif
            </div>
            @include('matches.partials.detail-player', ['p' => $detail['players'][1], 'withDecks' => $detail['type'] === 'tournament'])
        </div>
    @endif

    @if($detail['result'] || $detail['status'])
        <p class="mdetail-result">
            @if($detail['result'])<strong>{{ $detail['result'] }}</strong>@endif
            @if($detail['status'])<span>{{ $detail['status'] }}</span>@endif
        </p>
    @endif
    @if($detail['eloNote'])
        <p class="mdetail-elo-note"><x-icon name="info" size="15" />{{ $detail['eloNote'] }}</p>
    @endif

    @if($detail['chart'])
        <details class="mdetail-chart" open>
            <summary><x-icon name="activity" size="16" />Gráfico de vida<x-icon name="chevron-down" size="16" class="mdetail-chev" /></summary>
            @include('life.partials.chart', ['chart' => $detail['chart']])
        </details>
    @elseif($detail['played'])
        <p class="mdetail-nochart"><x-icon name="activity" size="16" />Sem gráfico de vida: esta partida não foi jogada com o contador de vida.</p>
    @endif

    @if($detail['link'])
        <a class="ui-btn ui-btn-outline ui-btn-sm mdetail-link" href="{{ $detail['link']['url'] }}">{{ $detail['link']['label'] }}<x-icon name="chevron-right" size="14" /></a>
    @endif
</div>
