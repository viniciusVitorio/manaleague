<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubPost;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Community\ClubService;
use App\Services\Community\NotificationService;
use App\Services\FriendshipService;
use App\Services\TournamentChampionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClubController extends Controller
{
    public const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Draft', 'Outro'];

    public function __construct(private readonly ClubService $clubs) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $mine = $user->clubs()->withCount(['members', 'tournaments'])->orderBy('name')->get();
        $discover = Club::query()->where('privacy', 'public')->whereNotIn('id', $mine->pluck('id'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.str_replace(['%', '_'], '', $q).'%')->orWhere('location', 'like', '%'.str_replace(['%', '_'], '', $q).'%')))
            ->withCount(['members', 'tournaments'])->orderByDesc('members_count')->orderBy('name')->paginate(12)->withQueryString();
        $next = Tournament::query()->whereIn('club_id', $mine->pluck('id'))->where('status', Tournament::STATUS_SETUP)->whereDate('tournament_date', '>=', today())
            ->orderBy('tournament_date')->get()->groupBy('club_id')->map->first();

        return view('community.clubs.index', compact('mine', 'discover', 'q', 'next'));
    }

    public function store(Request $request)
    {
        $club = $this->clubs->create($request->user(), $this->validated($request));

        return redirect()->route('clubs.show', $club)->with('status', 'Clube criado. Compartilhe o link de convite com a galera.');
    }

    public function show(Request $request, Club $club)
    {
        $this->authorize('view', $club);
        $user = $request->user();
        $role = $club->roleFor($user);
        $club->load(['members.playerProfile']);
        $members = $club->members->sortBy(fn ($m) => [array_search($m->pivot->role, ['owner', 'organizer', 'member']), mb_strtolower($m->playerProfile?->nickname ?? $m->name)])->values();
        $tournaments = Tournament::where('club_id', $club->id)->orderByDesc('tournament_date')->orderByDesc('id')->get()->filter(fn ($t) => $user->can('view', $t));
        $upcoming = $tournaments->filter(fn ($t) => $t->status !== Tournament::STATUS_FINISHED)->sortBy('tournament_date')->values();
        $champions = app(TournamentChampionService::class);
        $finished = $tournaments->filter(fn ($t) => $t->status === Tournament::STATUS_FINISHED)->take(8)->map(fn ($t) => ['t' => $t, 'champion' => $champions->for($t)])->values();
        $posts = $role || $club->privacy === 'public' ? $club->posts()->with('author.playerProfile')->orderByDesc('pinned')->latest()->limit(20)->get() : collect();
        $ranking = $this->clubs->ranking($club);
        $memberIds = $club->members->pluck('id');
        $invitableFriends = in_array($role, ['owner', 'organizer'], true)
            ? PlayerProfile::query()->whereIn('id', app(FriendshipService::class)->friendIds($user->id))->whereNotNull('account_user_id')->whereNotIn('account_user_id', $memberIds)->orderBy('nickname')->get()
            : collect();
        $inviteUrl = in_array($role, ['owner', 'organizer'], true) ? route('clubs.invite', $this->clubs->inviteToken($club)) : null;
        // Torneios pessoais (sem clube) criados por quem gerencia: podem ser trazidos para o clube.
        $linkable = in_array($role, ['owner', 'organizer'], true)
            ? $user->tournaments()->whereNull('club_id')->orderByDesc('tournament_date')->orderByDesc('id')->get()
            : collect();

        return view('community.clubs.show', compact('club', 'role', 'members', 'upcoming', 'finished', 'posts', 'ranking', 'invitableFriends', 'inviteUrl', 'linkable') + ['formats' => self::FORMATS]);
    }

    public function update(Request $request, Club $club)
    {
        $this->authorize('update', $club);
        $club->update($this->validated($request));

        return back()->with('status', 'Clube atualizado.');
    }

    public function join(Request $request, Club $club)
    {
        abort_unless($club->privacy === 'public', 403);
        $this->clubs->join($club, $request->user());

        return back()->with('status', 'Você entrou no clube.');
    }

    /** Pagina do link de convite: mostra o clube e pede confirmacao (GET nao altera nada). */
    public function invite(Request $request, string $token)
    {
        $club = $this->clubByToken($token);
        $club->loadCount('members');

        return view('community.clubs.invite', ['club' => $club, 'token' => $token, 'isMember' => (bool) $club->roleFor($request->user())]);
    }

    public function acceptInvite(Request $request, string $token)
    {
        $club = $this->clubByToken($token);
        $this->clubs->join($club, $request->user());

        return redirect()->route('clubs.show', $club)->with('status', "Bem-vindo ao {$club->name}!");
    }

    public function regenerateInvite(Club $club)
    {
        $this->authorize('manage', $club);
        $this->clubs->regenerateInvite($club);

        return back()->with('status', 'Novo link gerado. O link antigo parou de funcionar.');
    }

    public function addMember(Request $request, Club $club)
    {
        $this->authorize('manage', $club);
        $data = $request->validate(['email' => ['required_without:profile_id', 'nullable', 'email'], 'profile_id' => ['required_without:email', 'nullable', 'integer']]);
        if (! empty($data['profile_id'])) {
            // So amigos do organizador podem ser adicionados pela lista (evita adicionar qualquer conta por ID).
            abort_unless(app(FriendshipService::class)->friendIds($request->user()->id)->contains((int) $data['profile_id']), 403);
            $user = PlayerProfile::whereKey($data['profile_id'])->whereNotNull('account_user_id')->firstOrFail()->account;
        } else {
            $user = User::where('email', $data['email'])->first();
            if (! $user) {
                return back()->withErrors(['email' => 'Nenhuma conta com esse e-mail. Mande o link de convite.']);
            }
        }
        if (! $this->clubs->join($club, $user)) {
            return back()->with('status', 'Essa pessoa já é membro.');
        }
        app(NotificationService::class)->send($user, 'club.invite', "Você foi adicionado ao clube {$club->name}", '/clubs/'.$club->id, "club:{$club->id}:added:{$user->id}", ['club_id' => $club->id]);

        return back()->with('status', 'Membro adicionado.');
    }

    public function role(Request $request, Club $club, User $user)
    {
        $this->authorize('update', $club);
        $data = $request->validate(['role' => ['required', Rule::in(['member', 'organizer'])]]);
        abort_if($club->roleFor($user) === 'owner', 409, 'O dono não pode ser rebaixado.');
        abort_unless($club->roleFor($user), 404);
        $club->members()->updateExistingPivot($user->id, $data);

        return back()->with('status', 'Papel atualizado.');
    }

    public function remove(Request $request, Club $club, User $user)
    {
        $role = $club->roleFor($user);
        abort_unless($role, 404);
        abort_if($role === 'owner', 409, 'O dono deve permanecer no clube.');
        if ($request->user()->id !== $user->id) {
            $this->authorize('manage', $club);
            if ($role === 'organizer') {
                $this->authorize('update', $club);
            }
        }
        $club->members()->detach($user->id);
        $self = $request->user()->id === $user->id;

        return $self ? redirect()->route('clubs.index')->with('status', 'Você saiu do clube.') : back()->with('status', 'Membro removido.');
    }

    public function post(Request $request, Club $club)
    {
        $this->authorize('manage', $club);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'pinned' => ['nullable', 'boolean']]);
        $this->clubs->post($club, $request->user(), trim($data['body']), (bool) ($data['pinned'] ?? false));

        return back()->with('status', 'Aviso publicado e membros notificados.');
    }

    public function deletePost(Request $request, Club $club, ClubPost $post)
    {
        abort_unless($post->club_id === $club->id, 404);
        abort_unless($post->user_id === $request->user()->id || $request->user()->can('update', $club), 403);
        $post->delete();

        return back()->with('status', 'Aviso removido.');
    }

    public function tournament(Request $request, Club $club)
    {
        $this->authorize('manage', $club);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'format' => ['required', Rule::in(self::FORMATS)], 'tournament_date' => ['required', 'date'], 'max_players' => ['nullable', 'integer', 'min:4', 'max:128']]);
        $tournament = DB::transaction(function () use ($request, $club, $data) {
            $t = new Tournament($data + ['public_token' => Str::random(40), 'public_slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
            $t->user_id = $request->user()->id;
            $t->club_id = $club->id;
            $t->save();

            return $t;
        });
        foreach ($club->members()->where('users.id', '!=', $request->user()->id)->get() as $member) {
            app(NotificationService::class)->send($member, 'club.tournament', "Novo torneio no {$club->name}: {$tournament->name}", '/clubs/'.$club->id, "club:{$club->id}:tournament:{$tournament->id}:{$member->id}", ['club_id' => $club->id]);
        }

        return redirect()->route('tournaments.show', $tournament);
    }

    /**
     * Vincula um torneio ja existente ao clube. So o criador do torneio, sendo dono/organizador
     * do clube, pode fazer isso, e apenas com torneios pessoais (um torneio fica em um clube por vez).
     * Ao vincular, membros passam a ver o torneio e organizadores do clube a gerencia-lo.
     */
    public function linkTournament(Request $request, Club $club)
    {
        $this->authorize('manage', $club);
        $data = $request->validate(['tournament_id' => ['required', 'integer']]);
        $user = $request->user();
        $tournament = DB::transaction(function () use ($data, $user, $club) {
            $t = Tournament::query()->lockForUpdate()->find($data['tournament_id']);
            abort_unless($t && $t->user_id === $user->id, 404);
            abort_if($t->club_id !== null, 409, $t->club_id === $club->id ? 'Este torneio já é do clube.' : 'Este torneio já pertence a outro clube. Desvincule de lá primeiro.');
            $t->club_id = $club->id;
            $t->save();

            return $t;
        });
        foreach ($club->members()->where('users.id', '!=', $user->id)->get() as $member) {
            app(NotificationService::class)->send($member, 'club.tournament', "Torneio adicionado ao {$club->name}: {$tournament->name}", '/clubs/'.$club->id, "club:{$club->id}:tournament:{$tournament->id}:{$member->id}", ['club_id' => $club->id]);
        }

        return redirect()->route('clubs.show', $club)->with('status', "“{$tournament->name}” agora faz parte do clube e entra no ranking.");
    }

    /** Devolve o torneio para o criador (sai do clube e do ranking do clube). */
    public function unlinkTournament(Request $request, Club $club, Tournament $tournament)
    {
        $this->authorize('manage', $club);
        abort_unless($tournament->club_id === $club->id, 404);
        $tournament->club_id = null;
        $tournament->save();

        return redirect()->route('clubs.show', $club)->with('status', "“{$tournament->name}” saiu do clube e voltou a ser um torneio pessoal do criador.");
    }

    private function clubByToken(string $token): Club
    {
        abort_unless(strlen($token) === 40, 404);

        return Club::where('invite_token', $token)->firstOrFail();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'], 'location' => ['nullable', 'string', 'max:120'], 'privacy' => ['required', Rule::in(['private', 'public'])]]);
    }
}
