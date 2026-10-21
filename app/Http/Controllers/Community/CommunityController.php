<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\CommunityNotification;
use App\Models\NotificationPreference;
use App\Services\Community\CommunityAccess;
use App\Services\Community\CommunityHubService;
use App\Services\Community\DashboardService;
use App\Services\Community\NotificationService;
use App\Services\FriendshipService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CommunityController extends Controller
{
    public function feed(Request $request, CommunityHubService $hub)
    {
        $user = $request->user();
        $filter = array_key_exists((string) $request->query('tipo'), CommunityHubService::FILTERS) ? (string) $request->query('tipo') : 'all';

        return view('community.feed', ['activities' => $hub->feed($user, $filter), 'filter' => $filter, 'filters' => CommunityHubService::FILTERS, 'ranking' => $hub->friendsRanking($user), 'weekly' => $hub->weeklyWinners($user), 'upcoming' => $hub->upcoming($user), 'suggestions' => app(FriendshipService::class)->suggestions($user, 3), 'access' => app(CommunityAccess::class)]);
    }

    public function dashboard(Request $request)
    {
        return response()->view('community.dashboard', app(DashboardService::class)->for($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function notifications(Request $request)
    {
        $visible = app(NotificationService::class)->visible($request->user());
        $page = max(1, $request->integer('page', 1));
        $notifications = new LengthAwarePaginator($visible->forPage($page, 20)->values(), $visible->count(), 20, $page, ['path' => $request->url()]);
        $preference = NotificationPreference::firstOrNew(['user_id' => $request->user()->id], ['enabled' => true, 'muted_types' => []]);

        return view('community.notifications', compact('notifications', 'preference'));
    }

    public function read(Request $request, int $notification)
    {
        $n = CommunityNotification::where('user_id', $request->user()->id)->findOrFail($notification);
        abort_unless(app(NotificationService::class)->canRead($request->user(), $n), 404);
        if (! $n->read_at) {
            $n->update(['read_at' => now()]);
        }

return back()->with('status', 'Notificação marcada como lida.');
    }

    public function readAll(Request $request)
    {
        CommunityNotification::whereIn('id', app(NotificationService::class)->visible($request->user())->pluck('id'))->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notificações marcadas como lidas.');
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'muted_types' => ['sometimes', 'array', 'max:30'], 'muted_types.*' => ['string', 'max:80', 'regex:/^[a-zA-Z0-9_.-]+$/']]);
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], ['enabled' => $data['enabled'], 'muted_types' => $data['muted_types'] ?? []]);

        return back()->with('status','Preferências salvas.');
    }
}
