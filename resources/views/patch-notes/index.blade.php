@extends('layouts.app')
@php($title = 'Patch notes · ManaLeague')
@section('content')
<header class="page-head">
    <div><h1>Patch notes</h1><p>O que mudou em cada versão do ManaLeague.</p></div>
    @if($current)<x-ui.badge tone="success">Versão atual {{ $current->version }}</x-ui.badge>@endif
</header>

@if($notes->isEmpty())
    <x-ui.empty icon="megaphone" title="Nenhum patch note publicado ainda" text="As novidades de cada versão aparecem aqui assim que forem lançadas." />
@else
    <div class="patch-notes">
    @foreach($notes as $note)
        <article class="ui-card patch-note" id="{{ $note->version }}">
            <header class="patch-note-head">
                <div>
                    @if($note->title !== $note->version)<span class="eyebrow">{{ $note->version }}</span>@endif
                    <h2>{{ $note->title }}</h2>
                </div>
                <div class="patch-note-meta">
                    @if($current && $note->is($current))<x-ui.badge tone="success">Atual</x-ui.badge>@endif
                    <time datetime="{{ $note->published_at->toIso8601String() }}">{{ $note->published_at->timezone(config('app.timezone'))->format('d/m/Y') }}</time>
                </div>
            </header>
            @php($blocks = $note->blocks())
            @if($blocks)
                <div class="patch-note-body">
                @foreach($blocks as $block)
                    @if($block['type'] === 'heading')<h3>{{ $block['text'] }}</h3>
                    @elseif($block['type'] === 'list')<ul>@foreach($block['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                    @else<p>{{ $block['text'] }}</p>@endif
                @endforeach
                </div>
            @else
                <p class="patch-note-empty">Sem descrição para esta versão.</p>
            @endif
        </article>
    @endforeach
    </div>
    {{ $notes->links() }}
@endif
@endsection
