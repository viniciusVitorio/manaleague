{{-- Rodadas recolhidas, cada uma com o estado no resumo (concluída, a jogar ou quantas já têm resultado). --}}
{{-- Espera $rounds (partidas agrupadas por rodada), $tournament e $title; opcionais: $currentRound, $manage, $note. --}}
<section class="rounds-section" aria-labelledby="rounds-title">
    <div class="section-heading"><div><h2 id="rounds-title">{{ $title }}</h2></div>@if(! empty($note))<span class="muted">{{ $note }}</span>@endif</div>
    @foreach($rounds as $roundNumber => $matches)
        @php($roundPending = $matches->where('status', 'pending')->count())
        @php($roundUpcoming = ($currentRound ?? null) !== null && $roundNumber > $currentRound && $roundPending === $matches->count())
        <details class="round card" id="round-{{ $roundNumber }}">
            <summary>
                <span>Rodada {{ $roundNumber }}</span>
                <span class="tround-meta">@if($roundPending === 0)<em class="tround-chip is-done">Concluída</em>@elseif($roundUpcoming)<em class="tround-chip is-next">A jogar</em>@else<em class="tround-chip is-live">{{ $matches->count() - $roundPending }} de {{ $matches->count() }} com resultado</em>@endif {{ $matches->count() }} {{ $matches->count() === 1 ? 'mesa' : 'mesas' }}</span>
            </summary>
            <div class="match-grid">@foreach($matches as $match)@include('tournaments.partials.competition-match', ['manage' => $manage ?? false, 'live' => false, 'table' => $loop->iteration])@endforeach</div>
        </details>
    @endforeach
</section>
