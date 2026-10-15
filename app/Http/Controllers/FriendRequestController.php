<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FriendshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FriendRequestController extends Controller
{
    public function __construct(private readonly FriendshipService $friendships) {}

    public function accept(Request $request, User $requester): RedirectResponse
    {
        abort_unless($this->friendships->accept($request->user(), $requester), 404);

        return back()->with('status', 'Solicitação aceita.');
    }

    public function reject(Request $request, User $requester): RedirectResponse
    {
        abort_unless($request->user()->ensurePlayerProfile()->friendedBy()->whereKey($requester->id)->exists(), 404);
        $this->friendships->remove($request->user(), $requester->ensurePlayerProfile());

        return back()->with('status', 'Solicitação recusada.');
    }
}
