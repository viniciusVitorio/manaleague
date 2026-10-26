<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Vitrine do perfil: o jogador escolhe até 3 decks (visíveis para amigos) para destacar. */
class ShowcaseController extends Controller
{
    /** Coloca um deck no espaço {slot}. Se ele já estava em outro espaço, muda de lugar. */
    public function update(Request $request, int $slot): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $data = $request->validate(['deck_id' => ['required', 'integer', Rule::exists('decks', 'id')->where('player_profile_id', $profile->id)]]);
        $deck = $profile->decks()->findOrFail($data['deck_id']);
        if (! $deck->is_visible_to_friends) {
            throw ValidationException::withMessages(['deck_id' => 'Só decks visíveis para amigos entram na vitrine. Torne este deck visível em "Editar deck".']);
        }

        DB::transaction(function () use ($profile, $deck, $slot): void {
            $profile->decks()->where('showcase_position', $slot)->whereKeyNot($deck->id)->update(['showcase_position' => null]);
            $deck->forceFill(['showcase_position' => $slot])->save();
        });

        return redirect()->to(route('profiles.index').'#vitrine')->with('status', "{$deck->name} está na vitrine do seu perfil.");
    }

    public function destroy(Request $request, int $slot): RedirectResponse
    {
        $request->user()->ensurePlayerProfile()->decks()->where('showcase_position', $slot)->update(['showcase_position' => null]);

        return redirect()->to(route('profiles.index').'#vitrine')->with('status', 'Deck retirado da vitrine.');
    }
}
