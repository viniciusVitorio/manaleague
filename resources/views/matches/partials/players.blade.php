{{-- Quem joga a partida: "Você × adversário" (igual às linhas de desafio). O adversário vira link do perfil; na mesa Commander, todos.
     $plain = true quando a linha inteira já é um link (resumo da partida): só texto, link dentro de link não vale. --}}
@if($row['pod'])
    @foreach($row['podPlayers'] as $podPlayer)
        @unless($loop->first) · @endunless
        @if($plain ?? false)
            {{ $podPlayer->displayName() }}
        @else
            <x-profile-link :of="$podPlayer">{{ $podPlayer->displayName() }}</x-profile-link>
        @endif
    @endforeach
@elseif(! $row['opponent'])
    <span class="mm-me">Você ×</span> <span class="mm-opp">BYE</span>
@elseif($plain ?? false)
    <span class="mm-me">Você ×</span> <span class="mm-opp">{{ $row['opponent']->displayName() }}</span>
@else
    <span class="mm-me">Você ×</span> <span class="mm-opp"><x-profile-link :of="$row['opponent']">{{ $row['opponent']->displayName() }}</x-profile-link></span>
@endif
