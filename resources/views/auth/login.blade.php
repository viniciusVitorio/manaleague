@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card">
    <span class="eyebrow">Área do organizador</span>
    <h1>Acesse sua conta</h1>
    <p class="muted">Gerencie rodadas, resultados e a classificação da sua liga.</p>
    <form method="POST" action="{{ route('login') }}">@csrf
        <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
        <label>Senha<input type="password" name="password" required></label>
        <button class="btn btn-primary btn-block">Entrar</button>
    </form>
    <p class="auth-link">Sem conta? <a href="{{ route('register') }}">Crie sua conta</a></p>
</div></div>
@endsection
