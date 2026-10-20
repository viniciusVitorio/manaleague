<?php
namespace App\Http\Controllers;
use App\Models\PlayerProfile;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AchievementShareController extends Controller
{
    private function achievement(PlayerProfile $profile, string $key): array {
        abort_unless(isset(AchievementService::definitions()[$key]) && $profile->achievements()->where('achievement', $key)->exists(), 404);
        [$icon, $name, $description] = AchievementService::definitions()[$key];
        return compact('icon', 'name', 'description', 'key');
    }
    public function show(Request $request, string $key) {
        $profile = $request->user()->ensurePlayerProfile();
        return response()->view('challenges.share', ['achievement' => $this->achievement($profile, $key), 'public' => false, 'active' => DB::table('achievement_shares')->where('player_profile_id', $profile->id)->where('achievement', $key)->exists()])->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $request, string $key) {
        $profile = $request->user()->ensurePlayerProfile();
        $this->achievement($profile, $key);
        $token = bin2hex(random_bytes(32));
        DB::table('achievement_shares')->updateOrInsert(['player_profile_id' => $profile->id, 'achievement' => $key], ['token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]);
        return redirect()->route('achievements.share', $key)->with('achievement_share_url', route('achievements.public', $token))->with('status', 'Link criado. Qualquer link anterior foi revogado.');
    }
    public function destroy(Request $request, string $key) {
        DB::table('achievement_shares')->where('player_profile_id', $request->user()->ensurePlayerProfile()->id)->where('achievement', $key)->delete();
        return redirect()->route('profiles.index')->with('status', 'Link revogado. Imagens já baixadas não podem ser revogadas.');
    }
    public function publicShow(string $token) {
        $share = DB::table('achievement_shares')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($share, 404);
        return response()->view('challenges.share', ['achievement' => $this->achievement(PlayerProfile::findOrFail($share->player_profile_id), $share->achievement), 'public' => true, 'active' => true])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
