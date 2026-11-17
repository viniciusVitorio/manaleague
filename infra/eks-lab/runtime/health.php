<?php

/** Lab-only health controller. Never exposes database errors or credentials. */
function labHealth(string $path, ?callable $databaseProbe = null): int
{
    if (!app()->environment('eks-lab')) {
        return 404;
    }
    if ($path === '/up') {
        return 200;
    }
    try {
        ($databaseProbe ?? static fn () => Illuminate\Support\Facades\DB::select('SELECT 1'))();
        return 200;
    } catch (Throwable) {
        return 503;
    }
}
