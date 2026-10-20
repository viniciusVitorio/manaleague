@php($pendingSubmissions = \App\Models\MatchResultSubmission::whereIn('status', ['pending', 'disputed'])->whereHas('gameMatch', fn ($q) => $q->where('tournament_id', $tournament->id))->count())
<section class="card section-card tournament-tools">
@if($tournament->status === 'setup')
<h2>Inscrições por QR</h2><p>Exiba na loja ou compartilhe o PNG. Ao gerar um novo convite, o link e o QR antigos deixam de funcionar.</p>
<a class="btn btn-primary" href="{{ route('tournaments.qr.show',$tournament) }}">Exibir QR em tela cheia</a> <a class="btn btn-ghost" download="inscricoes.png" href="{{ route('tournaments.qr.png',$tournament) }}">Baixar PNG</a>
<form method="POST" action="{{ route('tournaments.invite.regenerate',$tournament) }}" class="qr-inline-form" onsubmit="return confirm('Gerar um novo convite? O link e o QR atuais deixam de funcionar.')">@csrf<button class="btn btn-ghost">Revogar e gerar novo convite</button></form>
@else
<h2>Mesa do organizador</h2><p>Projete a arena ao vivo e acompanhe os placares enviados pelos jogadores.</p>
@endif
<a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ route('arena.tv',$tournament->public_slug) }}">Modo TV ↗</a>
<a class="btn btn-ghost" href="{{ route('matches.submissions.queue',$tournament) }}">Resultados enviados @if($pendingSubmissions)<span class="nav-badge" aria-label="{{ $pendingSubmissions }} aguardando">{{ $pendingSubmissions }}</span>@endif</a>
</section>
