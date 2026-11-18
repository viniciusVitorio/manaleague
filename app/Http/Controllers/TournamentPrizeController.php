<?php

namespace App\Http\Controllers;

use App\Models\PrizeAward;
use App\Models\Tournament;
use App\Models\TournamentPrize;
use App\Services\PrizeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Premiação pelo lado do organizador: definir o que cada colocação ganha e marcar a entrega dos boosters. */
class TournamentPrizeController extends Controller
{
    public function __construct(private readonly PrizeService $prizes) {}

    public function edit(Tournament $tournament): View|RedirectResponse
    {
        $this->authorize('update', $tournament);
        if ($tournament->status === Tournament::STATUS_FINISHED) {
            return redirect()->to(route('tournaments.show', $tournament).'#premiacao');
        }

        // Um registro por colocação e tipo: o formulário mostra as quatro colocações, preenchidas ou não.
        $current = $tournament->prizes()->get()->groupBy('placement')
            ->map(fn ($prizes) => ['booster' => $prizes->firstWhere('kind', TournamentPrize::KIND_BOOSTER), 'trophy' => $prizes->firstWhere('kind', TournamentPrize::KIND_TROPHY)]);

        return view('tournaments.prizes', compact('tournament', 'current'));
    }

    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        $max = TournamentPrize::IMAGE_MAX_KILOBYTES;
        $data = $request->validate([
            'prizes' => ['nullable', 'array'],
            'images' => ['nullable', 'array'],
            // "image" do Laravel já recusa SVG; mimes confere o tipo pelo conteúdo, não pela extensão.
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$max],
        ], [
            'images.*.image' => 'Envie uma imagem JPG, PNG ou WebP.',
            'images.*.mimes' => 'Envie uma imagem JPG, PNG ou WebP.',
            'images.*.max' => 'Cada imagem pode ter no máximo '.number_format($max / 1024, 1, ',', '.').' MB.',
            'images.*.uploaded' => 'O envio da imagem falhou. Tente um arquivo menor.',
        ]);
        $images = array_intersect_key($request->file('images') ?? [], TournamentPrize::PLACEMENTS);
        $this->prizes->save($tournament, $data['prizes'] ?? [], $images);

        return redirect()->to(route('tournaments.show', $tournament).'#premiacao')->with('status', 'Premiação salva.');
    }

    /** O organizador marca o booster como entregue, ou desfaz se marcou errado. */
    public function deliver(Request $request, Tournament $tournament, PrizeAward $award): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($award->prize->tournament_id === $tournament->id, 404);

        if ($request->boolean('undo')) {
            $this->prizes->unredeem($award);

            return back()->with('status', 'Entrega desfeita.');
        }
        $this->prizes->redeem($award, $request->user());

        return back()->with('status', 'Prêmio marcado como entregue.');
    }
}
