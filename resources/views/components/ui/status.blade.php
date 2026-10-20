@props(['status'])
@php
// Estados padronizados: a cor so comunica o estado.
[$label, $tone] = match ($status) {
    'setup' => ['Preparação', 'neutral'],
    'league', 'active', 'accepted' => ['Em andamento', 'info'],
    'finals' => ['Finais', 'info'],
    'finished', 'completed' => ['Finalizado', 'neutral'],
    'pending', 'reported' => ['Pendente', 'warning'],
    'confirmed', 'resolved' => ['Confirmado', 'success'],
    'disputed' => ['Em disputa', 'danger'],
    'declined', 'cancelled', 'expired' => ['Encerrado', 'neutral'],
    default => [ucfirst((string) $status), 'neutral'],
};
@endphp
<x-ui.badge :tone="$tone" {{ $attributes }}><span class="ui-dot" aria-hidden="true"></span>{{ $label }}</x-ui.badge>
