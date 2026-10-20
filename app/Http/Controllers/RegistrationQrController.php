<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use App\Services\RegistrationQrService;
class RegistrationQrController extends Controller
{
    public function png(Tournament $tournament,RegistrationQrService $qr)
    {
        $this->authorize('update',$tournament);
        return response($qr->png(route('tournaments.invite.show',$tournament->invite_token)))
            ->header('Content-Type','image/png')->header('Cache-Control','private, no-store')
            ->header('Content-Disposition','inline; filename="inscricoes-'.$tournament->id.'.png"')
            ->header('X-Content-Type-Options','nosniff');
    }
    public function show(Tournament $tournament)
    {
        $this->authorize('update',$tournament);
        return response()->view('arena.registration-qr',compact('tournament'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
}
