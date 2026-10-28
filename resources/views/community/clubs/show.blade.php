@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
@php($manage = in_array($role, ['owner', 'organizer'], true))
<a class="back-link" href="{{ route('clubs.index') }}">← Clubes</a>
<header class="page-head club-head">
    <div class="club-title">
        <span class="club-mark is-lg" aria-hidden="true">{{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}</span>
        <div>
            <h1>{{ $club->name }}</h1>
            <p class="club-meta"><x-ui.badge>@if($club->privacy === 'private')<x-icon name="lock" size="12" />Clube privado @else<x-icon name="globe" size="12" />Clube público @endif</x-ui.badge> <span>{{ $members->count() }} {{ $members->count() === 1 ? 'membro' : 'membros' }}</span>@if($club->location)<span>· {{ $club->location }}</span>@endif</p>
            @if($club->description)<p class="club-desc">{{ $club->description }}</p>@endif
        </div>
    </div>
    <div class="hub-actions">
        @if(! $role && $club->privacy === 'public')<form method="POST" action="{{ route('clubs.join', $club) }}">@csrf<button class="ui-btn ui-btn-primary"><x-icon name="user-plus" />Entrar no clube</button></form>@endif
        @if($manage)<a class="ui-btn ui-btn-primary" href="#novo-torneio"><x-icon name="trophy" />Novo torneio</a>@endif
    </div>
</header>

<div class="hub-grid">
<div class="hub-main">
    <x-ui.card title="Mural" icon="megaphone" id="mural">
        @if($manage)
            <form method="POST" action="{{ route('clubs.posts.store', $club) }}" class="post-form">@csrf
                <textarea name="body" rows="2" maxlength="2000" required placeholder="Aviso para o clube: data do próximo evento, mudança de local, premiação…" aria-label="Novo aviso"></textarea>
                <div class="post-form-foot"><label class="small"><input type="checkbox" name="pinned" value="1"> Fixar no topo</label><button class="ui-btn ui-btn-primary ui-btn-sm">Publicar</button></div>
            </form>
        @endif
        @forelse($posts as $post)
            <article @class(['post', 'is-pinned' => $post->pinned])>
                <x-profile-link :of="$post->author" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$post->author" class="ui-avatar" aria-hidden="true" /></x-profile-link>
                <div class="post-body">
                    <p class="post-head"><strong><x-profile-link :of="$post->author">{{ $post->author->playerProfile?->nickname ?? $post->author->name }}</x-profile-link></strong> <time datetime="{{ $post->created_at->toIso8601String() }}">{{ $post->created_at->diffForHumans() }}</time>@if($post->pinned) <x-ui.badge tone="info"><x-icon name="pin" size="11" />Fixado</x-ui.badge>@endif</p>
                    <p class="post-text">{{ $post->body }}</p>
                </div>
                @if($post->user_id === auth()->id() || $role === 'owner')<form method="POST" action="{{ route('clubs.posts.destroy', [$club, $post]) }}" data-confirm="Apagar este aviso?">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Apagar aviso"><x-icon name="trash" /></button></form>@endif
            </article>
        @empty
            <p class="muted small">{{ $manage ? 'Nenhum aviso ainda. Os membros recebem notificação de cada aviso publicado.' : 'Nenhum aviso por enquanto.' }}</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Ranking do clube" icon="medal" meta="todos os torneios">
        @if($ranking->isEmpty())
            <x-ui.empty icon="medal" title="Ranking começa no primeiro torneio." text="Cada vitória vale 3 pontos e cada empate 1, somando todos os torneios do clube." />
        @else
            <div class="tools-table-wrap"><table class="rank-table">
                <thead><tr><th scope="col">#</th><th scope="col">Jogador</th><th scope="col" class="num">Pts</th><th scope="col" class="num">V-E-D</th><th scope="col" class="num">Torneios</th><th scope="col" class="num">Títulos</th></tr></thead>
                <tbody>
                @php($rankProfiles = \App\Models\PlayerProfile::whereIn('id', $ranking->take(20)->pluck('profile_id')->filter())->get()->keyBy('id'))
                @foreach($ranking->take(20) as $i => $r)
                    <tr><td class="num">{{ $i + 1 }}</td><td><x-profile-link :of="($r['profile_id'] ?? null) ? $rankProfiles->get($r['profile_id']) : null">@if($r['avatar'] ?? null){{ $r['avatar'] }} @endif{{ $r['name'] }}</x-profile-link></td><td class="num"><strong>{{ $r['points'] }}</strong></td><td class="num">{{ $r['wins'] }}-{{ $r['draws'] }}-{{ $r['losses'] }}</td><td class="num">{{ $r['events'] }}</td><td class="num">@if($r['titles'])<x-icon name="trophy" size="13" /> {{ $r['titles'] }}@else — @endif</td></tr>
                @endforeach
                </tbody>
            </table></div>
            <p class="muted small">3 pontos por vitória, 1 por empate. Desempate: títulos, depois vitórias. Mesas de Commander multiplayer não entram.</p>
        @endif
    </x-ui.card>

    <x-ui.card title="Torneios" icon="trophy">
        @if($upcoming->isEmpty() && $finished->isEmpty())
            <x-ui.empty icon="trophy" title="Nenhum torneio do clube ainda." :text="$manage ? 'Crie um novo ou vincule um torneio que você já tem, logo abaixo. Todos os membros são avisados.' : 'Quando os organizadores criarem um, aparece aqui.'" />
        @endif
        @foreach($upcoming as $t)
            <a class="ui-row" href="{{ route('tournaments.show', $t) }}"><span class="ui-row-icon"><x-icon name="calendar" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $t->name }}</span><span class="ui-row-sub">{{ $t->format }} · {{ $t->tournament_date?->format('d/m/Y') }} · {{ $t->status === 'setup' ? 'inscrições abertas' : 'em andamento' }}</span></span><x-ui.badge :tone="$t->status === 'setup' ? 'neutral' : 'info'">{{ $t->status === 'setup' ? 'Em breve' : 'Rolando' }}</x-ui.badge></a>
        @endforeach
        @foreach($finished as $f)
            <a class="ui-row" href="{{ route('tournaments.show', $f['t']) }}"><span class="ui-row-icon"><x-icon name="crown" /></span><span class="ui-row-main"><span class="ui-row-title">{{ $f['t']->name }}</span><span class="ui-row-sub">{{ $f['t']->format }} · {{ $f['t']->tournament_date?->format('d/m/Y') }}@if($f['champion']) · campeão: {{ $f['champion']->name }}@endif</span></span></a>
        @endforeach
        @if($manage)
            <details class="club-new" id="novo-torneio" @if($upcoming->isEmpty() && $finished->isEmpty()) open @endif>
                <summary><x-icon name="plus" />Novo torneio do clube</summary>
                <form method="POST" action="{{ route('clubs.tournaments.store', $club) }}" class="stack-form">@csrf
                    <label>Nome<input name="name" maxlength="120" required placeholder="Ex.: Pauper de Sexta #12"></label>
                    <div class="add-row">
                        <label>Formato<select name="format">@foreach($formats as $format)<option>{{ $format }}</option>@endforeach</select></label>
                        <label>Data<input type="date" name="tournament_date" required value="{{ now()->toDateString() }}"></label>
                        <label>Limite<input type="number" name="max_players" min="4" max="128" placeholder="sem limite"></label>
                    </div>
                    <button class="ui-btn ui-btn-primary">Criar e avisar os membros</button>
                </form>
            </details>
            @if($linkable->isNotEmpty())
            <details class="club-new" id="vincular-torneio">
                <summary><x-icon name="link" />Vincular torneio existente</summary>
                <form method="POST" action="{{ route('clubs.tournaments.link', $club) }}" class="stack-form" data-confirm="Vincular ao clube? Os membros passam a ver o torneio e os organizadores do clube podem gerenciá-lo.">@csrf
                    <label>Seus torneios fora de clubes<select name="tournament_id" required>@foreach($linkable as $lt)<option value="{{ $lt->id }}">{{ $lt->name }} · {{ $lt->format }} · {{ $lt->tournament_date?->format('d/m/Y') ?? 'sem data' }} · {{ ['setup' => 'inscrições', 'finished' => 'encerrado'][$lt->status] ?? 'em andamento' }}</option>@endforeach</select></label>
                    <p class="muted small">O torneio entra no ranking do clube (vale também para torneios já encerrados) e os membros são avisados.</p>
                    <button class="ui-btn ui-btn-primary">Vincular ao clube</button>
                </form>
            </details>
            @endif
        @endif
    </x-ui.card>
</div>

<aside class="hub-side">
    @if($inviteUrl)
    <x-ui.card title="Convidar" icon="link">
        <p class="muted small">Quem abrir este link entra no clube{{ $club->privacy === 'private' ? ', mesmo sendo privado' : '' }}.</p>
        <div class="invite-box"><input readonly value="{{ $inviteUrl }}" aria-label="Link de convite" id="club-invite-url"><button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-copy="#club-invite-url">Copiar</button></div>
        <form method="POST" action="{{ route('clubs.invite.regenerate', $club) }}" data-confirm="Gerar um novo link? O atual para de funcionar.">@csrf<button class="ui-link small">Gerar novo link</button></form>
        @if($invitableFriends->isNotEmpty())
            <form method="POST" action="{{ route('clubs.members.store', $club) }}" class="invite-friend">@csrf
                <label class="small">Adicionar amigo<select name="profile_id">@foreach($invitableFriends as $f)<option value="{{ $f->id }}">{{ $f->nickname }}</option>@endforeach</select></label>
                <button class="ui-btn ui-btn-outline ui-btn-sm">Adicionar</button>
            </form>
        @endif
        <details class="small muted"><summary>Adicionar por e-mail</summary><form method="POST" action="{{ route('clubs.members.store', $club) }}" class="invite-friend">@csrf<input type="email" name="email" required placeholder="email@exemplo.com" aria-label="E-mail"><button class="ui-btn ui-btn-outline ui-btn-sm">Adicionar</button></form></details>
    </x-ui.card>
    @endif

    <x-ui.card title="Membros" icon="users" :meta="(string) $members->count()">
        @foreach($members as $member)
            @php($nick = $member->playerProfile?->nickname ?? $member->name)
            <div class="member-row">
                <x-profile-link :of="$member" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$member" class="ui-avatar" aria-hidden="true" /></x-profile-link>
                <span class="member-name">@if($member->playerProfile && $member->id !== auth()->id())<a href="{{ route('profiles.show', $member->playerProfile) }}">{{ $nick }}</a>@else{{ $nick }}@endif<small>{{ ['owner' => 'Dono', 'organizer' => 'Organizador', 'member' => 'Membro'][$member->pivot->role] }}{{ $member->id === auth()->id() ? ' · você' : '' }}</small></span>
                @if($member->pivot->role !== 'owner')
                    @if($role === 'owner' || ($manage && $member->pivot->role === 'member') || $member->id === auth()->id())
                    <div class="ui-dropdown member-menu"><button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-menu-button aria-expanded="false" aria-controls="member-menu-{{ $member->id }}" aria-label="Ações para {{ $nick }}"><x-icon name="ellipsis" /></button>
                        <div class="ui-menu ui-menu-end" id="member-menu-{{ $member->id }}" hidden>
                            @can('update', $club)
                                <form method="POST" action="{{ route('clubs.members.role', [$club, $member]) }}">@csrf @method('PATCH')<input type="hidden" name="role" value="{{ $member->pivot->role === 'organizer' ? 'member' : 'organizer' }}"><button class="ui-menu-item">{{ $member->pivot->role === 'organizer' ? 'Tornar membro' : 'Tornar organizador' }}</button></form>
                            @endcan
                            <form method="POST" action="{{ route('clubs.members.remove', [$club, $member]) }}" data-confirm="{{ $member->id === auth()->id() ? 'Sair do clube?' : 'Remover '.$nick.' do clube?' }}">@csrf @method('DELETE')<button class="ui-menu-item">{{ $member->id === auth()->id() ? 'Sair do clube' : 'Remover do clube' }}</button></form>
                        </div>
                    </div>
                    @endif
                @endif
            </div>
        @endforeach
    </x-ui.card>

    @can('update', $club)
    <x-ui.card title="Configurações" icon="settings">
        <form method="POST" action="{{ route('clubs.update', $club) }}" class="stack-form">@csrf @method('PATCH')
            <label>Nome<input name="name" value="{{ $club->name }}" required maxlength="120"></label>
            <label>Cidade / local<input name="location" value="{{ $club->location }}" maxlength="120"></label>
            <label>Descrição<textarea name="description" maxlength="2000" rows="3">{{ $club->description }}</textarea></label>
            <label>Quem pode entrar<select name="privacy"><option value="private" @selected($club->privacy === 'private')>Privado: só com convite</option><option value="public" @selected($club->privacy === 'public')>Público: qualquer um entra</option></select></label>
            <button class="ui-btn ui-btn-outline">Salvar</button>
        </form>
    </x-ui.card>
    @endcan
</aside>
</div>
@endsection
