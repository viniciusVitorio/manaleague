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
        if ($databaseProbe !== null) {
            $databaseProbe();
        } else {
            // FPM's PHP_BINARY is php-fpm, not the CLI. Isolate all DB phases
            // (including DNS/TLS/auth/query) so a stuck libpq call is killable.
            $probe = new Symfony\Component\Process\Process([
                PHP_BINDIR.'/php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                __FILE__, '--database-probe',
            ], dirname(__DIR__, 3), null, null, 1.5);
            $probe->disableOutput();
            if ($probe->run() !== 0) {
                return 503;
            }
        }
        return 200;
    } catch (Throwable) {
        return 503;
    }
}

// Inherit the same guard, Laravel config and libpq CA environment. No secrets
// travel in argv; the parent discards all child output, including bootstrap errors.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    && ($argv[1] ?? '') === '--database-probe') {
    try {
        require __DIR__.'/database-guard.php';
        labDatabaseGuard();
        $root = dirname(__DIR__, 3);
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::select('SELECT 1');
        exit(0);
    } catch (Throwable) {
        exit(1);
    }
}
