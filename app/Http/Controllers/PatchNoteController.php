<?php

namespace App\Http\Controllers;

use App\Models\PatchNote;
use Illuminate\View\View;

class PatchNoteController extends Controller
{
    public function index(): View
    {
        return view('patch-notes.index', [
            'notes' => PatchNote::newestFirst()->paginate(10),
            'current' => PatchNote::newestFirst()->first(),
        ]);
    }
}
