@php($singleSet = $deck->singleSet())
@if($singleSet)<span class="single-set-badge" title="Todas as cartas do deck, sem contar os terrenos, são da coleção {{ $singleSet }}">✦ Coleção única · {{ $singleSet }}</span>@endif
