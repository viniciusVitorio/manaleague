<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use App\Services\LiveDisplayService;
use Illuminate\Http\Request;
class LiveDisplayController extends Controller
{
    public function data(Request $request,string $slug,LiveDisplayService $service)
    {
        $t=Tournament::where('public_slug',$slug)->firstOrFail();
        $response=response()->json($service->snapshot($t))->header('Cache-Control','public, no-cache, max-age=0');
        $response->setEtag(hash('sha256',$response->getContent()));
        $response->isNotModified($request);
        return $response;
    }
    public function tv(string $slug,LiveDisplayService $service)
    {
        $t=Tournament::where('public_slug',$slug)->firstOrFail();
        return response()->view('arena.tv',['snapshot'=>$service->snapshot($t),'slug'=>$slug])->header('Cache-Control','no-store');
    }
}
