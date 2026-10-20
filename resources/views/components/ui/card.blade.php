@props(['title' => null, 'icon' => null, 'meta' => null, 'href' => null, 'linkLabel' => 'Ver tudo'])
<section {{ $attributes->merge(['class' => 'ui-card']) }}>
    @if($title)
    <header class="ui-card-header">
        <h2 class="ui-card-title">@if($icon)<x-icon :name="$icon" class="ui-card-icon" />@endif{{ $title }}</h2>
        @if($meta)<span class="ui-card-meta">{{ $meta }}</span>@endif
        @if($href)<a class="ui-link" href="{{ $href }}">{{ $linkLabel }}<x-icon name="chevron-right" :size="14" /></a>@endif
    </header>
    @endif
    {{ $slot }}
</section>
