{{-- Quem joga a partida: "Você × adversário" (igual às linhas de desafio). O adversário vira link do perfil; na mesa Commander, todos. --}}
@if($row['pod'])
    @foreach($row['podPlayers'] as $podPlayer)@unless($loop->first) · @endunless<x-profile-link :of="$podPlayer">{{ $podPlayer->displayName() }}</x-profile-link>@endforeach
@else
    <span class="mm-me">Você ×</span> @if($row['opponent'])<span class="mm-opp"><x-profile-link :of="$row['opponent']">{{ $row['opponent']->displayName() }}</x-profile-link></span>@else<span class="mm-opp">BYE</span>@endif
@endif
