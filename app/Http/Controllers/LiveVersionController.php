<?php

namespace App\Http\Controllers;

use App\Services\LiveVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Responde só a versão atual de uma tela; a página compara com a dela e se atualiza quando muda. */
class LiveVersionController extends Controller
{
    public function __invoke(Request $request, LiveVersionService $versions): JsonResponse
    {
        $scope = (string) $request->query('scope', '');
        abort_if(mb_strlen($scope) > 200, 404);

        return response()->json(['v' => $versions->version($request->user(), $scope)])
            ->header('Cache-Control', 'private, no-store');
    }
}
