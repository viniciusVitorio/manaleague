<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\Community\NotificationService;
use App\Services\Decks\BanListService;
use App\Services\StandingsService;
use App\Services\TournamentRecapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TournamentController extends Controller
{
    private const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $tournaments = $user->tournaments()->withCount('players')->latest()->get();
        // Torneios de outros organizadores em que o jogador tem inscrição vinculada à conta.
        $playing = Tournament::query()
            ->where('user_id', '!=', $user->id)
            ->whereHas('players.profile', fn ($query) => $query->where('account_user_id', $user->id))
            ->with('user.playerProfile')->withCount('players')->latest()->get();

        return view('tournaments.index', compact('tournaments', 'playing'));
    }

    public function create(): View { return view('tournaments.create'); }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTournament($request);
        $validated['public_token'] = Str::random(40);
        // Sufixo aleatorio de 16 chars [a-z0-9] (~82 bits): a URL publica nao pode ser enumerada.
        $validated['public_slug'] = Str::limit(Str::slug($validated['name']), 60, '').'-'.Str::lower(Str::random(16));
        $validated['invite_token'] = Str::random(40);
        $tournament = $request->user()->tournaments()->create($validated);
        return redirect()->route('tournaments.show', $tournament)->with('status', 'Torneio criado. Agora chame a galera!');
    }

    public function show(Tournament $tournament, StandingsService $standings, TournamentRecapService $recaps): View
    {
        $this->authorize('view', $tournament);
        $tournament->load(['players.profile', 'matches' => fn ($query) => $query->orderBy('round')->orderBy('id'), 'matches.playerOne.profile', 'matches.playerTwo.profile']);
        $ranking = $standings->for($tournament);
        $rounds = $tournament->matches->groupBy('round');
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        $thirdPlace = $tournament->matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE);
        $enrolledProfileIds = $tournament->players->pluck('player_profile_id')->filter();
        $friends = $tournament->user->friendProfiles()->wherePivot('status', 'accepted')->whereNotIn('player_profiles.id', $enrolledProfileIds)->orderBy('nickname')->get();
        $recap = $recaps->for($tournament);
        // Inscritos cujo deck tem banidas (só nos torneios que barram): o organizador vê a marca, sem a lista de cartas.
        $bannedDecks = $tournament->blocksBannedCards() ? app(BanListService::class)->bannedByDeck($tournament->players->pluck('deck_id'), $tournament->format) : [];
        // O organizador tambem pode jogar: mostra o card de troca de deck da propria inscricao.
        $profile = auth()->user()->playerProfile()->with('decks')->first();
        $entry = $profile ? $tournament->players->firstWhere('player_profile_id', $profile->id) : null;
        return view('tournaments.show', compact('tournament', 'ranking', 'rounds', 'final', 'thirdPlace', 'friends', 'recap', 'profile', 'entry', 'bannedDecks'));
    }

    public function edit(Tournament $tournament): View
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'Só é possível editar antes do início.');
        return view('tournaments.edit', compact('tournament'));
    }

    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409);
        $validated = $this->validateTournament($request);
        $validated['max_players'] ??= null;
        if ($validated['max_players'] && $validated['max_players'] < $tournament->players()->count()) {
            return back()->withErrors(['max_players' => 'O limite não pode ser menor que o total de inscritos.'])->withInput();
        }
        $tournament->update($validated);
        return redirect()->route('tournaments.show', $tournament)->with('status', 'Torneio atualizado.'.$this->bannedWarning($tournament));
    }

    /**
     * Repetir torneio: cria o próximo com o mesmo formato, sistema e regras (e, se marcado, os mesmos jogadores).
     * Sai em preparação, com links novos; o clube continua o mesmo se quem repete ainda organiza o clube.
     */
    public function repeat(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tournament_date' => ['required', 'date'],
            'copy_players' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $club = $tournament->club_id ? $tournament->club : null;
        $club = $club && $user->can('manage', $club) ? $club : null;

        $copy = DB::transaction(function () use ($tournament, $validated, $user, $club): Tournament {
            $copy = new Tournament([
                ...array_filter($tournament->only(['format', 'system', 'ranked', 'round_limit', 'max_players', 'rules', 'allow_banned', 'house_rules']), fn ($value) => $value !== null),
                'name' => $validated['name'], 'tournament_date' => $validated['tournament_date'], 'status' => Tournament::STATUS_SETUP,
                'public_token' => Str::random(40), 'public_slug' => Str::limit(Str::slug($validated['name']), 60, '').'-'.Str::lower(Str::random(16)),
                'invite_token' => Str::random(40),
            ]);
            $copy->user_id = $user->id;
            $copy->club_id = $club?->id;
            $copy->round_minutes = $tournament->round_minutes;
            $copy->save();

            if ($validated['copy_players'] ?? false) {
                // Mesma inscrição (nome, perfil e deck); check-in e resultados começam do zero.
                foreach ($tournament->players()->get(['name', 'player_profile_id', 'deck_id', 'deck_name', 'deck_colors']) as $player) {
                    $copy->players()->create($player->only(['name', 'player_profile_id', 'deck_id', 'deck_name', 'deck_colors']));
                }
            }

            return $copy;
        });

        if ($club) {
            foreach ($club->members()->where('users.id', '!=', $user->id)->get() as $member) {
                app(NotificationService::class)->send($member, 'club.tournament', "Novo torneio no {$club->name}: {$copy->name}", '/clubs/'.$club->id, "club:{$club->id}:tournament:{$copy->id}:{$member->id}", ['club_id' => $club->id]);
            }
        }

        $players = $copy->players()->count();

        return redirect()->route('tournaments.show', $copy)->with('status', ($players
            ? "Torneio criado a partir de \"{$tournament->name}\" com {$players} jogadores. Confira a lista antes de começar."
            : "Torneio criado a partir de \"{$tournament->name}\". Agora chame a galera!").$this->bannedWarning($copy));
    }

    /** Aviso quando as regras barram o deck de quem já está inscrito. */
    private function bannedWarning(Tournament $tournament): string
    {
        if (! $tournament->blocksBannedCards()) return '';
        $count = count(app(BanListService::class)->bannedByDeck($tournament->players()->pluck('deck_id'), $tournament->format));
        if ($count === 0) return '';

        return $count === 1
            ? ' Atenção: 1 inscrito está com cartas banidas no deck e só pode fazer check-in depois de trocar.'
            : " Atenção: {$count} inscritos estão com cartas banidas no deck e só podem fazer check-in depois de trocar.";
    }

    public function regenerateInvite(Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409);
        $tournament->update(['invite_token' => Str::random(40)]);
        return back()->with('status', 'Novo link criado. O convite anterior não funciona mais.');
    }

    public function destroy(Tournament $tournament): RedirectResponse
    {
        $this->authorize('delete', $tournament);
        DB::transaction(fn () => $tournament->delete());
        return redirect()->route('tournaments.index')->with('status', 'Torneio excluido.');
    }

    private function validateTournament(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', Rule::in(self::FORMATS)],
            'system' => ['sometimes', 'required', Rule::in(array_keys(Tournament::SYSTEMS)), function ($attribute,$value,$fail) use ($request) { if ($value==='commander_pods' && $request->input('format')!=='Commander') $fail('Selecione o formato Commander para pods.'); }],
            'ranked' => ['sometimes', 'boolean', function ($attribute, $value, $fail) use ($request) { if ($value && $request->input('system') === 'commander_pods') $fail('Torneios com pods Commander não podem ser ranqueados.'); }],
            'round_limit' => ['nullable','integer','min:1','max:20'],
            'tournament_date' => ['required', 'date'],
            'max_players' => ['nullable', 'integer', 'min:4', 'max:128'],
            'rules' => ['sometimes', 'required', Rule::in([Tournament::RULES_STANDARD, Tournament::RULES_CUSTOM])],
            'allow_banned' => ['sometimes', 'boolean', function ($attribute, $value, $fail) use ($request) { if ($value && $request->input('rules') === Tournament::RULES_CUSTOM && $request->boolean('ranked')) $fail('Torneios ranqueados não aceitam cartas banidas.'); }],
            'house_rules' => ['nullable', 'string', 'max:1000'],
        ]);

        // Regras padrão do formato: banidas nunca entram e não há regras da casa.
        $custom = ($validated['rules'] ?? Tournament::RULES_STANDARD) === Tournament::RULES_CUSTOM;
        $validated['rules'] = $custom ? Tournament::RULES_CUSTOM : Tournament::RULES_STANDARD;
        $validated['allow_banned'] = $custom && ($validated['allow_banned'] ?? false);
        $validated['house_rules'] = $custom ? (trim((string) ($validated['house_rules'] ?? '')) ?: null) : null;

        return $validated;
    }
}
