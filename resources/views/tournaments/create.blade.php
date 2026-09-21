@extends('layouts.app')
@section('content')
<div class="form-shell card">
    <span class="eyebrow">Torneios</span>
    <h1>Criar torneio</h1>
    <p class="muted">Depois você cadastra os jogadores e o sistema gera o todos-contra-todos.</p>
    <form method="POST" action="{{ route('tournaments.store') }}">@csrf
        <label>Nome do torneio<input name="name" value="{{ old('name') }}" maxlength="120" placeholder="Ex.: Eagle TKS Pauper 2026" required autofocus></label>
        <label>Data do torneio<input type="date" name="tournament_date" value="{{ old('tournament_date', now()->format('Y-m-d')) }}" required></label>
        <label>Formato
            <select name="format" required>
                @foreach(['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'] as $format)
                    <option value="{{ $format }}" @selected(old('format', 'Pauper') === $format)>{{ $format }}</option>
                @endforeach
            </select>
        </label>
        <div class="button-row"><button class="btn btn-primary">Criar torneio</button><a class="btn btn-ghost" href="{{ route('tournaments.index') }}">Cancelar</a></div>
    </form>
</div>
@endsection
