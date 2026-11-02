{{-- "Confirmar todos": aparece com 2+ placares enviados pelos adversários esperando a sua confirmação. --}}
@php($pendingResults ??= app(\App\Services\PendingResultsService::class)->count(auth()->user()))
@if($pendingResults >= 2)
<div class="confirm-all" role="region" aria-label="Placares esperando confirmação">
    <p><strong>{{ $pendingResults }} placares esperando a sua confirmação.</strong> <span>Enviados pelos adversários em torneios, desafios e ranked.</span></p>
    <form method="POST" action="{{ route('results.confirm-all') }}" data-confirm="Confirmar os {{ $pendingResults }} placares enviados pelos adversários? Se algum estiver errado, conteste esse antes.">@csrf
        <button class="ui-btn ui-btn-primary ui-btn-sm"><x-icon name="circle-check" />Confirmar todos ({{ $pendingResults }})</button>
    </form>
</div>
@endif
