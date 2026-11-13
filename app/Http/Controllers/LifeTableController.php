<?php

namespace App\Http\Controllers;

use App\Models\LifeTable;
use App\Services\Life\LifeTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Contador de vida compartilhado: sincronia das jogadas da partida (JSON).
 */
class LifeTableController extends Controller
{
    public function __construct(
        private readonly LifeTableService $tables,
    ) {}

    /** Jogadas depois de ?after= (o celular pede ao abrir, ao reconectar e no polling sem socket). */
    public function sync(Request $request, LifeTable $table): JsonResponse
    {
        $this->authorizeTable($request, $table);

        return $this->json($this->tables->since($table, (int) $request->query('after', 0)));
    }

    /** Grava jogadas novas e devolve tudo depois de "after" (inclusive o que o outro celular mandou). */
    public function ops(Request $request, LifeTable $table): JsonResponse
    {
        $this->authorizeTable($request, $table);
        $ops = $request->input('ops');
        if (! is_array($ops) || ! array_is_list($ops)) {
            throw ValidationException::withMessages(['ops' => 'Jogadas inválidas.']);
        }

        return $this->json($this->tables->append($table, $request->user(), $ops, (int) $request->input('after', 0)));
    }

    /** Mesa de outra pessoa: 404 (o id e adivinhavel). */
    private function authorizeTable(Request $request, LifeTable $table): void
    {
        abort_unless($this->tables->allows($request->user(), $table), 404);
    }

    /** @param  array{seq: int, ops: list<array<string, mixed>>}  $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
