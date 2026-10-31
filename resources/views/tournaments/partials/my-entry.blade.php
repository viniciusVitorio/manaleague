{{-- Card "Sua inscricao" com troca de deck (so antes do torneio comecar). Espera $tournament, $entry, $profile. --}}
@if($entry && $tournament->status === \App\Models\Tournament::STATUS_SETUP)
<section class="card section-card my-entry">
    @php($entryNeedsDeck = $entry->needsDeck($tournament))
    @php($entryColors = $entry->deckColors())
    <div class="section-heading"><div><span class="eyebrow">Sua inscrição</span><h2>{{ $entry->deckLabel() ?: ($entryNeedsDeck ? 'Deck não informado' : 'Deck do draft') }} @if($entryColors)@include('partials.color-pips', ['colors' => $entryColors])@endif</h2></div>
        <button type="button" class="btn btn-ghost btn-small" data-toggle="my-entry-form" aria-controls="my-entry-form" aria-expanded="{{ $errors->any() || $entryNeedsDeck ? 'true' : 'false' }}">{{ $entryNeedsDeck ? 'Escolher deck' : 'Trocar deck' }}</button></div>
    @php($entryBanned = app(\App\Services\Decks\BanListService::class)->blockingCards($tournament, $entry->deck_id))
    @if($entryBanned)<div class="alert alert-danger" role="status">{{ \App\Services\Decks\BanListService::messageForOwner($tournament, $entryBanned) }}</div>@endif
    @if($entryNeedsDeck)<div class="alert alert-warning" role="status"><strong>Cadastre seu deck para este torneio.</strong> @if($entry->deck_name)"{{ $entry->deck_name }}" foi só digitado na inscrição e não é um deck salvo. @endif Para fazer o check-in, escolha um deck de {{ $tournament->format }} salvo na sua conta. @unless($profile->decks->contains(fn ($deck) => $tournament->acceptsDeck($deck)))Você ainda não tem nenhum: <a href="{{ route('decks.index') }}">criar um deck</a>.@endunless</div>@endif
    <div class="check-in-box @if($entry->checked_in_at) is-done @endif">
        @if($entry->checked_in_at)
            <p><strong>✓ Presença confirmada</strong> às {{ $entry->checked_in_at->timezone(config('app.timezone'))->format('H:i') }}. É só esperar as rodadas.</p>
            <form method="POST" action="{{ route('tournaments.check-in', $tournament) }}">@csrf<button class="btn btn-ghost btn-small">Desfazer check-in</button></form>
        @else
            <p><strong>Chegou no local?</strong> Confirme sua presença para o organizador não te tirar da lista.</p>
            <form method="POST" action="{{ route('tournaments.check-in', $tournament) }}">@csrf<button class="btn btn-primary btn-small">Fazer check-in</button></form>
        @endif
    </div>
    <p class="muted small-note">Você pode trocar o deck até o organizador iniciar o torneio.</p>
    <form method="POST" action="{{ route('tournaments.entry.update', $tournament) }}" id="my-entry-form" class="my-entry-form" @unless($errors->any() || $entryNeedsDeck) hidden @endunless>@csrf @method('PATCH')
        @include('tournaments.partials.deck-picker', ['profile' => $profile, 'current' => $entry, 'pickerId' => 'entry'])
        <button class="btn btn-primary" @disabled($tournament->requiresDeck() && ! $profile->decks->contains(fn ($deck) => $tournament->acceptsDeck($deck)))>Salvar deck</button>
    </form>
</section>
@endif
