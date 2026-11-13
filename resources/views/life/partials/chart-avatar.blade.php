{{-- Avatar de um lugar no gráfico, com anel na cor da linha. Jogador avulso de torneio (sem perfil) mostra a inicial. --}}
@if($profile)
    <x-avatar :of="$profile" class="lchart-avatar s{{ $seat + 1 }}" />
@else
    <span class="lchart-avatar s{{ $seat + 1 }}">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
@endif
