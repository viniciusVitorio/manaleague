@props(['label', 'value', 'icon', 'hint' => null, 'href' => null])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'ui-stat'.($href ? ' is-link' : '')]) }}>
    <div class="ui-stat-head"><span class="ui-stat-label">{{ $label }}</span><x-icon :name="$icon" class="ui-stat-icon" /></div>
    <p class="ui-stat-value">{{ $value }}</p>
    @if($hint)<p class="ui-stat-hint">{{ $hint }}</p>@endif
</{{ $tag }}>
