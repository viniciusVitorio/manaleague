{{-- Estado da entrega de um booster. Organizador ($manage): marca como entregue ou desfaz. Quem ganhou: só o estado. --}}
{{-- Espera $tournament, $award e $manage. --}}
<div class="prize-delivery">
    @if($award->isRedeemed())
        <span class="ui-badge ui-badge-success">{{ $manage ? 'Entregue' : 'Recebido' }}</span>
        @if($manage)
            <form method="POST" action="{{ route('tournaments.prizes.deliver', [$tournament, $award]) }}">@csrf @method('PATCH')<input type="hidden" name="undo" value="1"><button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Desfazer a entrega para {{ $award->player->name }}">Desfazer</button></form>
        @endif
    @elseif($manage)
        <form method="POST" action="{{ route('tournaments.prizes.deliver', [$tournament, $award]) }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline ui-btn-sm" aria-label="Marcar como entregue para {{ $award->player->name }}">Marcar como entregue</button></form>
    @else
        <a class="ui-badge ui-badge-warning" href="{{ route('prizes.index') }}">A receber</a>
    @endif
</div>
