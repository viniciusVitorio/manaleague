<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ranked\StoreSeasonRequest;
use App\Models\Club;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\RankedReviewFlag;
use App\Models\RankedSeason;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RankedAdminController extends Controller
{
    public function index(Request $request, SeasonService $seasons)
    {
        $this->authorize('manage', RankedSeason::class);
        $seasons->refresh();

        return response()->view('ranked.admin', [
            'seasons' => RankedSeason::query()->with(['formats', 'club'])->latest('starts_at')->get(),
            'disputes' => RankedMatch::query()->with(['playerOne', 'playerTwo', 'season'])->whereIn('status', ['disputed', 'reported'])->oldest('updated_at')->limit(50)->get(),
            'flags' => RankedReviewFlag::query()->with(['profileA', 'profileB', 'season'])->where('status', 'open')->latest()->limit(50)->get(),
            'suspended' => PlayerProfile::query()->whereNotNull('ranked_suspended_until')->where('ranked_suspended_until', '>', now())->get(),
            'clubs' => Club::orderBy('name')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreSeasonRequest $request, SeasonService $seasons)
    {
        $d = $request->validated();
        $tz = 'America/Sao_Paulo';
        $season = $seasons->create($request->user(), [
            'name' => $d['name'], 'starts_at' => Carbon::parse($d['starts_at'], $tz)->utc(), 'ends_at' => Carbon::parse($d['ends_at'], $tz)->utc(),
            'formats' => $d['formats'], 'scope' => $d['scope'], 'club_id' => $d['club_id'] ?? null, 'k_factor' => $d['k_factor'],
            'allow_draws' => $request->boolean('allow_draws'), 'settings' => ['pair_limit_24h' => (int) $d['pair_limit_24h']],
        ]);

        return back()->with('status', "Temporada {$season->name} criada como rascunho. Publique para valer.");
    }

    public function schedule(RankedSeason $season, SeasonService $seasons)
    {
        $this->authorize('manage', $season);
        $seasons->schedule($season);

        return back()->with('status', 'Temporada publicada.');
    }

    public function close(RankedSeason $season, SeasonService $seasons)
    {
        $this->authorize('manage', $season);
        $seasons->close($season);

        return back()->with('status', 'Temporada encerrando: novas partidas não pontuam; disputas pendentes têm prazo para resolução.');
    }

    public function flag(Request $request, RankedReviewFlag $flag)
    {
        $this->authorize('manage', RankedSeason::class);
        $data = $request->validate(['status' => ['required', 'in:dismissed,actioned']]);
        $flag->update(['status' => $data['status'], 'reviewed_by' => $request->user()->id]);

        return back()->with('status', 'Alerta revisado.');
    }

    public function suspend(Request $request)
    {
        $this->authorize('manage', RankedSeason::class);
        $data = $request->validate(['friend_code' => ['required', 'string', 'max:9'], 'days' => ['required', 'integer', 'min:0', 'max:365']]);
        $profile = PlayerProfile::where('friend_code', strtoupper(str_replace('-', '', $data['friend_code'])))->whereNotNull('account_user_id')->firstOrFail();
        $profile->forceFill(['ranked_suspended_until' => $data['days'] > 0 ? now()->addDays((int) $data['days']) : null])->save();

        return back()->with('status', $data['days'] > 0 ? "{$profile->nickname} suspenso do ranked por {$data['days']} dia(s)." : "Suspensão de {$profile->nickname} removida.");
    }
}
