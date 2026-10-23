@php($movable = $isOwner && in_array($card->board, ['main', 'side'], true))
@php($otherBoard = $card->board === 'side' ? 'main' : 'side')
<div class="card-line"
     @if($movable) draggable="true" data-move-url="{{ route('decks.cards.update', [$deck, $card]) }}" data-board="{{ $card->board }}" data-qty="{{ $card->quantity }}" data-name="{{ $card->name }}" @endif>
    <span class="card-qty">{{ $card->quantity }}</span>
    @php($faces = $card->faceNames())
    <span class="card-name" tabindex="0" title="{{ $card->name }}">
        <span class="card-face">{{ $faces[0] ?? $card->name }}</span>
        @if(count($faces) > 1)<span class="card-back">// {{ implode(' // ', array_slice($faces, 1)) }}</span>@endif
        @if($card->image_url)<img class="card-preview" src="{{ $card->image_url }}" alt="" loading="lazy" width="244" height="340">@endif
    </span>
    <span class="card-cost">@foreach($card->manaGroups() as $group)<span class="cost-group">@include('partials.mana-cost', ['symbols' => $group])</span>@endforeach</span>
    @if($isOwner)
        <span class="card-controls">
            <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="-1"><button class="qty-btn" aria-label="Remover uma cópia de {{ $card->name }}">−</button></form>
            <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="delta" value="1"><button class="qty-btn" aria-label="Adicionar uma cópia de {{ $card->name }}">+</button></form>
            @if($movable)
                <form method="POST" action="{{ route('decks.cards.update', [$deck, $card]) }}">@csrf @method('PATCH')<input type="hidden" name="move" value="1"><input type="hidden" name="board" value="{{ $otherBoard }}"><button class="qty-btn wide" title="{{ $otherBoard === 'main' ? 'Mover 1 cópia para o deck' : 'Mover 1 cópia para o sideboard' }}">{{ $otherBoard === 'main' ? '↑ deck' : '↓ side' }}</button></form>
            @endif
        </span>
    @endif
</div>
