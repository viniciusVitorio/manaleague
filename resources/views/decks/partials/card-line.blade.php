<div class="card-line">
    <span class="card-qty">{{ $card->quantity }}</span>
    <span class="card-name" tabindex="0">
        {{ $card->name }}
        @if($card->image_url)<img class="card-preview" src="{{ $card->image_url }}" alt="" loading="lazy" width="244" height="340">@endif
    </span>
    <span class="card-cost">@include('partials.mana-cost', ['symbols' => $card->manaSymbols()])</span>
    @if($isOwner)
        <span class="card-controls">
            <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="-1"><button class="qty-btn" aria-label="Remover uma cópia de {{ $card->name }}">−</button></form>
            <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="1"><button class="qty-btn" aria-label="Adicionar uma cópia de {{ $card->name }}">+</button></form>
            <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="quantity" value="{{ $card->quantity }}"><input type="hidden" name="board" value="{{ $card->board === 'side' ? 'main' : 'side' }}"><button class="qty-btn wide" title="{{ $card->board === 'side' ? 'Mover para o deck' : 'Mover para o sideboard' }}">{{ $card->board === 'side' ? '↑ deck' : '↓ side' }}</button></form>
        </span>
    @endif
</div>
