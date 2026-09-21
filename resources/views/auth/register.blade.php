@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card">
    <span class="eyebrow">Novo organizador</span>
    <h1>Crie sua conta</h1>
    <p class="muted">Sua conta controla os torneios e os links públicos mostram apenas os resultados.</p>
    <form method="POST" action="{{ route('register') }}">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" maxlength="100" required></label>
        <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Senha<input type="password" name="password" minlength="8" required><small>Minimo de 8 caracteres, com letras e numeros.</small></label>
        <label>Confirmar senha<input type="password" name="password_confirmation" required></label>
        <button class="btn btn-primary btn-block">Criar conta</button>
    </form>
    <p class="auth-link">Já possui uma conta? <a href="{{ route('login') }}">Entrar</a></p>
</div></div>
@endsection
