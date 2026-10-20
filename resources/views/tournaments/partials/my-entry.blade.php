{{-- Card "Sua inscricao" com troca de deck (so antes do torneio comecar). Espera $tournament, $entry, $profile. --}}
@if($entry && $tournament->status === \App\Models\Tournament::STATUS_SETUP)
<section class="card section-card my-entry">
    <div class="section-heading"><div><span class="eyebrow">Sua inscrição</span><h2>{{ $entry->deck_name ?: 'Deck surpresa' }} @if($entry->deck_colors)@include('partials.color-pips', ['colors' => $entry->deck_colors])@endif</h2></div>
        <button type="button" class="btn btn-ghost btn-small" data-toggle="my-entry-form" aria-controls="my-entry-form" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}">Trocar deck</button></div>
    <p class="muted small-note">Você pode trocar o deck até o organizador iniciar o torneio.</p>
    <form method="POST" action="{{ route('tournaments.entry.update', $tournament) }}" id="my-entry-form" class="my-entry-form" @unless($errors->any()) hidden @endunless>@csrf @method('PATCH')
        @include('tournaments.partials.deck-picker', ['profile' => $profile, 'current' => $entry, 'pickerId' => 'entry'])
        <button class="btn btn-primary">Salvar deck</button>
    </form>
</section>
@endif
