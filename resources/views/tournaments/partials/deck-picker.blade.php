{{-- Escolha de deck para inscricao/troca em torneio. Espera: $profile (com decks), opcional $current (Player). --}}
@php
    $currentDeckId = old('deck_id', $current?->deck_id ?? ($current ? null : $profile->decks->firstWhere('is_primary', true)?->id));
    $currentName = old('deck_name', $current ? $current->deck_name : $profile->preferred_deck_name);
    $currentColors = old('deck_colors', $current ? $current->deck_colors : $profile->preferred_deck_colors);
    $uid = $pickerId ?? 'deck';
    $bannedPicker = isset($tournament) && $tournament->blocksBannedCards()
        ? app(\App\Services\Decks\BanListService::class)->bannedByDeck($profile->decks->pluck('id'), $tournament->format) : [];
    $colorOptions = collect(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'])
        ->merge($profile->decks->pluck('colors'))->push($currentColors)->filter()->unique()->values();
@endphp
@if($profile->decks->isNotEmpty())
<label>Escolha um deck salvo
    <select name="deck_id" id="{{ $uid }}-saved" onchange="const o=this.options[this.selectedIndex];if(o.value){document.getElementById('{{ $uid }}-name').value=o.dataset.name||'';document.getElementById('{{ $uid }}-colors').value=o.dataset.colors||''}">
        <option value="">Outro (digitar abaixo)</option>
        @foreach($profile->decks as $deck)
            <option value="{{ $deck->id }}" data-name="{{ $deck->name }}" data-colors="{{ $deck->colors }}" @selected((string) $currentDeckId === (string) $deck->id)>{{ $deck->name }}{{ $deck->colors ? ' · '.$deck->colors : '' }}{{ $deck->is_primary ? ' ★' : '' }}{{ isset($bannedPicker[$deck->id]) ? ' · tem cartas banidas' : '' }}</option>
        @endforeach
    </select>
</label>
@if($bannedPicker)<p class="muted small-note">Decks com cartas banidas no {{ $tournament->format }} não podem ser usados neste torneio.</p>@endif
@endif
<label>Deck usado<input id="{{ $uid }}-name" name="deck_name" value="{{ $currentName }}" maxlength="100" oninput="const s=document.getElementById('{{ $uid }}-saved');if(s&&s.value&&this.value!==s.options[s.selectedIndex].dataset.name)s.value=''"></label>
<label>Cores<select id="{{ $uid }}-colors" name="deck_colors"><option value="">Não informar</option>@foreach($colorOptions as $color)<option value="{{ $color }}" @selected($currentColors === $color)>{{ $color }}</option>@endforeach</select></label>
