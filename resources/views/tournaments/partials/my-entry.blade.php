{{-- Card "Sua inscrição" (só antes do torneio começar): deck e presença em duas linhas, cada uma com o estado e a ação ao lado. --}}
{{-- A presença só pode ser confirmada no dia do evento (Tournament::playerCheckInWindow). Espera $tournament, $entry, $profile. --}}
@if($entry && $tournament->status === \App\Models\Tournament::STATUS_SETUP)
@php
    $entryNeedsDeck = $entry->needsDeck($tournament);
    $entryColors = $entry->deckColors();
    $entryBanned = app(\App\Services\Decks\BanListService::class)->blockingCards($tournament, $entry->deck_id);
    $deckLabel = $entry->deckLabel() ?: ($entryNeedsDeck ? null : 'Deck do draft');
    $deckProblem = $entryNeedsDeck || $entryBanned;
    $checkedIn = $entry->checked_in_at !== null;
    $window = $tournament->playerCheckInWindow();
    $eventDay = $tournament->tournament_date?->format('d/m');
    $usableDecks = $profile->decks->filter(fn ($deck) => $tournament->acceptsDeck($deck))->values();
    $hasDeckToPick = $usableDecks->isNotEmpty();
    $blockedDecks = $tournament->blocksBannedCards() ? app(\App\Services\Decks\BanListService::class)->blockedByDeck($usableDecks->pluck('id'), $tournament->format) : [];
    $blockedLabel = \App\Services\Decks\BanListService::blockedLabel($tournament->format);
    $otherFormatDecks = $profile->decks->count() - $usableDecks->count();
    $pickedDeckId = (string) old('deck_id', $entry->deck_id);
    // Estado da linha de presença: feita, liberada agora, travada pelo deck, antes do dia ou depois do dia.
    $presence = match (true) {
        $checkedIn => 'done',
        $window === 'early' => 'early',
        $window === 'late' => 'late',
        $deckProblem => 'blocked',
        default => 'open',
    };
@endphp
<section class="card section-card my-entry" aria-labelledby="my-entry-title">
    <header class="my-entry-head">
        <span class="eyebrow">Sua inscrição</span>
        <h2 id="my-entry-title"><x-icon name="circle-check" :size="20" />Você está inscrito</h2>
    </header>

    @if($entryBanned)<div class="alert alert-danger" role="status">{{ \App\Services\Decks\BanListService::messageForOwner($tournament, $entryBanned) }}</div>@endif
    @if($entryNeedsDeck)<div class="alert alert-warning" role="status"><strong>Cadastre seu deck para este torneio.</strong> @if($entry->deck_name)"{{ $entry->deck_name }}" foi só digitado na inscrição e não é um deck salvo. @endif Para fazer o check-in, escolha um deck de {{ $tournament->format }} salvo na sua conta. @unless($hasDeckToPick)Você ainda não tem nenhum: <a href="{{ route('decks.index') }}">criar um deck</a>.@endunless</div>@endif

    <ul class="entry-steps">
        <li @class(['entry-step', 'is-done' => ! $deckProblem, 'is-blocked' => $deckProblem])>
            <span class="entry-step-icon" aria-hidden="true"><x-icon name="layers" /></span>
            <div class="entry-step-body">
                <span class="entry-step-label">Deck</span>
                <strong>{{ $deckLabel ?? 'Nenhum deck escolhido' }} @if($entryColors)@include('partials.color-pips', ['colors' => $entryColors])@endif</strong>
                <small>Dá para trocar até o organizador começar o torneio.</small>
            </div>
            <button type="button" @class(['ui-btn', 'ui-btn-sm', 'ui-btn-primary' => $entryNeedsDeck, 'ui-btn-outline' => ! $entryNeedsDeck]) data-dialog-open="entry-deck-dialog" aria-haspopup="dialog">{{ $entryNeedsDeck ? 'Escolher deck' : 'Trocar deck' }}</button>
        </li>
        <li @class(['entry-step', 'is-done' => $presence === 'done', 'is-todo' => $presence === 'open', 'is-blocked' => in_array($presence, ['blocked', 'late'], true), 'is-wait' => $presence === 'early'])>
            <span class="entry-step-icon" aria-hidden="true"><x-icon :name="match ($presence) { 'done' => 'check-check', 'early' => 'calendar', 'late', 'blocked' => 'alert', default => 'pin' }" /></span>
            <div class="entry-step-body">
                <span class="entry-step-label">Presença</span>
                @switch($presence)
                    @case('done')
                        <strong>Presença confirmada às {{ $entry->checked_in_at->timezone(config('app.timezone'))->format('H:i') }}</strong>
                        <small>É só esperar as rodadas.</small>
                        @break
                    @case('early')
                        <strong>Check-in abre no dia do evento{{ $eventDay ? ' · '.$eventDay : '' }}</strong>
                        <small>Quando chegar ao local, volte aqui e confirme sua presença.</small>
                        @break
                    @case('late')
                        <strong>O check-in era no dia do evento{{ $eventDay ? ' · '.$eventDay : '' }}</strong>
                        <small>Fale com o organizador para marcar sua presença.</small>
                        @break
                    @case('blocked')
                        <strong>Confirme a presença depois de acertar o deck</strong>
                        <small>O check-in libera assim que o deck estiver válido para o {{ $tournament->format }}.</small>
                        @break
                    @default
                        <strong>Chegou ao local? Confirme sua presença</strong>
                        <small>Assim o organizador não te tira da lista.</small>
                @endswitch
            </div>
            @if($presence === 'done')
                <form method="POST" action="{{ route('tournaments.check-in', $tournament) }}">@csrf<button class="ui-btn ui-btn-ghost ui-btn-sm">Desfazer check-in</button></form>
            @elseif($presence === 'open')
                <form method="POST" action="{{ route('tournaments.check-in', $tournament) }}">@csrf<button class="ui-btn ui-btn-primary ui-btn-sm">Fazer check-in</button></form>
            @endif
        </li>
    </ul>

    {{-- Trocar deck: janela com os decks do formato como cartões; o atual vem marcado e os com banidas ficam travados --}}
    <dialog class="ui-dialog entry-deck-dialog" id="entry-deck-dialog" aria-labelledby="entry-deck-title" @if($errors->has('deck_id')) data-open-on-load @endif>
        <form method="POST" action="{{ route('tournaments.entry.update', $tournament) }}" class="entry-deck-form">@csrf @method('PATCH')
            <header class="ui-dialog-head">
                <h2 id="entry-deck-title">{{ $entryNeedsDeck ? 'Escolher deck' : 'Trocar deck' }}</h2>
                <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar"><x-icon name="x" /></button>
            </header>
            <div class="ui-dialog-body">
                <p class="muted entry-deck-hint">{{ $tournament->requiresDeck() ? 'Decks de '.$tournament->format.' salvos na sua conta.' : 'Neste draft o deck é montado na mesa; escolher um deck salvo é opcional.' }} Dá para trocar até o organizador começar o torneio.</p>
                @error('deck_id')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                @if($hasDeckToPick || ! $tournament->requiresDeck())
                    <fieldset class="deck-choices">
                        <legend class="sr-only">Deck para o torneio</legend>
                        @unless($tournament->requiresDeck())
                            <label class="deck-choice"><input type="radio" name="deck_id" value="" @checked($pickedDeckId === '')><span class="deck-choice-body"><strong>Sem deck salvo</strong><small>Montado no draft</small></span></label>
                        @endunless
                        @foreach($usableDecks as $deck)
                            <label @class(['deck-choice', 'is-blocked' => isset($blockedDecks[$deck->id])])>
                                <input type="radio" name="deck_id" value="{{ $deck->id }}" @checked($pickedDeckId === (string) $deck->id) @disabled(isset($blockedDecks[$deck->id])) @if($tournament->requiresDeck()) required @endif>
                                <span class="deck-choice-body">
                                    <strong>{{ $deck->name }}</strong>
                                    <small>@if($deck->colors)@include('partials.color-pips', ['colors' => $deck->colors])@endif {{ $deck->format ?: 'Formato livre' }}@if($deck->is_primary) · ★ principal @endif @if(isset($blockedDecks[$deck->id]))<span class="deck-choice-warn">· tem {{ $blockedLabel }}</span>@endif</small>
                                </span>
                                @if(! isset($blockedDecks[$deck->id]) && $entry->deck_id === $deck->id)<span class="ui-badge ui-badge-success">Atual</span>@endif
                            </label>
                        @endforeach
                    </fieldset>
                    @if($otherFormatDecks > 0)<p class="muted entry-deck-note">{{ $otherFormatDecks }} {{ $otherFormatDecks === 1 ? 'deck de outro formato não aparece' : 'decks de outros formatos não aparecem' }} aqui.</p>@endif
                @else
                    <x-ui.empty icon="layers" title="Você ainda não tem deck de {{ $tournament->format }}" text="Monte um deck na sua conta e volte aqui para escolher." :href="route('decks.index')" action="Criar um deck" />
                @endif
            </div>
            <footer class="ui-dialog-foot">
                <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-dialog-close>Cancelar</button>
                @if($hasDeckToPick || ! $tournament->requiresDeck())<button class="ui-btn ui-btn-primary ui-btn-sm">Usar este deck</button>@endif
            </footer>
        </form>
    </dialog>
</section>
@endif
