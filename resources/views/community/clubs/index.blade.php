@extends('layouts.app')
@section('content')
@include('community.nav')
<h1>Clubes e grupos</h1><p>Encontre sua mesa ou reúna seu grupo em um clube privado.</p>
<div class="community-grid"><section class="community-card"><h2>Seus clubes e clubes públicos</h2>
@forelse($clubs as $club)<article><h3><a href="{{ route('clubs.show',$club) }}">{{ $club->name }}</a></h3><p>{{ $club->privacy === 'private' ? 'Privado' : 'Público' }}</p></article>@empty<p>Nenhum clube por aqui. Crie o primeiro.</p>@endforelse
{{ $clubs->links() }}</section><section class="community-card"><h2>Criar clube</h2><form method="POST" action="{{ route('clubs.store') }}">@csrf
<label>Nome<input name="name" maxlength="120" value="{{ old('name') }}" required></label><label>Descrição<textarea name="description" maxlength="2000">{{ old('description') }}</textarea></label><label>Privacidade<select name="privacy"><option value="private">Privado — apenas membros</option><option value="public">Público — qualquer pessoa pode entrar</option></select></label><button class="btn btn-primary">Criar clube</button></form></section></div>
@endsection
