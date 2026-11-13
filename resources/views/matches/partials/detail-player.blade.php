{{-- Um jogador no resumo da partida: avatar, nome (link do perfil), deck, o ELO da partida (Ranked) e a marca de vencedor. --}}
<div @class(['mdetail-side', 'is-winner' => $p['winner']])>
    @if($p['profile'])
        <x-avatar :of="$p['profile']" class="mdetail-avatar" />
    @else
        <span class="mdetail-avatar">{{ mb_strtoupper(mb_substr($p['name'], 0, 1)) }}</span>
    @endif
    <span class="mdetail-who">
        <strong><x-profile-link :of="$p['profile']">{{ $p['name'] }}</x-profile-link></strong>
        @if($p['me'])<span class="mdetail-me">Você</span>@endif
    </span>
    @if($p['deck'])
        <span class="mdetail-deck">
            @if($p['deck']['url'])<a class="player-link" href="{{ $p['deck']['url'] }}">{{ $p['deck']['name'] }}</a>@else{{ $p['deck']['name'] }}@endif
            @if($p['deck']['colors'])@include('partials.color-pips', ['colors' => $p['deck']['colors']])@endif
        </span>
    @elseif($withDecks)
        <span class="mdetail-deck is-empty">Deck não informado</span>
    @endif
    @if($p['elo'])
        @php($e = $p['elo'])
        <span @class(['mdetail-elo', 'is-up' => $e['delta'] > 0, 'is-down' => $e['delta'] < 0])>
            <b>{{ $e['delta'] > 0 ? '+' : ($e['delta'] < 0 ? '−' : '±') }}{{ abs($e['delta']) }} ELO</b>
            <span class="mdetail-elo-path">{{ $e['before'] }} → {{ $e['after'] }}</span>
            <span class="mdetail-elo-tier">@include('ranked.partials.emblem', ['key' => $e['key'], 'size' => 15]){{ $e['division'] }}@if($e['moved'])<em>{{ $e['moved'] === 'up' ? 'subiu' : 'caiu' }}</em>@endif</span>
        </span>
    @endif
    @if($p['winner'])<span class="mdetail-crown"><x-icon name="crown" size="13" />Vencedor</span>@endif
</div>
