{{-- Escolha do deck para inscricao/troca em torneio: so decks salvos na conta e do formato do torneio.
     Espera: $profile (com decks), $tournament, opcional $current (Player). --}}
@php
    $uid = $pickerId ?? 'deck';
    $usable = $profile->decks->filter(fn ($deck) => $tournament->acceptsDeck($deck))->values();
    $currentDeckId = old('deck_id', $current ? $current->deck_id : $usable->firstWhere('is_primary', true)?->id);
    $bannedPicker = $tournament->blocksBannedCards()
        ? app(\App\Services\Decks\BanListService::class)->bannedByDeck($usable->pluck('id'), $tournament->format) : [];
    $hiddenDecks = $profile->decks->count() - $usable->count();
@endphp
@if($usable->isNotEmpty())
<label>Deck
    <select name="deck_id" id="{{ $uid }}-deck" @if($tournament->requiresDeck()) required @endif>
        @if(! $tournament->requiresDeck())
            <option value="">Sem deck salvo (montado no draft)</option>
        @elseif(! $currentDeckId)
            <option value="" disabled selected>Escolha um deck</option>
        @endif
        @foreach($usable as $deck)
            <option value="{{ $deck->id }}" @selected((string) $currentDeckId === (string) $deck->id)>{{ $deck->name }}{{ $deck->colors ? ' · '.$deck->colors : '' }}{{ $deck->is_primary ? ' ★' : '' }}{{ isset($bannedPicker[$deck->id]) ? ' · tem cartas banidas' : '' }}</option>
        @endforeach
    </select>
</label>
@if($bannedPicker)<p class="muted small-note">Decks com cartas banidas no {{ $tournament->format }} não podem ser usados neste torneio.</p>@endif
@if($hiddenDecks > 0)<p class="muted small-note">Aparecem só os decks de {{ $tournament->format }} e os decks sem formato definido.</p>@endif
@elseif($tournament->requiresDeck())
<div class="alert alert-warning" role="status">Para entrar neste torneio você precisa de um deck de {{ $tournament->format }} salvo na sua conta. <a href="{{ route('decks.index') }}">Criar um deck</a></div>
@else
<p class="muted small-note">Neste draft o deck é montado na mesa: não precisa escolher um deck salvo.</p>
@endif
