<?php

namespace App\Http\Controllers;

use App\Support\LifeColor;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Preferências da conta: tema da interface, contador da coleção nos decks e cor no contador de vida. */
class PreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(Theme::ALL)],
            'show_deck_ownership' => ['nullable', 'boolean'],
            'life_color' => ['nullable', Rule::in(array_keys(LifeColor::ALL))],
        ]);
        $request->user()->forceFill(['theme' => $data['theme'], 'show_deck_ownership' => $request->boolean('show_deck_ownership'),
            'life_color' => $data['life_color'] ?? null])->save();

        return back()->with('status', 'Preferências salvas.')->withCookie(Theme::cookie($data['theme']));
    }

    /** Atalho do menu: alterna entre escuro e claro. */
    public function toggleTheme(Request $request): RedirectResponse
    {
        $theme = Theme::current($request) === Theme::LIGHT ? Theme::DARK : Theme::LIGHT;
        $request->user()->forceFill(['theme' => $theme])->save();

        return back()->withCookie(Theme::cookie($theme));
    }
}
