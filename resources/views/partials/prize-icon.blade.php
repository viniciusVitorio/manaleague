{{-- Peça de um prêmio: a arte enviada pelo organizador (sempre quadrada) ou, sem ela, o ícone do catálogo na cor da colocação. --}}
{{-- Espera $prize (TournamentPrize) e $size (px do ícone). A cor vem da classe is-gold/is-silver/... de quem inclui. --}}
@php $prizeImage = $prize->imageUrl(); @endphp
<span @class(['prize-icon', 'has-image' => $prizeImage !== null]) aria-hidden="true">@if($prizeImage)<img src="{{ $prizeImage }}" alt="" width="{{ \App\Models\TournamentPrize::IMAGE_SIZE }}" height="{{ \App\Models\TournamentPrize::IMAGE_SIZE }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">@else<x-icon :name="$prize->icon()" :size="$size ?? 18" />@endif</span>
