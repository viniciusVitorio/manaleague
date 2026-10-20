@props(['icon' => 'inbox', 'title', 'text' => null, 'href' => null, 'action' => null])
<div {{ $attributes->merge(['class' => 'ui-empty']) }}>
    <span class="ui-empty-icon"><x-icon :name="$icon" :size="18" /></span>
    <div><p class="ui-empty-title">{{ $title }}</p>@if($text)<p class="ui-empty-text">{{ $text }}</p>@endif</div>
    @if($href && $action)<a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ $href }}">{{ $action }}</a>@endif
</div>
