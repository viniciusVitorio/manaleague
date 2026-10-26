<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Tema da interface. Logado: vale o que está salvo na conta.
 * Sem login (tela de entrar, cadastro): usa o cookie gravado na última escolha, para não "piscar" outro tema.
 */
final class Theme
{
    public const DARK = 'dark';

    public const LIGHT = 'light';

    public const ALL = [self::DARK, self::LIGHT];

    private const COOKIE = 'ml_theme';

    public static function current(?Request $request = null): string
    {
        $request ??= request();
        $theme = $request->user()?->theme ?? $request->cookie(self::COOKIE);

        return in_array($theme, self::ALL, true) ? $theme : self::DARK;
    }

    public static function cookie(string $theme): Cookie
    {
        return cookie()->forever(self::COOKIE, $theme, httpOnly: true, sameSite: 'lax');
    }
}
