@props(['count' => 0])
{{-- Sequência de vitórias no torneio: 🔥 a partir de 3 (On Fire), ⚡ a partir de 6 (Unstoppable). --}}
@if($count >= 3)<span class="streak" role="img" title="{{ $count }} vitórias seguidas" aria-label="{{ $count }} vitórias seguidas">{{ $count >= 6 ? '⚡' : '🔥' }}</span>@endif
