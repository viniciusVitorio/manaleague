{{--
    Card de deck (aba Decks e vitrine do perfil).
    $d: ['deck', 'cards', 'cover', 'single_set', 'record', 'coverage'?]
    $manage: true mostra os botões de gerenciar (só o dono, na aba Decks).
--}}
@php
    $deck = $d['deck'];
    $manage = $manage ?? false;
    $record = $d['record'] ?? null;
    $coverage = $d['coverage'] ?? null;
@endphp
<article class="deck-card">
    <a class="deck-card-art" href="{{ route('decks.show', $deck) }}" @if($d['cover']) style="--art: url('{{ $d['cover'] }}')" @endif aria-label="Abrir {{ $deck->name }}">
        <span class="deck-card-tags">
            @if($deck->is_primary)<span class="deck-tag is-primary">★ Principal</span>@endif
            @if($manage && ! $deck->is_visible_to_friends)<span class="deck-tag"><x-icon name="lock" :size="11" />Privado</span>@endif
            @if($manage && $deck->showcase_position)<span class="deck-tag" title="Aparece na vitrine do seu perfil">Na vitrine</span>@endif
            @if($manage && $deck->share_token_hash)<span class="deck-tag" title="Tem link de compartilhamento ativo"><x-icon name="link" :size="11" />Link</span>@endif
        </span>
    </a>
    <div class="deck-card-body">
        <div class="deck-card-title">
            <a href="{{ route('decks.show', $deck) }}"><strong>{{ $deck->name }}</strong></a>
            @include('partials.color-pips', ['colors' => $deck->colors])
        </div>
        <p class="deck-card-meta">{{ $deck->format ?: 'Formato livre' }} · {{ $d['cards'] }} {{ $d['cards'] === 1 ? 'carta' : 'cartas' }}@if($deck->cards_updated_at) · <span title="Lista atualizada em {{ $deck->cards_updated_at->format('d/m/Y H:i') }}">atualizada {{ $deck->cards_updated_at->diffForHumans() }}</span>@endif</p>
        @if($d['single_set'])<span class="single-set-badge" title="Todas as cartas do deck, sem contar os terrenos, são da coleção {{ $d['single_set'] }}">✦ Coleção única · {{ $d['single_set'] }}</span>@endif
        @if($record || $coverage)
            <dl class="deck-card-stats">
                @if($record)
                    <div><dt>Eventos</dt><dd>{{ $record['tournaments'] }} · {{ $record['wins'] }}V {{ $record['losses'] }}D</dd></div>
                @endif
                @if($coverage)
                    <div><dt>Na coleção</dt><dd><a href="{{ route('collection.missing', $deck) }}">{{ $coverage['percent'] }}%</a></dd></div>
                @endif
            </dl>
        @endif
        @if($coverage)<span class="deck-card-bar" aria-hidden="true"><span style="width: {{ $coverage['percent'] }}%"></span></span>@endif
    </div>
    {{-- Gerenciar (só o dono, na aba Decks): menu "..." no canto da arte. O cartão inteiro já abre o deck. --}}
    @if($manage)
        <div class="ui-dropdown deck-card-menu">
            <button type="button" class="ui-icon-btn" data-menu-button aria-expanded="false" aria-controls="deck-card-menu-{{ $deck->id }}" aria-label="Ações de {{ $deck->name }}" title="Ações do deck"><x-icon name="ellipsis" /></button>
            <div class="ui-menu ui-menu-end" id="deck-card-menu-{{ $deck->id }}" hidden>
                <a class="ui-menu-item" href="{{ route('decks.show', $deck) }}"><x-icon name="layers" />Abrir</a>
                <a class="ui-menu-item" href="{{ route('decks.edit', $deck) }}"><x-icon name="pencil" />Editar</a>
                @unless($deck->is_primary)
                    <form method="POST" action="{{ route('decks.primary', $deck) }}">@csrf @method('PATCH')<button class="ui-menu-item"><x-icon name="crown" />Tornar principal</button></form>
                    <div class="ui-menu-sep"></div>
                    <form method="POST" action="{{ route('decks.destroy', $deck) }}" data-confirm="Excluir este deck e todas as cartas?">@csrf @method('DELETE')<button class="ui-menu-item deck-card-del"><x-icon name="trash" />Excluir</button></form>
                @endunless
            </div>
        </div>
    @endif
</article>
