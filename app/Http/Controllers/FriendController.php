<?php

namespace App\Http\Controllers;

use App\Models\PlayerProfile;
use App\Models\User;
use App\Services\FriendshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FriendController extends Controller
{
    public function __construct(private readonly FriendshipService $friendships) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ensurePlayerProfile();
        $term = trim((string) $request->query('q', ''));
        $results = $term !== '' ? $this->friendships->search($user, mb_substr($term, 0, 80)) : collect();

        $friends = $this->friendships->friends($user);
        $incoming = $this->friendships->incoming($user);
        $outgoing = $this->friendships->outgoing($user);

        $statusById = $friends->pluck('id')->mapWithKeys(fn ($id) => [$id => 'friend'])
            ->union($outgoing->pluck('id')->mapWithKeys(fn ($id) => [$id => 'sent']))
            ->union($incoming->pluck('id')->mapWithKeys(fn ($id) => [$id => 'incoming']));

        return view('friends.index', compact('profile', 'term', 'results', 'friends', 'incoming', 'outgoing', 'statusById'));
    }

    /** Pedido por codigo de amigo: o codigo vem da busca ou foi compartilhado pelo dono. */
    public function store(Request $request, string $code): RedirectResponse
    {
        $profile = PlayerProfile::query()->where('friend_code', PlayerProfile::normalizeFriendCode($code))->firstOrFail();

        return $this->respond($this->friendships->request($request->user(), $profile), $profile);
    }

    public function accept(Request $request, string $code): RedirectResponse
    {
        $profile = PlayerProfile::query()->where('friend_code', PlayerProfile::normalizeFriendCode($code))->whereNotNull('account_user_id')->firstOrFail();
        abort_unless($this->friendships->accept($request->user(), $profile->account), 404);

        return back()->with('status', "Você e {$profile->nickname} agora são amigos.");
    }

    public function destroy(Request $request, string $code): RedirectResponse
    {
        $profile = PlayerProfile::query()->where('friend_code', PlayerProfile::normalizeFriendCode($code))->firstOrFail();
        $status = $this->friendships->status($request->user(), $profile);
        abort_unless($this->friendships->remove($request->user(), $profile), 404);

        return back()->with('status', match ($status) {
            FriendshipService::ACCEPTED => 'Amizade desfeita.',
            FriendshipService::PENDING => 'Pedido cancelado.',
            default => 'Pedido recusado.',
        });
    }

    public function privacy(Request $request): RedirectResponse
    {
        $validated = $request->validate(['discoverable' => ['required', 'boolean']]);
        $request->user()->ensurePlayerProfile()->update($validated);

        return back()->with('status', $validated['discoverable']
            ? 'Seu perfil agora aparece na busca por apelido.'
            : 'Seu perfil só pode ser encontrado pelo código de amigo.');
    }

    private function respond(string $result, PlayerProfile $profile): RedirectResponse
    {
        return match ($result) {
            'self' => back()->withErrors(['friend' => 'Você não pode adicionar a si mesmo.']),
            'no_account' => back()->withErrors(['friend' => 'Este perfil ainda não possui uma conta.']),
            'limit' => back()->withErrors(['friend' => 'Você tem pedidos pendentes demais. Aguarde respostas ou cancele alguns.']),
            'already_friends' => back()->with('status', "Você e {$profile->nickname} já são amigos."),
            'already_sent' => back()->with('status', 'Pedido já enviado; aguardando resposta.'),
            'accepted' => back()->with('status', "Você e {$profile->nickname} agora são amigos."),
            default => back()->with('status', "Pedido de amizade enviado para {$profile->nickname}."),
        };
    }
}
