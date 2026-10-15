@php($pipColors = $colors ? str_split(preg_replace('/[^WUBRGC]/', '', strtoupper($colors))) : [])
<span class="pips" aria-label="Cores: {{ $colors }}">@foreach($pipColors as $pip)<i class="pip pip-{{ strtolower($pip) }}" title="{{ ['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'][$pip] ?? $pip }}"></i>@endforeach</span>
