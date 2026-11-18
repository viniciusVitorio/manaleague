<?php

namespace App\Http\Controllers;

use App\Models\PrizeAward;
use App\Services\PrizeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Prêmios do jogador: boosters a receber e recebidos, e o destaque de troféus e medalhas no perfil. */
class MyPrizesController extends Controller
{
    public function __construct(private readonly PrizeService $prizes) {}

    public function index(Request $request): View
    {
        $profile = $request->user()->ensurePlayerProfile();

        return view('prizes.index', [
            'profile' => $profile,
            'inventory' => $this->prizes->inventory($profile),
            'trophies' => $this->prizes->trophies($profile)['total'],
        ]);
    }

    public function redeem(Request $request, int $award): RedirectResponse
    {
        $this->prizes->redeem($this->own($request, $award), $request->user());

        return back()->with('status', 'Prêmio marcado como recebido.');
    }

    public function showcase(Request $request, int $award): RedirectResponse
    {
        $featured = $this->prizes->toggleShowcase($this->own($request, $award));

        return redirect()->to(route('profiles.index').'#trofeus')->with('status', $featured ? 'Peça colocada em destaque no seu perfil.' : 'Peça retirada do destaque.');
    }

    /** Prêmio de outra pessoa responde 404, como se não existisse. */
    private function own(Request $request, int $award): PrizeAward
    {
        return $request->user()->ensurePlayerProfile()->prizeAwards()->with('prize')->findOrFail($award);
    }
}
