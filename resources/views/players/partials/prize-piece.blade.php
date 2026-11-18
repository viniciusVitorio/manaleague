{{-- Uma peça da estante: ícone na cor da colocação, nome, torneio e contexto (colocação, tamanho do torneio e data). --}}
{{-- Espera $award (com prize.tournament e players_count), $featured e $isOwner. --}}
@php
    $piece = $award->prize;
    $pieceTournament = $piece->tournament;
    $pieceMeta = implode(' · ', array_filter([
        $piece->placementLabel(),
        $pieceTournament->players_count.' '.($pieceTournament->players_count === 1 ? 'jogador' : 'jogadores'),
        $pieceTournament->tournament_date?->format('d/m/Y'),
    ]));
@endphp
<li @class(['prize-piece', 'is-'.$piece->tone(), 'is-featured' => $featured])>
    @include('partials.prize-icon', ['prize' => $piece, 'size' => $featured ? 40 : 20])
    <div class="prize-piece-text">
        <strong>{{ $piece->title() }}</strong>
        <a href="{{ route('arena.public', $pieceTournament->public_slug) }}">{{ $pieceTournament->name }}</a>
        <small>{{ $pieceMeta }}</small>
    </div>
    @if($isOwner)
        <form method="POST" action="{{ route('prizes.showcase', $award) }}" class="prize-pin">@csrf @method('PATCH')
            <button @class(['ui-icon-btn', 'is-on' => $featured]) aria-pressed="{{ $featured ? 'true' : 'false' }}" aria-label="{{ $featured ? 'Tirar '.$piece->title().' do destaque' : 'Destacar '.$piece->title() }}" title="{{ $featured ? 'Tirar do destaque' : 'Destacar no perfil' }}"><x-icon name="pin" /></button>
        </form>
    @endif
</li>
