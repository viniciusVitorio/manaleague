@php
// Emblemas originais das patentes (SVG inline). Forma muda por patente; cor vem da paleta abaixo.
$size ??= 20;
$c = ['ferro' => '#8b8b94', 'bronze' => '#c07a43', 'prata' => '#c9ced6', 'ouro' => '#e6b84a', 'platina' => '#5fd0bf', 'diamante' => '#7fa8ff', 'mestre' => '#c58cff'][$key] ?? '#a1a1aa';
$shield = 'M12 2.5 19.5 5v6.2c0 4.6-3.1 8.4-7.5 10.3C7.6 19.6 4.5 15.8 4.5 11.2V5z';
@endphp
<svg class="rank-emblem rank-emblem-{{ $key }}" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" focusable="false">
@switch($key)
@case('ferro')
<path d="M12 2.8 19.8 7.3v9.4L12 21.2 4.2 16.7V7.3z" fill="{{ $c }}" fill-opacity=".16" stroke="{{ $c }}" stroke-width="1.6"/><path d="M8.5 12h7" stroke="{{ $c }}" stroke-width="1.8"/>
@break
@case('bronze')
<path d="{{ $shield }}" fill="{{ $c }}" fill-opacity=".18" stroke="{{ $c }}" stroke-width="1.6"/><circle cx="12" cy="11" r="2.2" fill="{{ $c }}"/>
@break
@case('prata')
<path d="{{ $shield }}" fill="{{ $c }}" fill-opacity=".16" stroke="{{ $c }}" stroke-width="1.6"/><path d="m8 10.5 4 3 4-3" stroke="{{ $c }}" stroke-width="1.8"/>
@break
@case('ouro')
<path d="{{ $shield }}" fill="{{ $c }}" fill-opacity=".2" stroke="{{ $c }}" stroke-width="1.6"/><path d="m12 7.2 1.3 2.7 2.9.4-2.1 2 .5 2.9L12 13.8l-2.6 1.4.5-2.9-2.1-2 2.9-.4z" fill="{{ $c }}"/>
@break
@case('platina')
<path d="M12 2.2 17 5l3.2 5-1.6 6.4L12 21.8l-6.6-5.4L3.8 10 7 5z" fill="{{ $c }}" fill-opacity=".18" stroke="{{ $c }}" stroke-width="1.6"/><path d="m12 7 1.3 2.7 2.9.4-2.1 2 .5 2.9L12 13.6l-2.6 1.4.5-2.9-2.1-2 2.9-.4z" fill="{{ $c }}"/>
@break
@case('diamante')
<path d="M8.2 2.6h7.6l3.4 3.7v7L12 21.6l-7.2-8.3v-7z" fill="{{ $c }}" fill-opacity=".18" stroke="{{ $c }}" stroke-width="1.6"/><path d="M9.9 7.4h4.2l1.9 2.3-4 4.9-4-4.9z" fill="{{ $c }}"/>
@break
@default
<path d="{{ $shield }}" fill="{{ $c }}" fill-opacity=".2" stroke="{{ $c }}" stroke-width="1.6"/><path d="m7.8 14.5-.9-5.6 3 2.3L12 7.2l2.1 4 3-2.3-.9 5.6z" fill="{{ $c }}"/>
@endswitch
</svg>
